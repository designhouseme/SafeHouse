#!/usr/bin/env bash
# SafeHouse release flow. Signed manifest + checksummed zip; see plugin/src/Core/Updater.php.
#
#   ./dev/release.sh keygen                         create the signing key once, print the public key
#   ./dev/release.sh <x.y.z>                        bump version, lint, commit "Release x.y.z", tag vx.y.z,
#                                                   build signed files into build/<x.y.z>/
#   ./dev/release.sh snapshot <x.y.z> [outdir]      build signed files from the working tree (tracked files) with any version
#   ./dev/release.sh pubkey                         print the public key of the signing key
#
# Env:
#   SHOUSE_SIGNING_KEY  private key file (default ~/.config/wphouse/signing.key). Never commit it.
#   SHOUSE_RELEASE_URL  public base URL of the files (default https://updates.designhouse.me/shouse)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
KEY=${SHOUSE_SIGNING_KEY:-$HOME/.config/wphouse/signing.key}
RELEASE_URL=${SHOUSE_RELEASE_URL:-https://updates.designhouse.me/shouse}

die() { echo "release: $*" >&2; exit 1; }

# tool <mount-dir...> -- <args>: run release-tool.php in the composer image with the given dirs mounted at the same paths.
tool() {
	local mounts=(-v "$ROOT:$ROOT:ro")
	while [ "$1" != "--" ]; do mounts+=(-v "$1:$1"); shift; done
	shift
	docker run --rm -u "$(id -u):$(id -g)" "${mounts[@]}" -w "$ROOT" composer:2 php "$ROOT/dev/release-tool.php" "$@"
}

set_version() { # <plugin dir> <version>
	# -i.bak works with both GNU and BSD (macOS) sed; plain -i does not.
	sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$2/; s/^const SHOUSE_VERSION = '[^']*';/const SHOUSE_VERSION = '$2';/" "$1/shouse.php"
	sed -i.bak -E "s/^Stable tag: .*/Stable tag: $2/" "$1/readme.txt"
	rm -f "$1/shouse.php.bak" "$1/readme.txt.bak"
	grep -q "const SHOUSE_VERSION = '$2';" "$1/shouse.php" || die "could not set version in $1"
}

build() { # <git-ref> <version> <outdir>
	local ref=$1 version=$2 out=$3 work
	[ -f "$KEY" ] || die "no signing key at $KEY (run: ./dev/release.sh keygen)"
	mkdir -p "$out"
	out=$(cd "$out" && pwd)
	work=$(mktemp -d)
	mkdir "$work/src"
	git archive --format=tar "$ref:plugin" | tar -x -C "$work/src"   # the plugin/ tree only: everything in it ships
	set_version "$work/src" "$version"
	grep -q "__SHOUSE_PUBLIC_KEY__" "$work/src/src/Core/Updater.php" && die "Updater.php still has the placeholder public key"

	local zip="$out/shouse-$version.zip" keydir
	keydir=$(dirname "$KEY")
	tool "$work" "$out" "$keydir" -- package "$work/src" "$zip"
	tool "$out" "$keydir" -- manifest "$zip" "$out/manifest.json" "$version" "$RELEASE_URL/shouse-$version.zip" "$ROOT/CHANGELOG.md"
	tool "$out" "$keydir" -- sign "$KEY" "$out/manifest.json"
	tool "$out" "$keydir" -- verify "$(tool "$keydir" -- pubkey "$KEY")" "$out/manifest.json"
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
		pub=$(tool "$(dirname "$KEY")" -- keygen "$KEY")
		echo "Private key: $KEY (back it up in the password manager; without it no update can be shipped)"
		echo "Public key:  $pub"
		echo "Put the public key in PUBLIC_KEYS in plugin/src/Core/Updater.php."
		;;
	pubkey)
		tool "$(dirname "$KEY")" -- pubkey "$KEY"
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
		build "v$version" "$version" "build/$version"
		echo
		echo "Next: upload build/$version/* to $RELEASE_URL/ (manifest.json and manifest.json.sig last),"
		echo "then push the commit and tag."
		;;
	*)
		sed -n '2,14p' "$0"; exit 1
		;;
esac
