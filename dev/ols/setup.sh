#!/usr/bin/env bash
# One-time install of the OpenLiteSpeed test site. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"
URL="http://localhost:${OLS_PORT:-8896}"
wp() { ./wp.sh "$@"; }

docker compose up -d
if ! wp core is-installed 2>/dev/null; then
	wp core download --force >/dev/null || true
	wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db --skip-check --force \
		--extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
	wp core install --url="$URL" --title="WPHouse OLS" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email
fi
wp theme install twentytwentyfive --activate >/dev/null 2>&1 || true
wp plugin install woocommerce ${WOO_VERSION:+--version=$WOO_VERSION} --activate >/dev/null
wp plugin activate wphouse >/dev/null
# PHP runs as nobody under OLS; give it the docroot (the plugin mount is the repo and stays as it is).
docker compose exec -T ols sh -c 'find /var/www/vhosts/localhost/html -path "*/plugins/wphouse" -prune -o -exec chown 65534:65534 {} +'
until curl -s -o /dev/null "$URL/wp-login.php"; do sleep 2; done
echo "Ready: $URL/wp-admin (admin/admin)"
