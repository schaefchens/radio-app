#!/usr/bin/env bash
# Replace the local station's setup with production's, to try the program at
# home with the real library and hosts:
#   1. /mod › Status › "Station setup" › Download (admins), on the live site
#   2. npm run setup:import -- ~/Downloads/arche-setup-20261008.json
# The file holds no listener's data and no keys (server/app/Plan/StationSetup.php).
# The local database is copied to /_arche/var/backups first; the local admin,
# workers and usage stay; the local program and test submissions go.
set -euo pipefail

file="${1:-}"
# npm runs this from the repository root; a relative path is the caller's.
case "$file" in
  ""|/*) ;;
  *) file="${INIT_CWD:-$PWD}/$file" ;;
esac
if [ -z "$file" ] || [ ! -f "$file" ]; then
  echo "usage: npm run setup:import -- <arche-setup-YYYYMMDD.json>" >&2
  exit 1
fi

cd "$(dirname "$0")/.."
# `run`, not `exec`: it works whether or not the stack is up, on the same
# `var` volume. As www-data, like PHP-FPM: a backup or flag made by root
# would be another user's file in the station's private folder. The two
# paths are where compose mounts the dev station.
docker compose --env-file docker/compose.env run --rm --no-deps -T --user www-data \
  -e ARCHE_PUBLIC_DIR=/var/www/site -e ARCHE_DATA_DIR=/var/www/site/_arche/var \
  php php /srv/server/bin/import-setup.php < "$file"
