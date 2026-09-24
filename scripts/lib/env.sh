#!/usr/bin/env bash
# Dotenv reading for the shell scripts.
#
# Values are PARSED, never sourced: the SFTP password legitimately contains
# shell metacharacters, and `. .env` would either break on it or silently
# mangle it. Same rules as the PHP and Node loaders:
#
#   KEY=value            plain
#   KEY="value"          one pair of matching quotes is stripped
#   export KEY=value     tolerated
#   # comment            ignored (only as the first non-blank character)
#
# There are no inline comments: a `#` inside a value is part of the value.
# When a key appears twice the LAST assignment wins, which is also how the PHP
# loader reads the file — so scripts/init-secrets.sh can fill a key that exists
# but is empty by appending, without ever rewriting an existing line.

# env_value FILE KEY — print the value (empty when unset).
env_value() {
  local file="$1" key="$2" line value
  [ -f "$file" ] || return 0
  line=$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=" "$file" | tail -n1) || true
  [ -n "$line" ] || return 0
  value="${line#*=}"
  # Trim surrounding whitespace, then one pair of matching quotes.
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  case "$value" in
    \"*\") [ "${#value}" -ge 2 ] && { value="${value#\"}"; value="${value%\"}"; } ;;
    \'*\') [ "${#value}" -ge 2 ] && { value="${value#\'}"; value="${value%\'}"; } ;;
  esac
  printf '%s' "$value"
}

# env_has FILE KEY — true when the key is assigned a non-empty value.
env_has() {
  [ -n "$(env_value "$1" "$2")" ]
}

# env_keys FILE — every key name assigned in the file, in order, unique.
# Used to report which names changed without ever printing a value.
env_keys() {
  local file="$1"
  [ -f "$file" ] || return 0
  sed -n -E 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=.*/\2/p' "$file" \
    | awk '!seen[$0]++'
}

# require_env FILE KEY... — die with every missing key listed at once, so a
# first-time setup does not become one error per run.
require_env() {
  local file="$1" key missing=()
  shift
  [ -f "$file" ] || die "missing $file (copy .env.example and fill it in)"
  for key in "$@"; do
    env_has "$file" "$key" || missing+=("$key")
  done
  if [ ${#missing[@]} -gt 0 ]; then
    die "not set in $file: ${missing[*]}"
  fi
}
