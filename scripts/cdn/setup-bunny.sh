#!/usr/bin/env bash
#
# One-time (and repeatable) BunnyCDN setup: a pull zone in front of the
# webhosting that serves /program (the minute files) and /media (host audio,
# recordings, images) from Bunny's edge.
#
# Pull, not push: Bunny fetches each file once from the webhosting and keeps
# it as long as the file's own Cache-Control says (minute files immutable,
# live.json 15 s, a 404 never). The tick never uploads anything, so the radio
# never waits for the CDN — and without it the app falls back to the origin.
#
# Settings that matter:
#   Cache-Control from the origin respected; error responses not cached
#   request coalescing (a new minute file: one origin fetch, not thousands)
#   stale copies served while the origin is offline
#   CORS headers on json/mp3/images; POST requests blocked; cookies stripped
#   logging on, IP addresses dropped: the listener count reads the requests
#   for each minute file, and no address is ever kept
#
# Credentials: BUNNY_API_KEY from .env (never printed). Idempotent: an existing
# zone with the name is updated to these settings.
#
# Usage: scripts/cdn/setup-bunny.sh [--name NAME] [--origin URL] [--env FILE] [--dry-run]
#
# Prints the .env lines CDN_BASE_URL and BUNNY_PULL_ZONE_ID.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"
# shellcheck source=scripts/lib/env.sh
. "$REPO_ROOT/scripts/lib/env.sh"

NAME=arche-radio ORIGIN="" ENV_FILE="$REPO_ROOT/.env" DRY_RUN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --name)    shift; NAME="${1:-}" ;;
    --origin)  shift; ORIGIN="${1:-}" ;;
    --env)     shift; ENV_FILE="${1:-}" ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

require_tools curl jq
[[ "$NAME" =~ ^[a-z0-9-]+$ ]] || die "--name may hold only a-z, 0-9 and -"
KEY=$(env_value "$ENV_FILE" BUNNY_API_KEY)
[ -n "$KEY" ] || die "BUNNY_API_KEY is not set in $ENV_FILE"
[ -n "$ORIGIN" ] || ORIGIN=$(env_value "$ENV_FILE" SITE_BASE_URL)
ORIGIN="${ORIGIN%/}"
[[ "$ORIGIN" =~ ^https:// ]] || die "the origin must be an https URL (got '$ORIGIN')"

api() { # METHOD PATH [JSON]
  local method="$1" path="$2" body="${3:-}" out code
  out=$(mktemp)
  if [ -n "$body" ]; then
    code=$(curl -sS -o "$out" -w '%{http_code}' -X "$method" "https://api.bunny.net$path" \
      -H "AccessKey: $KEY" -H 'Content-Type: application/json' -H 'Accept: application/json' --data "$body")
  else
    code=$(curl -sS -o "$out" -w '%{http_code}' -X "$method" "https://api.bunny.net$path" \
      -H "AccessKey: $KEY" -H 'Accept: application/json')
  fi
  if [ "${code:0:1}" != 2 ]; then
    printf 'Bunny API %s %s → HTTP %s: %s\n' "$method" "$path" "$code" "$(jq -r '.Message // .message // .' "$out" 2>/dev/null | head -c 300)" >&2
    rm -f "$out"
    return 1
  fi
  cat "$out"
  rm -f "$out"
}

SETTINGS=$(jq -n --arg origin "$ORIGIN" '{
  OriginUrl: $origin,
  Type: 0,
  CacheControlMaxAgeOverride: -1,
  CacheControlPublicMaxAgeOverride: -1,
  CacheErrorResponses: false,
  IgnoreQueryStrings: true,
  EnableRequestCoalescing: true,
  RequestCoalescingTimeout: 30,
  UseStaleWhileOffline: true,
  VerifyOriginSSL: true,
  DisableCookies: true,
  BlockPostRequests: true,
  EnableAccessControlOriginHeader: true,
  AccessControlOriginHeaderExtensions: ["json", "mp3", "webp", "jpg", "png"],
  EnableLogging: true,
  LoggingIPAnonymizationEnabled: true,
  LogAnonymizationType: 1,
  EnableGeoZoneUS: true, EnableGeoZoneEU: true, EnableGeoZoneASIA: true, EnableGeoZoneSA: true, EnableGeoZoneAF: true
}')

info "Pull zone '$NAME' → origin $ORIGIN"
ZONES=$(api GET "/pullzone?page=0&perPage=1000&search=$NAME") || die "could not list pull zones"
ID=$(printf '%s' "$ZONES" | jq -r --arg n "$NAME" '(if type == "array" then . else (.Items // []) end) | map(select(.Name == $n)) | first | .Id // empty')

if [ "$DRY_RUN" -eq 1 ]; then
  if [ -n "$ID" ]; then info "would update pull zone $ID"; else info "would create pull zone $NAME"; fi
  printf '%s\n' "$SETTINGS" | jq -c .
  exit 0
fi

if [ -n "$ID" ]; then
  api POST "/pullzone/$ID" "$SETTINGS" >/dev/null || die "could not update pull zone $ID"
  ok "pull zone $ID updated"
else
  CREATED=$(api POST /pullzone "$(printf '%s' "$SETTINGS" | jq --arg n "$NAME" '. + {Name: $n}')") || die "could not create pull zone $NAME"
  ID=$(printf '%s' "$CREATED" | jq -r '.Id')
  ok "pull zone $ID created"
fi

ZONE=$(api GET "/pullzone/$ID") || die "could not read pull zone $ID"
HOST=$(printf '%s' "$ZONE" | jq -r '[.Hostnames[]? | select(.IsSystemHostname == true) | .Value] | first // ([.Hostnames[]?.Value] | first) // empty')
[ -n "$HOST" ] || die "pull zone $ID has no hostname yet"
ok "serving at https://$HOST"

echo
echo "Add to .env (the server and the app read these):"
echo "CDN_BASE_URL=https://$HOST"
echo "BUNNY_PULL_ZONE_ID=$ID"
