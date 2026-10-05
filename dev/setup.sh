#!/usr/bin/env bash
# One-time install of the dev site. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"
URL="http://localhost:${WP_PORT:-8894}"

docker compose up -d
until curl -s -o /dev/null "$URL/wp-login.php"; do sleep 2; done

if ! ./wp.sh core is-installed 2>/dev/null; then
	./wp.sh core install --url="$URL" --title="WPHouse dev" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email
fi
./wp.sh rewrite structure '/%postname%/' --hard
./wp.sh plugin install woocommerce wordfence --activate
./wp.sh plugin activate wphouse
./wp.sh wphouse status
echo "Ready: $URL/wp-admin (admin/admin), mail: http://localhost:${MAILPIT_UI_PORT:-8025}"
