#!/usr/bin/env bash
# SFTP plumbing for the deploy and probe scripts. Needs lib/log.sh and
# lib/env.sh sourced first.
#
# The target (Hetzner Webhosting, wwwNN.your-server.de) runs ProFTPD mod_sftp
# with password auth and NO shell: every remote operation has to be one sftp
# batch command. There is no tar, no unzip, no atomic `mv` of a staging tree,
# no remote hashing, and `ls -l` is the only inventory there is.
#
# Credentials come from the repo .env (never sourced — see lib/env.sh):
#   SFTP_HOST, SFTP_PORT (default 22), SFTP_USER, SFTP_PASSWORD,
#   SFTP_REMOTE_ROOT (default "/"; the account is jailed to the web root, so
#   "/" normally IS the web root).
#
# Every remote path in the scripts is written relative to the web root
# ("/_arche/.env") and goes through rpath, which puts SFTP_REMOTE_ROOT in front.

load_sftp_credentials() {
  local env_file="$1" root
  require_env "$env_file" SFTP_HOST SFTP_USER SFTP_PASSWORD
  SFTP_HOST=$(env_value "$env_file" SFTP_HOST)
  SFTP_PORT=$(env_value "$env_file" SFTP_PORT)
  SFTP_USER=$(env_value "$env_file" SFTP_USER)
  SFTP_PASSWORD=$(env_value "$env_file" SFTP_PASSWORD)
  root=$(env_value "$env_file" SFTP_REMOTE_ROOT)
  [ -n "$SFTP_PORT" ] || SFTP_PORT=22
  case "$SFTP_PORT" in ''|*[!0-9]*) die "SFTP_PORT must be a number" ;; esac

  # Normalise to "" (web root is the jail root) or "/some/dir" (no trailing
  # slash), so rpath can simply concatenate.
  [ -n "$root" ] || root=/
  root="/${root#/}"
  while [ "${root%/}" != "$root" ]; do root="${root%/}"; done
  SFTP_REMOTE_PREFIX="$root"
  export SFTP_HOST SFTP_PORT SFTP_USER SFTP_REMOTE_PREFIX
}

# rpath PATH — remote path for a web-root-relative PATH.
rpath() {
  local p="$1"
  p="/${p#/}"
  printf '%s%s' "$SFTP_REMOTE_PREFIX" "$p"
}

# Quote one argument for an sftp batch line. sftp's own tokenizer understands
# double quotes and backslash escapes; paths here never contain newlines.
sq() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  printf '"%s"' "$s"
}

# Two non-obvious requirements, both learned the hard way on the sibling
# projects:
#
#   * The batch must be a FILE, not `-b -`. sftp reading commands from stdin
#     competes with sshpass for the same stream and auth silently fails.
#   * `-o BatchMode=no` is mandatory. `sftp -b` implies BatchMode=yes, which
#     disables password prompts outright, and ssh then reports
#     "Permission denied" without ever having tried the password.
#
# The password goes through the environment (sshpass -e), so it never appears
# in the process list. SFTP_KNOWN_HOSTS_FILE (optional) points ssh at a
# different known_hosts file; the tests use it to keep a throwaway local SFTP
# container out of ~/.ssh/known_hosts.
run_sftp() {
  local batch="$1"
  local kh=()
  [ -n "${SFTP_KNOWN_HOSTS_FILE:-}" ] && kh=(-o "UserKnownHostsFile=$SFTP_KNOWN_HOSTS_FILE")
  SSHPASS="$SFTP_PASSWORD" sshpass -e sftp \
      -P "$SFTP_PORT" \
      -o StrictHostKeyChecking=accept-new \
      ${kh[@]+"${kh[@]}"} \
      -o PubkeyAuthentication=no \
      -o PreferredAuthentications=password \
      -o BatchMode=no \
      -o ConnectTimeout=20 \
      -o ServerAliveInterval=30 \
      -b "$batch" "$SFTP_USER@$SFTP_HOST"
}

# sftp_batch BATCH [STDOUT_LOG] — run a batch, keep stderr honest.
#
# `-mkdir` on a directory that already exists reports "Failure" and is ignored
# by sftp; `-rm` of something already gone reports "not found". Both are the
# idempotent path, not a problem, so they are filtered. Anything else on
# stderr is worth seeing.
sftp_batch() {
  local batch="$1" out="${2:-/dev/null}" err rc=0
  err=$(mktemp)
  run_sftp "$batch" > "$out" 2>"$err" || rc=$?
  # Deliberately narrow: a missing LOCAL file on `put` also says "No such file
  # or directory", and that one must stay visible.
  grep -v -E 'remote mkdir .*: Failure|remote delete .*: No such file|Couldn.t delete file: (No such file|.*not found)' "$err" >&2 || true
  rm -f "$err"
  return "$rc"
}

# remote_sizes DIR... — "<path>\t<size>" for every regular file directly in
# each DIR (web-root-relative, e.g. "/", "/assets"), in ONE session.
#
# One `ls -l` per directory over a single connection: a connection per
# directory would mean hundreds of logins for vendor/. The batch echoes each
# command ("sftp> -ls -l ...") ahead of its output, which is how the listing
# lines are attributed to their directory. `ls -l` omits dotfiles — which is
# what we want: .htaccess and friends are always re-uploaded, never pruned.
# A directory that does not exist yet just lists nothing (`-` prefix).
remote_sizes() {
  local batch out d
  batch=$(mktemp)
  out=$(mktemp)
  for d in "$@"; do
    printf -- '-ls -l %s\n' "$(sq "$(rpath "$d")")" >> "$batch"
  done
  run_sftp "$batch" > "$out" 2>/dev/null || true
  rm -f "$batch"
  # ProFTPD / OpenSSH long format: perms links owner group size mon day time name
  awk -v prefix="$SFTP_REMOTE_PREFIX" '
    /^sftp> / {
      dir = $0
      sub(/^sftp> -?ls -l /, "", dir)
      gsub(/^"|"$/, "", dir)
      if (prefix != "" && index(dir, prefix) == 1) dir = substr(dir, length(prefix) + 1)
      sub(/^\/+/, "", dir); sub(/\/+$/, "", dir)
      next
    }
    /^-/ && NF >= 9 {
      name = $0
      # Drop the first eight fields; what is left is the name (may hold spaces).
      for (i = 1; i <= 8; i++) sub(/^[^ ]+ +/, "", name)
      sub(/.*\//, "", name)
      if (name ~ /^\./) next
      path = (dir == "") ? name : dir "/" name
      printf "%s\t%s\n", path, $5
    }
  ' "$out"
  rm -f "$out"
}

# remote_get REMOTE LOCAL — download; quietly nonzero when REMOTE is missing.
remote_get() {
  local remote="$1" local_path="$2" batch rc=0
  batch=$(mktemp)
  printf 'get %s %s\n' "$(sq "$(rpath "$remote")")" "$(sq "$local_path")" > "$batch"
  run_sftp "$batch" >/dev/null 2>&1 || rc=$?
  rm -f "$batch"
  [ "$rc" -eq 0 ] && [ -f "$local_path" ]
}

# remote_exists REMOTE — 0 exists, 1 does not exist, 2 could not tell
# (connection or permission trouble). Callers that guard against overwriting
# something must treat 2 as "assume it exists".
remote_exists() {
  local remote="$1" batch err rc=0
  batch=$(mktemp)
  err=$(mktemp)
  printf 'ls -l %s\n' "$(sq "$(rpath "$remote")")" > "$batch"
  run_sftp "$batch" >/dev/null 2>"$err" || rc=$?
  rm -f "$batch"
  if [ "$rc" -eq 0 ]; then rm -f "$err"; return 0; fi
  if grep -qi -E 'not found|no such file|does not exist' "$err"; then rm -f "$err"; return 1; fi
  rm -f "$err"
  return 2
}

# sftp_reachable — can we log in at all? Used to degrade a --dry-run
# gracefully when the server (or the network) is not there.
sftp_reachable() {
  local batch rc=0
  batch=$(mktemp)
  printf 'pwd\n' > "$batch"
  run_sftp "$batch" >/dev/null 2>&1 || rc=$?
  rm -f "$batch"
  [ "$rc" -eq 0 ]
}
