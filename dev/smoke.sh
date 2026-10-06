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

echo "== integrations"
check "Wordfence and WooCommerce detected"   "wordfence,woocommerce" "$(wp eval 'echo implode( ",", array_keys( WPHouse\Core\Integrations::detected() ) );')"

echo "== vulnerability alerts (signed data and matching: ./dev/advisory-test.sh)"
[ "$has_wf" = 1 ] && check "stands down while Wordfence is active" "yes no" "$(wp wphouse status | awk '$1 == "vulnerabilities" { print $2, $3 }')"

echo
[ "$fails" -eq 0 ] && echo "All checks passed." || echo "$fails check(s) failed."
exit "$fails"
