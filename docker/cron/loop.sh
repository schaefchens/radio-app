#!/bin/sh
# Stands in for the konsoleH cron: one POST a minute with the key in the
# Authorization header, and nothing else — the tick decides what is due.
KEY=$(sed -n 's/^[[:space:]]*CRON_KEY[[:space:]]*=[[:space:]]*//p' /run/arche.env | head -n1 | tr -d "\"'")
[ -n "$KEY" ] || echo "cron: CRON_KEY missing from .env — run npm run init-secrets"
while true; do
  curl -fsS -m 5 -X POST -H "Authorization: Bearer $KEY" http://web/cron.php >/dev/null \
    || echo "cron: tick request failed"
  sleep 60
done
