#!/usr/bin/env bash
# WP-CLI against the OpenLiteSpeed test site: ./dev/ols/wp.sh plugin list
set -euo pipefail
cd "$(dirname "$0")"
exec docker compose --profile cli run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp --allow-root "$@"
