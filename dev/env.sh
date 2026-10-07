#!/usr/bin/env bash
# Source before running a dev harness. Credentials are local, persistent and never tracked.
# For an existing volume, reset its admin password explicitly or recreate the disposable volume.
shouse_dev_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
shouse_dev_file="$shouse_dev_root/build/dev-credentials.env"
if [ ! -f "$shouse_dev_file" ]; then
	mkdir -p "$shouse_dev_root/build"
	shouse_dev_temp=$(umask 077; mktemp "$shouse_dev_root/build/.credentials.XXXXXX")
	for shouse_dev_name in SHOUSE_DEV_ADMIN_PASSWORD SHOUSE_DEV_DB_PASSWORD SHOUSE_DEV_DB_ROOT_PASSWORD; do
		shouse_dev_value=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
		[ "${#shouse_dev_value}" = 48 ] || { rm -f "$shouse_dev_temp"; return 1; }
		printf '%s=%s\n' "$shouse_dev_name" "$shouse_dev_value" >> "$shouse_dev_temp"
	done
	# Atomic first writer wins, including when parallel harnesses start together.
	ln "$shouse_dev_temp" "$shouse_dev_file" 2>/dev/null || [ -f "$shouse_dev_file" ] || { rm -f "$shouse_dev_temp"; return 1; }
	rm -f "$shouse_dev_temp"
fi
set -a
# shellcheck source=/dev/null
source "$shouse_dev_file"
set +a
unset shouse_dev_name shouse_dev_value shouse_dev_temp
