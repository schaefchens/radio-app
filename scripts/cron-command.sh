#!/usr/bin/env bash
#
# Print the konsoleH cron line that drives the station.
#
# Usage: scripts/cron-command.sh [--show] [--env-file PATH]
#
# The webhosting runs ONE cron job, once a minute, that POSTs to /cron.php
# with CRON_KEY as a Bearer token; the tick decides what is due. By default
# the key is masked on screen and the complete line is written to
# build/deploy-state/cron-command.txt (mode 600) to copy from — terminal
# scrollback is not a good place for it. --show prints it in full.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"
# shellcheck source=scripts/lib/env.sh
. "$REPO_ROOT/scripts/lib/env.sh"

ENV_FILE="$REPO_ROOT/.env" SHOW=0
while [ $# -gt 0 ]; do
  case "$1" in
    --show)     SHOW=1 ;;
    --env-file) shift; ENV_FILE="${1:-}"; [ -n "$ENV_FILE" ] || die "--env-file needs a path" ;;
    -h|--help)  sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)          die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

env_has "$ENV_FILE" SITE_BASE_URL || die "SITE_BASE_URL not set in $ENV_FILE"
env_has "$ENV_FILE" CRON_KEY || die "CRON_KEY not set in $ENV_FILE — run: npm run init-secrets"
SITE=$(env_value "$ENV_FILE" SITE_BASE_URL); SITE="${SITE%/}"
KEY=$(env_value "$ENV_FILE" CRON_KEY)

header_line() {
  printf "* * * * * /usr/bin/curl --silent --show-error --connect-timeout 2 --max-time 5 --request POST --header 'Authorization: Bearer %s' '%s/cron.php'" "$1" "$SITE"
}
query_line() {
  printf "* * * * * /usr/bin/curl --silent --show-error --connect-timeout 2 --max-time 5 --request POST '%s/cron.php?key=%s'" "$SITE" "$1"
}

masked="${KEY:0:4}…${KEY: -4}"
STATE_DIR="$REPO_ROOT/build/deploy-state"
mkdir -p "$STATE_DIR"
(
  umask 077
  {
    printf '# konsoleH cron (every minute). Preferred: key in the Authorization header.\n'
    header_line "$KEY"; printf '\n\n'
    printf '# Fallback ONLY if the panel cannot send headers — the key then lands in access logs.\n'
    query_line "$KEY"; printf '\n'
  } > "$STATE_DIR/cron-command.txt"
)

info "konsoleH → Cronjobs → new job, every minute, command:"
if [ "$SHOW" -eq 1 ]; then header_line "$KEY"; else header_line "$masked"; fi
printf '\n\n'
info "Fallback, only if the panel cannot send an Authorization header:"
if [ "$SHOW" -eq 1 ]; then query_line "$KEY"; else query_line "$masked"; fi
printf '\n'
warn "the query-string variant puts CRON_KEY in the server's access logs; /cron.php only"
warn "triggers a rate-limited tick, but prefer the header form whenever the panel allows it"
printf '\n'
if [ "$SHOW" -eq 1 ]; then
  ok "full lines also in build/deploy-state/cron-command.txt"
else
  ok "key masked above; the complete lines are in build/deploy-state/cron-command.txt (or rerun with --show)"
fi
