#!/usr/bin/env bash
# Browser checks for bot protection (assets/bots.js): wp-login, registration, a comment, and the
# block and classic checkout with Cloudflare's always-pass test keys. Needs WooCommerce and Playwright:
#   PLAYWRIGHT_PATH=/path/to/node_modules/playwright ./dev/bots-e2e.sh
# Sets up a product, cash on delivery and a classic checkout page, and puts everything back after.
# Test orders stay on the dev site.
set -euo pipefail
cd "$(dirname "$0")"
U=http://localhost:${WP_PORT:-8894}
wp() { ./wp.sh "$@" 2>/dev/null; }

[ "$(wp eval 'echo class_exists("WooCommerce") ? 1 : 0;')" = 1 ] || { echo "WooCommerce is not active."; exit 1; }
run=$RANDOM
settings=$(wp option get shouse_settings --format=json || echo '{}')
reg=$(wp option get users_can_register)
soon=$(wp option get woocommerce_coming_soon || echo no)
cod=$(wp option get woocommerce_cod_settings --format=json || echo '{}')
product=$(wp wc product create --name="SafeHouse e2e $run" --regular_price=10 --user=admin --porcelain)
page=$(wp post create --post_type=page --post_title="SafeHouse e2e checkout $run" --post_name="shouse-e2e-checkout-$run" --post_status=publish --post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' --porcelain)

cleanup() {
	wp option update shouse_settings "$settings" --format=json >/dev/null
	wp option update users_can_register "$reg" >/dev/null
	wp option update woocommerce_coming_soon "$soon" >/dev/null
	wp option update woocommerce_cod_settings "$cod" --format=json >/dev/null
	wp config delete SHOUSE_TURNSTILE_SITE_KEY >/dev/null
	wp config delete SHOUSE_TURNSTILE_SECRET_KEY >/dev/null
	wp post delete "$page" --force >/dev/null
	wp wc product delete "$product" --force=true --user=admin >/dev/null
	wp user get "e2e$run" --field=ID >/dev/null && wp user delete "e2e$run" --yes >/dev/null
	for id in $(wp comment list --search="e2e comment $run" --field=comment_ID); do wp comment delete "$id" --force >/dev/null; done
	wp shouse watch accept >/dev/null
}
trap cleanup EXIT

wp eval '$o = get_option("shouse_settings", []); $o["modules"]["bots"] = true; $o["modules"]["tweaks"] = false; $o["modules"]["maintenance"] = false;
$o["bots"] = ["honeypot"=>true,"turnstile_login"=>true,"turnstile_register"=>true,"turnstile_lostpassword"=>true,"turnstile_comments"=>true,"turnstile_checkout"=>true,"when_unavailable"=>"allow"]; update_option("shouse_settings", $o);' >/dev/null
wp option update users_can_register 1 >/dev/null
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json >/dev/null
wp config set SHOUSE_TURNSTILE_SITE_KEY 1x00000000000000000000AA >/dev/null
wp config set SHOUSE_TURNSTILE_SECRET_KEY 1x0000000000000000000000000000000AA >/dev/null
sleep 3 # opcache rereads wp-config.php at most every 2 s.

U="$U" PRODUCT_ID="$product" CLASSIC_PATH="/shouse-e2e-checkout-$run/" RUN_ID="$run" OUT="../build/e2e" node bots-e2e.mjs
