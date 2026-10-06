#!/usr/bin/env bash
# Regression checks against the dev site (run ./dev/setup.sh once first).
# Switches modules and the overlapping Wordfence options as needed, then restores everything.
# Exit code = number of failed checks.
set -uo pipefail
cd "$(dirname "$0")"
U=http://localhost:${WP_PORT:-8894}
wp() { ./wp.sh "$@" 2>/dev/null; }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }
fails=0
check() { # <name> <expected> <actual>
	if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi
}

has_wf=$(wp eval 'echo class_exists("wfConfig") ? 1 : 0;')
has_woo=$(wp eval 'echo class_exists("WooCommerce") ? 1 : 0;')
backup=$(wp option get wphouse_settings --format=json || echo '{}')
[ "$has_wf" = 1 ] && wf=$(wp eval 'echo (int) wfConfig::get("loginSec_disableAuthorScan"), (int) wfConfig::get("loginSec_maskLoginErrors");')
restore() {
	wp option update wphouse_settings "$backup" --format=json >/dev/null
	[ "$has_wf" = 1 ] && wp eval "wfConfig::set('loginSec_disableAuthorScan', ${wf:0:1}); wfConfig::set('loginSec_maskLoginErrors', ${wf:1:1});" >/dev/null
	wp wphouse lock >/dev/null
}
trap restore EXIT
echo "WordPress $(wp core version), PHP $(wp eval 'echo PHP_VERSION;'), WooCommerce: $has_woo, Wordfence: $has_wf"

# Our own code paths, not Wordfence's: switch its overlapping options off for the run.
wp eval '
if ( class_exists( "wfConfig" ) ) { wfConfig::set("loginSec_disableAuthorScan", 0); wfConfig::set("loginSec_maskLoginErrors", 0); }
$o = get_option("wphouse_settings", []);
foreach (["hardening","lockdown","watch","plugin_health","tweaks","duplicate","smtp","scripts"] as $m) { $o["modules"][$m] = true; }
$o["modules"]["maintenance"] = false;
$o["hardening"] = ["file_editor"=>true,"user_enumeration"=>true,"login_errors"=>true,"hide_version"=>true,"xmlrpc"=>true,"headers"=>true,"hsts"=>false];
$o["tweaks"] = ["disable_comments"=>true,"disable_search"=>true] + (array) ($o["tweaks"] ?? []);
$o["scripts"] = ["head"=>"<!-- smoke-head -->","body_open"=>"","footer"=>"<!-- smoke-foot -->","skip_admins"=>false];
update_option("wphouse_settings", $o);' >/dev/null

echo "== hardening"
check "xmlrpc.php answers 403"            403 "$(code -X POST -d '<methodCall/>' "$U/xmlrpc.php")"
check "?author=1 redirects home"          301 "$(code "$U/?author=1")"
check "REST users hidden from visitors"   401 "$(code "$U/wp-json/wp/v2/users")"
check "REST users, uppercase route"       401 "$(code "$U/?rest_route=/wp/v2/USERS")"
check "REST posts?author=1 still public"  200 "$(code "$U/wp-json/wp/v2/posts?author=1")"
# WordPress < 7 renders a normal page instead of a 404 for a removed sitemap provider; what matters is no user list.
check "users sitemap not in index"        0   "$(curl -s "$U/wp-sitemap.xml" | grep -c 'wp-sitemap-users')"
check "users sitemap not served"          0   "$(curl -s "$U/wp-sitemap-users-1.xml" | grep -c '<urlset')"
check "nosniff header"                    1   "$(curl -sI "$U/" | grep -ci '^x-content-type-options: nosniff')"
check "no generator tag"                  0   "$(curl -s "$U/" | grep -ci 'name="generator" content="WordPress')"
# Language-independent: the error for an unknown user must equal the error for a wrong password.
login_msg() { curl -s -b "wordpress_test_cookie=WP%20Cookie%20check" --data-urlencode "log=$1" -d "pwd=wrong&testcookie=1" "$U/wp-login.php" | tr '\n' ' ' | grep -o 'id="login_error".\{0,250\}' | sed 's/<[^>]*>//g' | cut -c1-200; }
unknown=$(login_msg nosuchuser); known=$(login_msg admin)
check "login error shown"                 1   "$([ -n "$unknown" ] && echo 1 || echo 0)"
check "same error, unknown vs known user" same "$([ "$unknown" = "$known" ] && echo same || echo "differs: $unknown | $known")"
if [ "$has_woo" = 1 ]; then
	# Real-world setup: Wordfence masking on. It does not cover the WooCommerce form, WPHouse must.
	[ "$has_wf" = 1 ] && wp eval 'wfConfig::set("loginSec_maskLoginErrors", 1);' >/dev/null
	MA=$(wp eval 'echo wc_get_page_permalink("myaccount");')
	woo_login() {
		local jar page nonce
		jar=$(mktemp); page=$(curl -s -c "$jar" -b "$jar" "$MA")
		nonce=$(echo "$page" | grep -o 'name="woocommerce-login-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
		curl -s -c "$jar" -b "$jar" --data-urlencode "username=$1" -d "password=wrong&woocommerce-login-nonce=$nonce&_wp_http_referer=%2F&login=1" "$MA" \
			| tr '\n' ' ' | grep -o 'notice-banner__content">.\{0,250\}\|woocommerce-error.\{0,250\}' | sed 's/<[^>]*>//g' | cut -c1-160
		rm -f "$jar"
	}
	wunknown=$(woo_login nosuchuser); wknown=$(woo_login admin)
	check "Woo login error shown"            1   "$([ -n "$wunknown" ] && echo 1 || echo 0)"
	check "Woo login: same error for both"   same "$([ "$wunknown" = "$wknown" ] && echo same || echo "differs: $wunknown | $wknown")"
	[ "$has_wf" = 1 ] && wp eval 'wfConfig::set("loginSec_maskLoginErrors", 0);' >/dev/null
fi
check "file editor cap removed"           false "$(wp eval 'var_export(user_can(1, "edit_plugins"));')"
check "X-Forwarded-For is not trusted"    203.0.113.5 "$(wp eval '$_SERVER["REMOTE_ADDR"]="203.0.113.5"; $_SERVER["HTTP_X_FORWARDED_FOR"]="1.2.3.4"; echo WPHouse\Core\Net::client_ip();')"

echo "== lockdown"
./wp.sh plugin install hello-dolly >/dev/null 2>&1
check "install blocked while locked"      blocked "$(wp plugin is-installed hello-dolly && echo installed || echo blocked)"
check "blocked install logged"            install_blocked "$(wp wphouse log --limit=1 --format=csv | tail -1 | cut -d, -f2)"
check "install cap removed"               false "$(wp eval 'var_export(user_can(1, "install_plugins"));')"
check "update cap kept"                   true  "$(wp eval 'var_export(user_can(1, "update_plugins"));')"

echo "== tweaks and scripts"
check "search redirects home"             301 "$(code "$U/?s=test")"
check "comment on a post is refused"      403 "$(code -d 'comment_post_ID=1&author=a&email=a@b.test&comment=x' "$U/wp-comments-post.php")"
check "head snippet printed"              1   "$(curl -s "$U/" | grep -c 'smoke-head')"
check "footer snippet printed"            1   "$(curl -s "$U/" | grep -c 'smoke-foot')"

echo "== bots"
# Comments back on (tweaks), a valid From address so lost-password mail goes out, registration open.
wp eval '$o = get_option("wphouse_settings"); $o["modules"]["tweaks"] = false; $o["modules"]["bots"] = true; $o["smtp"]["from_email"] = "wordpress@example.test";
$o["bots"] = ["honeypot"=>true,"turnstile_login"=>true,"turnstile_register"=>true,"turnstile_lostpassword"=>true,"turnstile_comments"=>true,"turnstile_checkout"=>true,"when_unavailable"=>"allow"]; update_option("wphouse_settings", $o);' >/dev/null
reg_before=$(wp option get users_can_register); woo_reg_before=$(wp option get woocommerce_enable_myaccount_registration)
wp option update users_can_register 1 >/dev/null
[ "$has_woo" = 1 ] && wp option update woocommerce_enable_myaccount_registration yes >/dev/null
n=$RANDOM
proof=$(curl -s "$U/wp-login.php?action=register" | grep -o 'data-proof="[^"]*"' | head -1 | sed 's/data-proof="//;s/"//')
check "honeypot printed on registration"  1   "$([ -n "$proof" ] && echo 1 || echo 0)"
check "register without proof refused"    200 "$(code -d "user_login=bot$n&user_email=bot$n@example.test&wphouse_url=&wphouse_proof=" "$U/wp-login.php?action=register")"
check "register with filled trap refused" 200 "$(code -d "user_login=bot$n&user_email=bot$n@example.test&wphouse_url=http://spam.test&wphouse_proof=$proof" "$U/wp-login.php?action=register")"
check "register with proof accepted"      302 "$(code -d "user_login=hp$n&user_email=hp$n@example.test&wphouse_url=&wphouse_proof=$proof" "$U/wp-login.php?action=register")"
check "lost password without proof"       200 "$(code -d "user_login=admin" "$U/wp-login.php?action=lostpassword")"
check "lost password with proof"          302 "$(code -d "user_login=admin&wphouse_url=&wphouse_proof=$proof" "$U/wp-login.php?action=lostpassword")"
check "comment without proof refused"     403 "$(code -d "comment_post_ID=1&author=a&email=a@b.test&comment=bot$n" "$U/wp-comments-post.php")"
check "comment with proof accepted"       302 "$(code -d "comment_post_ID=1&author=a&email=a@b.test&comment=human$n&wphouse_url=&wphouse_proof=$proof" "$U/wp-comments-post.php")"
check "login has no honeypot"             302 "$(code -b "wordpress_test_cookie=WP%20Cookie%20check" -d "log=admin&pwd=admin&testcookie=1" "$U/wp-login.php")"
check "admin password reset unaffected"   true "$(wp eval 'var_export(true === retrieve_password("admin"));')"
if [ "$has_woo" = 1 ]; then
	woo_register() { # <email> <extra fields>
		local jar nonce
		jar=$(mktemp)
		nonce=$(curl -s -c "$jar" -b "$jar" "$MA" | grep -o 'name="woocommerce-register-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
		code -c "$jar" -b "$jar" -d "email=$1&woocommerce-register-nonce=$nonce&_wp_http_referer=%2F&register=1$2" "$MA"
		rm -f "$jar"
	}
	check "Woo register without proof"      200 "$(woo_register "wbot$n@example.test" "")"
	check "Woo register with proof"         302 "$(woo_register "whp$n@example.test" "&wphouse_url=&wphouse_proof=$proof")"
fi

# Cloudflare test keys: this site key always issues XXXX.DUMMY.TOKEN.XXXX, the 1x secret accepts it, the 2x secret rejects all.
T=XXXX.DUMMY.TOKEN.XXXX
# opcache rereads wp-config.php at most every 2 s.
wpconf() { wp config "$@" >/dev/null; sleep 3; }
wp config set WPHOUSE_TURNSTILE_SITE_KEY 1x00000000000000000000AA >/dev/null
wpconf set WPHOUSE_TURNSTILE_SECRET_KEY 1x0000000000000000000000000000000AA
wlogin() { code -b "wordpress_test_cookie=WP%20Cookie%20check" -d "log=admin&pwd=admin&testcookie=1$1" "$U/wp-login.php"; }
check "widget on wp-login"                1   "$(curl -s "$U/wp-login.php" | grep -c 'class="wphouse-turnstile"')"
check "login without token refused"       200 "$(wlogin "")"
check "login with token accepted"         302 "$(wlogin "&cf-turnstile-response=$T")"
# The accepted comment above would trip the 15-second flood check.
wp comment delete "$(wp comment list --search="human$n" --field=comment_ID)" --force >/dev/null
check "comment without token refused"     403 "$(code -d "comment_post_ID=1&author=a&email=a@b.test&comment=t$n&wphouse_url=&wphouse_proof=$proof" "$U/wp-comments-post.php")"
if [ "$has_woo" = 1 ]; then
	SA="$U/wp-json/wc/store/v1"
	# A valid body, so that WooCommerce's own parameter check (400) does not answer first.
	B='{"billing_address":{"first_name":"A","last_name":"B","address_1":"X 1","city":"Y","postcode":"00-001","country":"PL","email":"a@b.test"},"payment_method":"cod"}'
	sapi() { code -H 'Content-Type: application/json' -d "$B" "$@"; }
	check "Store API checkout, no token"    403 "$(sapi -X POST "$SA/checkout")"
	check "Store API, calc_totals bypass"   403 "$(sapi -X POST "$SA/checkout?__experimental_calc_totals=1")"
	check "Store API, uppercase route"      403 "$(sapi -X POST "$U/?rest_route=/wc/store/v1/CHECKOUT")"
	check "Store API, method override"      403 "$(sapi -X GET "$SA/checkout?_method=POST")"
	check "Store API, override header"      403 "$(sapi -X GET -H 'X-HTTP-Method-Override: POST' "$SA/checkout")"
	check "Store API, order-pay route"      403 "$(sapi -X POST "$SA/checkout/1")"
	# Built outside $( ): macOS bash 3.2 mangles escaped quotes inside a quoted command substitution.
	BATCH="{\"requests\":[{\"path\":\"/wc/store/v1/checkout\",\"method\":\"POST\",\"body\":$B}]}"
	check "Store API, inside a batch"       403 "$(curl -s -X POST -H 'Content-Type: application/json' -d "$BATCH" "$SA/batch" | grep -o '"status":[0-9]*' | head -1 | cut -d: -f2)"
	# 401 is WooCommerce asking for its nonce: our check let the request through.
	check "Store API checkout with token"   401 "$(sapi -X POST -H "X-WPHouse-Turnstile: $T" "$SA/checkout")"
	check "Store API update (PUT) untouched" 401 "$(sapi -X PUT "$SA/checkout?__experimental_calc_totals=1")"
fi
wpconf set WPHOUSE_TURNSTILE_SECRET_KEY 2x0000000000000000000000000000000AA
check "token rejected by Cloudflare"      200 "$(wlogin "&cf-turnstile-response=$T")"
# Cloudflare unreachable from the server: the setting decides.
wpconf set WPHOUSE_TURNSTILE_SECRET_KEY 1x0000000000000000000000000000000AA
docker compose exec -T wordpress sh -c 'mkdir -p wp-content/mu-plugins && echo "<?php add_filter(\"pre_http_request\", fn(\$r, \$a, \$url) => str_contains(\$url, \"challenges.cloudflare.com\") ? new WP_Error(\"http_request_failed\", \"down\") : \$r, 10, 3);" > wp-content/mu-plugins/wphouse-smoke-cf-down.php' >/dev/null 2>&1
check "Cloudflare down, allow: login"     302 "$(wlogin "&cf-turnstile-response=$T")"
check "Cloudflare down, empty token"      200 "$(wlogin "")"
wp eval '$o = get_option("wphouse_settings"); $o["bots"]["when_unavailable"] = "block"; update_option("wphouse_settings", $o);' >/dev/null
check "Cloudflare down, block: login"     200 "$(wlogin "&cf-turnstile-response=$T")"
docker compose exec -T wordpress rm -f wp-content/mu-plugins/wphouse-smoke-cf-down.php >/dev/null 2>&1
if [ "$has_wf" = 1 ]; then
	ls_before=$(wp eval '$s = \WordfenceLS\Controller_Settings::shared(); echo wp_json_encode(array_map(fn($k) => $s->get($k), ["enable-auth-captcha"=>"enable-auth-captcha","recaptcha-site-key"=>"recaptcha-site-key","recaptcha-secret"=>"recaptcha-secret","enable-woocommerce-integration"=>"enable-woocommerce-integration"]));')
	wp eval '\WordfenceLS\Controller_Settings::shared()->set_multiple(["enable-auth-captcha"=>true,"recaptcha-site-key"=>"smoke","recaptcha-secret"=>"smoke","enable-woocommerce-integration"=>true], true);' >/dev/null
	check "Wordfence captcha covers login"  1   "$(wp wphouse status | grep -c 'turnstile_login: Wordfence Login Security')"
	wp eval "\WordfenceLS\Controller_Settings::shared()->set_multiple(json_decode('$ls_before', true), true);" >/dev/null
fi
wp config delete WPHOUSE_TURNSTILE_SITE_KEY >/dev/null
wp config delete WPHOUSE_TURNSTILE_SECRET_KEY >/dev/null
wp option update users_can_register "$reg_before" >/dev/null
[ "$has_woo" = 1 ] && wp option update woocommerce_enable_myaccount_registration "$woo_reg_before" >/dev/null
for u in "hp$n" "whp$n"; do wp user delete "$u" --yes >/dev/null; done
wp user list --field=user_email | grep -q "whp$n@example.test" && wp user delete "$(wp user get "whp$n@example.test" --field=ID)" --yes >/dev/null
# The section edited wp-config.php and registration: keep the change alerts of the dev site quiet.
wp wphouse watch accept >/dev/null

echo "== maintenance"
wp eval '$o = get_option("wphouse_settings"); $o["modules"]["maintenance"] = true; update_option("wphouse_settings", $o);' >/dev/null
check "visitors get 503"                  503 "$(code "$U/")"
check "JSON Accept header gets 503"       503 "$(code -H 'Accept: application/json' "$U/")"
check "empty ?wc-api= gets 503"           503 "$(code "$U/?wc-api=")"
[ "$has_woo" = 1 ] && check "Store API still answers" 200 "$(code "$U/wp-json/wc/store/v1/cart")"
# 400 means WooCommerce answered the callback itself (nothing hooked) before maintenance could: gateways still work.
[ "$has_woo" = 1 ] && check "?wc-api= callbacks reach Woo" 400 "$(code "$U/?wc-api=wphouse_smoke")"
check "wp-login still answers"            200 "$(code "$U/wp-login.php")"
check "wp-cron still answers"             200 "$(code "$U/wp-cron.php")"

echo "== Cloudflare proxy (visitor address)"
cf=$(wp eval '
$o = get_option( "wphouse_settings" ); $orig = $o["general"]["proxy"] ?? "";
$set = function ( $v ) use ( &$o ) { $o["general"]["proxy"] = $v; update_option( "wphouse_settings", $o ); };
$set( "cloudflare" ); $_SERVER["HTTP_CF_CONNECTING_IP"] = "203.0.113.9";
$_SERVER["REMOTE_ADDR"] = "173.245.48.5"; echo WPHouse\Core\Net::client_ip(), " ";
$_SERVER["REMOTE_ADDR"] = "2606:4700::1"; echo WPHouse\Core\Net::client_ip(), " ";
$_SERVER["REMOTE_ADDR"] = "198.51.100.20"; echo WPHouse\Core\Net::client_ip(), " ";
$set( "" ); $_SERVER["REMOTE_ADDR"] = "173.245.48.5"; echo WPHouse\Core\Net::client_ip(), " ", WPHouse\Core\Net::knows_visitor_ip() ? "known" : "unknown";
$set( $orig );')
check "Cloudflare on: visitor from CF-Connecting-IP"        203.0.113.9   "$(echo "$cf" | cut -d" " -f1)"
check "Cloudflare on: IPv6 edge too"                        203.0.113.9   "$(echo "$cf" | cut -d" " -f2)"
check "Cloudflare on: direct hit ignores a forged header"   198.51.100.20 "$(echo "$cf" | cut -d" " -f3)"
check "Cloudflare off: the header is not believed"          173.245.48.5  "$(echo "$cf" | cut -d" " -f4)"
check "Cloudflare off: the visitor address counts as unknown" unknown    "$(echo "$cf" | cut -d" " -f5)"

echo "== login limits (lockouts, device cookies, REST: ./dev/ols/login-limits-test.sh)"
[ "$has_wf" = 1 ] && check "stands down while Wordfence brute force protection is on" "yes no" "$(wp wphouse status | awk '$1 == "login_limits" { print $2, $3 }')"

echo "== integrations"
check "Wordfence and WooCommerce detected"   "wordfence,woocommerce" "$(wp eval 'echo implode( ",", array_keys( WPHouse\Core\Integrations::detected() ) );')"

echo "== vulnerability alerts (signed data and matching: ./dev/advisory-test.sh)"
[ "$has_wf" = 1 ] && check "stands down while Wordfence is active" "yes no" "$(wp wphouse status | awk '$1 == "vulnerabilities" { print $2, $3 }')"

echo
[ "$fails" -eq 0 ] && echo "All checks passed." || echo "$fails check(s) failed."
exit "$fails"
