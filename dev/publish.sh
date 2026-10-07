#!/usr/bin/env bash
# Publish a built release (build/x.y.z from ./dev/release.sh build), after its tag is pushed:
#   1. to the update host: Cloudflare R2 bucket shouse-updates, served by the updates/ Worker at
#      https://updates.designhouse.me/shouse/. SHOUSE_RELEASE_TOKEN is required: every publication uses
#      the upload Worker's conditional immutable writes, including manual publication;
#   2. as a GitHub release of the same tag, with the same signed files and the CHANGELOG.md section as notes
#      (needs `gh` with a token that may create releases; SHOUSE_GITHUB_REPO=none skips this step).
# ./dev/publish.sh x.y.z
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=${1:?usage: ./dev/publish.sh x.y.z}
REPO=${SHOUSE_GITHUB_REPO:-designhouseme/SafeHouse}
UPLOAD_URL=${SHOUSE_UPLOAD_URL:-https://shouse-ingest.designhouse.me}
DIR=build/$VERSION
die() { echo "publish: $*" >&2; exit 1; }
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die 'version must be x.y.z'
[ -n "${SHOUSE_RELEASE_TOKEN:-}" ] || die 'SHOUSE_RELEASE_TOKEN is required; direct bucket writes would bypass immutable publication'
[[ $UPLOAD_URL == https://* ]] || die 'SHOUSE_UPLOAD_URL must use HTTPS'
[ -f "$DIR/manifest.json" ] && [ -f "$DIR/manifest.json.sig" ] && [ -f "$DIR/release.json" ] && [ -f "$DIR/shouse-$VERSION.zip" ] || die "build $DIR first (./dev/release.sh build $VERSION)"

# The manifest must describe exactly this zip: same version, same checksum.
read -r m_version m_sha < <(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m["version"], m["sha256"])' "$DIR/manifest.json")
[ "$m_version" = "$VERSION" ] || die "manifest says $m_version, not $VERSION"
[ "$(shasum -a 256 "$DIR/shouse-$VERSION.zip" | cut -d' ' -f1)" = "$m_sha" ] || die "zip checksum does not match the manifest"
python3 - "$DIR" <<'PY'
import base64, json, pathlib, sys, time
def require(ok, message):
    if not ok: raise SystemExit(message)
root = pathlib.Path(sys.argv[1])
body = (root / 'manifest.json').read_bytes()
manifest = json.loads(body)
envelope = json.loads((root / 'release.json').read_bytes())
require(envelope['format'] == 1 and base64.b64decode(envelope['payload'], validate=True) == body, 'envelope payload mismatch')
require((root / 'manifest.json.sig').read_text().strip() in envelope['signatures'], 'envelope signature mismatch')
require(manifest['protocol'] == 2 and manifest['channel'] == 'release', 'unsupported metadata')
require(manifest['issued_at'] <= time.time() + 300 < manifest['expires_at'], 'expired metadata')
require((root / ('shouse-' + manifest['version'] + '.zip')).stat().st_size == manifest['size'], 'size mismatch')
PY

# Checked before anything is uploaded: release notes, and the tag on GitHub.
notes=$(awk -v v="## $VERSION" '$0 == v || index($0, v " ") == 1 { on = 1; next } /^## / { on = 0 } on' CHANGELOG.md | sed -e '/./,$!d')
[ -n "$notes" ] || die "CHANGELOG.md has no '## $VERSION' section"
git ls-remote --exit-code --tags origin "refs/tags/v$VERSION" >/dev/null || die "tag v$VERSION is not on GitHub yet: git push origin main v$VERSION"

AUTH=$(umask 077 && mktemp)
trap 'rm -f "$AUTH"' EXIT
printf 'Authorization: Bearer %s\n' "$SHOUSE_RELEASE_TOKEN" > "$AUTH" # keeps the token out of argv
put() { # file, content type, cache control
	# Metadata is set by the Worker, not by the caller.
	curl -fsS --proto '=https' --retry 3 -o /dev/null -X PUT -H "@$AUTH" --data-binary "@$DIR/$1" "$UPLOAD_URL/shouse/$1"
	echo "uploaded shouse/$1"
}
put "shouse-$VERSION.zip" application/zip 'public, max-age=31536000, immutable'
put shouse-latest.zip application/zip 'public, max-age=300'
# Compatibility for old clients. Their two-request protocol has an unavoidable transition window.
put manifest.json.sig text/plain 'public, max-age=300'
put manifest.json application/json 'public, max-age=300'
# New clients observe one atomic object only, after every referenced artifact is present.
put release.json application/json 'public, max-age=300'
echo "Published $VERSION through $UPLOAD_URL"

if [ "$REPO" = none ]; then
	exit 0
fi
if gh release view "v$VERSION" --repo "$REPO" >/dev/null 2>&1; then
	echo "GitHub release v$VERSION already exists in $REPO; left as it is."
	exit 0
fi
printf '%s\n\n%s\n' "$notes" "Signed release: \`manifest.json\` carries the SHA-256 of the zip and is signed with the SafeHouse release key (\`manifest.json.sig\`, Ed25519; public key in \`PUBLIC_KEYS\`, plugin/src/Core/Updater.php). Sites update from https://updates.designhouse.me/shouse/ and install only packages that pass both checks." |
	gh release create "v$VERSION" --repo "$REPO" --verify-tag --title "SafeHouse $VERSION" --notes-file - \
		"$DIR/shouse-$VERSION.zip" "$DIR/manifest.json" "$DIR/manifest.json.sig" "$DIR/release.json"
echo "GitHub release v$VERSION created in $REPO"
