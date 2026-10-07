#!/usr/bin/env bash
# Independent Redis regression suite: no existing WordPress, database or developer stack is touched.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
name="shouse-cache-regression-$$"
image=${SHOUSE_CACHE_TEST_IMAGE:-safehouse-object-cache-tests:local}
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker build -q -t "$image" - < "$ROOT/dev/ols/object-cache-security.Dockerfile" >/dev/null
docker network create --internal "$name" >/dev/null
docker run -d --name "$name" --network "$name" --network-alias redis redis:7-alpine >/dev/null
for _ in {1..30}; do
    if docker exec "$name" redis-cli PING 2>/dev/null | grep -q PONG; then break; fi
    sleep 1
done
run() {
    docker run --rm --read-only --network "$name" --cap-drop ALL --security-opt no-new-privileges \
        -v "$ROOT":/app:ro "$image" /app/dev/ols/object-cache-security.php "$@"
}
run cache
for mode in guard-scan guard-unlink guard-race guard-db guard-success secret-missing secret-empty secret-default; do run "$mode"; done
prefix_a=$(run prefix)
prefix_b=$(docker run --rm --read-only --network "$name" --cap-drop ALL --security-opt no-new-privileges \
    -e SHOUSE_TEST_DB_HOST=db-b -v "$ROOT":/app:ro "$image" /app/dev/ols/object-cache-security.php prefix)
[ "$prefix_a" != "$prefix_b" ] || { echo 'FAIL: independent database hosts share a cache namespace'; exit 1; }
echo 'PASS independent database hosts have different namespaces'
