#!/usr/bin/env bash
# The advisory upload Worker (updates/ingest/) under `wrangler dev`, with a local R2 bucket: no Cloudflare login.
# Checks that it stores only advisory files, only with the token, with server-set metadata.
# Exit code = number of failed checks.
set -uo pipefail
cd "$(dirname "$0")/../updates/ingest"
PORT=${INGEST_PORT:-8898}
U=http://localhost:$PORT
T=../../build/ingest-test
STATE=$T/state
TOKEN=test-token-0123456789abcdef0123456789abcdef
mkdir -p "$T"
rm -rf "$STATE"
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1 (expected '$2', got '$3')"; fails=$((fails + 1)); fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
put() { code -X PUT -H "Authorization: Bearer ${3:-$TOKEN}" --data-binary "@$2" "$U/$1"; }
stored() { npx --yes wrangler@4 r2 object get "shouse-updates/$1" --local --persist-to "$STATE" --pipe 2>/dev/null; }

printf '{"a":1}' > "$T/shard.json"
printf 'c2ln\n' > "$T/index.json.sig"
: > "$T/empty"
head -c $((5 * 1024 * 1024)) /dev/zero > "$T/big"

npx --yes wrangler@4 dev --local --port "$PORT" --persist-to "$STATE" --var "INGEST_TOKEN:$TOKEN" > "$T/dev.log" 2>&1 &
dev=$!
trap 'kill $dev 2>/dev/null; wait $dev 2>/dev/null' EXIT
for _ in $(seq 60); do [ "$(code "$U/")" != 000 ] && break; sleep 1; done

echo "== refused"
check "GET"                              405 "$(code "$U/shouse/advisories/0a.json")"
check "POST"                             405 "$(code -X POST --data x "$U/shouse/advisories/0a.json")"
check "no token"                         401 "$(code -X PUT --data x "$U/shouse/advisories/0a.json")"
check "wrong token"                      401 "$(put shouse/advisories/0a.json "$T/shard.json" wrong-token-0123456789abcdef0123456789)"
check "release manifest out of reach"    404 "$(put shouse/manifest.json "$T/shard.json")"
check "release zip out of reach"         404 "$(put shouse/shouse-latest.zip "$T/shard.json")"
check "shard name outside 00-ff"         404 "$(put shouse/advisories/zz.json "$T/shard.json")"
check "empty body"                       400 "$(put shouse/advisories/0b.json "$T/empty")"
check "too large"                        413 "$(put shouse/advisories/0c.json "$T/big")"
check "nothing stored by refusals"       "" "$(stored shouse/manifest.json)"

echo "== stored"
check "shard"                            201 "$(put shouse/advisories/0a.json "$T/shard.json")"
check "shard content"                    '{"a":1}' "$(stored shouse/advisories/0a.json)"
check "signature"                        201 "$(put shouse/advisories/index.json.sig "$T/index.json.sig")"
check "index"                            201 "$(put shouse/advisories/index.json "$T/shard.json")"
npx --yes wrangler@4 r2 object get shouse-updates/shouse/advisories/index.json.sig --local --persist-to "$STATE" --file "$T/got.sig" >/dev/null 2>&1
check "signature bytes intact"           "$(cat "$T/index.json.sig")" "$(cat "$T/got.sig" 2>/dev/null)"

echo "== read back through the update host Worker"
cd ..
npx --yes wrangler@4 dev --local --port ${READ_PORT:-8909} --persist-to "ingest/$STATE" > "ingest/$T/read.log" 2>&1 &
reader=$!
trap 'kill $dev $reader 2>/dev/null; wait $dev $reader 2>/dev/null' EXIT
R=http://localhost:${READ_PORT:-8909}
for _ in $(seq 60); do [ "$(code "$R/")" != 000 ] && break; sleep 1; done
check "served"                           '{"a":1}' "$(curl -s "$R/shouse/advisories/0a.json")"
check "content type set by the Worker"   application/json "$(curl -s -D - -o /dev/null "$R/shouse/advisories/0a.json" | tr -d '\r' | grep -i '^content-type:' | cut -d' ' -f2-)"
check "cache control set by the Worker"  'public, max-age=300' "$(curl -s -D - -o /dev/null "$R/shouse/advisories/0a.json" | tr -d '\r' | grep -i '^cache-control:' | cut -d' ' -f2-)"
check "signature served as text"         text/plain "$(curl -s -D - -o /dev/null "$R/shouse/advisories/index.json.sig" | tr -d '\r' | grep -i '^content-type:' | cut -d' ' -f2-)"

echo
[ "$fails" -eq 0 ] && echo "All ingest checks passed." || echo "$fails check(s) failed. Logs: $T/dev.log, $T/read.log"
exit "$fails"
