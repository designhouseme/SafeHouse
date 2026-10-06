#!/usr/bin/env bash
# One-time install of the OpenLiteSpeed test site. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"
URL="http://localhost:${OLS_PORT:-8896}"
wp() { ./wp.sh "$@"; }

[ -d ../../build/wp-cli.phar ] && rmdir ../../build/wp-cli.phar # created by `docker compose up` before the download
[ -f ../../build/wp-cli.phar ] || { mkdir -p ../../build && curl -sSL -o ../../build/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; }
docker compose up -d
until docker compose exec -T ols true 2>/dev/null; do sleep 1; done
if ! wp core is-installed 2>/dev/null; then
	wp core download --force >/dev/null || true
	wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db --skip-check --force \
		--extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_REDIS_HOST', 'redis' );
PHP
	wp core install --url="$URL" --title="SafeHouse OLS" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email
fi
wp config set WP_REDIS_HOST redis >/dev/null # sites set up before Redis was part of the harness
wp config set SHOUSE_DISABLE_UPDATES true --raw >/dev/null # the plugin folder is the git checkout, mounted without its .git
# Docker Desktop hands requests from the host over from a private gateway address: trust it as a proxy, so
# tests can play many visitors with X-Forwarded-For (dev/ols/login-limits-test.sh). Test site only.
wp config set SHOUSE_TRUSTED_PROXIES "[ '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16' ]" --raw >/dev/null
wp theme install twentytwentyfive --activate >/dev/null 2>&1 || true
wp plugin install woocommerce ${WOO_VERSION:+--version=$WOO_VERSION} --activate >/dev/null
wp plugin activate shouse >/dev/null
# PHP runs as nobody under OLS; give it the docroot (the plugin mount is the checkout and stays as it is).
docker compose exec -T ols sh -c 'find /var/www/vhosts/localhost/html -path "*/plugins/shouse" -prune -o -exec chown 65534:65534 {} +'
until curl -s -o /dev/null "$URL/wp-login.php"; do sleep 2; done
echo "Ready: $URL/wp-admin (admin/admin)"
