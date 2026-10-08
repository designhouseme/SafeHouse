#!/usr/bin/env bash
# Requires an already-running disposable dev/security stack; does not start or remove services.
set -euo pipefail
cd "$(dirname "$0")/../.."
source dev/env.sh
: "${COMPOSE_PROJECT_NAME:?Run within the disposable security harness or set its project name}"
docker compose -f dev/security/compose.yml run --rm -T --entrypoint sh cli -s <<'SHOUSE_RUNTIME_SHELL'
set -eu
runtime_dir=$(mktemp -d /tmp/shouse-runtime-XXXXXX)
export SHOUSE_RUNTIME_FIXTURE_STATE="$runtime_dir/state.json"
export SHOUSE_RUNTIME_FIXTURE=1
cleanup() {
    if [ -f "$SHOUSE_RUNTIME_FIXTURE_STATE" ]; then
        wp eval-file /tests/security/runtime-test.php restore >/dev/null 2>&1 || { echo "FAIL: runtime state restoration failed; backup retained in $runtime_dir" >&2; return 1; }
    fi
    rm -rf "$runtime_dir"
}
trap cleanup EXIT
wp eval-file /tests/security/runtime-test.php prepare
for scenario in baseline fatal memory timeout slow session expired safe disabled; do
    wp eval-file /tests/security/runtime-test.php reset "$scenario"
    result=0
    timeout -s KILL 15 php -d display_errors=0 -d log_errors=0 /tests/security/runtime-process.php "$scenario" >"$runtime_dir/$scenario.out" 2>"$runtime_dir/$scenario.err" || result=$?
    case "$scenario" in
        baseline|fatal|memory|timeout)
            [ "$result" != 0 ] && [ "$result" != 124 ] && [ "$result" != 137 ] || { echo "FAIL: $scenario did not end through its expected PHP failure (exit $result)"; exit 1; }
            ;;
        *)
            [ "$result" = 0 ] || { echo "FAIL: $scenario exited $result"; exit 1; }
            ;;
    esac
    wp eval-file /tests/security/runtime-test.php verify "$scenario"
    if [ "$scenario" = fatal ]; then
        test -s "$runtime_dir/baseline.out" || { echo 'FAIL: baseline WordPress fatal response was empty'; exit 1; }
        cmp -s "$runtime_dir/baseline.out" "$runtime_dir/fatal.out" || { echo 'FAIL: runtime monitoring changed the default WordPress fatal response'; exit 1; }
        echo 'PASS: default WordPress fatal response is byte-for-byte unchanged'
    fi
done
printf '%s\n' '<?php define( "SHOUSE_SAFE_MODE", true );' >"$runtime_dir/safe-mode.php"
wp --require="$runtime_dir/safe-mode.php" shouse stability status >"$runtime_dir/safe-status.json"
wp --require="$runtime_dir/safe-mode.php" shouse stability scan >"$runtime_dir/safe-scan.json"
wp --require="$runtime_dir/safe-mode.php" eval-file /tests/security/runtime-test.php verify-safe-cli "$runtime_dir/safe-status.json" "$runtime_dir/safe-scan.json"
wp eval-file /tests/security/runtime-test.php restore
echo 'PASS: real PHP runtime failure, sampling and isolation regressions'
SHOUSE_RUNTIME_SHELL
