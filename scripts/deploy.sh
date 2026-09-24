#!/usr/bin/env bash
#
# Deploy ARCHE to the Hetzner webhosting (SITE_BASE_URL in .env).
#
# The SFTP account is jailed to the site's document root, so remote "/" is the
# web root and the private half of the app lives inside it, in /_arche/, behind
# `Require all denied`. There is no shell on the far side: no tar, no unzip, no
# atomic swap of a staging tree, no remote hashing. Files are put one at a time,
# which is why this uploads INCREMENTALLY: every deploy writes a
# `.deploy-manifest` of content hashes, the next one diffs against it and
# uploads only what changed — cross-checked against the real remote file sizes,
# so a file deleted or truncated on the server comes back even though the
# manifest still lists it. (Pattern from walk-in-the-spirit-game's deploy.)
#
# The order is the safety argument:
#   1. directories, then /_arche/.htaccess and two marker files, then the
#      maintenance flag (/_arche/var/maintenance: the PHP tick skips while it
#      exists, so it never runs against half-uploaded code)
#   2. an HTTP check that the markers answer 403 — nothing private goes up
#      before the deny rule is PROVEN to work on this host
#   3. the server .env (only with --initial / --env)
#   4. /_arche/{vendor,app,config,resources}, then the PWA assets
#   5. api/*.php and cron.php, then the nested .htaccess/.user.ini files, then
#      the root .htaccess
#   6. index.html, sw.js, registerSW.js, manifest.webmanifest — last, so no
#      client boots an entry point whose assets are not there yet
#   7. the manifest (this deploy's claim about the server; believed only once
#      true), optional prune, maintenance flag off, verify
#
# Usage: scripts/deploy.sh [options]
#
#   --dry-run       assemble and print the plan; upload nothing
#   --offline       with --dry-run: do not contact the server at all
#   --verify-only   run the post-deploy HTTP checks and exit
#   --initial       first install: also upload a server .env built from an
#                   allow-list of .env keys (never SFTP_*); refuses when the
#                   server already has /_arche/.env or /_arche/var/arche.sqlite
#   --env           replace the server's /_arche/.env with a fresh one
#   --prune         delete remote files this build no longer produces (never
#                   /program, /media, /_arche/var, /_probe or dotfiles)
#   --skip-build    assemble from the existing app/dist
#   --force-all     ignore the manifest; re-upload every file
#   --jobs N        parallel sftp connections (default 4)
#   --env-file F    read settings from F instead of .env
#   -h, --help      this text

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"
# shellcheck source=scripts/lib/env.sh
. "$REPO_ROOT/scripts/lib/env.sh"
# shellcheck source=scripts/lib/sftp.sh
. "$REPO_ROOT/scripts/lib/sftp.sh"

ENV_FILE="$REPO_ROOT/.env"
SITE_DIR="$REPO_ROOT/build/site"
STATE_DIR="$REPO_ROOT/build/deploy-state"
MANIFEST_NAME=".deploy-manifest"
MAINT_PATH="/_arche/var/maintenance"
DENY_MARKERS=(/_arche/.deny-check /_arche/deny-check.txt)

# Uploaded last, after everything they reference is in place.
ENTRY_FILES=(index.html sw.js registerSW.js manifest.webmanifest)

# The only keys that ever reach the server. Everything else in .env — above
# all the SFTP_* login — stays on this machine.
SERVER_ENV_KEYS=(
  SITE_BASE_URL
  ANTHROPIC_KEY OPENAI_KEY YOUTUBE_API_KEY
  AI_MODE AI_TEXT_PROVIDER HOST_MODEL MODERATION_MODEL OPENAI_HOST_MODEL OPENAI_MODERATION_MODEL AI_DAILY_BUDGET_USD
  TTS_PROVIDER ELEVENLABS_API_KEY ELEVENLABS_MODEL ELEVENLABS_VOICE_EN ELEVENLABS_VOICE_DE
  ELEVENLABS_MAX_CHARS_PER_DAY
  CRON_KEY NODE_SECRET IDENTITY_PEPPER ADMIN_SETUP_KEY TOKEN_SIGNING_KEY TOKEN_PUBLIC_KEY
  REALTIME_DRIVER REALTIME_STATIC_URL HETZNER_CLOUD_TOKEN REALTIME_SLOTS REALTIME_MAX_NODES
  REALTIME_SERVER_TYPE REALTIME_FALLBACK_TYPE REALTIME_LOCATION REALTIME_FIREWALL_ID REALTIME_ACME_EMAIL
  STATION_LANGS TICK_BUDGET SQLITE_WAL MODERATION_HUMAN_REVIEW HOST_MIN_LISTENERS
  HOST_MAX_BREAKS_PER_DAY MODERATION_MAX_PER_DAY PULSE_SECONDS
  CDN_BASE_URL BUNNY_PULL_ZONE_ID BUNNY_API_KEY
  SUBMISSIONS_PER_IP_HOUR IDENTITIES_PER_IP_DAY
)
# Without these the server cannot run at all (cron auth, identity hashing,
# realtime tokens, the admin claim).
SERVER_ENV_REQUIRED=(CRON_KEY NODE_SECRET IDENTITY_PEPPER ADMIN_SETUP_KEY TOKEN_SIGNING_KEY TOKEN_PUBLIC_KEY)

# Written by PHP on the server (or by the probe / this script's own deny
# check). Never listed for upload, never pruned.
is_runtime_path() {
  case "$1" in
    program/*|media/*|_arche/var/*|_arche/var|_probe/*|_probe|_arche/deny-check.txt) return 0 ;;
  esac
  return 1
}

DO_BUILD=1 FORCE_ALL=0 DO_PRUNE=0 DRY_RUN=0 OFFLINE=0 VERIFY_ONLY=0 JOBS=4 ENV_MODE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)     DRY_RUN=1 ;;
    --offline)     OFFLINE=1 ;;
    --verify-only) VERIFY_ONLY=1 ;;
    --initial)     [ -z "$ENV_MODE" ] || die "--initial and --env are mutually exclusive"; ENV_MODE=initial ;;
    --env)         [ -z "$ENV_MODE" ] || die "--initial and --env are mutually exclusive"; ENV_MODE=replace ;;
    --prune)       DO_PRUNE=1 ;;
    --skip-build)  DO_BUILD=0 ;;
    --force-all)   FORCE_ALL=1 ;;
    --jobs)        shift; JOBS="${1:-}" ;;
    --env-file)    shift; ENV_FILE="${1:-}"; [ -n "$ENV_FILE" ] || die "--env-file needs a path" ;;
    -h|--help)     sed -n '2,50p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)             die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

case "$JOBS" in ''|*[!0-9]*) die "--jobs needs a number" ;; esac
[ "$JOBS" -ge 1 ] || die "--jobs must be at least 1"
[ "$OFFLINE" -eq 0 ] || [ "$DRY_RUN" -eq 1 ] || die "--offline only makes sense with --dry-run"

require_env "$ENV_FILE" SITE_BASE_URL
SITE_URL=$(env_value "$ENV_FILE" SITE_BASE_URL)
SITE_URL="${SITE_URL%/}"

WORK=$(mktemp -d)
MAINT_SET=0
cleanup() {
  local rc=$?
  if [ "$MAINT_SET" -eq 1 ]; then
    # Best effort: a flag left behind would stop the station's ticks. The PHP
    # side also ignores a flag older than a few minutes, for exactly this case.
    printf -- '-rm %s\n' "$(sq "$(rpath "$MAINT_PATH")")" > "$WORK/maint-off.batch"
    if sftp_batch "$WORK/maint-off.batch" >/dev/null 2>&1; then
      warn "maintenance flag removed after an interrupted deploy"
    else
      bad "could not remove $MAINT_PATH — delete it over SFTP (the tick skips while it is fresh)"
    fi
  fi
  rm -rf "$WORK"
  exit "$rc"
}
trap cleanup EXIT

# --- verify ------------------------------------------------------------------

# What the deploy is supposed to guarantee, checked from the outside.
verify() {
  info "Verifying $SITE_URL"
  local failed=0 warned=0

  req() { curl -sS --max-time 20 "$@" 2>/dev/null; }
  code_of() { req -o /dev/null -w '%{http_code}' "$@" || echo 000; }
  header_of() { # header_of NAME curl-args... → value of the first NAME header
    local name="$1"; shift
    req -o /dev/null -D - "$@" | tr -d '\r' | awk -v n="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')" '
      { line = $0; lower = tolower(line) }
      index(lower, n ":") == 1 { sub(/^[^:]*:[ \t]*/, "", line); print line; exit }' || true
  }
  pass() { ok "$1"; }
  fail() { bad "$1"; failed=$(( failed + 1 )); }
  soft() { warn "$1"; warned=$(( warned + 1 )); }

  is_json() { # stdin → exit 0 when it parses as JSON
    if command -v php >/dev/null 2>&1; then
      php -r 'json_decode(stream_get_contents(STDIN)); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);'
    elif command -v python3 >/dev/null 2>&1; then
      python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null
    elif command -v jq >/dev/null 2>&1; then
      jq -e . >/dev/null 2>&1
    else
      cat >/dev/null; return 0
    fi
  }

  check_status() { # PATH WANT LABEL [curl args]
    local path="$1" want="$2" label="$3" got; shift 3
    got=$(code_of "$@" "$SITE_URL$path")
    if [ "$got" = "$want" ]; then pass "$label ($path → $got)"; else fail "$label ($path → $got, expected $want)"; fi
  }

  # JSON endpoint: status, content type and a body that actually parses — an
  # Apache error page with the right status would otherwise pass.
  check_json() { # PATH WANT_STATUS LABEL [curl args]
    local path="$1" want="$2" label="$3" body type got; shift 3
    body="$WORK/body.$$"
    got=$(req -o "$body" -w '%{http_code} %{content_type}' "$@" "$SITE_URL$path" || echo "000 -")
    type="${got#* }"; got="${got%% *}"
    case "$want" in *"$got"*) ;; *) fail "$label ($path → $got, expected $want)"; rm -f "$body"; return ;; esac
    case "$type" in
      application/json*) ;;
      *) fail "$label ($path → $got but $type, expected application/json)"; rm -f "$body"; return ;;
    esac
    if is_json < "$body"; then pass "$label ($path → $got JSON)"; else fail "$label ($path → $got, body is not JSON)"; fi
    rm -f "$body"
  }

  check_json /api/time 200 "API answers"

  # Private code and data: denied, whether or not the file exists yet.
  local p
  for p in /_arche/.env /_arche/vendor/autoload.php /_arche/var/arche.sqlite \
           /_arche/.deny-check /_arche/deny-check.txt /_arche/ "/$MANIFEST_NAME"; do
    check_status "$p" 403 "private path denied"
  done

  # Bearer auth must reach PHP. Under FPM, Apache drops the Authorization
  # header unless the .htaccess passes it on (SetEnvIf) — then every cron call
  # is a 401 and nothing ever runs, with no error anywhere.
  check_json /cron.php 401 "cron refuses a request without key" -X POST
  local cron_key
  cron_key=$(env_value "$ENV_FILE" CRON_KEY)
  if [ -n "$cron_key" ]; then
    check_json /cron.php "200 202" "cron accepts the Bearer key (header reaches PHP)" \
      -X POST -H "Authorization: Bearer $cron_key"
  else
    soft "CRON_KEY not in $ENV_FILE — skipped the authenticated cron check (npm run init-secrets)"
  fi

  # SPA: a deep link is the app shell; a missing asset stays a real 404.
  local spa
  spa=$(req -o /dev/null -w '%{http_code} %{content_type}' "$SITE_URL/schedule" || echo "000 -")
  case "$spa" in "200 text/html"*) pass "SPA fallback (/schedule → $spa)" ;; *) fail "SPA fallback (/schedule → $spa, expected 200 text/html)" ;; esac
  check_status /assets/nope.js 404 "missing asset is a 404, not the shell"

  local html js
  html=$(req "$SITE_URL/" || true)
  if printf '%s' "$html" | grep -q 'src="/assets/'; then
    pass "index.html references /assets/"
  else
    fail "index.html does not reference /assets/"
  fi
  js=$(printf '%s' "$html" | grep -o '/assets/[^"]*\.js' | head -n1 || true)
  if [ -n "$js" ]; then
    # The host sends .js as text/javascript; a deflate rule listing only
    # application/javascript leaves the bundle uncompressed, silently.
    if [ "$(header_of content-encoding -H 'Accept-Encoding: gzip' "$SITE_URL$js")" = gzip ]; then
      pass "main bundle served gzipped ($js)"
    else
      fail "main bundle NOT gzipped ($js) — check AddOutputFilterByType lists text/javascript"
    fi
  else
    fail "no /assets/*.js found in index.html"
  fi

  local cc
  cc=$(header_of cache-control "$SITE_URL/sw.js")
  case "$cc" in *no-cache*) pass "sw.js revalidates (Cache-Control: $cc)" ;; *) fail "sw.js Cache-Control is '$cc', expected no-cache" ;; esac

  local mtype
  mtype=$(req -o /dev/null -w '%{http_code} %{content_type}' "$SITE_URL/manifest.webmanifest" || echo "000 -")
  case "$mtype" in
    "200 application/manifest+json"*) pass "PWA manifest ($mtype)" ;;
    200*) soft "PWA manifest served as '${mtype#200 }' — Chrome may ignore it (AddType application/manifest+json)" ;;
    *) fail "PWA manifest (/manifest.webmanifest → $mtype)" ;;
  esac

  # Program files: channels.json exists once the generator has run.
  local ch
  ch=$(req -o /dev/null -w '%{http_code} %{content_type}' "$SITE_URL/program/channels.json" || echo "000 -")
  case "$ch" in
    "200 application/json"*) pass "program published (/program/channels.json)" ;;
    404*) soft "/program/channels.json not published yet (fresh install: it appears after the first tick)" ;;
    *) fail "/program/channels.json → $ch, expected 200 application/json" ;;
  esac
  # A 404 must not be cached: the file for a minute that is not generated yet
  # becomes real a moment later, and an immutable 404 would hide it for good.
  local miss
  miss=$(code_of "$SITE_URL/program/__verify_missing__.json")
  cc=$(header_of cache-control "$SITE_URL/program/__verify_missing__.json")
  if [ "$miss" = 404 ]; then
    case "$cc" in *no-store*) pass "program 404 is not cacheable (Cache-Control: $cc)" ;; *) fail "program 404 Cache-Control is '$cc', expected no-store" ;; esac
  else
    fail "missing program file → $miss, expected 404"
  fi

  # no-referrer makes YouTube refuse the embed (error 153).
  local rp
  rp=$(header_of referrer-policy "$SITE_URL/")
  case "$rp" in
    no-referrer) fail "Referrer-Policy is no-referrer — YouTube embeds fail with error 153" ;;
    "") soft "no Referrer-Policy header on / (expected strict-origin-when-cross-origin)" ;;
    *) pass "Referrer-Policy: $rp" ;;
  esac

  # The CDN (a pull zone with this site as origin): the page may read from it,
  # and it answers for the program with the CORS header the app needs — a
  # failing edge only costs the fallback to this site, so nothing here is
  # fatal for the radio, but each one means the CDN carries nothing.
  local cdn csp acao
  cdn=$(env_value "$ENV_FILE" CDN_BASE_URL); cdn="${cdn%/}"
  if [ -n "$cdn" ] && [ "$cdn" != off ]; then
    csp=$(header_of content-security-policy "$SITE_URL/")
    case "$csp" in
      *"connect-src 'self' $cdn "*) pass "CSP allows the CDN ($cdn)" ;;
      *) fail "CSP does not allow the CDN $cdn — assembled without --cdn?" ;;
    esac
    ch=$(req -o /dev/null -w '%{http_code} %{content_type}' -H "Origin: $SITE_URL" "$cdn/program/channels.json" || echo "000 -")
    acao=$(header_of access-control-allow-origin -H "Origin: $SITE_URL" "$cdn/program/channels.json")
    case "$ch" in
      "200 application/json"*)
        if [ -n "$acao" ]; then pass "CDN serves the program with CORS ($cdn → $ch, ACAO $acao)"
        else fail "CDN answers without Access-Control-Allow-Origin — the app cannot read it (scripts/cdn/setup-bunny.sh)"; fi ;;
      404*) soft "CDN: /program/channels.json not published yet" ;;
      *) fail "CDN $cdn/program/channels.json → $ch, expected 200 application/json" ;;
    esac
  fi

  [ "$warned" -eq 0 ] || info "$warned warning(s)"
  [ "$failed" -eq 0 ] || die "$failed check(s) failed"
  info "All checks passed"
}

if [ "$VERIFY_ONLY" -eq 1 ]; then
  require_tools curl
  verify
  exit 0
fi

require_tools sshpass sftp curl shasum rsync

# --- assemble ------------------------------------------------------------------

assemble_args=()
[ "$DO_BUILD" -eq 1 ] || assemble_args+=(--skip-build)
# The page's CSP names the CDN the server tells the app about.
cdn_base=$(env_value "$ENV_FILE" CDN_BASE_URL)
if [ -n "$cdn_base" ] && [ "$cdn_base" != off ]; then assemble_args+=(--cdn "$cdn_base"); fi
bash "$REPO_ROOT/scripts/assemble-site.sh" ${assemble_args[@]+"${assemble_args[@]}"}
[ -f "$SITE_DIR/index.html" ] || die "$SITE_DIR is incomplete"

load_sftp_credentials "$ENV_FILE"

# --- manifests -----------------------------------------------------------------

# "<sha256>  <path>" for every non-dotfile. Dotfiles (.htaccess, .user.ini)
# are control files: re-uploaded on every deploy, in their own slot of the
# order, never size-checked (ls -l hides them) and never pruned.
#
# `shasum -a 256` spelled out: macOS's own `sha256` prints a format that parses
# as garbage here, and xargs execs a binary, not a shell function.
build_local_manifest() {
  ( cd "$SITE_DIR" && find . -type f ! -name '.*' -print0 | xargs -0 shasum -a 256 ) \
    | sed 's|^\([0-9a-f]\{64\}\)  \./|\1  |' \
    | LC_ALL=C sort
}

manifest_paths() { sed 's|^[0-9a-f]\{64\}  ||' "$@"; }

STAT_FLAVOUR=gnu
stat -f '%z' . >/dev/null 2>&1 && STAT_FLAVOUR=bsd

local_sizes() {
  (
    cd "$SITE_DIR"
    if [ "$STAT_FLAVOUR" = bsd ]; then
      find . -type f ! -name '.*' -print0 | xargs -0 stat -f '%z %N'
    else
      find . -type f ! -name '.*' -print0 | xargs -0 stat -c '%s %n'
    fi
  ) | awk '{ size = $1; $1 = ""; sub(/^ /, ""); sub(/^\.\//, ""); printf "%s\t%s\n", $0, size }'
}

# Directories to inventory remotely: the root plus every local directory,
# minus the runtime ones (their contents are the server's, not ours).
inventory_dirs() {
  printf '/\n'
  ( cd "$SITE_DIR" && find . -mindepth 1 -type d ! -name '.*' | sed 's|^\./||' | LC_ALL=C sort ) \
    | while IFS= read -r d; do is_runtime_path "$d/" || printf '/%s\n' "$d"; done
}

# --- planning ------------------------------------------------------------------

REMOTE_OK=1
REMOTE_ENV_STATE="unknown"   # present | missing | unknown

plan() {
  info "Hashing $SITE_DIR"
  build_local_manifest > "$WORK/local.man"
  ok "$(wc -l < "$WORK/local.man" | tr -d ' ') file(s) + $(find "$SITE_DIR" -type f -name '.*' ! -name '.DS_Store' | wc -l | tr -d ' ') control file(s), $(du -sh "$SITE_DIR" | cut -f1 | tr -d ' \t')"

  if [ "$OFFLINE" -eq 1 ]; then
    REMOTE_OK=0
  elif ! sftp_reachable; then
    [ "$DRY_RUN" -eq 1 ] || die "cannot log in to $SFTP_USER@$SFTP_HOST:$SFTP_PORT"
    REMOTE_OK=0
    warn "server not reachable — planning as if it were empty"
  fi

  : > "$WORK/remote.man"
  : > "$WORK/remote.sizes"
  if [ "$REMOTE_OK" -eq 1 ]; then
    info "Reading remote state"
    if remote_get "/$MANIFEST_NAME" "$WORK/remote.man"; then
      LC_ALL=C sort "$WORK/remote.man" -o "$WORK/remote.man"
      ok "manifest found ($(wc -l < "$WORK/remote.man" | tr -d ' ') entries)"
    else
      : > "$WORK/remote.man"
      ok "no manifest on the server — treating this as a first deploy"
    fi
    inventory_dirs > "$WORK/dirs"
    # shellcheck disable=SC2046
    remote_sizes $(cat "$WORK/dirs") | LC_ALL=C sort > "$WORK/remote.sizes"
    ok "$(wc -l < "$WORK/remote.sizes" | tr -d ' ') file(s) currently on the server (outside runtime paths)"

    local st=0
    remote_exists /_arche/.env || st=$?
    case "$st" in 0) REMOTE_ENV_STATE=present ;; 1) REMOTE_ENV_STATE=missing ;; *) REMOTE_ENV_STATE=unknown ;; esac
  elif [ "$OFFLINE" -eq 1 ]; then
    ok "--offline: remote state not read (plan assumes an empty server)"
  fi

  if [ "$FORCE_ALL" -eq 1 ]; then
    info "--force-all: uploading everything"
    manifest_paths "$WORK/local.man" > "$WORK/upload"
  else
    local_sizes | LC_ALL=C sort > "$WORK/local.sizes"
    # (a) content changed or path is new
    LC_ALL=C comm -23 "$WORK/local.man" "$WORK/remote.man" | manifest_paths > "$WORK/changed"
    # (b) the manifest and the server disagree: missing remotely or a
    #     different size (an upload interrupted after the manifest went up).
    LC_ALL=C comm -23 "$WORK/local.sizes" "$WORK/remote.sizes" | cut -f1 > "$WORK/mismatched"
    cat "$WORK/changed" "$WORK/mismatched" | LC_ALL=C sort -u > "$WORK/upload"
  fi

  # Partition into the upload phases (see the header).
  : > "$WORK/p.private"; : > "$WORK/p.bulk"; : > "$WORK/p.php"
  local path e is_entry
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    is_entry=0
    for e in "${ENTRY_FILES[@]}"; do [ "$path" = "$e" ] && is_entry=1; done
    [ "$is_entry" -eq 1 ] && continue          # always sent in the tail
    case "$path" in
      _arche/*)          printf '%s\n' "$path" >> "$WORK/p.private" ;;
      api/*.php|*.php)   case "$path" in */*/*) printf '%s\n' "$path" >> "$WORK/p.bulk" ;; *) printf '%s\n' "$path" >> "$WORK/p.php" ;; esac ;;
      *)                 printf '%s\n' "$path" >> "$WORK/p.bulk" ;;
    esac
  done < "$WORK/upload"

  # Control files, always sent: the deny rule first (step 1), the root
  # .htaccess after the nested ones, everything else in between.
  ( cd "$SITE_DIR" && find . -type f -name '.*' ! -name '.DS_Store' | sed 's|^\./||' | LC_ALL=C sort ) > "$WORK/control.all"
  grep -v -x -E '_arche/\.htaccess|\.htaccess' "$WORK/control.all" > "$WORK/p.control" || : > "$WORK/p.control"
  local c
  while IFS= read -r c; do
    case "$c" in
      */.htaccess|*/.user.ini|.user.ini) ;;
      *) warn "unexpected dotfile in the tree, uploaded as a control file: $c" ;;
    esac
  done < "$WORK/p.control"
  [ -f "$SITE_DIR/_arche/.htaccess" ] || die "build/site/_arche/.htaccess missing — the deny rule is what makes /_arche private"
  [ -f "$SITE_DIR/.htaccess" ] || die "build/site/.htaccess missing"
}

# --- server .env ---------------------------------------------------------------

# Quote a value for the server .env the way the loaders read it back: bare
# when safe, else one pair of quotes (which the loaders strip).
quote_env_value() {
  local v="$1"
  case "$v" in
    *[[:space:]]*|\"*|\'*)
      case "$v" in
        *\"*) case "$v" in *\'*) die "an .env value contains both quote kinds; cannot write it safely" ;; esac; printf "'%s'" "$v" ;;
        *) printf '"%s"' "$v" ;;
      esac ;;
    *) printf '%s' "$v" ;;
  esac
}

# A key the server does not use does not go up: the Hetzner token only with
# the hcloud driver, the ElevenLabs key only when the voice is switched to it.
# A leaked server .env then holds no more than the running station needs.
server_needs() { # KEY
  case "$1" in
    HETZNER_CLOUD_TOKEN) [ "$(env_value "$ENV_FILE" REALTIME_DRIVER)" = hcloud ] ;;
    ELEVENLABS_API_KEY) [ "$(env_value "$ENV_FILE" TTS_PROVIDER)" = elevenlabs ] ;;
    BUNNY_API_KEY) env_has "$ENV_FILE" BUNNY_PULL_ZONE_ID ;;
    *) return 0 ;;
  esac
}

make_server_env() {
  local out="$1" key value missing=()
  for key in "${SERVER_ENV_REQUIRED[@]}"; do env_has "$ENV_FILE" "$key" || missing+=("$key"); done
  [ ${#missing[@]} -eq 0 ] || die "not set in $ENV_FILE: ${missing[*]} — run: npm run init-secrets"
  # OpenAI alone runs everything; Anthropic is optional (Claude then writes
  # and moderates instead of OpenAI).
  for key in OPENAI_KEY YOUTUBE_API_KEY; do
    env_has "$ENV_FILE" "$key" || warn "$key is not set — the server will run without it"
  done
  (
    umask 077
    {
      printf '# Server environment for %s — written by scripts/deploy.sh %s.\n' "$SITE_URL" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      printf '# An allow-list of the repo .env; SFTP credentials never come here.\n'
      for key in "${SERVER_ENV_KEYS[@]}"; do
        value=$(env_value "$ENV_FILE" "$key")
        [ -n "$value" ] || continue
        server_needs "$key" || continue
        printf '%s=%s\n' "$key" "$(quote_env_value "$value")"
      done
    } > "$out"
  )
}

# "<KEY> <sha256 of value>" — enough to say which names changed between two
# deploys without keeping (or printing) a single value.
env_fingerprint() {
  local file="$1" key
  env_keys "$file" | while IFS= read -r key; do
    printf '%s %s\n' "$key" "$(printf '%s' "$(env_value "$file" "$key")" | shasum -a 256 | cut -c1-64)"
  done | LC_ALL=C sort
}

report_env_changes() {
  local new_fp="$1" old_fp="$2"
  if [ ! -f "$old_fp" ]; then
    info "server .env keys: $(cut -d' ' -f1 "$new_fp" | tr '\n' ' ')(no previous record on this machine)"
    return
  fi
  local added removed changed
  added=$(LC_ALL=C join -v1 <(cut -d' ' -f1 "$new_fp") <(cut -d' ' -f1 "$old_fp") | tr '\n' ' ')
  removed=$(LC_ALL=C join -v2 <(cut -d' ' -f1 "$new_fp") <(cut -d' ' -f1 "$old_fp") | tr '\n' ' ')
  changed=$(LC_ALL=C join "$new_fp" "$old_fp" | awk '$2 != $3 { print $1 }' | tr '\n' ' ')
  info "server .env vs. the last one this machine uploaded:"
  printf '    added:   %s\n    removed: %s\n    changed: %s\n' "${added:--}" "${removed:--}" "${changed:--}"
}

# --- upload helpers ------------------------------------------------------------

# upload_list LIST LABEL — put every web-root-relative path in LIST, split
# across $JOBS connections. One connection tops out well below the link.
upload_list() {
  local list="$1" label="$2" count jobs i n=0 path
  count=$(grep -c . "$list" 2>/dev/null || true)
  [ "${count:-0}" -gt 0 ] || { ok "$label: nothing to upload"; return 0; }
  jobs="$JOBS"; [ "$jobs" -gt "$count" ] && jobs="$count"

  for i in $(seq 1 "$jobs"); do : > "$WORK/shard.$label.$i.batch"; done
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    n=$(( n + 1 )); i=$(( (n - 1) % jobs + 1 ))
    printf 'put %s %s\n' "$(sq "$SITE_DIR/$path")" "$(sq "$(rpath "/$path")")" >> "$WORK/shard.$label.$i.batch"
  done < "$list"

  info "$label: $count file(s) over $jobs connection(s)"
  local pids=() failed=0 watcher=""
  for i in $(seq 1 "$jobs"); do
    sftp_batch "$WORK/shard.$label.$i.batch" "$WORK/shard.$label.$i.log" 2>"$WORK/shard.$label.$i.err" &
    pids+=("$!")
  done
  if [ -t 1 ]; then progress_watch "$label" "$count" & watcher=$!; fi
  for i in "${!pids[@]}"; do wait "${pids[$i]}" || failed=$(( failed + 1 )); done
  if [ -n "$watcher" ]; then kill "$watcher" 2>/dev/null || true; wait "$watcher" 2>/dev/null || true; printf '\r\033[K'; fi
  if [ "$failed" -gt 0 ]; then
    cat "$WORK"/shard."$label".*.err >&2 || true
    die "$failed of $jobs connection(s) failed in '$label' — rerun to resume (the manifest was not updated)"
  fi
  ok "$label: $count file(s) uploaded"
}

progress_watch() {
  local label="$1" total="$2" done_n
  while :; do
    sleep 2
    done_n=$(cat "$WORK"/shard."$label".*.log 2>/dev/null | grep -c '^Uploading' || true)
    printf '\r\033[K    %s: %s/%s files' "$label" "${done_n:-0}" "$total"
  done
}

# serial_put LABEL PATH... — one connection, in the given order.
serial_put() {
  local label="$1" batch path; shift
  # The label is prose ("nested .htaccess / .user.ini"); the file name is not.
  batch="$WORK/serial.$(printf '%s' "$label" | tr -c 'A-Za-z0-9' '_').batch"
  : > "$batch"
  for path in "$@"; do
    [ -n "$path" ] || continue
    printf 'put %s %s\n' "$(sq "$SITE_DIR/$path")" "$(sq "$(rpath "/$path")")" >> "$batch"
  done
  [ -s "$batch" ] || return 0
  sftp_batch "$batch" || die "upload failed: $label"
  ok "$label"
}

# --- steps ---------------------------------------------------------------------

step_prepare_and_deny() {
  local batch="$WORK/prepare.batch" d
  : > "$batch"
  # find is pre-order: parents are created before their children.
  while IFS= read -r d; do
    printf -- '-mkdir %s\n' "$(sq "$(rpath "/${d#./}")")" >> "$batch"
  done < <(cd "$SITE_DIR" && find . -mindepth 1 -type d | LC_ALL=C sort)
  printf -- '-mkdir %s\n' "$(sq "$(rpath /_arche)")" "$(sq "$(rpath /_arche/var)")" >> "$batch"
  printf 'put %s %s\n' "$(sq "$SITE_DIR/_arche/.htaccess")" "$(sq "$(rpath /_arche/.htaccess)")" >> "$batch"
  printf 'deny-check %s\n' "$(date -u +%s)" > "$WORK/marker"
  for d in "${DENY_MARKERS[@]}"; do
    printf 'put %s %s\n' "$(sq "$WORK/marker")" "$(sq "$(rpath "$d")")" >> "$batch"
  done
  # The flag carries its creation time: the tick ignores a stale one, so an
  # interrupted deploy can never park the station for good.
  date -u +%s > "$WORK/maintenance"
  printf 'put %s %s\n' "$(sq "$WORK/maintenance")" "$(sq "$(rpath "$MAINT_PATH")")" >> "$batch"
  MAINT_SET=1
  sftp_batch "$batch" || die "could not prepare directories / the deny rule"
  ok "directories, /_arche/.htaccess, deny markers, maintenance flag"
}

step_check_deny() {
  local m got
  for m in "${DENY_MARKERS[@]}"; do
    got=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL$m" 2>/dev/null || echo 000)
    [ "$got" = 403 ] || die "$SITE_URL$m answered $got, not 403 — /_arche is NOT private on this host. Nothing private was uploaded."
  done
  ok "/_arche answers 403 — safe to upload private files"
}

step_server_env() {
  [ -n "$ENV_MODE" ] || return 0
  local tmp="$WORK/server.env" fp_new="$WORK/server.env.fp" fp_old
  mkdir -p "$STATE_DIR"
  fp_old="$STATE_DIR/server-env.$SFTP_HOST.fingerprint"
  make_server_env "$tmp"
  env_fingerprint "$tmp" > "$fp_new"
  report_env_changes "$fp_new" "$fp_old"
  printf 'put %s %s\n' "$(sq "$tmp")" "$(sq "$(rpath /_arche/.env)")" > "$WORK/env.batch"
  # Owner-only on top of the deny rule; PHP runs as the account user.
  printf -- '-chmod 600 %s\n' "$(sq "$(rpath /_arche/.env)")" >> "$WORK/env.batch"
  sftp_batch "$WORK/env.batch" || die "could not upload /_arche/.env"
  cp "$fp_new" "$fp_old"
  rm -f "$tmp"
  ok "server .env written ($(grep -c '^[A-Z]' "$fp_new" | tr -d ' ') keys, values not shown)"
}

step_tail() {
  local present=() e
  for e in "${ENTRY_FILES[@]}"; do [ -f "$SITE_DIR/$e" ] && present+=("$e"); done
  cp "$WORK/local.man" "$WORK/$MANIFEST_NAME"
  local batch="$WORK/tail.batch"
  : > "$batch"
  for e in ${present[@]+"${present[@]}"}; do
    printf 'put %s %s\n' "$(sq "$SITE_DIR/$e")" "$(sq "$(rpath "/$e")")" >> "$batch"
  done
  # Last and only last: it is this deploy's claim about what the server holds.
  printf 'put %s %s\n' "$(sq "$WORK/$MANIFEST_NAME")" "$(sq "$(rpath "/$MANIFEST_NAME")")" >> "$batch"
  sftp_batch "$batch" || die "entry-point upload failed"
  ok "entry points and manifest written"
}

prune() {
  info "Pruning files this build does not produce"
  cut -f1 "$WORK/remote.sizes" | LC_ALL=C sort > "$WORK/remote.paths"
  manifest_paths "$WORK/local.man" | LC_ALL=C sort > "$WORK/local.paths"
  : > "$WORK/stale"
  local path
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    is_runtime_path "$path" && continue
    printf '%s\n' "$path" >> "$WORK/stale"
  done < <(LC_ALL=C comm -23 "$WORK/remote.paths" "$WORK/local.paths")
  local n; n=$(grep -c . "$WORK/stale" || true)
  if [ "${n:-0}" -eq 0 ]; then ok "nothing stale"; return; fi
  sed 's|^|  - |' "$WORK/stale"
  if [ "$DRY_RUN" -eq 1 ]; then ok "would remove $n file(s)"; return; fi
  : > "$WORK/prune.batch"
  while IFS= read -r path; do printf -- '-rm %s\n' "$(sq "$(rpath "/$path")")" >> "$WORK/prune.batch"; done < "$WORK/stale"
  sftp_batch "$WORK/prune.batch" || die "prune failed"
  ok "removed $n file(s)"
}

maintenance_off() {
  printf -- '-rm %s\n' "$(sq "$(rpath "$MAINT_PATH")")" > "$WORK/maint-off.batch"
  sftp_batch "$WORK/maint-off.batch" || die "could not remove $MAINT_PATH"
  MAINT_SET=0
  ok "maintenance flag removed"
}

guard_initial() {
  [ "$ENV_MODE" = initial ] || return 0
  [ "$REMOTE_OK" -eq 1 ] || { warn "--initial: server not checked (offline); a real run refuses an existing .env/database"; return 0; }
  local st p
  for p in /_arche/.env /_arche/var/arche.sqlite; do
    st=0; remote_exists "$p" || st=$?
    case "$st" in
      0) die "--initial refused: $p already exists on the server (use --env to replace the .env; the database is never overwritten)" ;;
      1) ;;
      *) die "--initial refused: could not tell whether $p exists" ;;
    esac
  done
  ok "--initial: no server .env or database yet"
}

print_list() { # FILE LABEL
  local n; n=$(grep -c . "$1" 2>/dev/null || true); n=${n:-0}
  info "$2: $n file(s)"
  if [ "$n" -gt 0 ]; then
    head -n 60 "$1" | sed 's|^|    |'
    [ "$n" -le 60 ] || printf '    … and %s more\n' "$(( n - 60 ))"
  fi
}

# --- main ------------------------------------------------------------------------

plan
guard_initial

if [ "$REMOTE_OK" -eq 1 ] && [ -z "$ENV_MODE" ] && [ "$REMOTE_ENV_STATE" = missing ]; then
  warn "the server has no /_arche/.env yet — first deploy? use --initial"
fi

if [ "$DRY_RUN" -eq 1 ]; then
  info "Dry run — target $SFTP_USER@$SFTP_HOST:$SFTP_PORT, remote root '${SFTP_REMOTE_PREFIX:-/}'"
  info "1. directories, /_arche/.htaccess, deny markers, maintenance flag, then an HTTP 403 check"
  if [ -n "$ENV_MODE" ]; then
    make_server_env "$WORK/server.env"
    info "2. server .env ($ENV_MODE): $(env_keys "$WORK/server.env" | tr '\n' ' ')"
    rm -f "$WORK/server.env"
  fi
  print_list "$WORK/p.private" "3. private (/_arche)"
  print_list "$WORK/p.bulk" "4. PWA and public files"
  print_list "$WORK/p.php" "5. PHP entry points"
  print_list "$WORK/p.control" "6. nested .htaccess / .user.ini (always)"
  info "7. root .htaccess (always)"
  info "8. entry points (always): ${ENTRY_FILES[*]} — then $MANIFEST_NAME"
  if [ "$DO_PRUNE" -eq 1 ]; then
    if [ "$REMOTE_OK" -eq 1 ]; then prune; else warn "prune: skipped (no remote inventory)"; fi
  fi
  info "Dry run complete; nothing was uploaded"
  exit 0
fi

step_prepare_and_deny
step_check_deny
step_server_env
upload_list "$WORK/p.private" private
upload_list "$WORK/p.bulk" public
# shellcheck disable=SC2046
serial_put "PHP entry points" $(cat "$WORK/p.php")
# shellcheck disable=SC2046
serial_put "nested .htaccess / .user.ini" $(cat "$WORK/p.control")
serial_put "root .htaccess" .htaccess
step_tail
[ "$DO_PRUNE" -eq 1 ] && prune
maintenance_off
verify
