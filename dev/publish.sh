#!/usr/bin/env bash
# Publish a built release (build/x.y.z from ./dev/release.sh), after its commit and tag are pushed:
#   1. to the update host: Cloudflare R2 bucket shouse-updates, served by the updates/ Worker at
#      https://updates.designhouse.me/shouse/ (needs `npx wrangler login` on the account that owns it);
#   2. as a GitHub release of the same tag, with the same signed files and the CHANGELOG.md section as notes
#      (needs `gh auth login`). Nothing is built in CI: the signing key never leaves this machine.
# ./dev/publish.sh x.y.z
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=${1:?usage: ./dev/publish.sh x.y.z}
BUCKET=${SHOUSE_R2_BUCKET:-shouse-updates}
REPO=${SHOUSE_GITHUB_REPO:-designhouseme/SafeHouse}
DIR=build/$VERSION
die() { echo "publish: $*" >&2; exit 1; }
[ -f "$DIR/manifest.json" ] && [ -f "$DIR/manifest.json.sig" ] && [ -f "$DIR/shouse-$VERSION.zip" ] || die "build $DIR first (./dev/release.sh $VERSION)"

# The manifest must describe exactly this zip: same version, same checksum.
read -r m_version m_sha < <(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m["version"], m["sha256"])' "$DIR/manifest.json")
[ "$m_version" = "$VERSION" ] || die "manifest says $m_version, not $VERSION"
[ "$(shasum -a 256 "$DIR/shouse-$VERSION.zip" | cut -d' ' -f1)" = "$m_sha" ] || die "zip checksum does not match the manifest"

# Checked before anything is uploaded: release notes, and the tag on GitHub.
notes=$(awk -v v="## $VERSION" '$0 == v || index($0, v " ") == 1 { on = 1; next } /^## / { on = 0 } on' CHANGELOG.md | sed -e '/./,$!d')
[ -n "$notes" ] || die "CHANGELOG.md has no '## $VERSION' section"
git ls-remote --exit-code --tags origin "refs/tags/v$VERSION" >/dev/null || die "tag v$VERSION is not on GitHub yet: git push origin main v$VERSION"

put() { # file, content type, cache control
	npx --yes wrangler@4 r2 object put "$BUCKET/shouse/$1" --file "$DIR/$1" --content-type "$2" --cache-control "$3" --remote >/dev/null
	echo "uploaded shouse/$1"
}
put "shouse-$VERSION.zip" application/zip 'public, max-age=31536000, immutable'
put shouse-latest.zip application/zip 'public, max-age=300'
# Last, and together: sites then see either the old pair or the new pair, never a new zip without its manifest.
put manifest.json.sig text/plain 'public, max-age=300'
put manifest.json application/json 'public, max-age=300'
echo "Published $VERSION to r2://$BUCKET/shouse/"

if gh release view "v$VERSION" --repo "$REPO" >/dev/null 2>&1; then
	echo "GitHub release v$VERSION already exists in $REPO; left as it is."
	exit 0
fi
printf '%s\n\n%s\n' "$notes" "Signed release: \`manifest.json\` carries the SHA-256 of the zip and is signed with the SafeHouse release key (\`manifest.json.sig\`, Ed25519; public key in \`PUBLIC_KEYS\`, plugin/src/Core/Updater.php). Sites update from https://updates.designhouse.me/shouse/ and install only packages that pass both checks." |
	gh release create "v$VERSION" --repo "$REPO" --verify-tag --title "SafeHouse $VERSION" --notes-file - \
		"$DIR/shouse-$VERSION.zip" "$DIR/manifest.json" "$DIR/manifest.json.sig"
echo "GitHub release v$VERSION created in $REPO"
