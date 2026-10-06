#!/usr/bin/env bash
# One-time install of the dev site. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"
URL="http://localhost:${WP_PORT:-8894}"

docker compose up -d
until curl -s -o /dev/null "$URL/wp-login.php"; do sleep 2; done

if ! ./wp.sh core is-installed 2>/dev/null; then
	./wp.sh core install --url="$URL" --title="SafeHouse dev" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email
fi
./wp.sh rewrite structure '/%postname%/' --hard
# Pin older versions for old WordPress: WOO_VERSION=9.4.3 ./setup.sh
./wp.sh plugin install woocommerce ${WOO_VERSION:+--version=$WOO_VERSION} --activate || echo "WooCommerce not active on this WordPress version"
./wp.sh plugin install wordfence ${WF_VERSION:+--version=$WF_VERSION} --activate || echo "Wordfence not active on this WordPress version"
./wp.sh plugin activate shouse
./wp.sh shouse status
echo "Ready: $URL/wp-admin (admin/admin), mail: http://localhost:${MAILPIT_UI_PORT:-8025}"
