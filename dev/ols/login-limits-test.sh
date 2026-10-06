#!/usr/bin/env bash
# Login limits on the OpenLiteSpeed site (./dev/ols/setup.sh first). Each visitor is played with its own
# X-Forwarded-For address: setup.sh trusts the Docker gateway as a proxy on this test site only.
set -uo pipefail
cd "$(dirname "$0")"
U=http://localhost:${OLS_PORT:-8896}
T=../../build/olstest
mkdir -p "$T"
wp() { ./wp.sh "$@" 2>/dev/null; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
jar() { local j="$T/$1.jar"; : > "$j"; echo "$j"; }
verdict() { # body file, headers file
	if grep -qi '^location: .*wp-admin' "$2"; then echo in
	elif grep -q 'Too many failed login attempts' "$1"; then echo locked
	elif grep -q 'paused for new devices' "$1"; then echo paused
	else echo denied; fi
}
login() { # ip user password [jar]
	local j=${4:-$(jar anon)}
	curl -s -o "$T/body" -D "$T/head" -c "$j" -b "$j" -b "wordpress_test_cookie=WP%20Cookie%20check" -H "X-Forwarded-For: $1" \
		--data-urlencode "log=$2" --data-urlencode "pwd=$3" -d testcookie=1 "$U/wp-login.php"
	verdict "$T/body" "$T/head"
}
woo_login() { # ip user password
	local j nonce
	j=$(jar woo)
	nonce=$(curl -s -c "$j" -b "$j" -H "X-Forwarded-For: $1" "$MA" | grep -o 'name="woocommerce-login-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
	curl -s -o "$T/body" -D "$T/head" -c "$j" -b "$j" -H "X-Forwarded-For: $1" --data-urlencode "username=$2" --data-urlencode "password=$3" \
		-d "woocommerce-login-nonce=$nonce&_wp_http_referer=%2F&login=1" "$MA"
	if grep -q 'Too many failed login attempts' "$T/body"; then echo locked
	elif grep -qi '^location:' "$T/head" && grep -qi 'wordpress_logged_in' "$T/head"; then echo in
	else echo denied; fi
}
rest() { curl -s -o "$T/body" -w '%{http_code}' -u "victim:$2" -H "X-Forwarded-For: $1" "$U/?rest_route=/wp/v2/users/me"; }
seconds_left() { wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until) FROM %i WHERE subject = %s', \$wpdb->prefix . 'shouse_login', '$1' ) );"; }
expire() { wp eval "global \$wpdb; \$wpdb->query( \$wpdb->prepare( 'UPDATE %i SET locked_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE subject = %s', \$wpdb->prefix . 'shouse_login', '$1' ) );" >/dev/null; }

backup=$(wp option get shouse_settings --format=json)
trap 'wp option update shouse_settings "$backup" --format=json >/dev/null; wp shouse login unlock --all >/dev/null' EXIT
wp eval '$o = get_option( "shouse_settings" ); $o["modules"]["login_limits"] = true; $o["modules"]["bots"] = false; $o["modules"]["litespeed"] = false;
$o["login_limits"] = [ "ip_attempts" => 3, "ip_window" => 15, "ip_lockout" => 1, "account_attempts" => 4, "allowlist" => "203.0.113.250", "notify" => false ];
update_option( "shouse_settings", $o );' >/dev/null
wp user get victim >/dev/null 2>&1 || wp user create victim victim@example.test --role=subscriber >/dev/null
wp user update victim --user_pass=Correct-Horse-1 >/dev/null
wp shouse login unlock --all >/dev/null
GOOD=Correct-Horse-1
DEVICE=$(jar device)

echo "== lockout by address"
A=203.0.113.10
for i in 1 2 3; do login $A victim wrong >/dev/null; done
check "3 failures lock the address out"            locked "$(login $A victim wrong)"
check "the right password is refused while locked" locked "$(login $A victim $GOOD)"
check "another address still logs in"              in     "$(login 203.0.113.11 victim $GOOD "$DEVICE")"
left=$(seconds_left $A)
check "first lockout is one minute"                yes    "$([ "$left" -gt 40 ] && [ "$left" -le 60 ] && echo yes || echo "no ($left s)")"
login $A victim wrong >/dev/null
check "refused attempts do not extend it"          yes    "$([ "$(seconds_left $A)" -le "$left" ] && echo yes || echo no)"
expire $A
for i in 1 2 3; do login $A victim wrong >/dev/null; done
left=$(seconds_left $A)
check "the next lockout is four times longer"      yes    "$([ "$left" -gt 200 ] && [ "$left" -le 240 ] && echo yes || echo "no ($left s)")"
wp shouse login unlock $A >/dev/null
check "wp shouse login unlock <address> lifts it" in     "$(login $A victim $GOOD "$DEVICE")"
check "the account was paused meanwhile (its failures came from new devices)" paused "$(login 203.0.113.12 victim $GOOD)"
wp shouse login unlock victim >/dev/null
check "wp shouse login unlock <login> lifts the pause" in "$(login 203.0.113.12 victim $GOOD)"

echo "== account paused for new devices"
wp shouse login unlock --all >/dev/null
for i in 1 2 3 4; do login 198.51.100.$i victim wrong >/dev/null; done
check "a new device is paused, even with the right password" paused "$(login 198.51.100.9 victim $GOOD)"
check "a device that logged in before still gets in"         in     "$(login 198.51.100.10 victim $GOOD "$DEVICE")"
for i in 1 2 3 4; do login 198.51.100.2$i ghost wrong >/dev/null; done
check "a made-up account pauses the same way (no enumeration)" paused "$(login 198.51.100.30 ghost whatever)"
for i in 1 2 3; do login 198.51.100.40 ghost whatever >/dev/null; done
check "hammering a paused account still locks the address out" locked "$(login 198.51.100.40 ghost whatever)"
check "the table holds no plain user names"        0      "$(wp eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE subject IN (%s, %s)", $wpdb->prefix . "shouse_login", "victim", "ghost" ) );')"

echo "== WooCommerce login form"
wp shouse login unlock --all >/dev/null
MA=$(wp eval 'echo wc_get_page_permalink( "myaccount" );')
C=203.0.113.20
check "Woo login works"                            in     "$(woo_login $C victim $GOOD)"
for i in 1 2 3; do woo_login $C victim wrong >/dev/null; done
check "Woo failures lock the address out"          locked "$(woo_login $C victim $GOOD)"

echo "== application passwords (REST)"
wp shouse login unlock --all >/dev/null
app=$(wp user application-password create victim "limits-$RANDOM" --porcelain)
G=203.0.113.30
check "a valid application password works"        200 "$(rest $G "$app")"
for i in 1 2 3; do rest $G wrong-password >/dev/null; done
check "a locked address cannot use a valid one"    yes "$(code=$(rest $G "$app"); [ "$code" != 200 ] && grep -q shouse_locked "$T/body" && echo yes || echo "no ($code)")"

echo "== exceptions and safety"
wp shouse login unlock --all >/dev/null
for i in 1 2 3 4 5; do login 203.0.113.250 victim wrong >/dev/null; done
check "an allowlisted address is never locked out" in "$(login 203.0.113.250 victim $GOOD)"
check "behind Cloudflare without the setting, addresses are never locked out" 0 "$(wp eval '
$_SERVER["REMOTE_ADDR"] = "173.245.48.5"; unset( $_SERVER["HTTP_X_FORWARDED_FOR"] );
for ( $i = 0; $i < 5; $i++ ) { do_action( "wp_login_failed", "safety-test", new WP_Error( "incorrect_password", "x" ) ); }
global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE kind = %s", $wpdb->prefix . "shouse_login", "ip" ) );')"

echo
[ "$fails" -eq 0 ] && echo "All login limit checks passed." || echo "$fails login limit check(s) failed."
exit "$fails"
