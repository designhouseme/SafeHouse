#!/usr/bin/env bash
# Tests that need the plugin installed from a zip (never on the dev site, whose plugin directory is this repo):
# Plugin Check, safe mode, WPHOUSE_MODULES pinning, WPHOUSE_LOCK_SETTINGS, deactivation and uninstall cleanup.
set -uo pipefail
cd "$(dirname "$0")/.."
T=build/updtest
mkdir -p "$T/www/wphouse" "$T/pkgs"
export WPHOUSE_SIGNING_KEY=$PWD/$T/dev-signing.key WPHOUSE_RELEASE_URL=http://updates/wphouse
[ -f "$WPHOUSE_SIGNING_KEY" ] || ./dev/release.sh keygen >/dev/null
export WPHOUSE_TEST_PUBKEY=$(./dev/release.sh pubkey)
dc() { docker compose -f dev/updtest/docker-compose.yml "$@"; }
wp() { dc --profile cli run --rm -T wpcli wp "$@" 2>/dev/null; }
U=http://localhost:8895
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }

./dev/release.sh snapshot "$(sed -n "s/^const WPHOUSE_VERSION = '\(.*\)';/\1/p" wphouse.php)" "$T/pkgs/pkg" >/dev/null
ZIP=$(ls "$T"/pkgs/pkg/wphouse-[0-9]*.zip)
dc down -v >/dev/null 2>&1
dc up -d >/dev/null 2>&1
until curl -s -o /dev/null "$U/wp-login.php"; do sleep 2; done
wp core install --url=$U --title=pkgtest --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email >/dev/null
wp plugin install "/pkgs/pkg/$(basename "$ZIP")" --activate >/dev/null
check "installed from zip" active "$(wp plugin get wphouse --field=status)"
check "no PHP notices on activation" 0 "$(dc exec -T wordpress sh -c 'cat wp-content/debug.log 2>/dev/null | grep -ci wphouse')"

echo "== Plugin Check"
wp plugin install plugin-check --activate >/dev/null
wp plugin check wphouse --format=csv > "$T/plugin-check.csv"
# Findings that follow from private distribution (own updater, "wp" in the name, own translations) are expected.
unexpected=$(grep -E ',(ERROR|WARNING),' "$T/plugin-check.csv" | grep -v -F -f dev/plugin-check-expected.txt || true)
echo "${unexpected:-  (only expected findings)}"
check "no unexpected Plugin Check findings" 0 "$(printf '%s' "$unexpected" | grep -c . || true)"

echo "== safe mode"
wp wphouse safe-mode on >/dev/null
check "safe mode stops modules" 0 "$(wp wphouse status --format=csv | grep -c ',yes,settings')"
check "xmlrpc reachable in safe mode" 200 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall><methodName>demo.sayHello</methodName></methodCall>' $U/xmlrpc.php)"
wp wphouse safe-mode off >/dev/null
check "modules back after safe mode" 403 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall/>' $U/xmlrpc.php)"
wp config set WPHOUSE_SAFE_MODE true --raw >/dev/null
sleep 3 # Apache's opcache revalidates wp-config.php every 2 s.
check "WPHOUSE_SAFE_MODE constant" 200 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall><methodName>demo.sayHello</methodName></methodCall>' $U/xmlrpc.php)"
wp config delete WPHOUSE_SAFE_MODE >/dev/null

echo "== WPHOUSE_MODULES and WPHOUSE_LOCK_SETTINGS"
wp config set WPHOUSE_MODULES "[ 'lockdown' => true, 'hardening' => false ]" --raw >/dev/null
check "pinned module runs" "lockdown,yes,yes,WPHOUSE_MODULES" "$(wp wphouse status --format=csv | grep '^lockdown' | cut -d, -f1-4)"
check "pinned-off module stops" "hardening,no,no,WPHOUSE_MODULES" "$(wp wphouse status --format=csv | grep '^hardening' | cut -d, -f1-4)"
check "CLI warns about pin" 1 "$(dc --profile cli run --rm -T wpcli wp wphouse module disable lockdown 2>&1 | grep -c 'pinned')"
wp config delete WPHOUSE_MODULES >/dev/null
wp config set WPHOUSE_LOCK_SETTINGS true --raw >/dev/null
check "locked settings ignore saves" unchanged "$(wp eval 'wp_set_current_user(1); $s = WPHouse\Plugin::instance()->settings; $before = $s->all(); $after = $s->sanitize(["modules" => ["tweaks" => 1]]); echo $before === $after ? "unchanged" : "changed";')"
wp config delete WPHOUSE_LOCK_SETTINGS >/dev/null

echo "== deactivate and uninstall"
wp wphouse safe-mode on >/dev/null
wp plugin deactivate wphouse >/dev/null
check "cron cleared on deactivation" 0 "$(wp cron event list --fields=hook --format=csv | grep -c wphouse)"
wp plugin uninstall wphouse >/dev/null
check "files removed" 0 "$(dc exec -T wordpress sh -c 'ls wp-content/plugins | grep -c wphouse || true')"
check "options removed" 0 "$(wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%wphouse%'" --skip-column-names)"
check "log table dropped" 0 "$(wp db query "SHOW TABLES LIKE 'wp_wphouse_log'" --skip-column-names | wc -l)"
check "safe-mode flag removed" 0 "$(dc exec -T wordpress sh -c 'ls wp-content | grep -c wphouse-safe-mode || true')"

[ "${KEEP:-0}" = 1 ] || dc down -v >/dev/null 2>&1
echo; [ "$fails" -eq 0 ] && echo "All package checks passed." || echo "$fails package check(s) failed."
exit "$fails"
