#!/usr/bin/env bash
# Look the local station's library up for real (Library\Knowledge): OpenAI's
# web search and Gemini listening to each video, about 10 cents a song. The
# local stack keeps running with stub AI; this one run is live. Then read the
# results in /mod › Library.
#   npm run library:research -- [--limit=N] [--again] [--yt=ID,ID] [--kinds=song,preaching]
set -euo pipefail
cd "$(dirname "$0")/.."
# As www-data, like PHP-FPM: the station's private folder stays one user's.
docker compose --env-file docker/compose.env run --rm --no-deps -T --user www-data \
  -e ARCHE_PUBLIC_DIR=/var/www/site -e ARCHE_DATA_DIR=/var/www/site/_arche/var \
  php php /srv/server/bin/research-library.php "$@"
