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

backup=$(wp option get wphouse_settings --format=json)
wf=$(wp eval 'echo (int) wfConfig::get("loginSec_disableAuthorScan"), (int) wfConfig::get("loginSec_maskLoginErrors");')
restore() {
	wp option update wphouse_settings "$backup" --format=json >/dev/null
	wp eval "wfConfig::set('loginSec_disableAuthorScan', ${wf:0:1}); wfConfig::set('loginSec_maskLoginErrors', ${wf:1:1});" >/dev/null
	wp wphouse lock >/dev/null
}
trap restore EXIT

# Our own code paths, not Wordfence's: switch its overlapping options off for the run.
wp eval '
wfConfig::set("loginSec_disableAuthorScan", 0); wfConfig::set("loginSec_maskLoginErrors", 0);
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
check "users sitemap gone"                404 "$(code "$U/wp-sitemap-users-1.xml")"
check "nosniff header"                    1   "$(curl -sI "$U/" | grep -ci '^x-content-type-options: nosniff')"
check "no generator tag"                  0   "$(curl -s "$U/" | grep -ci 'name="generator" content="WordPress')"
login_msg() { curl -s -b "wordpress_test_cookie=WP%20Cookie%20check" --data-urlencode "log=$1" -d "pwd=wrong&testcookie=1" "$U/wp-login.php" | grep -c 'username, email address or password is incorrect'; }
check "login error: unknown user"         1   "$(login_msg nosuchuser)"
check "login error: known user"           1   "$(login_msg admin)"
check "file editor cap removed"           false "$(wp eval 'var_export(user_can(1, "edit_plugins"));')"
check "X-Forwarded-For is not trusted"    203.0.113.5 "$(wp eval '$_SERVER["REMOTE_ADDR"]="203.0.113.5"; $_SERVER["HTTP_X_FORWARDED_FOR"]="1.2.3.4"; echo WPHouse\Core\Net::client_ip();')"

echo "== lockdown"
check "install blocked while locked"      1   "$(./wp.sh plugin install hello-dolly 2>&1 | grep -c 'locked by WPHouse')"
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
check "Store API still answers"           200 "$(code "$U/wp-json/wc/store/v1/cart")"
check "wp-login still answers"            200 "$(code "$U/wp-login.php")"
check "wp-cron still answers"             200 "$(code "$U/wp-cron.php")"

echo
[ "$fails" -eq 0 ] && echo "All checks passed." || echo "$fails check(s) failed."
exit "$fails"
