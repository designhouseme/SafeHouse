#!/usr/bin/env bash
# Tests that need the plugin installed from a zip (never on the dev site, whose plugin directory is this repo):
# Plugin Check, safe mode, SHOUSE_MODULES pinning, SHOUSE_LOCK_SETTINGS, deactivation and uninstall cleanup.
set -uo pipefail
cd "$(dirname "$0")/.."
source dev/env.sh
T=build/updtest
mkdir -p "$T/www/shouse" "$T/pkgs"
export SHOUSE_SIGNING_KEY=$PWD/$T/dev-signing.key SHOUSE_RELEASE_URL=http://updates/shouse
[ -f "$SHOUSE_SIGNING_KEY" ] || ./dev/release.sh keygen >/dev/null
export SHOUSE_TEST_PUBKEY=$(./dev/release.sh pubkey)
dc() { docker compose -f dev/updtest/docker-compose.yml "$@"; }
wp() { dc --profile cli run --rm -T wpcli wp "$@" 2>/dev/null; }
U=http://localhost:8895
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }

./dev/release.sh snapshot "$(sed -n "s/^const SHOUSE_VERSION = '\(.*\)';/\1/p" plugin/shouse.php)" "$T/pkgs/pkg" >/dev/null
ZIP=$(ls "$T"/pkgs/pkg/shouse-[0-9]*.zip)
dc down -v >/dev/null 2>&1
dc up -d >/dev/null 2>&1
until curl -s -o /dev/null "$U/wp-login.php"; do sleep 2; done
wp core install --url=$U --title=pkgtest --admin_user=admin --admin_password="$SHOUSE_DEV_ADMIN_PASSWORD" --admin_email=a@example.test --skip-email >/dev/null
wp plugin install "/pkgs/pkg/$(basename "$ZIP")" --activate >/dev/null
check "installed from zip" active "$(wp plugin get shouse --field=status)"
check "no PHP notices on activation" 0 "$(dc exec -T wordpress sh -c 'cat wp-content/debug.log 2>/dev/null | grep -ci shouse')"

echo "== Plugin Check"
wp plugin install plugin-check --activate >/dev/null
wp plugin check shouse --format=csv > "$T/plugin-check.csv"
# Findings that follow from private distribution (own updater, "wp" in the name, own translations) are expected.
unexpected=$(grep -E ',(ERROR|WARNING),' "$T/plugin-check.csv" | grep -v -F -f dev/plugin-check-expected.txt || true)
echo "${unexpected:-  (only expected findings)}"
check "no unexpected Plugin Check findings" 0 "$(printf '%s' "$unexpected" | grep -c . || true)"

echo "== safe mode"
wp shouse safe-mode on >/dev/null
check "safe mode stops modules" 0 "$(wp shouse status --format=csv | grep -c ',yes,settings')"
check "xmlrpc reachable in safe mode" 200 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall><methodName>demo.sayHello</methodName></methodCall>' $U/xmlrpc.php)"
wp shouse safe-mode off >/dev/null
check "modules back after safe mode" 403 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall/>' $U/xmlrpc.php)"
wp config set SHOUSE_SAFE_MODE true --raw >/dev/null
sleep 3 # Apache's opcache revalidates wp-config.php every 2 s.
check "SHOUSE_SAFE_MODE constant" 200 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '<methodCall><methodName>demo.sayHello</methodName></methodCall>' $U/xmlrpc.php)"
wp config delete SHOUSE_SAFE_MODE >/dev/null

echo "== SHOUSE_MODULES and SHOUSE_LOCK_SETTINGS"
wp config set SHOUSE_MODULES "[ 'lockdown' => true, 'hardening' => false ]" --raw >/dev/null
check "pinned module runs" "lockdown,yes,yes,SHOUSE_MODULES" "$(wp shouse status --format=csv | grep '^lockdown' | cut -d, -f1-4)"
check "pinned-off module stops" "hardening,no,no,SHOUSE_MODULES" "$(wp shouse status --format=csv | grep '^hardening' | cut -d, -f1-4)"
check "CLI warns about pin" 1 "$(dc --profile cli run --rm -T wpcli wp shouse module disable lockdown 2>&1 | grep -c 'pinned')"
wp config delete SHOUSE_MODULES >/dev/null
wp config set SHOUSE_LOCK_SETTINGS true --raw >/dev/null
check "locked settings ignore saves" unchanged "$(wp eval 'wp_set_current_user(1); $s = SafeHouse\Plugin::instance()->settings; $before = $s->all(); $after = $s->sanitize(["modules" => ["tweaks" => 1]]); echo $before === $after ? "unchanged" : "changed";')"
wp config delete SHOUSE_LOCK_SETTINGS >/dev/null

echo "== deactivate and uninstall"
wp shouse safe-mode on >/dev/null
wp plugin deactivate shouse >/dev/null
check "cron cleared on deactivation" 0 "$(wp cron event list --fields=hook --format=csv | grep -c shouse)"
wp plugin uninstall shouse >/dev/null
check "files removed" 0 "$(dc exec -T wordpress sh -c 'ls wp-content/plugins | grep -c shouse || true')"
check "options removed" 0 "$(wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%shouse%'" --skip-column-names)"
check "log table dropped" 0 "$(wp db query "SHOW TABLES LIKE 'wp_shouse_log'" --skip-column-names | wc -l | tr -d ' ')"
check "safe-mode flag removed" 0 "$(dc exec -T wordpress sh -c 'ls wp-content | grep -c shouse-safe-mode || true')"

[ "${KEEP:-0}" = 1 ] || dc down -v >/dev/null 2>&1
echo; [ "$fails" -eq 0 ] && echo "All package checks passed." || echo "$fails package check(s) failed."
exit "$fails"
