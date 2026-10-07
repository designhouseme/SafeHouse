#!/usr/bin/env bash
# Isolated OLS regression. Creates test posts and temporarily toggles LiteSpeed/safe mode.
# Requires the disposable dev/ols site. SHOUSE_OLS_INSIDE=1 runs inside the OLS container itself.
set -euo pipefail
if [ "${SHOUSE_OLS_INSIDE:-0}" = 1 ]; then
    wp() { /usr/local/lsws/lsphp83/bin/php /usr/local/bin/wp-cli.phar --allow-root "$@"; }
else
    cd "$(dirname "$0")"
    source ../env.sh
    wp() { ./wp.sh "$@"; }
fi
U=${SHOUSE_LS_TEST_URL:-http://localhost:${OLS_PORT:-8896}}
ADMIN=${SHOUSE_LS_TEST_ADMIN:-admin}
: "${SHOUSE_DEV_ADMIN_PASSWORD:?Set the isolated test admin password}"
T=$(mktemp -d)
POST=''
# No external mail or requests are needed. Do not run against a production installation.
[ "$(wp eval 'echo wp_get_environment_type();')" = local ] || { echo 'Requires a disposable local WordPress'; exit 1; }
[ "$(wp eval 'echo (int) SafeHouse\Core\SafeMode::active();')" = 0 ] || { echo 'Requires safe mode initially off'; exit 1; }
WAS=$(wp eval 'echo (int) SafeHouse\Plugin::instance()->settings->module_enabled(new SafeHouse\Modules\LiteSpeed());')
cleanup() {
    wp shouse safe-mode off >/dev/null 2>&1 || true
    [ -z "$POST" ] || wp post delete "$POST" --force >/dev/null 2>&1 || true
    if [ "$WAS" = 0 ]; then wp shouse module disable litespeed >/dev/null 2>&1 || true; else wp shouse module enable litespeed >/dev/null 2>&1 || true; fi
    rm -rf "$T"
}
trap cleanup EXIT
cache() { curl -fsS --max-time 15 -o /dev/null -D - "$@" | tr -d '\r' | awk 'tolower($1)=="x-litespeed-cache:" {v=$2} END {print v=="" ? "-" : v}'; }
check() { [ "$2" = "$3" ] || { echo "FAIL $1: expected $2; got $3"; exit 1; }; echo "PASS $1"; }
warm() { cache "$U/" >/dev/null; check 'anonymous cache hit' hit "$(cache "$U/")"; }
receive() { curl -sS --max-time 15 -o /dev/null "$U/wp-admin/admin-ajax.php"; }
wp shouse module disable litespeed >/dev/null
receive
curl -sS --max-time 15 -o /dev/null -c "$T/login.jar" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
    --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$SHOUSE_DEV_ADMIN_PASSWORD" -d testcookie=1 "$U/wp-login.php"
check 'session created before activation has no supplementary vary cookie' 0 "$(grep -c _lscache_vary "$T/login.jar" || true)"
grep -q wordpress_logged_in_ "$T/login.jar" || { echo 'FAIL test login'; exit 1; }
wp shouse module enable litespeed >/dev/null
receive
warm
check 'session predating activation bypasses an existing public cache hit' - "$(cache -b "$T/login.jar" "$U/")"
curl -sS --max-time 15 -D "$T/renew.headers" -o /dev/null -b "$T/login.jar" -c "$T/login.jar" "$U/"
grep -qi '^set-cookie: _lscache_vary=1;' "$T/renew.headers" || { echo 'FAIL session vary renewal'; exit 1; }
echo 'PASS existing session gets a renewed vary cookie'
grep -v _lscache_vary "$T/login.jar" > "$T/long-session.jar"
check 'long session still bypasses cache after supplementary cookie disappears' - "$(cache -b "$T/long-session.jar" "$U/")"
HASH=$(wp eval 'echo COOKIEHASH;')
for COOKIE in woocommerce_items_in_cart woocommerce_cart_hash "wp_woocommerce_session_$HASH" "wp-postpass_$HASH" "comment_author_$HASH"; do
    check "$COOKIE without supplementary vary bypasses cache" - "$(cache -H "Cookie: $COOKIE=regression" "$U/")"
done
POST=$(wp post create --post_type=post --post_title='SafeHouse password cache regression' --post_status=publish --post_password=cache-test-password --post_content='PRIVATE-CACHE-REGRESSION-CONTENT' --porcelain)
URL=$(wp eval "echo get_permalink($POST);")
curl -sS --max-time 15 -o /dev/null -c "$T/postpass.jar" --data-urlencode 'post_password=cache-test-password' "$U/wp-login.php?action=postpass"
grep -q _lscache_vary "$T/postpass.jar" || { echo 'FAIL post password vary cookie'; exit 1; }
echo 'PASS password entry sets the supplementary vary cookie'
check 'password visitor is not cached' - "$(cache -b "$T/postpass.jar" "$URL")"
warm
wp post update "$POST" --post_title='Changed by CLI cache regression' >/dev/null
sleep 1
check 'CLI content change dispatches its own purge before next front-end visit' miss "$(cache "$U/")"
warm
wp shouse module disable litespeed >/dev/null
sleep 1
check 'disabling the module via CLI purges existing pages' - "$(cache "$U/")"
check 'global purge receiver consumes queue while module is off' absent "$(wp eval 'echo get_option("shouse_litespeed_purge", false) === false ? "absent" : "pending";')"
wp shouse module enable litespeed >/dev/null
receive
warm
wp shouse safe-mode on >/dev/null
sleep 1
check 'safe mode purges cached pages despite disabled module hooks' - "$(cache "$U/")"
wp shouse safe-mode off >/dev/null
if [ "${SHOUSE_OLS_INSIDE:-0}" = 1 ]; then wp eval-file /tests/ols/cache-hooks-test.php; fi
printf '%s\n' 'All LiteSpeed security regressions passed.'
