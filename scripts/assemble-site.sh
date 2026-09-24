#!/usr/bin/env bash
#
# Assemble the web-root tree exactly as it lives on the server.
#
# The SFTP account is jailed to the web root, so remote "/" is the site and
# everything private has to live INSIDE it, in /_arche/ (Require all denied).
# This script is the one place that knows how the repo maps onto that tree:
#
#   app/dist/**            → /                 the PWA
#   server/public/**       → /                 entry points + every .htaccess
#                                              and .user.ini (dotfiles kept)
#   server/app/**          → /_arche/app/      private PHP
#   server/config/**       → /_arche/config/
#   server/resources/**    → /_arche/resources/
#   server/vendor/**       → /_arche/vendor/   (no Composer on the host)
#
# Runtime-only on the server, never assembled: /program/** and /media/**
# (except their .htaccess), /_arche/var/**, /_arche/.env.
#
# Usage: scripts/assemble-site.sh [options]
#
#   (default)      full deploy tree into build/site/, rebuilt from scratch
#   --dev          fill .data/site/ for the Docker stack instead: server/public
#                  plus the empty runtime and mount-point directories compose
#                  bind-mounts over. Never deletes runtime data already there.
#   --with-app     with --dev: also copy app/dist (a prod-like local run)
#   --out DIR      write somewhere else
#   --cdn ORIGIN   the CDN in front of /program and /media (e.g.
#                  https://arche-radio.b-cdn.net): allowed by the page's CSP.
#                  Without it the CSP allows only the site itself.
#   --skip-build   do not run `npm run build` first
#   -h, --help     this text

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"

MODE=full OUT="" DO_BUILD=1 WITH_APP=0 CDN=

while [ $# -gt 0 ]; do
  case "$1" in
    --dev)        MODE=dev ;;
    --with-app)   WITH_APP=1 ;;
    --out)        shift; OUT="${1:-}"; [ -n "$OUT" ] || die "--out needs a directory" ;;
    --cdn)        shift; CDN="${1:-}" ;;
    --skip-build) DO_BUILD=0 ;;
    -h|--help)    sed -n '2,35p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)            die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

# An origin only (it lands inside a quoted header and a sed replacement).
CDN="${CDN%/}"
if [ -n "$CDN" ] && ! [[ "$CDN" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?$ ]]; then
  die "--cdn must be an origin like https://name.b-cdn.net (got '$CDN')"
fi

if [ -z "$OUT" ]; then
  if [ "$MODE" = dev ]; then OUT="$REPO_ROOT/.data/site"; else OUT="$REPO_ROOT/build/site"; fi
fi
case "$OUT" in /*) ;; *) OUT="$REPO_ROOT/$OUT" ;; esac
# The output may be deleted and rebuilt, so it must be exactly the path it
# looks like: "build/../server" would otherwise pass for a build/ directory.
case "/$OUT/" in */../*|*/./*) die "--out must be a plain path (no . or .. segments): $OUT" ;; esac
OUT="${OUT%/}"

require_tools rsync find

PUBLIC="server/public"
# Files the server cannot work without. Checked up front so a half-written
# tree never reaches the deploy step.
REQUIRED_PUBLIC=(.htaccess api/index.php cron.php _arche/.htaccess)

# macOS litter and repo-only markers never belong on the server.
RSYNC_BASE=(-a --exclude '.DS_Store' --exclude '.gitkeep')

check_public() {
  local f missing=()
  [ -d "$PUBLIC" ] || die "$PUBLIC/ does not exist yet"
  for f in "${REQUIRED_PUBLIC[@]}"; do [ -f "$PUBLIC/$f" ] || missing+=("$PUBLIC/$f"); done
  [ ${#missing[@]} -eq 0 ] || die "missing web-root file(s): ${missing[*]}"
}

build_app() {
  info "Building the PWA (npm run build)"
  npm run build
  [ -f app/dist/index.html ] || die "build produced no app/dist/index.html"
}

# A file present in both app/dist and server/public would silently lose one
# side depending on copy order. There is no legitimate case, so it is fatal.
check_overlap() {
  local a b clash
  a=$(mktemp); b=$(mktemp)
  ( cd app/dist && find . -type f ! -name '.DS_Store' | sed 's|^\./||' | LC_ALL=C sort ) > "$a"
  ( cd "$PUBLIC" && find . -type f ! -name '.DS_Store' ! -name '.gitkeep' | sed 's|^\./||' | LC_ALL=C sort ) > "$b"
  clash=$(LC_ALL=C comm -12 "$a" "$b")
  rm -f "$a" "$b"
  [ -z "$clash" ] || die "app/dist and $PUBLIC both provide: $(printf '%s' "$clash" | tr '\n' ' ')"
}

# Nothing runtime and nothing secret may ride along with a deploy. A committed
# program file would overwrite the live timeline; a stray .env would be
# uploaded next to (or over) the server's own.
check_no_runtime() {
  local root="$1" found
  found=$(
    cd "$root"
    {
      [ -d program ] && find program -type f ! -name '.htaccess' ! -name '.user.ini'
      [ -d media ] && find media -type f ! -name '.htaccess' ! -name '.user.ini'
      [ -d _arche/var ] && find _arche/var -type f
      find . -type f \( -name '.env' -o -name '.env.*' -o -name '*.env' -o -name '*.sqlite' -o -name '*.sqlite-*' \) \
        ! -path './_arche/vendor/*'
    } 2>/dev/null | sed 's|^\./||' | LC_ALL=C sort -u || true
  )
  [ -z "$found" ] || die "refusing to assemble runtime or secret files:
$(printf '%s\n' "$found" | sed 's/^/    /')"
}

# The root .htaccess carries the page's CSP with a __CDN__ placeholder.
apply_cdn() {
  local f="$1/.htaccess" tmp
  [ -f "$f" ] || die "$f is missing"
  tmp=$(mktemp)
  if [ -n "$CDN" ]; then sed "s#__CDN__#$CDN#g" "$f" > "$tmp"; else sed 's# __CDN__##g' "$f" > "$tmp"; fi
  # Keep the copy's mode (rsync -a preserved the source's).
  cat "$tmp" > "$f"
  rm -f "$tmp"
  if grep -q '__CDN__' "$f"; then die "a __CDN__ placeholder is left in $f"; fi
  if [ -n "$CDN" ]; then ok "CSP allows the CDN $CDN"; fi
}

assemble_full() {
  check_public
  [ -f server/vendor/autoload.php ] \
    || die "server/vendor is missing — run: npm run composer -- install --no-dev --prefer-dist --optimize-autoloader"
  [ -d server/app ] || die "server/app/ does not exist yet"
  if [ "$DO_BUILD" -eq 1 ]; then build_app; fi
  [ -f app/dist/index.html ] || die "app/dist/index.html not found — run without --skip-build"
  check_overlap

  # The tree is rebuilt from scratch, so the output directory is deleted
  # first — which is only ever allowed inside this repo's build/. Anywhere else
  # (including .data/, where the local station keeps its live data) the
  # target must not exist yet or be empty.
  case "$OUT" in
    "$REPO_ROOT"/build/?*) ;;
    *)
      if [ -e "$OUT" ] && [ -n "$(ls -A "$OUT" 2>/dev/null)" ]; then
        die "refusing to replace non-empty $OUT (only directories inside build/ are rebuilt in place)"
      fi
      ;;
  esac

  info "Assembling $OUT"
  rm -rf "$OUT"
  mkdir -p "$OUT/_arche"

  rsync "${RSYNC_BASE[@]}" app/dist/ "$OUT/"
  rsync "${RSYNC_BASE[@]}" "$PUBLIC/" "$OUT/"
  apply_cdn "$OUT"

  local d
  for d in app config resources bin; do
    if [ -d "server/$d" ]; then rsync "${RSYNC_BASE[@]}" "server/$d/" "$OUT/_arche/$d/"; fi
  done
  # The wake controller renders this into every realtime node's user_data;
  # infra/ is its source of truth, the server reads its copy from resources/.
  cp -p infra/realtime/cloud-init.yaml.tmpl "$OUT/_arche/resources/cloud-init.yaml.tmpl"
  # vendor/ goes up file by file over SFTP, so everything the runtime never
  # loads is dropped: package test suites (autoload-dev only) and dotfiles.
  rsync "${RSYNC_BASE[@]}" \
    --exclude '/*/*/tests/' --exclude '/*/*/Tests/' \
    --exclude '/.*' --exclude '/**/.*' \
    server/vendor/ "$OUT/_arche/vendor/"

  check_no_runtime "$OUT"

  local files size vendor
  files=$(find "$OUT" -type f | wc -l | tr -d ' ')
  vendor=$(find "$OUT/_arche/vendor" -type f | wc -l | tr -d ' ')
  size=$(du -sh "$OUT" | cut -f1 | tr -d ' \t')
  ok "$files file(s), $size ($vendor in _arche/vendor)"
}

assemble_dev() {
  check_public
  info "Filling $OUT for the Docker stack"
  # Runtime directories (PHP writes here) and the mount points compose binds
  # server/{app,config,vendor,resources} onto.
  mkdir -p "$OUT/program" "$OUT/media" "$OUT/_arche/var" \
           "$OUT/_arche/app" "$OUT/_arche/config" "$OUT/_arche/vendor" "$OUT/_arche/resources"

  # No --delete: .data/site holds the local station's live program, media and
  # database, and a re-assemble must never cost them. Inside the runtime
  # directories only their dotfiles (.htaccess/.user.ini) are refreshed.
  rsync "${RSYNC_BASE[@]}" \
    --exclude '/_arche/app/' --exclude '/_arche/config/' --exclude '/_arche/vendor/' \
    --exclude '/_arche/resources/' --exclude '/_arche/var/' \
    --exclude '/program/*' --exclude '/media/*' \
    "$PUBLIC/" "$OUT/"
  apply_cdn "$OUT"
  local d f
  for d in program media; do
    [ -d "$PUBLIC/$d" ] || continue
    for f in "$PUBLIC/$d"/.htaccess "$PUBLIC/$d"/.user.ini; do
      if [ -f "$f" ]; then cp -p "$f" "$OUT/$d/"; fi
    done
    if find "$PUBLIC/$d" -type f ! -name '.htaccess' ! -name '.user.ini' ! -name '.gitkeep' | grep -q .; then
      warn "$PUBLIC/$d holds non-dotfiles; not copied (runtime directory)"
    fi
  done

  if [ "$WITH_APP" -eq 1 ]; then
    if [ "$DO_BUILD" -eq 1 ]; then build_app; fi
    [ -f app/dist/index.html ] || die "app/dist/index.html not found — run without --skip-build"
    check_overlap
    rsync "${RSYNC_BASE[@]}" app/dist/ "$OUT/"
    ok "PWA copied from app/dist"
  fi
  ok "ready: $OUT (runtime data kept)"
}

if [ "$MODE" = dev ]; then assemble_dev; else assemble_full; fi
