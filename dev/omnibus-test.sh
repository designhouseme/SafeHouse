#!/usr/bin/env bash
# Omnibus price history on the dev site (./dev/setup.sh first; needs WooCommerce).
# Builds price histories with backdated rows, then checks the figure shown and that pages never write.
# Exit code = number of failed checks.
set -uo pipefail
cd "$(dirname "$0")"
wp() { ./wp.sh "$@" 2>/dev/null; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
php() { wp eval "use SafeHouse\Modules\Omnibus; global \$wpdb; \$t = Omnibus::table(); $1"; }

backup=$(wp option get shouse_settings --format=json || echo '{}')
soon=$(wp option get woocommerce_coming_soon || echo no)
made=()
cleanup() {
	[ ${#made[@]} -gt 0 ] && php "foreach ([$(IFS=,; echo "${made[*]}")] as \$id) { \$p = wc_get_product(\$id); \$p && \$p->delete(true); }" >/dev/null
	wp option update shouse_settings "$backup" --format=json >/dev/null
	wp option update woocommerce_coming_soon "$soon" >/dev/null
}
trap cleanup EXIT

module() { wp shouse module "$1" omnibus >/dev/null; }
simple() { # regular [sale]; sets $id (no subshell, so made keeps it for the cleanup)
	id=$(php "\$p = new WC_Product_Simple(); \$p->set_name('Omnibus test'); \$p->set_status('publish'); \$p->set_regular_price('$1'); \$p->set_sale_price('${2:-}'); echo \$p->save();")
	made+=("$id")
}
prices() { php "\$p = wc_get_product($1); \$p->set_regular_price('$2'); \$p->set_sale_price('${3:-}'); \$p->save();" >/dev/null; }
rows() { php "echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id = %d', \$t, $1));"; }
all_rows() { php "echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM %i', \$t));"; }
trail() { php "echo implode(' ', array_map(fn(\$r) => (float) \$r['price'], Omnibus::history($1)));"; }
history() { # id "days:price days:price ..." oldest first; replaces the rows
	php "\$wpdb->delete(\$t, ['product_id' => $1]); foreach (explode(' ', '$2') as \$e) { [\$d, \$v] = explode(':', \$e); \$wpdb->insert(\$t, ['product_id' => $1, 'price' => \$v, 'recorded_at' => gmdate('Y-m-d H:i:s', time() - \$d * DAY_IN_SECONDS)]); }" >/dev/null
}
age() { php "\$wpdb->query(\$wpdb->prepare('UPDATE %i SET recorded_at = %s WHERE product_id = %d ORDER BY id DESC LIMIT 1', \$t, gmdate('Y-m-d H:i:s', time() - $2 * DAY_IN_SECONDS), $1));" >/dev/null; }
lowest() { php "\$r = Omnibus::lowest(wc_get_product($1)); echo \$r ? (float) \$r[0] . ' ' . \$r[1] : 'none';"; }
page() { curl -s "$(php "echo get_permalink($1);")"; }
first_line() { # text of the first lowest-price line on the product page ("" when there is none)
	page "$1" | grep -o '<small class="shouse-omnibus"[^>]*>.*</small>' | head -1 | sed 's#</small>.*##; s/<[^>]*>//g; s/&[^;]*;//g'
}
shown() { first_line "$1" | sed 's/.*: //' | tr -cd '0-9'; } # the amount, digits only

[ "$(wp eval 'echo class_exists("WooCommerce") ? 1 : 0;')" = 1 ] || { echo "WooCommerce is not active"; exit 1; }
wp option update woocommerce_coming_soon no >/dev/null # anonymous visitors must see the shop
php '$o = get_option("shouse_settings", []); $o["modules"]["omnibus"] = true; $o["omnibus"] = ["display" => true, "label" => ""]; update_option("shouse_settings", $o);' >/dev/null

echo "== the calculation"
calc() { php "\$n = time(); \$d = DAY_IN_SECONDS; \$r = Omnibus::lowest_before(array_map(fn(\$e) => ['time' => \$n - \$e[0] * \$d, 'price' => (float) \$e[1]], $1), $2, \$n); echo null === \$r ? 'null' : \$r;"; }
check "no history"                                  null "$(calc '[]' 90)"
check "only the current price on record"            null "$(calc '[[5,90]]' 90)"
check "price in effect when the window opened"      100  "$(calc '[[60,80],[40,100],[0,90]]' 90)"
check "older prices do not count"                   100  "$(calc '[[60,80],[40,100],[10,90]]' 90)"
check "earlier sale inside the window counts"       70   "$(calc '[[60,80],[35,100],[20,70],[15,100],[0,90]]' 90)"
check "a further reduction looks back from itself"  90   "$(calc '[[50,100],[10,90],[0,80]]' 80)"
check "unseen change: look back from now"           90   "$(calc '[[50,100],[5,90]]' 80)"
check "a raise right before the sale is not enough" 100  "$(calc '[[25,100],[10,120],[0,90]]' 90)"

echo "== recording"
simple 100
check "new product: one row"                        "100" "$(trail "$id")"
prices "$id" 100
check "saving without a change adds nothing"        "100" "$(trail "$id")"
prices "$id" 100 80
check "reduction recorded"                          "100 80" "$(trail "$id")"
php "update_post_meta($id, '_price', '75');" >/dev/null
check "a direct _price write is recorded"           "100 80 75" "$(trail "$id")"

echo "== the first change after recording starts keeps the outgoing price"
module disable
simple 100
module enable
php "update_option('shouse_omnibus_since', time() - 20 * DAY_IN_SECONDS);" >/dev/null
check "nothing recorded before the first change"    0 "$(rows "$id")"
prices "$id" 120
check "outgoing price kept, then the new one"       "100 120" "$(trail "$id")"
age "$id" 10
prices "$id" 120 90
check "raise then sale shows the price before the raise" "100 history" "$(lowest "$id")"
check "product page shows it"                       10000 "$(shown "$id")"

echo "== already reduced when recording starts"
module disable
simple 100 80
module enable
check "regular price stands in"                     "100 regular" "$(lowest "$id")"
check "product page shows the regular price"        10000 "$(shown "$id")"

echo "== windows with real saves"
simple 100
history "$id" "60:80 40:100"
prices "$id" 100 90
check "price in effect when the window opened"      "100 history" "$(lowest "$id")"
simple 100
history "$id" "60:80 35:100 20:70 15:100"
prices "$id" 100 90
check "earlier sale inside the window"              "70 history" "$(lowest "$id")"
simple 100
history "$id" "50:100"
prices "$id" 100 90
age "$id" 10
prices "$id" 100 80
check "further reduction"                           "90 history" "$(lowest "$id")"

echo "== scheduled sale"
simple 100
php "\$p = wc_get_product($id); \$p->set_sale_price('85'); \$p->set_date_on_sale_from(time() + DAY_IN_SECONDS); \$p->save();" >/dev/null
check "future sale does not change the price"       "100" "$(trail "$id")"
php "update_post_meta($id, '_sale_price_dates_from', time() - 60); wc_scheduled_sales();" >/dev/null
check "sale start recorded by the cron"             "100 85" "$(trail "$id")"

echo "== variations"
var=$(php "\$p = new WC_Product_Variable(); \$p->set_name('Omnibus variable'); \$p->set_status('publish');
\$a = new WC_Product_Attribute(); \$a->set_name('Size'); \$a->set_options(['S', 'M']); \$a->set_visible(true); \$a->set_variation(true); \$p->set_attributes([\$a]); \$id = \$p->save();
foreach (['s' => ['100', '80'], 'm' => ['120', '']] as \$size => \$pr) { \$v = new WC_Product_Variation(); \$v->set_parent_id(\$id); \$v->set_attributes(['size' => strtoupper(\$size)]); \$v->set_regular_price(\$pr[0]); \$v->set_sale_price(\$pr[1]); \$v->set_status('publish'); \$v->save(); }
WC_Product_Variable::sync(\$id); echo \$id;")
made+=("$var")
lines() { php "echo substr_count(wc_get_product($1)->get_price_html(), 'shouse-omnibus');"; }
check "parent price is not recorded"                0 "$(rows "$var")"
check "no line next to the price range"             0 "$(lines "$var")"
check "line on the reduced variation"               1 "$(lines "$(php "echo wc_get_product($var)->get_children()[0];")")"
check "no line on the full-price variation"         0 "$(lines "$(php "echo wc_get_product($var)->get_children()[1];")")"
check "product page carries it for the variation"   1 "$(page "$var" | grep -o 'data-product_variations="[^"]*"' | grep -o 'shouse-omnibus' | wc -l | tr -d ' ')"
same=$(php "\$p = new WC_Product_Variable(); \$p->set_name('Omnibus same'); \$p->set_status('publish');
\$a = new WC_Product_Attribute(); \$a->set_name('Size'); \$a->set_options(['S', 'M']); \$a->set_visible(true); \$a->set_variation(true); \$p->set_attributes([\$a]); \$id = \$p->save();
foreach (['S', 'M'] as \$size) { \$v = new WC_Product_Variation(); \$v->set_parent_id(\$id); \$v->set_attributes(['size' => \$size]); \$v->set_regular_price('100'); \$v->set_sale_price('80'); \$v->set_status('publish'); \$v->save(); }
WC_Product_Variable::sync(\$id); echo \$id;")
made+=("$same")
check "same-price variations share one line"        1 "$(lines "$same")"

echo "== pages never write"
before=$(all_rows)
for p in "${made[@]}"; do page "$p" >/dev/null; curl -s -o /dev/null "http://localhost:${WP_PORT:-8894}/?rest_route=/wc/store/v1/products/$p"; done
curl -s -o /dev/null "http://localhost:${WP_PORT:-8894}/?post_type=product"
check "row count unchanged after page views"        "$before" "$(all_rows)"

echo "== settings"
id=${made[1]} # the product reduced after a raise
php '$o = get_option("shouse_settings"); $o["omnibus"]["label"] = "Was at least %s lately"; update_option("shouse_settings", $o);' >/dev/null
check "custom wording"                              1 "$(first_line "$id" | grep -c '^Was at least [0-9.,]* lately$')"
php '$o = get_option("shouse_settings"); $o["omnibus"]["display"] = false; update_option("shouse_settings", $o);' >/dev/null
check "display off: no line"                        "" "$(shown "$id")"
prices "$id" 130
check "display off: still recording"                130 "$(trail "$id" | awk '{print $NF}')"

echo "== upkeep"
simple 100
history "$id" "500:100 400:90 100:80"
php '(new Omnibus())->purge();' >/dev/null
check "purge keeps the last price before the cutoff" "90 80" "$(trail "$id")"
php "wp_delete_post($id, true);" >/dev/null
check "deleted product: rows gone"                  0 "$(rows "$id")"

echo
[ "$fails" -eq 0 ] && echo "All Omnibus checks passed." || echo "$fails check(s) failed."
exit "$fails"
