# shellcheck shell=bash
# Shared helpers for scripts/realtime/*.sh — sourced, never executed.
#
# All progress output goes to stderr, so functions can hand values back on
# stdout ($(ensure_…)) and the final summary is the only thing on stdout.

die()  { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[34m==>\033[0m %s\n' "$*" >&2; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*" >&2; }
note() { printf '  \033[33m•\033[0m %s\n' "$*" >&2; }

require_tools() {
  local t missing=()
  for t in "$@"; do command -v "$t" >/dev/null 2>&1 || missing+=("$t"); done
  [ ${#missing[@]} -eq 0 ] || die "missing required tool(s): ${missing[*]}"
}

# Parsed, never sourced: the repo .env also holds the SFTP password, whose
# shell metacharacters would break (or execute) under `. .env`.
read_env_value() {
  local file="$1" key="$2" value
  value=$(sed -n "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*//p" "$file" | head -n1)
  value="${value%"${value##*[![:space:]]}"}"
  value="${value#\"}"; value="${value%\"}"
  value="${value#\'}"; value="${value%\'}"
  printf '%s' "$value"
}

# Hetzner credentials, in this order:
#
#   1. HETZNER_CLOUD_TOKEN from the env file, exported as HCLOUD_TOKEN — the
#      variable the hcloud CLI prefers over any context. Never printed.
#   2. Otherwise the named hcloud context, passed explicitly with --context on
#      every call, so whichever context happens to be *active* (another
#      project's) can never be the one that gets modified.
#
# Sets HC_MODE (token|context|none), HC_CONTEXT and HC_SOURCE (for a log
# line) — globals read by the scripts that source this file. With `optional`,
# missing credentials are not fatal (dry runs).
# shellcheck disable=SC2034
use_hcloud_credentials() {
  local env_file="$1" context="$2" optional="${3:-}" token=""
  [ -f "$env_file" ] && token=$(read_env_value "$env_file" HETZNER_CLOUD_TOKEN)
  if [ -n "$token" ]; then
    export HCLOUD_TOKEN="$token"
    HC_MODE=token
    HC_SOURCE="HETZNER_CLOUD_TOKEN from ${env_file##*/}"
    return 0
  fi
  # A token inherited from the shell would silently override the context.
  unset HCLOUD_TOKEN
  if hcloud context list -o noheader -o columns=name 2>/dev/null | grep -qx -- "$context"; then
    HC_MODE=context
    HC_CONTEXT="$context"
    HC_SOURCE="hcloud context '$context'"
    return 0
  fi
  if [ "$optional" = optional ]; then
    HC_MODE=none
    HC_SOURCE="none"
    return 0
  fi
  die "no HETZNER_CLOUD_TOKEN in $env_file and no hcloud context named '$context' (hcloud context create $context, or pass --context NAME)"
}

# hcloud with the credentials chosen above.
hc() {
  case "${HC_MODE:-none}" in
    token)   command hcloud "$@" ;;
    context) command hcloud --context "$HC_CONTEXT" "$@" ;;
    *)       die "internal: hcloud called without credentials" ;;
  esac
}
