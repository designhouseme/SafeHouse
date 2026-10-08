#!/usr/bin/env bash
# Install a real ZIP into a disposable WordPress site; never mount the checkout as its plugin.
# No signing key, outbound WordPress traffic, published ports or persistent test credentials.
# Minimum matrix: SHOUSE_PACKAGE_WP_IMAGE=wordpress:6.6-php8.1-apache \
#                 SHOUSE_PACKAGE_CLI_IMAGE=wordpress:cli-php8.1 ./dev/package-lifecycle-test.sh
set -euo pipefail
cd "$(dirname "$0")/.."

command -v timeout >/dev/null || { echo 'GNU timeout is required for bounded lifecycle checks.' >&2; exit 1; }
package_root=$PWD
package_compose="$package_root/dev/package-lifecycle/compose.yml"
package_work=$(mktemp -d "${TMPDIR:-/tmp}/shouse-package-lifecycle.XXXXXXXX")
package_project="shouse-package-${RANDOM}-${RANDOM}-$$"
package_started=0
package_tool_image=${SHOUSE_RELEASE_IMAGE:-composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac}

dc() {
	timeout --foreground --kill-after=10s 180s docker compose --project-name "$package_project" -f "$package_compose" "$@"
}
wp() { dc run --rm -T cli wp "$@"; }
phase() { wp eval-file /tests/security/package-lifecycle-test.php "$1"; }
cleanup() {
	package_status=$?
	trap - EXIT INT TERM HUP
	if [ "$package_started" = 1 ]; then
		if ! timeout --foreground --kill-after=5s 45s docker compose --project-name "$package_project" -f "$package_compose" down -v --remove-orphans --timeout 5; then
			echo "FAIL: could not remove disposable project $package_project" >&2
			[ "$package_status" -ne 0 ] || package_status=1
		fi
	fi
	rm -rf -- "$package_work"
	exit "$package_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

[[ $package_tool_image =~ @sha256:[0-9a-f]{64}$ ]] || { echo 'SHOUSE_RELEASE_IMAGE must be pinned by digest.' >&2; exit 1; }
mkdir -p "$package_work/src" "$package_work/packages"
chmod 755 "$package_work" "$package_work/packages" # The CLI container runs as the WordPress user.
cp -R plugin/. "$package_work/src/"
cp LICENSE "$package_work/src/LICENSE"
export SHOUSE_PACKAGE_ARTIFACTS="$package_work/packages"
SHOUSE_PACKAGE_DB_PASSWORD=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
SHOUSE_PACKAGE_DB_ROOT_PASSWORD=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
package_admin_password=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
[ "${#SHOUSE_PACKAGE_DB_PASSWORD}" = 48 ] && [ "${#SHOUSE_PACKAGE_DB_ROOT_PASSWORD}" = 48 ] && [ "${#package_admin_password}" = 48 ] || { echo 'Could not generate fixture credentials.' >&2; exit 1; }
export SHOUSE_PACKAGE_DB_PASSWORD SHOUSE_PACKAGE_DB_ROOT_PASSWORD

timeout --foreground --kill-after=10s 180s docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
	-u "$(id -u):$(id -g)" \
	-v "$package_root/dev/release-tool.php:/opt/release-tool.php:ro" \
	-v "$package_work/src:/src:ro" -v "$package_work/packages:/packages" \
	--entrypoint php "$package_tool_image" /opt/release-tool.php package /src /packages/shouse.zip

echo "== ZIP lifecycle: $package_project"
package_started=1
dc up -d --wait --wait-timeout 120 db wordpress
package_ready=0
package_deadline=$((SECONDS + 60))
while [ "$SECONDS" -lt "$package_deadline" ]; do
	if timeout --foreground --kill-after=1s 5s docker compose --project-name "$package_project" -f "$package_compose" exec -T wordpress test -f /var/www/html/wp-config.php; then package_ready=1; break; fi
	sleep 1
done
[ "$package_ready" = 1 ] || { echo 'FAIL: disposable WordPress did not initialize within 60 seconds.' >&2; exit 1; }

wp core install --url=http://wordpress --title='SafeHouse ZIP lifecycle' --admin_user=admin \
	--admin_password="$package_admin_password" --admin_email=admin@example.test --skip-email
wp plugin install /packages/shouse.zip --activate
phase seed
wp shouse safe-mode on
phase safe
wp shouse queue status --format=json
wp shouse safe-mode off
phase active
wp plugin deactivate shouse
phase inactive
phase legacy-queue
wp plugin install /packages/shouse.zip --force --activate
phase updated
wp plugin deactivate shouse
phase inactive
wp plugin activate shouse
phase reactivated
phase prepare-uninstall
wp plugin uninstall shouse --deactivate
phase uninstalled
wp eval 'if (!WP_DEBUG || (is_file(WP_CONTENT_DIR . "/debug.log") && filesize(WP_CONTENT_DIR . "/debug.log") > 0)) { WP_CLI::error("Lifecycle debug logging is disabled or contains errors."); } WP_CLI::success("Debug logging stayed enabled and empty.");'
echo 'PASS: disposable ZIP installation, upgrade, recovery and uninstall lifecycle'
