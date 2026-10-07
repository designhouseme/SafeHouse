#!/usr/bin/env bash
# WP-CLI on the OpenLiteSpeed test site, run by the site's own lsphp (it has PhpRedis): ./dev/ols/wp.sh plugin list
set -euo pipefail
cd "$(dirname "$0")"
source ../env.sh
exec docker compose exec -T ols /usr/local/lsws/lsphp83/bin/php -d memory_limit=512M -d 'error_reporting=E_ALL & ~E_DEPRECATED' \
	/usr/local/bin/wp-cli.phar --allow-root --path=/var/www/vhosts/localhost/html "$@"
