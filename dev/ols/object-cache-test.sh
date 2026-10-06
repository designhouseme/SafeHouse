#!/usr/bin/env bash
# End-to-end tests of the SafeHouse Redis object cache on the OpenLiteSpeed site (./dev/ols/setup.sh first).
# WordPress core's own object cache tests run separately: ./dev/ols/object-cache-core-test.sh.
set -uo pipefail
cd "$(dirname "$0")"
U=http://localhost:${OLS_PORT:-8896}
WPC=/var/www/vhosts/localhost/html/wp-content
T=../../build/olstest
mkdir -p "$T"
wp() { ./wp.sh "$@" 2>/dev/null; }
rc() { docker compose exec -T redis redis-cli "$@"; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
fresh() { echo "$U/?oc=$RANDOM$RANDOM"; } # a new URL every time: never a page-cache hit, always WordPress
title() { curl -s "$(fresh)" | grep -o '<title>[^<]*' | head -1 | sed 's/<title>//'; }
keys() { rc --scan | grep -c -F "$1"; } # fixed-string match: WP_CACHE_KEY_SALT may hold * ? [ characters

wp shouse module disable litespeed >/dev/null
wp shouse object-cache disable >/dev/null

echo "== enable"
check "enable succeeds"                      1     "$(wp shouse object-cache enable | grep -c 'Object cache enabled')"
check "drop-in is the SafeHouse loader"        ours  "$(wp eval 'echo SafeHouse\Core\ObjectCache::dropin_state();')"
check "WP-CLI runs on Redis"                 connected "$(wp shouse object-cache status --format=csv | grep '^redis,' | cut -d, -f2)"
check "site answers"                         200   "$(curl -s -o /dev/null -w '%{http_code}' "$(fresh)")"
prefix=$(wp eval 'echo shouse_object_cache_prefix();')
check "site data is in Redis"                yes   "$([ "$(keys "$prefix")" -gt 10 ] && echo yes || echo no)"
name="Object cache $RANDOM"
wp option update blogname "$name" >/dev/null
check "a change made in WP-CLI shows on the site" "$name" "$(title | cut -c1-${#name})"

echo "== signed values"
rc SET "${prefix}1:default:shouse-forged" 'O:8:"stdClass":1:{s:1:"x";i:1;}' >/dev/null
check "a value planted in Redis is a miss, never unserialized" "bool(false)" "$(wp eval '$f = null; wp_cache_get( "shouse-forged", "default", false, $f ); var_dump( $f );' | tr -d '\n')"
wp eval 'wp_cache_set( "shouse-a", "hello" );' >/dev/null
rc COPY "${prefix}1:default:shouse-a" "${prefix}1:default:shouse-b" >/dev/null
check "a signed value copied to another key is a miss" "bool(false)" "$(wp eval 'var_dump( wp_cache_get( "shouse-b" ) );' | tr -d '\n')"
check "the original key still reads"         hello "$(wp eval 'echo wp_cache_get( "shouse-a" );')"

echo "== groups (WordPress's own tests skip this for external caches)"
check "flushing a group clears it in Redis and keeps the others" "miss|v" "$(wp eval 'wp_cache_set( "k", "v", "grp-a" ); wp_cache_set( "k", "v", "grp-b" ); wp_cache_flush_group( "grp-a" ); wp_cache_flush_runtime(); echo false === wp_cache_get( "k", "grp-a" ) ? "miss" : "hit", "|", wp_cache_get( "k", "grp-b" );')"
check "non-persistent groups never reach Redis" 0 "$(wp eval 'wp_cache_add_non_persistent_groups( "np-test" ); wp_cache_set( "k", "v", "np-test" );' >/dev/null; keys "${prefix}1:np-test:")"
check "code checking for WP_Object_Cache still works" yes "$(wp eval 'global $wp_object_cache; echo $wp_object_cache instanceof WP_Object_Cache ? "yes" : "no";')"

echo "== WooCommerce order through the Store API"
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","enable_for_virtual":"yes"}' --format=json >/dev/null
product=$(wp wc product create --user=admin --name="Object cache test" --regular_price=10 --virtual=true --manage_stock=true --stock_quantity=5 --porcelain)
H=$T/headers.txt
curl -s -o /dev/null -D "$H" "$U/?rest_route=/wc/store/v1/cart"
nonce=$(tr -d '\r' < "$H" | awk -F': ' 'tolower($1) == "nonce" { print $2 }')
token=$(tr -d '\r' < "$H" | awk -F': ' 'tolower($1) == "cart-token" { print $2 }')
api() { curl -s -H "Nonce: $nonce" -H "Cart-Token: $token" -H 'Content-Type: application/json' "$@"; }
added=$(api -w '\n%{http_code}' -d "{\"id\":$product,\"quantity\":1}" "$U/?rest_route=/wc/store/v1/cart/add-item")
check "add to cart"                          201 "$(echo "$added" | tail -1)"
[ "$(echo "$added" | tail -1)" = 201 ] || echo "      $(echo "$added" | head -1 | cut -c1-300)"
check "the cart remembers it"                1   "$(api "$U/?rest_route=/wc/store/v1/cart" | grep -o '"items_count":[0-9]*' | cut -d: -f2)"
address='"first_name":"Test","last_name":"Buyer","address_1":"Testowa 1","city":"Warszawa","postcode":"00-001","country":"PL","phone":"500000000"'
order=$(api -d "{\"billing_address\":{$address,\"email\":\"buyer@example.test\"},\"shipping_address\":{$address},\"payment_method\":\"cod\"}" "$U/?rest_route=/wc/store/v1/checkout" | grep -o '"order_id":[0-9]*' | cut -d: -f2)
check "checkout creates an order"            yes        "$([ -n "$order" ] && echo yes || echo no)"
check "the order is processing"              processing "$(wp eval "\$o = wc_get_order( (int) '$order' ); echo \$o ? \$o->get_status() : 'none';")"
check "stock went down in WP-CLI's view"     4          "$(wp eval "echo wc_get_product( $product )->get_stock_quantity();")"
check "and in the site's view"               4          "$(curl -s "$U/?rest_route=/wc/store/v1/products/$product&oc=$RANDOM" | grep -o '"maximum":[0-9]*' | head -1 | cut -d: -f2)"

echo "== Redis outage"
wp option update blogname "Before outage" >/dev/null
title >/dev/null
docker compose stop redis >/dev/null 2>&1
check "site works without Redis"             200 "$(curl -s -o /dev/null -w '%{http_code}' "$(fresh)")"
wp option update blogname "During outage" >/dev/null
check "the outage is recorded"               1   "$(wp eval 'echo get_option( "shouse_object_cache_stale" ) ? 1 : 0;')"
docker compose start redis >/dev/null 2>&1
until rc PING 2>/dev/null | grep -q PONG; do sleep 1; done
check "Redis kept its old data (append-only file)" yes "$([ "$(keys "$prefix")" -gt 0 ] && echo yes || echo no)"
check "after the outage the site shows the change, not Redis's old copy" "During outage" "$(title | cut -c1-13)"
check "the record is cleared"                0   "$(wp eval 'echo get_option( "shouse_object_cache_stale" ) ? 1 : 0;')"

echo "== safe mode"
docker compose exec -T ols touch "$WPC/shouse-safe-mode"
check "safe mode switches the cache off"     off "$(wp eval 'echo wp_using_ext_object_cache() ? "on" : "off";')"
wp option update blogname "During safe mode" >/dev/null
docker compose exec -T ols rm -f "$WPC/shouse-safe-mode"
check "changes made in safe mode show afterwards" "During safe mode" "$(title | cut -c1-16)"

echo "== deactivate and disable"
wp plugin deactivate shouse >/dev/null
check "deactivating SafeHouse removes the drop-in" none "$(docker compose exec -T ols sh -c "test -f $WPC/object-cache.php && echo ours || echo none" | tr -d '\r')"
wp plugin activate shouse >/dev/null
wp shouse object-cache enable >/dev/null
wp shouse object-cache disable >/dev/null
check "disable removes the drop-in"          none "$(wp eval 'echo SafeHouse\Core\ObjectCache::dropin_state();')"
check "and this site's keys"                 0    "$(keys "$prefix")"
check "site answers on WordPress's own cache" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$(fresh)")"

echo
[ "$fails" -eq 0 ] && echo "All object cache checks passed." || echo "$fails object cache check(s) failed."
exit "$fails"
