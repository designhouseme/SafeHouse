#!/usr/bin/env python3
"""Exercise actual publisher scripts with inert local git/curl commands; no network or account."""
import base64
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import time

REPO = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='shouse-publish-') as tmp:
    root = pathlib.Path(tmp)
    (root / 'dev').mkdir()
    (root / 'bin').mkdir()
    for script in ('publish.sh', 'publish-advisories.sh'):
        shutil.copy2(REPO / 'dev' / script, root / 'dev' / script)
    for name, body in {
        'git': '#!/bin/sh\nexit 0\n',
        'curl': '''#!/usr/bin/env python3
import os, sys
url = sys.argv[-1]
with open(os.environ['TEST_REQUESTS'], 'a') as out: out.write(url + '\\n')
if os.environ.get('TEST_FAIL_SUFFIX') and url.endswith(os.environ['TEST_FAIL_SUFFIX']): sys.exit(22)
''',
    }.items():
        path = root / 'bin' / name
        path.write_text(body)
        path.chmod(0o755)
    env = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'],
               SHOUSE_RELEASE_TOKEN='fixture-release-token', SHOUSE_INGEST_TOKEN='fixture-advisory-token',
               SHOUSE_UPLOAD_URL='https://fixture.invalid', SHOUSE_GITHUB_REPO='none', TEST_REQUESTS=str(root / 'requests'))
    signature = base64.b64encode(bytes(64)).decode()

    def envelope(payload):
        return json.dumps({'format': 1, 'payload': base64.b64encode(payload).decode(), 'signatures': [signature]})

    def run(script, arg, fail='', success=True):
        (root / 'requests').write_text('')
        result = subprocess.run(['bash', str(root / 'dev' / script), str(arg)], env=dict(env, TEST_FAIL_SUFFIX=fail), capture_output=True, text=True)
        assert (result.returncode == 0) is success, result.stderr
        return (root / 'requests').read_text().splitlines()

    output = root / 'build' / '9.9.9'
    output.mkdir(parents=True)
    archive = b'fixture archive'
    (output / 'shouse-9.9.9.zip').write_bytes(archive)
    (output / 'shouse-latest.zip').write_bytes(archive)
    (root / 'CHANGELOG.md').write_text('## 9.9.9\n\nFixture release.\n')
    manifest = json.dumps({'protocol': 2, 'channel': 'release', 'version': '9.9.9', 'issued_at': int(time.time()),
                           'expires_at': int(time.time()) + 3600, 'sha256': hashlib.sha256(archive).hexdigest(), 'size': len(archive)}).encode()
    (output / 'manifest.json').write_bytes(manifest)
    (output / 'manifest.json.sig').write_text(signature)
    (output / 'release.json').write_text(envelope(manifest))
    paths = run('publish.sh', '9.9.9')
    assert [p.rsplit('/', 1)[-1] for p in paths] == ['shouse-9.9.9.zip', 'shouse-latest.zip', 'manifest.json.sig', 'manifest.json', 'release.json']
    assert len(run('publish.sh', '9.9.9', fail='shouse-9.9.9.zip', success=False)) == 1
    print('PASS release uploads package before legacy pair and atomic envelope; failed package stops publication')

    output = root / 'advisories'
    (output / 'sha256').mkdir(parents=True)
    shard = b'{}'
    digest = hashlib.sha256(shard).hexdigest()
    (output / 'sha256' / (digest + '.json')).write_bytes(shard)
    for number in range(256):
        (output / f'{number:02x}.json').write_bytes(shard)
    index = json.dumps({'protocol': 2, 'channel': 'advisories', 'issued_at': int(time.time()), 'expires_at': int(time.time()) + 3600,
                       'shards': {f'{n:02x}': digest for n in range(256)}}).encode()
    (output / 'index.json').write_bytes(index)
    (output / 'index.json.sig').write_text(signature)
    (output / 'feed.json').write_text(envelope(index))
    paths = run('publish-advisories.sh', output)
    assert paths[0].endswith('/sha256/' + digest + '.json')
    assert paths[-3:] == ['https://fixture.invalid/shouse/advisories/' + p for p in ('index.json.sig', 'index.json', 'feed.json')]
    assert len(paths) == 260
    assert len(run('publish-advisories.sh', output, fail='/sha256/' + digest + '.json', success=False)) == 1
    (output / '00.json').write_bytes(b'broken')
    assert run('publish-advisories.sh', output, success=False) == []
    print('PASS immutable shards precede legacy shards and atomic feed; upload failure/inconsistent files stop publication')
