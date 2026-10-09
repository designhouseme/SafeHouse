#!/usr/bin/env bash
# Security release gate. Every service is disposable; no published ports or outbound fixture traffic.
set -euo pipefail
cd "$(dirname "$0")/.."
source dev/env.sh
export COMPOSE_PROJECT_NAME="shouse-security-${GITHUB_RUN_ID:-local}-$$"
dc() { docker compose -f dev/security/compose.yml "$@"; }
wp() { dc run --rm -T cli wp "$@"; }
cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
if [ "${1:-}" != --wp-only ]; then
	python3 dev/signed-publish-test.py
	docker run --rm --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=128m -v "$PWD":/app:ro -w /app --entrypoint php \
		composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac dev/signed-producer-test.php
	bash dev/ols/object-cache-security.sh
	docker run --rm --network none -v "$PWD":/app:ro -w /app node:24 node dev/ingest-security-test.mjs
fi
dc up -d --wait
ready=0
for _ in {1..30}; do
	if dc exec -T wordpress test -f /var/www/html/wp-config.php; then ready=1; break; fi
	sleep 2
done
[ "$ready" = 1 ] || { echo 'FAIL: disposable WordPress did not initialize'; exit 1; }
wp core install --url=http://wordpress --title='SafeHouse security tests' --admin_user=admin \
	--admin_password="$SHOUSE_DEV_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
wp plugin activate shouse
wp eval 'echo "WordPress ", get_bloginfo("version"), "; PHP ", PHP_VERSION, "\n";'
for fixture in security-auth-test.php security-honeypot-test.php security-hardening-test.php security/duplicate.php security/outbox.php security/watch-read-test.php security/watch-budget-test.php security/queue-budget-test.php security/stability-diagnostics-test.php signed-data-wp-test.php download-transport-test.php security-omnibus-test.php ols/cache-hooks-test.php; do
	wp eval-file "/tests/$fixture"
done
wp eval-file /tests/security/queue-race.php seed
pids=()
for _ in 1 2 3; do wp eval-file /tests/security/queue-race.php work & pids+=("$!"); done
race_status=0
for pid in "${pids[@]}"; do wait "$pid" || race_status=1; done
[ "$race_status" = 0 ] || { echo 'FAIL: queue worker exited unsuccessfully'; exit 1; }
wp eval-file /tests/security/queue-race.php verify
# A typo must fail before SMTP is configured; no real transport is involved.
wp eval-file /tests/security/smtp.php
bash dev/security/runtime-test.sh
printf '%s\n' 'PASS: security release gate'
