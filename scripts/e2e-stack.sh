#!/usr/bin/env bash
#
# The end-to-end test stack: the production build of the PWA and the PHP core
# on Apache + PHP-FPM (the deployed tree, every .htaccess and its headers), the
# realtime server, and a fake YouTube Data API — on its own data in .data/e2e,
# so the dev station is never touched and no real key is ever used.
#
# Usage: scripts/e2e-stack.sh up|down|reset|status [--skip-build]
#
#   up            build the PWA, assemble .data/e2e/site, write
#                 .data/e2e/arche.env on the first run, start everything
#                 (web http://localhost:8090, realtime ws://localhost:8797)
#   down          stop it; the data stays, the next run continues the station
#   reset         stop it and delete .data/e2e: the next run starts from nothing
#   status        whether it answers
#   --skip-build  with up: use the existing app/dist
#   -h, --help    this text

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"

DIR="$REPO_ROOT/.data/e2e"
ENV_FILE="$DIR/arche.env"
BASE_URL="http://localhost:8090"
COMPOSE=(docker compose -p arche-e2e --env-file docker/e2e/compose.env -f compose.yaml -f docker/e2e/compose.e2e.yaml)

CMD="${1:-}"
[ $# -gt 0 ] && shift
BUILD=1
while [ $# -gt 0 ]; do
  case "$1" in
    --skip-build) BUILD=0 ;;
    *) die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

write_env() {
  [ -f "$ENV_FILE" ] && return 0
  mkdir -p "$DIR"
  ( umask 077; cat > "$ENV_FILE" ) <<'EOF'
# Written by scripts/e2e-stack.sh for the e2e stack only. Never a real key:
# AI is stubbed, YouTube is the fake in app/tests/e2e/fake-youtube.mjs.
ARCHE_ENV=local
AI_MODE=stub
THUMBS=0
HOST_MIN_LISTENERS=0
YOUTUBE_API_KEY=e2e-fake-key
YOUTUBE_API_BASE=http://fakeyt:8080/youtube/v3
REALTIME_DRIVER=static
REALTIME_STATIC_URL=ws://localhost:8797/ws
TTS_PROVIDER=openai
ELEVENLABS_MAX_CHARS_PER_DAY=0
# Every test browser comes from the same Docker address.
SUBMISSIONS_PER_IP_HOUR=100000
IDENTITIES_PER_IP_DAY=100000
EOF
  bash scripts/init-secrets.sh --env-file "$ENV_FILE" >/dev/null
  ok "wrote $ENV_FILE"
}

wait_up() {
  local i
  for i in $(seq 1 60); do
    if curl -fsS -m 2 "$BASE_URL/api/time" >/dev/null 2>&1; then return 0; fi
    sleep 1
  done
  return 1
}

case "$CMD" in
  up)
    require_tools docker curl rsync
    if [ "$BUILD" -eq 1 ]; then
      info "Building the PWA"
      npm run build >/dev/null
    fi
    bash scripts/assemble-site.sh --dev --with-app --skip-build --out "$DIR/site" >/dev/null
    write_env
    info "Starting the e2e stack (project arche-e2e)"
    "${COMPOSE[@]}" up -d --build --quiet-pull
    wait_up || die "the e2e stack did not answer on $BASE_URL"
    ok "e2e stack up: $BASE_URL"
    ;;
  down)
    "${COMPOSE[@]}" down
    ;;
  reset)
    "${COMPOSE[@]}" down --volumes
    rm -rf "$DIR"
    ok "removed .data/e2e"
    ;;
  status)
    if curl -fsS -m 2 "$BASE_URL/api/time" >/dev/null 2>&1; then ok "up: $BASE_URL"; else warn "down"; exit 1; fi
    ;;
  -h|--help|help|'')
    sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'
    ;;
  *)
    die "unknown command: $CMD (try --help)"
    ;;
esac
