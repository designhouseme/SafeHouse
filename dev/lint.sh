#!/usr/bin/env bash
# PHPCS + PHPStan inside the composer:2 image (no local PHP needed). ./dev/lint.sh [--fix]
set -euo pipefail
cd "$(dirname "$0")/.."
run() { docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 "$@"; }
[ -d vendor ] || run composer install --no-interaction --no-progress --quiet
if [ "${1:-}" = "--fix" ]; then run vendor/bin/phpcbf || true; fi
run vendor/bin/phpcs
run php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
