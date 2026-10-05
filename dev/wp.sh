#!/usr/bin/env bash
# Run WP-CLI against the dev site: ./dev/wp.sh plugin list
set -euo pipefail
cd "$(dirname "$0")"
exec docker compose --profile cli run --rm -T wpcli wp "$@"
