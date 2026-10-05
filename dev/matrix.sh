#!/usr/bin/env bash
# Run the smoke suite on another WordPress/PHP pair in its own throwaway stack.
#   ./dev/matrix.sh 6.6 8.1          oldest supported
#   ./dev/matrix.sh latest 8.4
# KEEP=1 leaves the stack running. WOO_VERSION / WF_VERSION pin plugin versions for old WordPress.
set -euo pipefail
cd "$(dirname "$0")"
WPV=${1:?usage: matrix.sh <wp-version|latest> <php-version> [port]}
PHPV=${2:?php version}
PORT=${3:-8896}
[ "$WPV" = latest ] && tag="php$PHPV-apache" || tag="$WPV-php$PHPV-apache"
export COMPOSE_PROJECT_NAME="wphouse-matrix-${WPV//./}-${PHPV//./}"
export WP_IMAGE="wordpress:$tag" CLI_IMAGE="wordpress:cli-php$PHPV" WP_PORT=$PORT MAILPIT_UI_PORT=$((PORT + 1000))
echo "== $WP_IMAGE on :$PORT ($COMPOSE_PROJECT_NAME)"
./setup.sh >/dev/null
docker compose exec -T wordpress sh -c ': > wp-content/debug.log' || true
code=0
./smoke.sh || code=$?
echo "== PHP notices/warnings from WPHouse:"
docker compose exec -T wordpress sh -c 'grep -i "wphouse" wp-content/debug.log || echo none'
[ "${KEEP:-0}" = 1 ] || docker compose down -v >/dev/null 2>&1
exit "$code"
