#!/usr/bin/env bash
# Publish locally generated, signed advisory data. The immutable shards precede the atomic envelope.
# Usage: SHOUSE_INGEST_TOKEN=... ./dev/publish-advisories.sh <advisory-output-directory>
set -euo pipefail
DIR=${1:?usage: publish-advisories.sh advisory-directory}
UPLOAD_URL=${SHOUSE_UPLOAD_URL:-https://shouse-ingest.designhouse.me}
[ -n "${SHOUSE_INGEST_TOKEN:-}" ] || { echo 'SHOUSE_INGEST_TOKEN is required' >&2; exit 1; }
[[ $UPLOAD_URL == https://* ]] || { echo 'SHOUSE_UPLOAD_URL must use HTTPS' >&2; exit 1; }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
chmod 700 "$WORK"
printf 'Authorization: Bearer %s\n' "$SHOUSE_INGEST_TOKEN" > "$WORK/auth"
python3 - "$DIR" > "$WORK/files" <<'PY'
import base64, hashlib, json, pathlib, re, sys, time
def require(ok, message):
    if not ok: raise SystemExit(message)
root = pathlib.Path(sys.argv[1])
body = (root / 'index.json').read_bytes()
index = json.loads(body)
envelope = json.loads((root / 'feed.json').read_bytes())
require(envelope['format'] == 1 and base64.b64decode(envelope['payload'], validate=True) == body, 'envelope mismatch')
require((root / 'index.json.sig').read_text().strip() in envelope['signatures'], 'signature mismatch')
require(index['protocol'] == 2 and index['channel'] == 'advisories', 'unsupported metadata')
require(index['issued_at'] <= time.time() + 300 < index['expires_at'], 'expired metadata')
require(set(index['shards']) == {f'{n:02x}' for n in range(256)}, 'incomplete shard index')
for name, digest in index['shards'].items():
    require(re.fullmatch('[0-9a-f]{64}', digest), 'invalid hash')
    legacy = (root / f'{name}.json').read_bytes()
    immutable = (root / 'sha256' / f'{digest}.json').read_bytes()
    require(legacy == immutable and hashlib.sha256(immutable).hexdigest() == digest, 'shard mismatch')
    require(len(immutable) <= 4 * 1024 * 1024, 'oversized shard')
for digest in sorted(set(index['shards'].values())):
    print(f'sha256/{digest}.json')
for name in sorted(index['shards']):
    print(f'{name}.json')
print('index.json.sig')
print('index.json')
print('feed.json')
PY
while IFS= read -r file; do
	curl -fsS --proto '=https' --retry 3 -o /dev/null -X PUT -H "@$WORK/auth" --data-binary "@$DIR/$file" "$UPLOAD_URL/shouse/advisories/$file"
done < "$WORK/files"
echo 'Published advisory shards, legacy compatibility pair and atomic feed.json.'
