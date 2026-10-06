#!/usr/bin/env bash
# Refresh Net::CLOUDFLARE_RANGES from https://www.cloudflare.com/ips/. Run before every release and
# review the diff: a range Cloudflare dropped must not stay trusted.
set -euo pipefail
cd "$(dirname "$0")/.."
F=plugin/src/Core/Net.php
list=$( { curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6; } | grep -E '^[0-9a-fA-F:.]+/[0-9]+$' )
[ "$(echo "$list" | wc -l | tr -d ' ')" -ge 10 ] || { echo "cloudflare-ips: unexpected answer from cloudflare.com" >&2; exit 1; }
RANGES=$(echo "$list" | while read -r range; do printf "\t\t'%s',\n" "$range"; done)
export RANGES
awk '/cloudflare-ips:begin/ { print; printf "%s\n", ENVIRON["RANGES"]; skip = 1; next } /cloudflare-ips:end/ { skip = 0 } !skip' "$F" > "$F.tmp"
mv "$F.tmp" "$F"
sed -i.bak "s/checked [0-9-]*\. Refresh with dev\/cloudflare-ips.sh/checked $(date +%F). Refresh with dev\/cloudflare-ips.sh/" "$F" && rm -f "$F.bak"
git --no-pager diff --stat -- "$F"
