#!/usr/bin/env bash
# End-to-end test of the vulnerability alerts module in the isolated WordPress (dev/updtest).
# Builds signed advisory data for what is installed there, then checks matching, the admin notice,
# the log, a tampered shard, and that data signed with the release key is refused.
# Uses throwaway keys in build/updtest, never a production key. Run ./dev/update-test.sh once first.
set -euo pipefail
cd "$(dirname "$0")/.."
T=build/updtest
A=$T/www/wphouse/advisories
mkdir -p "$T/pkgs" "$A"
export WPHOUSE_SIGNING_KEY=$PWD/$T/dev-signing.key
[ -f "$WPHOUSE_SIGNING_KEY" ] || ./dev/release.sh keygen >/dev/null
export WPHOUSE_TEST_PUBKEY=$(./dev/release.sh pubkey)
php() { docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli php "$@"; }
[ -f "$T/dev-advisory.key" ] || php dev/release-tool.php keygen "$T/dev-advisory.key" >/dev/null
export WPHOUSE_TEST_ADVISORY_PUBKEY=$(php dev/release-tool.php pubkey "$T/dev-advisory.key")

dc() { docker compose -f dev/updtest/docker-compose.yml "$@"; }
wp() { dc --profile cli run --rm -T wpcli wp "$@" 2>&1 | grep -v -E "Container|^\s*$" || true; }
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; exit 1; }

dc up -d --wait wordpress updates >/dev/null 2>&1 || dc up -d
until curl -s -o /dev/null http://localhost:8895/wp-login.php; do sleep 2; done
wp core is-installed | grep -q Error && fail "isolated site not installed: run ./dev/update-test.sh first"
./dev/release.sh snapshot 0.1.2 "$T/pkgs/adv" >/dev/null
wp wphouse unlock >/dev/null # update-test.sh leaves install lockdown on
wp plugin install /pkgs/adv/wphouse-0.1.2.zip --activate --force >/dev/null
[ "$(wp plugin get wphouse --field=version)" = "0.1.2" ] || fail "snapshot 0.1.2 not installed"
wp plugin get akismet --field=version | grep -q -E '^[0-9]' || wp plugin install akismet >/dev/null

# A feed in Wordfence's shape: Akismet critical at its installed version, core medium, the theme not affected.
core=$(wp core version); akismet=$(wp plugin get akismet --field=version); theme=$(wp theme list --status=active --field=name)
cat > "$T/feed.json" <<EOF
{
 "11111111-1111-4111-8111-111111111111": { "id": "11111111-1111-4111-8111-111111111111", "title": "Akismet test - Unauthenticated test vulnerability",
  "software": [ { "type": "plugin", "name": "Akismet", "slug": "akismet", "affected_versions": { "x": { "from_version": "*", "from_inclusive": true, "to_version": "$akismet", "to_inclusive": true } }, "patched": true, "patched_versions": [ "999.0" ] } ],
  "cvss": { "score": 9.8, "rating": "Critical" }, "published": "2026-10-01 00:00:00", "references": [], "copyrights": { "message": "" } },
 "22222222-2222-4222-8222-222222222222": { "id": "22222222-2222-4222-8222-222222222222", "title": "WordPress Core test - Contributor+ test vulnerability",
  "software": [ { "type": "core", "name": "WordPress Core", "slug": "wordpress", "affected_versions": { "x": { "from_version": "$core", "from_inclusive": true, "to_version": "999.0", "to_inclusive": false } }, "patched": true, "patched_versions": [ "999.0" ] } ],
  "cvss": { "score": 5.0, "rating": "Medium" }, "published": "2026-10-01 00:00:00", "references": [], "copyrights": { "message": "", "mitre": { "notice": "Test notice.", "license": "", "license_url": "" } } },
 "33333333-3333-4333-8333-333333333333": { "id": "33333333-3333-4333-8333-333333333333", "title": "Theme test - fixed long ago",
  "software": [ { "type": "theme", "name": "Theme", "slug": "$theme", "affected_versions": { "x": { "from_version": "*", "from_inclusive": true, "to_version": "0.1", "to_inclusive": false } }, "patched": true, "patched_versions": [ "0.1" ] } ],
  "cvss": { "score": 9.0, "rating": "Critical" }, "published": "2026-10-01 00:00:00", "references": [], "copyrights": { "message": "" } }
}
EOF
php dev/cve-watch.php --feed="$T/feed.json" --since=2026-09-01 --dry-run --advisories="$A" --advisory-key="$T/dev-advisory.key" >/dev/null 2>&1

# 1. Matching: Akismet critical, core warning, theme clean.
wp option delete wphouse_vulnerabilities >/dev/null
out=$(wp wphouse vulnerabilities --refresh --format=csv)
echo "$out" | grep -q "^plugin:akismet,$akismet,critical,9.8,999.0," && pass "vulnerable Akismet $akismet reported as critical" || fail "akismet: $out"
echo "$out" | grep -q "^core:wordpress,$core,warning,5.0,999.0," && pass "core $core reported as warning" || fail "core: $out"
echo "$out" | grep -q "theme:" && fail "theme reported although not affected: $out" || pass "unaffected theme not reported"

# 2. Admin notice for urgent findings only, log entry and attribution.
notice=$(wp eval 'wp_set_current_user( 1 ); do_action( "admin_notices" );')
echo "$notice" | grep -q "$akismet (CVSS 9.8): Akismet test" && pass "admin notice names Akismet" || fail "notice: $notice"
echo "$notice" | grep -q "WordPress $core" && fail "non-urgent core finding in the notice" || pass "non-urgent finding kept out of the notice"
echo "$notice" | grep -q 'wordfence.com/threat-intel/vulnerabilities/id/11111111-1111-4111-8111-111111111111' && pass "details link built from a valid id" || fail "details link: $notice"
wp wphouse log --limit=10 | grep -q vulnerability_found && pass "finding logged (and e-mailed)" || fail "no vulnerability_found log entry"
[ "$(wp eval 'echo get_option( "wphouse_vulnerabilities" )["attribution"];')" = "Vulnerability data: Wordfence Intelligence. Test notice." ] && pass "attribution stored" || fail "attribution"

# 3. Range rules match Wordfence: "*" open, inclusive and exclusive bounds.
ranges=$(wp eval '$f = [ WPHouse\Modules\Vulnerabilities::class, "affected" ]; $r = [ [ "4.3.0", true, "4.3.1", true ] ]; $x = [ [ "*", true, "2.0", false ] ];
echo implode( ",", array_map( "intval", [ $f( "4.3.0", $r ), $f( "4.3.1", $r ), $f( "4.3.2", $r ), $f( "4.2.9", $r ), $f( "1.9.9", $x ), $f( "2.0", $x ) ] ) );')
[ "$ranges" = "1,1,0,0,1,0" ] && pass "version ranges" || fail "version ranges: $ranges"

# 4. Tampered shard: its hash no longer matches the signed index.
shard=$(php -r 'echo substr( md5( "plugin:akismet" ), 0, 2 );')
cp "$A/$shard.json" "$A/$shard.json.orig"
printf ' ' >> "$A/$shard.json"
wp option delete wphouse_vulnerabilities >/dev/null
wp wphouse vulnerabilities --refresh 2>&1 | grep -q "does not match its signed checksum" && pass "tampered shard rejected" || fail "tampered shard accepted"
mv "$A/$shard.json.orig" "$A/$shard.json"

# 5. Index signed with the release key: the advisory channel must not trust it.
cp "$A/index.json.sig" "$A/index.json.sig.orig"
php dev/release-tool.php sign "$T/dev-signing.key" "$A/index.json" >/dev/null
wp wphouse vulnerabilities --refresh 2>&1 | grep -q "signature is invalid" && pass "index signed with the release key rejected" || fail "release key accepted for advisories"
mv "$A/index.json.sig.orig" "$A/index.json.sig"
wp wphouse vulnerabilities --refresh >/dev/null
echo "All advisory tests passed."
