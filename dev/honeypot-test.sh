#!/usr/bin/env bash
# Isolated WordPress + real browser regressions; no external mail or HTTP.
# PLAYWRIGHT_PATH=/path/to/playwright bash dev/honeypot-test.sh
set -euo pipefail
cd "$(dirname "$0")/.."
source dev/env.sh
export COMPOSE_PROJECT_NAME="shouse-honeypot-$$"
port=${SHOUSE_HP_TEST_PORT:-8897}
override=$(mktemp)
# Docker's internal network suppresses published ports. This disposable bridge exposes
# only loopback; the mu-plugin blocks outbound PHP HTTP/mail and the browser blocks other origins.
printf 'services:\n  wordpress:\n    ports:\n      - "127.0.0.1:%s:80"\nnetworks:\n  default:\n    internal: false\n' "$port" > "$override"
dc() { docker compose -f dev/security/compose.yml -f "$override" "$@"; }
wp() { dc run --rm -T cli wp "$@"; }
cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$override"; }
trap cleanup EXIT
dc up -d --wait
for _ in {1..30}; do
	if dc exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
	sleep 1
done
wp core install --url="http://localhost:$port" --title='SafeHouse honeypot tests' --admin_user=admin \
	--admin_password="$SHOUSE_DEV_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
wp plugin activate shouse
wp eval-file /tests/security-form-guard-test.php
for mode in direct generic generic-real cloudflare cloudflare-option; do
	wp eval-file /tests/security-net-quota-test.php "$mode"
done
wp eval-file /tests/security-honeypot-test.php
wp eval-file /tests/security-hardening-test.php
wp eval '$o=get_option("shouse_settings",[]); $o["modules"]["bots"]=true; $o["modules"]["tweaks"]=false; $o["modules"]["watch"]=false; $o["bots"]=["honeypot"=>true]; update_option("shouse_settings",$o); update_option("users_can_register",1); update_option("require_name_email",1);'
wp config set SHOUSE_HP_BROWSER_TEST true --raw
U="http://localhost:$port" node dev/honeypot-e2e.mjs
