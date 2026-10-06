#!/usr/bin/env bash
# Acceptance tests for the LiteSpeed page cache module on the OpenLiteSpeed site (./dev/ols/setup.sh first).
# A page counts as served from cache when LiteSpeed answers "X-LiteSpeed-Cache: hit".
set -uo pipefail
cd "$(dirname "$0")"
U=http://localhost:${OLS_PORT:-8896}
T=../../build/olstest
mkdir -p "$T"
wp() { ./wp.sh "$@" 2>/dev/null; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
# cache <curl args...>: prints hit, miss or "-" (not cacheable, no header).
cache() { curl -s -o /dev/null -D - "$@" | tr -d '\r' | awk 'tolower($1) == "x-litespeed-cache:" { v = $2 } END { print (v == "" ? "-" : v) }'; }
twice() { cache "$@" >/dev/null; cache "$@"; }
purge_now() { curl -s -o /dev/null "$U/wp-admin/admin-ajax.php"; } # sends a queued purge (wp-cron.php answers before WordPress loads)

wp wphouse module enable litespeed >/dev/null
wp option update woocommerce_default_customer_address base >/dev/null
product=$(wp wc product list --user=admin --field=id --per_page=1)
[ -n "$product" ] || product=$(wp wc product create --user=admin --name="Cache test" --regular_price=10 --porcelain)
product_url=$(wp eval "echo get_permalink( $product );")
cart=$(wp option get woocommerce_cart_page_id); checkout=$(wp option get woocommerce_checkout_page_id); account=$(wp option get woocommerce_myaccount_page_id)
wp wphouse cache purge >/dev/null; purge_now

echo "== anonymous visitors"
check "home: first view is a miss"          miss "$(cache "$U/")"
check "home: second view comes from cache"  hit  "$(cache "$U/")"
check "product page cached"                 hit  "$(twice "$product_url")"
check "cart never cached"                   -    "$(twice "$U/?page_id=$cart")"
check "checkout never cached"               -    "$(twice "$U/?page_id=$checkout")"
check "my account never cached"             -    "$(twice "$U/?page_id=$account")"
check "404 never cached"                    -    "$(twice "$U/?p=999999")"
check "search never cached"                 -    "$(twice "$U/?s=test")"
check "POST never cached"                   -    "$(twice -X POST -d x=1 "$U/")"

echo "== personal visitors reach WordPress"
check "WordPress login cookie alone still gets the cached page (why the vary cookie exists)" hit "$(cache -H 'Cookie: wordpress_logged_in_x=1' "$U/")"
J=$T/admin.jar; rm -f "$J"
curl -s -o /dev/null -c "$J" -b "wordpress_test_cookie=WP%20Cookie%20check" -d "log=admin&pwd=admin&testcookie=1" "$U/wp-login.php"
check "login sets the vary cookie"          1 "$(grep -c '_lscache_vary' "$J")"
check "logged-in: home never from cache"    - "$(twice -b "$J" "$U/")"
logout=$(curl -s -b "$J" "$U/wp-admin/" | grep -o 'wp-login.php?action=logout[^"]*' | head -1 | sed 's/&amp;/\&/g')
curl -s -o /dev/null -b "$J" -c "$J" "$U/$logout"
check "logout clears the vary cookie"       0 "$(grep -v '^#' "$J" | grep -c '_lscache_vary')"

C=$T/cart.jar; rm -f "$C"
curl -s -o /dev/null -c "$C" "$U/?add-to-cart=$product"
check "add to cart sets the vary cookie"    1 "$(grep -c '_lscache_vary' "$C")"
check "with a cart: home never from cache"  - "$(twice -b "$C" "$U/")"
check "a visitor without a cart still gets the cache" hit "$(cache "$U/")"

echo "== WooCommerce Store API"
# WooCommerce 11 hands the Store API nonce out in API response headers, never in page HTML, so a cached
# page cannot carry one visitor's nonce to another. Re-check this when WooCommerce changes.
check "cached product page holds no Store API nonce" 0 "$(curl -s "$product_url" | grep -c -E 'storeApiNonce|wcStoreApiNonce')"
check "Store API hands out the nonce in a header"    1 "$(curl -s -D - -o /dev/null "$U/?rest_route=/wc/store/v1/cart" | grep -c -i '^nonce:')"
check "Store API answers are never cached"           - "$(twice "$U/?rest_route=/wc/store/v1/cart")"

echo "== purges"
check "home cached before a change"         hit  "$(twice "$U/")"
pass=$(wp user application-password create admin "cache-test-$RANDOM" --porcelain)
check "post updated through the REST API"  200 "$(curl -s -o /dev/null -w '%{http_code}' -u "admin:$pass" -X POST -d "title=Changed $RANDOM" "$U/?rest_route=/wp/v2/posts/1")"
check "editing a post through the REST API clears the cache" miss "$(cache "$U/")"
cache "$U/" >/dev/null
wp post update 1 --post_title="Changed from CLI $RANDOM" >/dev/null
check "a CLI change waits for the next request..." hit "$(cache "$U/")"
purge_now
check "...and is applied by it"             miss "$(cache "$U/")"

echo "== maintenance mode"
cache "$U/" >/dev/null
wp wphouse module enable maintenance >/dev/null; purge_now
check "maintenance page not served from cache" 503 "$(curl -s -o /dev/null -w '%{http_code}' "$U/")"
check "503 never cached"                    - "$(twice "$U/")"
wp wphouse module disable maintenance >/dev/null; purge_now
check "site back after maintenance"         200 "$(curl -s -o /dev/null -w '%{http_code}' "$U/")"

echo
[ "$fails" -eq 0 ] && echo "All cache checks passed." || echo "$fails cache check(s) failed."
exit "$fails"
