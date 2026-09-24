#!/usr/bin/env bash
#
# Generate the server secrets into .env — append-only and idempotent.
#
# Usage: scripts/init-secrets.sh [--env-file PATH]
#
# Adds only what is missing (absent or empty), never prints a value:
#
#   CRON_KEY          64 hex  Bearer key of the konsoleH cron → /cron.php
#   NODE_SECRET       64 hex  HMAC key of the realtime nodes' reports
#   IDENTITY_PEPPER   64 hex  server-side pepper for identity hashes
#   ADMIN_SETUP_KEY   48 hex  one-time key for claiming the first admin
#   TOKEN_SIGNING_KEY + TOKEN_PUBLIC_KEY
#                     an Ed25519 pair (libsodium, base64): the secret key stays
#                     on the webhosting and signs realtime join tokens; the
#                     realtime nodes get only the public key, so a node can
#                     check a token but never mint one
#
# Existing lines are left byte-for-byte as they are. A key that is present but
# empty is filled by appending a second assignment — the loaders read the LAST
# assignment of a key, so the new value wins without rewriting the old line.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"
# shellcheck source=scripts/lib/env.sh
. "$REPO_ROOT/scripts/lib/env.sh"

ENV_FILE="$REPO_ROOT/.env"

while [ $# -gt 0 ]; do
  case "$1" in
    --env-file) shift; ENV_FILE="${1:-}"; [ -n "$ENV_FILE" ] || die "--env-file needs a path" ;;
    -h|--help)  sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)          die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

if [ ! -f "$ENV_FILE" ]; then
  ( umask 077; : > "$ENV_FILE" )
  info "created $ENV_FILE (mode 600)"
fi

random_hex() { # BYTES
  local out
  if command -v openssl >/dev/null 2>&1; then
    out=$(openssl rand -hex "$1")
  else
    out=$(od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n')
  fi
  [ "${#out}" -eq $(( $1 * 2 )) ] || die "could not read $1 random bytes"
  printf '%s' "$out"
}

# One PHP snippet, run locally when this machine's PHP has sodium, otherwise
# in the Docker php image (which always does). Single-quoted on purpose: the
# $ belong to PHP, not to the shell.
# shellcheck disable=SC2016
SODIUM_PAIR='$kp = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($kp)), "\n", base64_encode(sodium_crypto_sign_publickey($kp)), "\n";'
# shellcheck disable=SC2016
SODIUM_CHECK='$sk = base64_decode(getenv("SK"), true); $pk = base64_decode(getenv("PK"), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { echo "bad-secret"; exit; }
if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { echo "bad-public"; exit; }
echo hash_equals(sodium_crypto_sign_publickey_from_secretkey($sk), $pk) ? "match" : "mismatch";'

run_php() { # SNIPPET [ENV=VALUE...]
  local snippet="$1"; shift
  if command -v php >/dev/null 2>&1 && php -r 'exit(function_exists("sodium_crypto_sign_keypair") ? 0 : 1);'; then
    env "$@" php -r "$snippet"
  elif command -v docker >/dev/null 2>&1; then
    local e args=()
    for e in "$@"; do args+=(-e "$e"); done
    docker compose --env-file docker/compose.env run --rm --no-deps -T ${args[@]+"${args[@]}"} php php -r "$snippet"
  else
    die "need PHP with sodium, or Docker, to create the Ed25519 pair"
  fi
}

added=() refilled=() lines=()

add_key() { # NAME VALUE
  local name="$1" value="$2"
  [ -n "$value" ] || die "generated an empty $name"
  # Present-but-empty keys are filled by a later assignment (last one wins).
  if grep -q -E "^[[:space:]]*(export[[:space:]]+)?${name}[[:space:]]*=" "$ENV_FILE"; then refilled+=("$name"); fi
  lines+=("$name=$value")
  added+=("$name")
}

for spec in CRON_KEY:32 NODE_SECRET:32 IDENTITY_PEPPER:32 ADMIN_SETUP_KEY:24; do
  key="${spec%%:*}" bytes="${spec##*:}"
  env_has "$ENV_FILE" "$key" || add_key "$key" "$(random_hex "$bytes")"
done

has_sk=0 has_pk=0
env_has "$ENV_FILE" TOKEN_SIGNING_KEY && has_sk=1
env_has "$ENV_FILE" TOKEN_PUBLIC_KEY && has_pk=1
if [ "$has_sk" -eq 1 ] && [ "$has_pk" -eq 1 ]; then
  verdict=$(run_php "$SODIUM_CHECK" "SK=$(env_value "$ENV_FILE" TOKEN_SIGNING_KEY)" "PK=$(env_value "$ENV_FILE" TOKEN_PUBLIC_KEY)" 2>/dev/null || echo unknown)
  case "$verdict" in
    match)   ok "TOKEN_SIGNING_KEY and TOKEN_PUBLIC_KEY form a valid pair" ;;
    unknown) warn "could not check the Ed25519 pair (no PHP/Docker)" ;;
    *)       die "TOKEN_SIGNING_KEY / TOKEN_PUBLIC_KEY do not form a valid Ed25519 pair ($verdict). Remove BOTH lines from $ENV_FILE and rerun — tokens signed with the old key will stop verifying." ;;
  esac
elif [ "$has_sk" -ne "$has_pk" ]; then
  die "only one of TOKEN_SIGNING_KEY / TOKEN_PUBLIC_KEY is set. They are one Ed25519 pair and cannot be regenerated separately: remove the remaining one from $ENV_FILE and rerun (realtime nodes then need the new public key — rebuild the snapshot or update their env)."
else
  pair=$(run_php "$SODIUM_PAIR")
  sk=$(printf '%s\n' "$pair" | sed -n 1p)
  pk=$(printf '%s\n' "$pair" | sed -n 2p)
  [ "${#sk}" -eq 88 ] && [ "${#pk}" -eq 44 ] || die "unexpected Ed25519 key sizes from PHP"
  add_key TOKEN_SIGNING_KEY "$sk"
  add_key TOKEN_PUBLIC_KEY "$pk"
fi

if [ ${#added[@]} -eq 0 ]; then
  ok "nothing to do — every generated key is already set in $ENV_FILE"
  exit 0
fi

# Terminate a last line that has no newline, so our block starts on its own
# line (the existing line's bytes stay the same).
if [ -s "$ENV_FILE" ] && [ "$(tail -c1 "$ENV_FILE" | wc -l | tr -d ' ')" -eq 0 ]; then
  printf '\n' >> "$ENV_FILE"
fi
{
  printf '\n# --- generated by scripts/init-secrets.sh %s (append-only; never edit by hand) ---\n' \
    "$(date -u +%Y-%m-%d)"
  printf '%s\n' "${lines[@]}"
} >> "$ENV_FILE"

ok "added to $ENV_FILE: ${added[*]}"
if [ ${#refilled[@]} -gt 0 ]; then
  warn "these were present but empty and are now set by a later line: ${refilled[*]}"
fi
