#!/usr/bin/env bash
#
# One-time Hetzner Cloud setup for ARCHE's realtime nodes.
#
# A node is deleted when idle and created again from the realtime snapshot
# when someone opens the chat, so everything that must outlive a node is made
# here, once per slot rtN:
#
#   arche-rtN-ipv4, arche-rtN-ipv6   Primary IPs, auto-delete off: the DNS
#                                    name of the slot never changes
#   arche-rtN-certs                  10 GB ext4 Volume: Caddy's certificates
#                                    survive the server (Let's Encrypt allows
#                                    only five duplicate certificates a week)
#
# plus one firewall `arche-rt`: tcp 80/443 from anywhere, tcp 22 only from
# --admin-cidr. Idempotent — resources that exist (by name) are reused.
#
# Credentials: HETZNER_CLOUD_TOKEN from .env (never printed). Without it the
# hcloud context named by --context (default `arche`) is used, explicitly.
#
# Usage: scripts/realtime/setup-hetzner.sh [options]
#
#   --slots N          number of node slots (default 1)
#   --location LOC     Hetzner location (default fsn1)
#   --domain DOMAIN    slot hosts are rtN.DOMAIN (default radio.schaefchens.de)
#   --admin-cidr CIDR  allow SSH (tcp/22) from this range; default: closed
#   --sync-firewall    replace the rules of an existing `arche-rt` firewall
#   --context NAME     hcloud context if .env has no token (default arche)
#   --env FILE         env file to read (default: .env in the repo root)
#   --dry-run          look up what exists, create nothing
#   -h, --help         this text
#
# Prints the DNS records to create and the .env lines the PHP wake controller
# reads (REALTIME_SLOTS, REALTIME_FIREWALL_ID, REALTIME_LOCATION,
# REALTIME_SERVER_TYPE).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/realtime/lib.sh
. "$REPO_ROOT/scripts/realtime/lib.sh"

SLOTS=1 LOCATION=fsn1 DOMAIN=radio.schaefchens.de ADMIN_CIDR="" SYNC_FW=0
CONTEXT=arche ENV_FILE="$REPO_ROOT/.env" DRY_RUN=0
SERVER_TYPE=cax11
FIREWALL_NAME=arche-rt

while [ $# -gt 0 ]; do
  case "$1" in
    --slots)         shift; SLOTS="${1:-}" ;;
    --location)      shift; LOCATION="${1:-}" ;;
    --domain)        shift; DOMAIN="${1:-}" ;;
    --admin-cidr)    shift; ADMIN_CIDR="${1:-}" ;;
    --sync-firewall) SYNC_FW=1 ;;
    --context)       shift; CONTEXT="${1:-}" ;;
    --env)           shift; ENV_FILE="${1:-}" ;;
    --dry-run)       DRY_RUN=1 ;;
    -h|--help)       awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; exit 0 ;;
    *)               die "unknown option: $1 (try --help)" ;;
  esac
  shift
done

case "$SLOTS" in ''|*[!0-9]*) die "--slots needs a number" ;; esac
[ "$SLOTS" -ge 1 ] && [ "$SLOTS" -le 9 ] || die "--slots must be between 1 and 9"
[[ "$LOCATION" =~ ^[a-z0-9-]+$ ]] || die "--location looks wrong: $LOCATION"
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || die "--domain looks wrong: $DOMAIN"
if [ -n "$ADMIN_CIDR" ] && ! [[ "$ADMIN_CIDR" =~ ^[0-9a-fA-F:.]+/[0-9]{1,3}$ ]]; then
  die "--admin-cidr must be CIDR notation, e.g. 203.0.113.7/32"
fi

require_tools hcloud jq
if [ "$DRY_RUN" -eq 1 ]; then
  use_hcloud_credentials "$ENV_FILE" "$CONTEXT" optional
else
  use_hcloud_credentials "$ENV_FILE" "$CONTEXT"
fi
info "Hetzner credentials: $HC_SOURCE"
[ "$DRY_RUN" -eq 1 ] && info "Dry run — nothing will be created"

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# --- inventory (read-only) ----------------------------------------------------

if [ "$HC_MODE" = none ]; then
  note "no credentials: cannot look up existing resources, showing the plan only"
  PIPS='[]' VOLS='[]' FWS='[]'
else
  PIPS=$(hc primary-ip list -o json)
  VOLS=$(hc volume list -o json)
  FWS=$(hc firewall list -o json)
fi

field() { jq -r --arg n "$2" ".[] | select(.name == \$n) | $3" <<<"$1" | head -n1; }

# --- primary IPs ---------------------------------------------------------------

ensure_primary_ip() {
  local type="$1" name="$2" slot="$3" id loc autodel
  id=$(field "$PIPS" "$name" '.id')
  if [ -n "$id" ]; then
    loc=$(field "$PIPS" "$name" '(.location.name // .datacenter.location.name // "")')
    autodel=$(field "$PIPS" "$name" '.auto_delete')
    [ "$loc" = "$LOCATION" ] || die "$name exists in '$loc', not $LOCATION — a node can only use IPs of its own location"
    [ "$autodel" = "false" ] || die "$name has auto-delete on; it would vanish with the next node (hcloud primary-ip update --auto-delete=false $name)"
    ok "$name exists (id $id)"
    printf '%s' "$id"
    return
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    note "would create $type Primary IP $name in $LOCATION (auto-delete off)"
    return
  fi
  id=$(hc primary-ip create --type "$type" --name "$name" --location "$LOCATION" --auto-delete=false \
         --label app=arche --label role=realtime-node --label slot="$slot" -o json | jq -r '.primary_ip.id // .id')
  [ -n "$id" ] && [ "$id" != null ] || die "could not create $name"
  ok "$name created (id $id)"
  printf '%s' "$id"
}

# --- volumes ---------------------------------------------------------------------

ensure_volume() {
  local name="$1" slot="$2" id loc
  id=$(field "$VOLS" "$name" '.id')
  if [ -n "$id" ]; then
    loc=$(field "$VOLS" "$name" '.location.name')
    [ "$loc" = "$LOCATION" ] || die "$name exists in '$loc', not $LOCATION"
    ok "$name exists (id $id)"
    printf '%s' "$id"
    return
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    note "would create 10 GB ext4 Volume $name in $LOCATION"
    return
  fi
  id=$(hc volume create --name "$name" --size 10 --location "$LOCATION" --format ext4 \
         --label app=arche --label role=realtime-certs --label slot="$slot" -o json | jq -r '.volume.id // .id')
  [ -n "$id" ] && [ "$id" != null ] || die "could not create $name"
  ok "$name created (id $id)"
  printf '%s' "$id"
}

# --- firewall ----------------------------------------------------------------------

firewall_rules() {
  jq -n --arg admin "$ADMIN_CIDR" '
    [ {direction: "in", protocol: "tcp", port: "80",  source_ips: ["0.0.0.0/0", "::/0"], description: "ACME + redirect"},
      {direction: "in", protocol: "tcp", port: "443", source_ips: ["0.0.0.0/0", "::/0"], description: "wss"} ]
    + (if $admin == "" then [] else
        [ {direction: "in", protocol: "tcp", port: "22", source_ips: [$admin], description: "admin ssh"} ] end)'
}

ensure_firewall() {
  local id
  firewall_rules > "$WORK/rules.json"
  id=$(field "$FWS" "$FIREWALL_NAME" '.id')
  if [ -n "$id" ]; then
    if [ "$SYNC_FW" -eq 1 ]; then
      if [ "$DRY_RUN" -eq 1 ]; then
        note "would replace the rules of $FIREWALL_NAME"
      else
        hc firewall replace-rules --rules-file "$WORK/rules.json" "$FIREWALL_NAME" >/dev/null
        ok "$FIREWALL_NAME rules replaced"
      fi
    fi
    ok "$FIREWALL_NAME exists (id $id)"
    printf '%s' "$id"
    return
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    note "would create firewall $FIREWALL_NAME ($(jq -r 'map(.port) | join(", ")' "$WORK/rules.json") in)"
    return
  fi
  id=$(hc firewall create --name "$FIREWALL_NAME" --rules-file "$WORK/rules.json" --label app=arche -o json \
         | jq -r '.firewall.id // .id')
  [ -n "$id" ] && [ "$id" != null ] || die "could not create $FIREWALL_NAME"
  ok "$FIREWALL_NAME created (id $id)"
  printf '%s' "$id"
}

# --- main --------------------------------------------------------------------------

SLOT_JSON='[]'
DNS_LINES=()

for n in $(seq 1 "$SLOTS"); do
  slot="rt$n"
  host="$slot.$DOMAIN"
  info "Slot $slot ($host)"
  v4=$(ensure_primary_ip ipv4 "arche-$slot-ipv4" "$slot")
  v6=$(ensure_primary_ip ipv6 "arche-$slot-ipv6" "$slot")
  vol=$(ensure_volume "arche-$slot-certs" "$slot")
  SLOT_JSON=$(jq -c --arg slot "$slot" --arg host "$host" --arg v4 "$v4" --arg v6 "$v6" --arg vol "$vol" \
    '. + [{slot: $slot, host: $host,
           ipv4: ($v4 | if . == "" then null else tonumber end),
           ipv6: ($v6 | if . == "" then null else tonumber end),
           volume: ($vol | if . == "" then null else tonumber end)}]' <<<"$SLOT_JSON")
done

info "Firewall"
FW_ID=$(ensure_firewall)

# Addresses for DNS, freshly listed so newly created IPs are included.
if [ "$HC_MODE" != none ]; then PIPS=$(hc primary-ip list -o json); fi
for n in $(seq 1 "$SLOTS"); do
  slot="rt$n"
  a=$(field "$PIPS" "arche-$slot-ipv4" '.ip')
  aaaa=$(field "$PIPS" "arche-$slot-ipv6" '.ip')
  # A Primary IPv6 is a /64; the server answers on ::1 of it.
  [ -n "$aaaa" ] && aaaa="${aaaa%/64}" && aaaa="${aaaa%::}::1"
  DNS_LINES+=("  $slot.$DOMAIN.  A     ${a:-<new IPv4>}")
  DNS_LINES+=("  $slot.$DOMAIN.  AAAA  ${aaaa:-<new IPv6>::1}")
done

echo
echo "DNS records to create (at the DNS host of $DOMAIN):"
printf '%s\n' "${DNS_LINES[@]}"
echo
echo "Add to .env (the PHP wake controller reads these):"
echo "REALTIME_SLOTS=$SLOT_JSON"
echo "REALTIME_FIREWALL_ID=${FW_ID:-}"
echo "REALTIME_LOCATION=$LOCATION"
echo "REALTIME_SERVER_TYPE=$SERVER_TYPE"
[ "$DRY_RUN" -eq 1 ] && { echo; info "Dry run complete — ids of resources not yet created are null/empty"; }
exit 0
