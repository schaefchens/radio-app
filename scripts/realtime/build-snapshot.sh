#!/usr/bin/env bash
#
# Build the Hetzner snapshot every realtime node is created from.
#
#   1. docker buildx: arche-realtime:<git sha> for the node architecture
#      (cax* = arm64, what the Mac builds natively; cx*/cpx*/ccx* = amd64) —
#      the same Dockerfile the local compose stack runs
#   2. a temporary ubuntu-24.04 server with a throwaway SSH key
#   3. Docker from docker.com's apt repository; the image loaded over scp
#      (nodes have no registry), caddy:2 pulled, /opt/arche/compose.node.yaml
#      + Caddyfile and arche-node.service installed and enabled — not started:
#      a fresh node starts it from cloud-init, once /etc/arche/node.env and the
#      certificate Volume are in place
#   4. a /health smoke test of the image on the server itself
#   5. cloud-init clean, power off, snapshot labelled
#        app=arche role=realtime-node version=<sha> arch=<arm|x86>
#   6. temporary server and key deleted — also when anything above fails
#   7. older snapshots pruned: the newest --keep stay, plus every snapshot
#      labelled pinned=true (hcloud image add-label <id> pinned=true)
#
# The PHP wake controller uses the newest snapshot with those labels and
# renders IMAGE=arche-realtime:<its version label> into the node's user_data.
#
# Credentials: HETZNER_CLOUD_TOKEN from .env (never printed). Without it the
# hcloud context named by --context (default `arche`) is used, explicitly.
#
# Usage: scripts/realtime/build-snapshot.sh [options]
#
#   --type TYPE      server type to build on (default cax11; nodes must use
#                    the same architecture and at least the same disk)
#   --location LOC   Hetzner location (default fsn1)
#   --keep N         snapshots to keep besides pinned ones (default 2)
#   --context NAME   hcloud context if .env has no token (default arche)
#   --env FILE       env file to read (default: .env in the repo root)
#   --allow-dirty    build even with uncommitted changes in realtime/,
#                    shared/ or infra/realtime/
#   --dry-run        print the plan and the existing snapshots; build and
#                    create nothing
#   -h, --help       this text

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/realtime/lib.sh
. "$REPO_ROOT/scripts/realtime/lib.sh"

TYPE=cax11 LOCATION=fsn1 KEEP=2 CONTEXT=arche ENV_FILE="$REPO_ROOT/.env" ALLOW_DIRTY=0 DRY_RUN=0

while [ $# -gt 0 ]; do
  case "$1" in
    --type)        shift; TYPE="${1:-}" ;;
    --location)    shift; LOCATION="${1:-}" ;;
    --keep)        shift; KEEP="${1:-}" ;;
    --context)     shift; CONTEXT="${1:-}" ;;
    --env)         shift; ENV_FILE="${1:-}" ;;
    --allow-dirty) ALLOW_DIRTY=1 ;;
    --dry-run)     DRY_RUN=1 ;;
    -h|--help)     awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; exit 0 ;;
    *)             die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

case "$KEEP" in ''|*[!0-9]*) die "--keep needs a number" ;; esac
[ "$KEEP" -ge 1 ] || die "--keep must be at least 1 (the snapshot just built)"
[[ "$TYPE" =~ ^[a-z0-9-]+$ ]] || die "--type looks wrong: $TYPE"
[[ "$LOCATION" =~ ^[a-z0-9-]+$ ]] || die "--location looks wrong: $LOCATION"

case "$TYPE" in
  cax*) PLATFORM=linux/arm64 ARCH=arm ;;
  *)    PLATFORM=linux/amd64 ARCH=x86 ;;
esac

require_tools docker hcloud jq git ssh scp ssh-keygen gzip
cd "$REPO_ROOT"

VERSION=$(git rev-parse --short=12 HEAD 2>/dev/null) || die "not a git checkout"
if [ -n "$(git status --porcelain -- realtime shared infra/realtime 2>/dev/null)" ]; then
  if [ "$ALLOW_DIRTY" -eq 0 ]; then
    [ "$DRY_RUN" -eq 1 ] || die "uncommitted changes in realtime/, shared/ or infra/realtime/ — commit first, or pass --allow-dirty"
    note "uncommitted changes in realtime/, shared/ or infra/realtime/ — a real run needs a commit or --allow-dirty"
  fi
  VERSION="$VERSION-dirty$(date -u +%Y%m%d%H%M)"
fi
IMAGE="arche-realtime:$VERSION"
SELECTOR="app=arche,role=realtime-node"
SERVER_NAME="arche-snapshot-$VERSION"
KEY_NAME="arche-snapshot-$VERSION-$$"

if [ "$DRY_RUN" -eq 1 ]; then
  use_hcloud_credentials "$ENV_FILE" "$CONTEXT" optional
else
  use_hcloud_credentials "$ENV_FILE" "$CONTEXT"
fi
info "Hetzner credentials: $HC_SOURCE"

# --- prune -------------------------------------------------------------------------

# Snapshots to delete: all with our labels except the newest $KEEP and pinned.
prune_candidates() {
  hc image list --type snapshot --selector "$SELECTOR" -o json \
    | jq -r --argjson keep "$KEEP" '
        sort_by(.created) | reverse
        | [ .[] | select((.labels.pinned // "") != "true") ]
        | .[$keep:] | .[] | "\(.id)\t\(.labels.version // "?")\t\(.created)"'
}

list_snapshots() {
  hc image list --type snapshot --selector "$SELECTOR" -o json \
    | jq -r 'sort_by(.created) | reverse | .[]
             | "  \(.id)  version=\(.labels.version // "?")  arch=\(.architecture)  \(.created)\(if (.labels.pinned // "") == "true" then "  pinned" else "" end)"'
}

# --- dry run ---------------------------------------------------------------------

if [ "$DRY_RUN" -eq 1 ]; then
  info "Dry run — nothing is built or created"
  cat >&2 <<EOF
  version      $VERSION
  image        $IMAGE ($PLATFORM)
  build server $SERVER_NAME ($TYPE, ubuntu-24.04, $LOCATION)
  snapshot     labels app=arche role=realtime-node version=$VERSION arch=$ARCH
  keep         newest $KEEP + pinned
EOF
  for f in realtime/Dockerfile infra/realtime/compose.node.yaml infra/realtime/Caddyfile infra/realtime/arche-node.service; do
    [ -f "$f" ] || die "missing $f"
  done
  ok "input files present"
  if [ "$HC_MODE" != none ]; then
    info "Existing snapshots ($SELECTOR)"
    list_snapshots >&2 || true
    pruned=$(prune_candidates || true)
    if [ -n "$pruned" ]; then
      info "A real run would prune (after adding the new one, one more may go):"
      printf '%s\n' "$pruned" | sed 's/^/  /' >&2
    fi
  else
    note "no credentials: skipped the snapshot listing"
  fi
  exit 0
fi

# --- real run ------------------------------------------------------------------------

WORK=$(mktemp -d)
SERVER_CREATED=0
KEY_CREATED=0

cleanup() {
  local status=$?
  set +e
  if [ "$SERVER_CREATED" -eq 1 ] && hc server describe "$SERVER_NAME" >/dev/null 2>&1; then
    info "Deleting build server $SERVER_NAME"
    hc server delete "$SERVER_NAME" >/dev/null 2>&1 || note "could not delete $SERVER_NAME — delete it by hand (it bills per hour)"
  fi
  if [ "$KEY_CREATED" -eq 1 ]; then
    hc ssh-key delete "$KEY_NAME" >/dev/null 2>&1 || note "could not delete ssh-key $KEY_NAME"
  fi
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

info "Building $IMAGE for $PLATFORM"
docker buildx build --platform "$PLATFORM" -f realtime/Dockerfile -t "$IMAGE" --load . >&2
docker save "$IMAGE" | gzip > "$WORK/image.tar.gz"
ok "image saved ($(du -h "$WORK/image.tar.gz" | cut -f1 | tr -d ' '))"

ssh-keygen -t ed25519 -N '' -q -C "$KEY_NAME" -f "$WORK/id_ed25519"
hc ssh-key create --name "$KEY_NAME" --public-key-from-file "$WORK/id_ed25519.pub" \
  --label app=arche --label role=snapshot-builder >/dev/null
KEY_CREATED=1

info "Creating build server $SERVER_NAME ($TYPE, $LOCATION)"
SERVER_CREATED=1
hc server create --name "$SERVER_NAME" --type "$TYPE" --image ubuntu-24.04 --location "$LOCATION" \
  --ssh-key "$KEY_NAME" --label app=arche --label role=snapshot-builder -o json > "$WORK/server.json"
IP=$(jq -r '.server.public_net.ipv4.ip // empty' "$WORK/server.json")
[ -n "$IP" ] || die "build server has no IPv4 address"
ok "server up at $IP"

SSH_OPTS=(-i "$WORK/id_ed25519" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null
          -o LogLevel=ERROR -o ConnectTimeout=5 -o BatchMode=yes -o ServerAliveInterval=15)
# The command string is meant to expand here (e.g. IMAGE=… below); the remote
# scripts themselves arrive on stdin from quoted heredocs and expand remotely.
# shellcheck disable=SC2029
remote() { ssh "${SSH_OPTS[@]}" "root@$IP" "$@"; }

info "Waiting for SSH"
for _ in $(seq 1 60); do
  remote true 2>/dev/null && break
  sleep 5
done
remote true || die "SSH did not come up on $IP"
ok "SSH ready"

info "Installing Docker"
remote 'bash -s' >&2 <<'REMOTE'
set -euo pipefail
cloud-init status --wait >/dev/null 2>&1 || true
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -q
apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable docker
docker pull -q caddy:2
mkdir -p /opt/arche /etc/arche /var/lib/arche-caddy
REMOTE
ok "Docker installed, caddy:2 pulled"

info "Loading $IMAGE and installing the node service"
scp "${SSH_OPTS[@]}" -q "$WORK/image.tar.gz" infra/realtime/compose.node.yaml infra/realtime/Caddyfile \
  infra/realtime/arche-node.service "root@$IP:/root/"
remote "IMAGE='$IMAGE' bash -s" >&2 <<'REMOTE'
set -euo pipefail
gunzip -c /root/image.tar.gz | docker load -q
install -m 0644 /root/compose.node.yaml /opt/arche/compose.node.yaml
install -m 0644 /root/Caddyfile /opt/arche/Caddyfile
install -m 0644 /root/arche-node.service /etc/systemd/system/arche-node.service
systemctl daemon-reload
systemctl enable arche-node.service
rm -f /root/image.tar.gz /root/compose.node.yaml /root/Caddyfile /root/arche-node.service

# Smoke test: the image starts on this architecture and answers /health.
docker run -d --name arche-smoke -e NODE_SLOT=smoke -p 127.0.0.1:18787:8787 "$IMAGE" >/dev/null
ok=0
for _ in $(seq 1 20); do
  if curl -fsS http://127.0.0.1:18787/health >/dev/null 2>&1; then ok=1; break; fi
  sleep 1
done
docker rm -f arche-smoke >/dev/null
[ "$ok" -eq 1 ] || { echo "smoke test failed: $IMAGE did not answer /health" >&2; exit 1; }

# Leave nothing behind that would make every node a clone of this one.
apt-get clean
rm -rf /var/lib/apt/lists/*
rm -f /root/.ssh/authorized_keys /root/.bash_history
cloud-init clean --logs --machine-id 2>/dev/null || { cloud-init clean --logs; truncate -s 0 /etc/machine-id; }
sync
# The throwaway key is gone from authorized_keys now, so there is no second
# SSH session: the power-off is scheduled from this one, as a transient timer
# that outlives the logout.
systemd-run --quiet --no-block --on-active=3 /usr/bin/systemctl poweroff
REMOTE
ok "node service installed and enabled; /health smoke test passed"

info "Powering off"
for _ in $(seq 1 60); do
  [ "$(hc server describe "$SERVER_NAME" -o json | jq -r .status)" = off ] && break
  sleep 5
done
if [ "$(hc server describe "$SERVER_NAME" -o json | jq -r .status)" != off ]; then
  note "clean shutdown timed out; powering off hard"
  hc server poweroff "$SERVER_NAME" >/dev/null
fi
ok "server off"

info "Creating snapshot"
hc server create-image --type snapshot --description "arche-realtime-$VERSION" \
  --label app=arche --label role=realtime-node --label version="$VERSION" --label arch="$ARCH" \
  "$SERVER_NAME" >/dev/null
SNAPSHOT_ID=$(hc image list --type snapshot --selector "$SELECTOR,version=$VERSION" -o json \
  | jq -r 'sort_by(.created) | last | .id // empty')
[ -n "$SNAPSHOT_ID" ] || die "snapshot was not found after creation"
ok "snapshot $SNAPSHOT_ID"

hc server delete "$SERVER_NAME" >/dev/null && SERVER_CREATED=0
hc ssh-key delete "$KEY_NAME" >/dev/null && KEY_CREATED=0
ok "build server and key deleted"

info "Pruning old snapshots (keeping $KEEP + pinned)"
while IFS=$'\t' read -r id version created; do
  [ -n "$id" ] || continue
  [ "$id" = "$SNAPSHOT_ID" ] && continue
  hc image delete "$id" >/dev/null && ok "deleted $id (version $version, $created)"
done < <(prune_candidates)

echo
echo "Snapshot ready:"
echo "  id       $SNAPSHOT_ID"
echo "  version  $VERSION"
echo "  image    $IMAGE   (nodes get IMAGE=$IMAGE in user_data)"
echo "  arch     $ARCH    (create nodes with a matching server type, e.g. $TYPE)"
