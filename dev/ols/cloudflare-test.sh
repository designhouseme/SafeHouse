#!/usr/bin/env bash
# Cloudflare cache module on the OpenLiteSpeed site (./dev/ols/setup.sh first), against a stand-in for
# the Cloudflare API (cloudflare-mock.php) on the harness network. No call reaches Cloudflare.
set -uo pipefail
cd "$(dirname "$0")"
D=$(cd ../.. && pwd)/build/olstest/cfmock
mkdir -p "$D"
: > "$D/requests.jsonl"
echo ok > "$D/mode"
ZONE=0123456789abcdef0123456789abcdef
wp() { ./wp.sh "$@" 2>/dev/null; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
calls() { grep -c . "$D/requests.jsonl"; }
last() { tail -1 "$D/requests.jsonl"; }
fresh() { wp transient delete shouse_cloudflare_last >/dev/null; }

docker rm -f shouse-cfmock >/dev/null 2>&1
docker run -d --name shouse-cfmock --network shouse-ols_default -v "$PWD":/mock:ro -v "$D":/data php:8.3-cli php -S 0.0.0.0:8080 /mock/cloudflare-mock.php >/dev/null
backup=$(wp option get shouse_settings --format=json)
cleanup() {
	docker rm -f shouse-cfmock >/dev/null 2>&1
	for c in SHOUSE_CLOUDFLARE_TOKEN SHOUSE_CLOUDFLARE_ZONE SHOUSE_CLOUDFLARE_API; do wp config delete "$c" >/dev/null; done
	wp option update shouse_settings "$backup" --format=json >/dev/null
	wp option delete shouse_cloudflare_result shouse_cloudflare_pending >/dev/null
	wp cron event delete shouse_cloudflare_purge >/dev/null
}
trap cleanup EXIT

echo "== without credentials"
wp shouse module enable cloudflare >/dev/null
check "unavailable without token and zone" "yes no" "$(wp shouse status | awk '$1 == "cloudflare" { print $2, $3 }')"
wp config set SHOUSE_CLOUDFLARE_TOKEN test-token-123 >/dev/null
wp config set SHOUSE_CLOUDFLARE_ZONE "$ZONE" >/dev/null
wp config set SHOUSE_CLOUDFLARE_API http://shouse-cfmock:8080/client/v4 >/dev/null
check "runs with them"                      "yes yes" "$(wp shouse status | awk '$1 == "cloudflare" { print $2, $3 }')"

echo "== a post change clears its own pages"
fresh; : > "$D/requests.jsonl"
wp post update 1 --post_title="Cloudflare $RANDOM" >/dev/null
check "one call"                            1 "$(calls)"
check "to this zone's purge endpoint, with the token" yes "$(last | grep -q "/zones/$ZONE/purge_cache" && last | grep -q 'Bearer test-token-123' && echo yes || echo no)"
check "the post's address and the home page, not everything" yes "$(last | python3 -c 'import sys, json; b = json.loads(sys.stdin.read())["body"]; f = b.get("files", []); print("yes" if f and "purge_everything" not in b and any("p=1" in u or "hello-world" in u for u in f) and any(u.rstrip("/").endswith(":8896") for u in f) else "no: %s" % b)')"

echo "== at most one call every 30 seconds"
wp post update 1 --post_title="Cloudflare again $RANDOM" >/dev/null
check "a second change right after waits"   1   "$(calls)"
check "and is queued for cron"              1   "$(wp cron event list --hook=shouse_cloudflare_purge --format=count)"
fresh
wp cron event run shouse_cloudflare_purge >/dev/null
check "cron sends it"                       2   "$(calls)"

echo "== site-wide changes and comments"
fresh
wp eval 'do_action( "wp_update_nav_menu", 1 );' >/dev/null
check "a menu change clears everything"     yes "$(last | grep -q '"purge_everything":true' && echo yes || echo no)"
fresh; n=$(calls)
wp comment create --comment_post_ID=1 --comment_content="Buy cheap things" --comment_approved=0 >/dev/null
check "an unapproved comment clears nothing" "$n" "$(calls)"
fresh
wp comment create --comment_post_ID=1 --comment_content="Nice post" --comment_approved=1 >/dev/null
check "an approved comment clears its post" "$((n + 1))" "$(calls)"

echo "== failures"
echo error > "$D/mode"; fresh
./wp.sh post update 1 --post_title="Cloudflare refused $RANDOM" >/dev/null 2>&1
check "a refusal does not break the change" 0 "$?"
check "the refusal is logged"              yes "$(wp shouse log --limit=5 | grep -q cloudflare_purge_failed && echo yes || echo no)"
check "and shows in Site Health"            recommended "$(wp eval 'echo SafeHouse\Plugin::instance()->module( "cloudflare" )->site_health_result()["status"];')"
check "the token never reaches the activity log" 0 "$(wp eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE message LIKE %s OR context LIKE %s", $wpdb->prefix . "shouse_log", "%test-token-123%", "%test-token-123%" ) );')"

echo
[ "$fails" -eq 0 ] && echo "All Cloudflare checks passed." || echo "$fails Cloudflare check(s) failed."
exit "$fails"
