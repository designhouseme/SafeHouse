#!/usr/bin/env bash
# Disposable host-boundary tests. The limits below are test fixtures, not hosting recommendations.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v docker >/dev/null
command -v timeout >/dev/null
project="shouse-host-protection-${UID}-$$"
compose=(docker compose -p "$project" -f dev/host-protection/compose.yml)
bounded() { timeout --signal=TERM --kill-after=5s "$@"; }
cleanup() {
    result=$?
    trap - EXIT
    if [ "$result" -ne 0 ]; then
        bounded 15 "${compose[@]}" logs --no-color --tail 60 || true
        bounded 10 "${compose[@]}" exec -T php cat /tmp/shouse-fpm-error.log || true
    fi
    if ! bounded 45 "${compose[@]}" down --volumes --remove-orphans --timeout 3; then
        echo "FAIL: disposable lab cleanup failed for $project" >&2
        result=1
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

bounded 180 "${compose[@]}" pull --quiet
bounded 60 "${compose[@]}" up -d --wait --wait-timeout 30
bounded 100 "${compose[@]}" exec -T php php /fixtures/run.php
