#!/usr/bin/env bash
# SafeHouse release flow. Signed manifest + checksummed zip; see plugin/src/Core/Updater.php.
#
#   ./dev/release.sh <x.y.z>                        bump version, lint, commit "Release x.y.z", tag vx.y.z. Pushing the
#                                                   tag starts .github/workflows/release.yml, which builds, signs and
#                                                   publishes after a maintainer approves the "release" environment.
#   ./dev/release.sh build <x.y.z> [outdir]         build signed files from tag vx.y.z (default build/<x.y.z>/); refuses
#                                                   a key that the tag's Updater.php does not trust. Used by the workflow.
#   ./dev/release.sh snapshot <x.y.z> [outdir]      build signed files from the working tree (tracked files), any version
#                                                   and any key (tests)
#   ./dev/release.sh keygen | pubkey                create a signing key / print its public key
#   ./dev/release.sh renew <x.y.z> [outdir]          rebuild the same tagged ZIP and renew signed metadata (180 days)
#
# Env:
#   SHOUSE_SIGNING_KEY  private key file (default ~/.config/wphouse/signing.key). Never commit it. The release key
#                       itself lives in the "release" environment of designhouseme/SafeHouse and an offline backup.
#   SHOUSE_RELEASE_URL  public base URL of the files (default https://updates.designhouse.me/shouse)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
KEY=${SHOUSE_SIGNING_KEY:-$HOME/.config/wphouse/signing.key}
RELEASE_URL=${SHOUSE_RELEASE_URL:-https://updates.designhouse.me/shouse}
TOOL_IMAGE=${SHOUSE_RELEASE_IMAGE:-composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac}

die() { echo "release: $*" >&2; exit 1; }

# Every tool invocation has no network and only explicitly mounted files/directories.
# Add :ro to an input mount. No secret is mounted by packaging, manifest creation or verification.
tool() {
	[[ $TOOL_IMAGE =~ @sha256:[0-9a-f]{64}$ ]] || die 'SHOUSE_RELEASE_IMAGE must be pinned by digest'
	local mounts=(-v "$ROOT/dev/release-tool.php:/opt/release-tool.php:ro") path
	while [ "$1" != "--" ]; do
		path=${1%:ro}
		if [ "$path" != "$1" ]; then mounts+=(-v "$path:$path:ro"); else mounts+=(-v "$path:$path"); fi
		shift
	done
	shift
	docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
		-u "$(id -u):$(id -g)" "${mounts[@]}" --entrypoint php "$TOOL_IMAGE" /opt/release-tool.php "$@"
}

set_version() { # <plugin dir> <version>
	# -i.bak works with both GNU and BSD (macOS) sed; plain -i does not.
	sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$2/; s/^const SHOUSE_VERSION = '[^']*';/const SHOUSE_VERSION = '$2';/" "$1/shouse.php"
	sed -i.bak -E "s/^Stable tag: .*/Stable tag: $2/" "$1/readme.txt"
	rm -f "$1/shouse.php.bak" "$1/readme.txt.bak"
	grep -q "const SHOUSE_VERSION = '$2';" "$1/shouse.php" || die "could not set version in $1"
}

build() { # <git-ref> <version> <outdir> [trusted: 1 = the key must be one PUBLIC_KEYS in Updater.php lists]
	local ref=$1 version=$2 out=$3 trusted=${4:-0} work
	[ -f "$KEY" ] || die "no signing key at $KEY (run: ./dev/release.sh keygen)"
	mkdir -p "$out"
	out=$(cd "$out" && pwd)
	work=$(mktemp -d)
	mkdir "$work/src"
	git archive --format=tar "$ref:plugin" | tar -x -C "$work/src"   # the plugin/ tree only: everything in it ships
	git show "$ref:CHANGELOG.md" > "$work/CHANGELOG.md"
	git show "$ref:LICENSE" > "$work/src/LICENSE" # the license travels with every copy (Apache-2.0, section 4)
	set_version "$work/src" "$version"
	grep -q "__SHOUSE_PUBLIC_KEY__" "$work/src/src/Core/Updater.php" && die "Updater.php still has the placeholder public key"

	KEY=$(cd "$(dirname "$KEY")" && pwd)/$(basename "$KEY")
	local zip="$out/shouse-$version.zip"
	tool "$work:ro" "$out" -- package "$work/src" "$zip"
	tool "$work:ro" "$out" -- manifest "$zip" "$out/manifest.json" "$version" "$RELEASE_URL/shouse-$version.zip" "$work/CHANGELOG.md"
	tool "$out" "$KEY:ro" -- sign "$KEY" "$out/manifest.json"
	local pub
	pub=$(tool "$KEY:ro" -- pubkey "$KEY")
	tool "$out:ro" -- verify "$pub" "$out/manifest.json"
	tool "$out" -- envelope "$out/manifest.json" "$out/release.json"
	if [ "$trusted" = 1 ]; then
		# The plugin installs only what one of these keys signed: a release signed with any other key would be refused by every site.
		sed -n '/PUBLIC_KEYS = \[/,/\];/p' "$work/src/src/Core/Updater.php" | grep -qF "'$pub'" || die "the signing key ($pub) is not in PUBLIC_KEYS of v$version"
		echo "signing key is trusted by v$version"
	fi
	cp "$zip" "$out/shouse-latest.zip"
	rm -rf "$work"
	echo
	echo "Built $version in $out:"
	ls -1 "$out"
}

case "${1:-}" in
	keygen)
		[ -e "$KEY" ] && die "$KEY already exists"
		mkdir -p "$(dirname "$KEY")" && chmod 700 "$(dirname "$KEY")"
		keywork=$(mktemp -d)
		trap 'rm -rf "$keywork"' EXIT
		pub=$(tool "$keywork" -- keygen "$keywork/signing.key")
		# noclobber avoids replacing a key that appeared while the container was running.
		(set -o noclobber; umask 077; cat "$keywork/signing.key" > "$KEY")
		echo "Private key: $KEY (back it up in the password manager; without it no update can be shipped)"
		echo "Public key:  $pub"
		echo "Put the public key in PUBLIC_KEYS in plugin/src/Core/Updater.php."
		;;
	pubkey)
		KEY=$(cd "$(dirname "$KEY")" && pwd)/$(basename "$KEY")
		tool "$KEY:ro" -- pubkey "$KEY"
		;;
	build|renew)
		version=${2:?usage: build <x.y.z> [outdir]}
		[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must be x.y.z"
		git rev-parse -q --verify "refs/tags/v$version" >/dev/null || die "no tag v$version"
		git show "v$version:plugin/shouse.php" | grep -q "^const SHOUSE_VERSION = '$version';" || die "plugin/shouse.php in v$version is not version $version"
		build "v$version" "$version" "${3:-build/$version}" 1
		;;
	snapshot)
		version=${2:?usage: snapshot <version> [outdir]}
		ref=$(git stash create)   # includes staged/unstaged changes to tracked files, leaves the tree alone
		build "${ref:-HEAD}" "$version" "${3:-build/snapshot-$version}"
		;;
	[0-9]*)
		version=$1
		[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must be x.y.z"
		[ -z "$(git status --porcelain)" ] || die "working tree is not clean"
		[ "$(git branch --show-current)" = main ] || die "release from main"
		git rev-parse -q --verify "refs/tags/v$version" >/dev/null && die "tag v$version exists"
		grep -q "^## $version\b" CHANGELOG.md || die "CHANGELOG.md has no '## $version' section"
		set_version plugin "$version"
		./dev/lint.sh
		git commit -q -am "Release $version"
		git tag -a "v$version" -m "SafeHouse $version"
		echo
		echo "Next: git push origin main v$version. GitHub Actions then builds and signs $version and, once you approve"
		echo "the \"release\" environment, publishes it to $RELEASE_URL/ and as a GitHub release."
		echo "Without Actions: ./dev/release.sh build $version && ./dev/publish.sh $version (needs the release key here)."
		;;
	*)
		sed -n '2,19p' "$0"; exit 1
		;;
esac
