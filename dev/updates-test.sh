#!/usr/bin/env bash
# The update host Worker (updates/) under `wrangler dev`, with a local R2 bucket: no Cloudflare login needed.
# Checks that it serves exactly the release and advisory files, with their metadata, and nothing else.
# Exit code = number of failed checks.
set -uo pipefail
cd "$(dirname "$0")/../updates"
PORT=${UPDATES_PORT:-8897}
U=http://localhost:$PORT
T=../build/updates-test
STATE=$T/state
mkdir -p "$T"
rm -rf "$STATE"
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
wr() { npx --yes wrangler@4 "$@" >/dev/null 2>&1; }
put() { # key file content-type cache-control
	wr r2 object put "shouse-updates/$1" --file "$2" --content-type "$3" --cache-control "$4" --local --persist-to "$STATE" || { echo "could not put $1"; exit 1; }
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
header() { curl -s -D - -o /dev/null "${@:2}" | tr -d '\r' | grep -i "^$1:" | head -1 | cut -d' ' -f2-; }

printf '{"version":"9.9.9"}' > "$T/manifest.json"
printf 'c2lnbmF0dXJl\n' > "$T/manifest.json.sig"
head -c 4096 /dev/urandom > "$T/release.zip"
printf '{}' > "$T/shard.json"
put shouse/manifest.json "$T/manifest.json" application/json 'public, max-age=300'
put shouse/manifest.json.sig "$T/manifest.json.sig" text/plain 'public, max-age=300'
put shouse/shouse-9.9.9.zip "$T/release.zip" application/zip 'public, max-age=31536000, immutable'
put shouse/advisories/0a.json "$T/shard.json" application/json 'public, max-age=300'
put shouse/secret.txt "$T/shard.json" text/plain 'no-store'

npx --yes wrangler@4 dev --local --port "$PORT" --persist-to "$STATE" > "$T/dev.log" 2>&1 &
dev=$!
trap 'kill $dev 2>/dev/null; wait $dev 2>/dev/null' EXIT
for _ in $(seq 60); do [ "$(code "$U/")" != 000 ] && break; sleep 1; done

echo "== served"
check "manifest"                         200 "$(code "$U/shouse/manifest.json")"
check "manifest body intact"             '{"version":"9.9.9"}' "$(curl -s "$U/shouse/manifest.json")"
check "manifest content type"            application/json "$(header content-type "$U/shouse/manifest.json")"
check "manifest cache control"           'public, max-age=300' "$(header cache-control "$U/shouse/manifest.json")"
check "signature"                        200 "$(code "$U/shouse/manifest.json.sig")"
check "release zip"                      200 "$(code "$U/shouse/shouse-9.9.9.zip")"
check "zip bytes intact"                 "$(shasum -a 256 < "$T/release.zip")" "$(curl -s "$U/shouse/shouse-9.9.9.zip" | shasum -a 256)"
check "zip immutable"                    'public, max-age=31536000, immutable' "$(header cache-control "$U/shouse/shouse-9.9.9.zip")"
check "advisory shard"                   200 "$(code "$U/shouse/advisories/0a.json")"
check "HEAD has the length"              4096 "$(header content-length -I "$U/shouse/shouse-9.9.9.zip")"
etag=$(header etag "$U/shouse/manifest.json")
check "If-None-Match answers 304"        304 "$(code -H "If-None-Match: $etag" "$U/shouse/manifest.json")"
check "range request"                    206 "$(code -H 'Range: bytes=0-99' "$U/shouse/shouse-9.9.9.zip")"
check "range header"                     'bytes 0-99/4096' "$(header content-range -H 'Range: bytes=0-99' "$U/shouse/shouse-9.9.9.zip")"
check "nosniff"                          nosniff "$(header x-content-type-options "$U/shouse/manifest.json")"

echo "== refused"
check "root"                             404 "$(code "$U/")"
check "listing"                          404 "$(code "$U/shouse/")"
check "other key in the bucket"          404 "$(code "$U/shouse/secret.txt")"
check "allowed name, not uploaded"       404 "$(code "$U/shouse/shouse-latest.zip")"
check "shard name outside 00-ff"         404 "$(code "$U/shouse/advisories/zz.json")"
check "encoded slash"                    404 "$(code --path-as-is "$U/shouse%2Fmanifest.json")"
check "dot segments resolve first"       404 "$(code --path-as-is "$U/shouse/advisories/%2e%2e/secret.txt")"
check "POST"                             405 "$(code -X POST --data x "$U/shouse/manifest.json")"
check "PUT"                              405 "$(code -X PUT --data x "$U/shouse/manifest.json")"
check "DELETE"                           405 "$(code -X DELETE "$U/shouse/manifest.json")"
check "manifest still there"             200 "$(code "$U/shouse/manifest.json")"

echo
[ "$fails" -eq 0 ] && echo "All update host checks passed." || { echo "$fails check(s) failed. Worker log: $T/dev.log"; }
exit "$fails"
