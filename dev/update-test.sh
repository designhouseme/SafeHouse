#!/usr/bin/env bash
# End-to-end test of signed self-updates in an isolated WordPress (dev/updtest).
# Installs a 0.1.0 zip, publishes a signed 0.1.1, then checks that:
#   a tampered manifest is rejected, a swapped zip is rejected, and the genuine update installs.
# Uses a throwaway dev key in build/updtest, never the production signing key.
set -euo pipefail
cd "$(dirname "$0")/.."
T=build/updtest
mkdir -p "$T/www/wphouse" "$T/pkgs"
export WPHOUSE_SIGNING_KEY=$PWD/$T/dev-signing.key
export WPHOUSE_RELEASE_URL=http://updates/wphouse
[ -f "$WPHOUSE_SIGNING_KEY" ] || ./dev/release.sh keygen >/dev/null
export WPHOUSE_TEST_PUBKEY=$(./dev/release.sh pubkey)

dc() { docker compose -f dev/updtest/docker-compose.yml "$@"; }
wp() { dc --profile cli run --rm -T wpcli wp "$@" 2>&1 | grep -v -E "Container|^\s*$" || true; }
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; exit 1; }

./dev/release.sh snapshot 0.1.0 "$T/pkgs/base" >/dev/null
rm -f "$T/www/wphouse/"*
dc up -d --wait wordpress updates >/dev/null 2>&1 || dc up -d
until curl -s -o /dev/null http://localhost:8895/wp-login.php; do sleep 2; done
wp core is-installed | grep -q Error && true
wp core install --url=http://localhost:8895 --title=updtest --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email >/dev/null
wp plugin install /pkgs/base/wphouse-0.1.0.zip --activate --force >/dev/null
[ "$(wp plugin get wphouse --field=version)" = "0.1.0" ] || fail "0.1.0 not installed"
pass "0.1.0 installed from zip"

./dev/release.sh snapshot 0.1.1 "$T/www/wphouse" >/dev/null
M=$T/www/wphouse/manifest.json

# 1. Tampered manifest: one byte changed, signature must fail.
cp "$M" "$M.orig"
sed -i 's/"name": "WPHouse"/"name": "WPHousf"/' "$M"
out=$(wp wphouse update-check)
echo "$out" | grep -q "signature is invalid" && pass "tampered manifest rejected" || fail "tampered manifest: $out"
mv "$M.orig" "$M"

# 2. Swapped zip: manifest is genuine, the file behind download_url is not.
Z=$T/www/wphouse/wphouse-0.1.1.zip
cp "$Z" "$Z.orig" && cp "$T/pkgs/base/wphouse-0.1.0.zip" "$Z"
wp wphouse update-check | grep -q "Update available: 0.1.1" || fail "update not offered"
out=$(wp plugin update wphouse)
echo "$out" | grep -q "does not match the signed checksum" && pass "swapped zip rejected" || fail "swapped zip: $out"
[ "$(wp plugin get wphouse --field=version)" = "0.1.0" ] || fail "version changed after rejected update"
mv "$Z.orig" "$Z"

# 3. Genuine update.
wp wphouse update-check | grep -q "Update available: 0.1.1" || fail "update not offered"
wp plugin update wphouse >/dev/null
[ "$(wp plugin get wphouse --field=version)" = "0.1.1" ] && pass "signed 0.1.1 installed" || fail "update did not install"
wp wphouse log --limit=5
echo "All update tests passed. Stop with: docker compose -f dev/updtest/docker-compose.yml down -v"
