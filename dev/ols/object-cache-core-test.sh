#!/usr/bin/env bash
# WordPress core's own PHPUnit tests for the object cache API (and options/transients, which lean on it
# hardest), run with the SafeHouse object cache as the drop-in. Uses the OLS harness's lsphp (PhpRedis),
# MariaDB and Redis: run ./dev/ols/setup.sh first. ./dev/ols/object-cache-core-test.sh [extra phpunit args]
set -euo pipefail
cd "$(dirname "$0")"
ROOT=$(cd ../.. && pwd)
WD=$ROOT/build/wordpress-develop
TAG=${WP_TESTS_TAG:-7.1.2}

docker compose up -d >/dev/null 2>&1
if [ ! -f "$WD/wp-tests-config-sample.php" ]; then
	git clone -q --depth 1 --branch "$TAG" https://github.com/WordPress/wordpress-develop.git "$WD"
fi
if [ ! -f "$WD/vendor/bin/phpunit" ]; then
	docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$WD":/app -w /app composer:2 \
		composer install --no-interaction --no-progress --quiet --ignore-platform-reqs
fi
docker compose exec -T db mariadb -uroot -pwordpress -e 'CREATE DATABASE IF NOT EXISTS wordpress_tests' >/dev/null

sed -e "s/youremptytestdbnamehere/wordpress_tests/" -e "s/yourusernamehere/root/" -e "s/yourpasswordhere/wordpress/" -e "s/localhost/db/" \
	"$WD/wp-tests-config-sample.php" > "$WD/wp-tests-config.php"
sed -i.bak "s|define( 'WP_PHP_BINARY', 'php' );|define( 'WP_PHP_BINARY', '/usr/local/lsws/lsphp83/bin/php' );|" "$WD/wp-tests-config.php" && rm -f "$WD/wp-tests-config.php.bak"
cat >> "$WD/wp-tests-config.php" <<'PHP'

define( 'WP_REDIS_HOST', 'redis' );
define( 'WP_CACHE_KEY_SALT', 'wptests:' );
PHP
sed 's/__SHOUSE_FOLDER__/shouse/' "$ROOT/src/ObjectCache/loader.php" > "$WD/src/wp-content/object-cache.php"

commands() { docker compose exec -T redis redis-cli INFO stats | tr -d '\r' | awk -F: '$1 == "total_commands_processed" { print $2 }'; }
before=$(commands)
docker run --rm --network shouse-ols_default --entrypoint /usr/local/lsws/lsphp83/bin/php \
	-v "$WD":/wp -v "$ROOT":/wp/src/wp-content/plugins/shouse:ro -v "$ROOT":/wp/tests/phpunit/data/plugins/shouse:ro -w /wp \
	litespeedtech/openlitespeed:1.9.2-lsphp83 -d memory_limit=1G vendor/bin/phpunit --group cache,option "$@" | tee "$ROOT/build/core-cache-tests.txt" || true

# One known difference: Tests_Cache::test_wp_cache_flush_group expects an external cache NOT to support
# flushing a group (WordPress's fallback for drop-ins without it). This cache supports it, so that one
# assertion fails by design; dev/ols/object-cache-test.sh checks that group flushes work. Anything else fails the run.
OUT=$ROOT/build/core-cache-tests.txt
summary=$(grep -E '^(Tests:|OK \()' "$OUT" | tail -1)
errors=$(echo "$summary" | grep -o 'Errors: [0-9]*' | grep -o '[0-9]*' || true)
failures=$(echo "$summary" | grep -o 'Failures: [0-9]*' | grep -o '[0-9]*' || true)
only_known=$(sed -n '/failure/,/^--$\|skipped/p' "$OUT" | grep -E '^[0-9]+\) ' | grep -v -c 'Tests_Cache::test_wp_cache_flush_group$' || true)

# Proof the suite ran on the SafeHouse cache, not on WordPress's own: only the drop-in talks to this Redis.
# (Counting keys proves nothing: the tests flush the cache after every test.)
used=$(( $(commands) - before ))
echo "Redis commands during the run: $used"
[ "$used" -gt 1000 ] || { echo "FAIL: the tests did not use the SafeHouse object cache"; exit 1; }
[ "${errors:-0}" -eq 0 ] && [ "${failures:-0}" -le 1 ] && [ "$only_known" -eq 0 ] || { echo "FAIL: unexpected errors or failures, see build/core-cache-tests.txt ($summary)"; exit 1; }
echo "Core object cache tests passed on the SafeHouse cache (one known, intended difference: group flush is supported)."
