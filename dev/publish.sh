#!/usr/bin/env bash
# Publish a built release (build/x.y.z from ./dev/release.sh) to the update host: Cloudflare R2 bucket
# wphouse-updates, served as https://updates.designhouse.me/wphouse/ once the custom domain is attached.
# Needs `npx wrangler login` on the Cloudflare account that owns the bucket. ./dev/publish.sh x.y.z
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=${1:?usage: ./dev/publish.sh x.y.z}
BUCKET=${WPHOUSE_R2_BUCKET:-wphouse-updates}
DIR=build/$VERSION
die() { echo "publish: $*" >&2; exit 1; }
[ -f "$DIR/manifest.json" ] && [ -f "$DIR/manifest.json.sig" ] && [ -f "$DIR/wphouse-$VERSION.zip" ] || die "build $DIR first (./dev/release.sh $VERSION)"

# The manifest must describe exactly this zip: same version, same checksum.
read -r m_version m_sha < <(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m["version"], m["sha256"])' "$DIR/manifest.json")
[ "$m_version" = "$VERSION" ] || die "manifest says $m_version, not $VERSION"
[ "$(shasum -a 256 "$DIR/wphouse-$VERSION.zip" | cut -d' ' -f1)" = "$m_sha" ] || die "zip checksum does not match the manifest"

put() { # file, content type, cache control
	npx --yes wrangler@4 r2 object put "$BUCKET/wphouse/$1" --file "$DIR/$1" --content-type "$2" --cache-control "$3" --remote >/dev/null
	echo "uploaded wphouse/$1"
}
put "wphouse-$VERSION.zip" application/zip 'public, max-age=31536000, immutable'
put wphouse-latest.zip application/zip 'public, max-age=300'
# Last, and together: sites then see either the old pair or the new pair, never a new zip without its manifest.
put manifest.json.sig text/plain 'public, max-age=300'
put manifest.json application/json 'public, max-age=300'
echo "Published $VERSION to r2://$BUCKET/wphouse/"
