#!/usr/bin/env bash
# Output helpers shared by the deploy scripts. Colour only on a terminal, so a
# log captured from CI or a pipe stays readable.

if [ -t 1 ]; then
  _c_red=$'\033[31m' _c_green=$'\033[32m' _c_yellow=$'\033[33m' _c_blue=$'\033[34m' _c_off=$'\033[0m'
else
  _c_red='' _c_green='' _c_yellow='' _c_blue='' _c_off=''
fi

die()  { printf '%serror:%s %s\n' "$_c_red" "$_c_off" "$*" >&2; exit 1; }
info() { printf '%s==>%s %s\n' "$_c_blue" "$_c_off" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$_c_green" "$_c_off" "$*"; }
bad()  { printf '  %s✗%s %s\n' "$_c_red" "$_c_off" "$*"; }
warn() { printf '  %s!%s %s\n' "$_c_yellow" "$_c_off" "$*"; }

require_tools() {
  local t missing=()
  for t in "$@"; do command -v "$t" >/dev/null 2>&1 || missing+=("$t"); done
  if [ ${#missing[@]} -gt 0 ]; then
    die "missing required tool(s): ${missing[*]}
  install with: brew install ${missing[*]}"
  fi
}
