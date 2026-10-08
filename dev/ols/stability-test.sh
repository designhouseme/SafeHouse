#!/usr/bin/env bash
# Real WooCommerce/Action Scheduler pilot on an already-running disposable OLS stack.
# Uses the same COMPOSE_PROJECT_NAME and OLS_PORT as dev/ols/setup.sh; owns no services.
set -euo pipefail
cd "$(dirname "$0")"
# Bound each Docker/WP-CLI invocation; CI tears down the disposable stack on failure.
timeout --signal=TERM --kill-after=10s 120s ./wp.sh --require=/tests/ols/stability-test.php eval-file /tests/ols/stability-test.php prepare
timeout --signal=TERM --kill-after=10s 120s ./wp.sh --require=/tests/ols/stability-test.php eval-file /tests/ols/stability-test.php
