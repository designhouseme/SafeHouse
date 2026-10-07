// Deterministic concurrency and streaming regressions. No network or Cloudflare account.
import { readFile } from 'node:fs/promises';
import { createHash, timingSafeEqual } from 'node:crypto';
import assert from 'node:assert/strict';
Object.defineProperty(globalThis, 'crypto', { value: { subtle: {
  digest: async (alg, bytes) => Uint8Array.from(createHash(alg.replaceAll('-', '').toLowerCase()).update(Buffer.from(bytes)).digest()).buffer,
  timingSafeEqual: (a, b) => timingSafeEqual(Buffer.from(a), Buffer.from(b)),
} } });
const source = await readFile(new URL('../updates/ingest/src/index.js', import.meta.url), 'utf8');
const { default: worker } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const store = new Map();
const env = { RELEASE_TOKEN: 'release-fixture-012345678901234567890123456789', INGEST_TOKEN: 'advisory-fixture-012345678901234567890123456789', UPDATES: {
  async put(key, value, options) {
    assert.ok(options.sha256);
    if (options.onlyIf) {
      assert.equal(options.onlyIf.get('if-none-match'), '*');
      if (store.has(key)) return null;
    }
    const entry = { size: value.byteLength, checksums: { sha256: options.sha256 }, value, metadata: options.httpMetadata };
    store.set(key, entry); return entry;
  },
  async head(key) { return store.get(key) ?? null; },
  async get(key) { const data = store.get(key); return data ? { arrayBuffer: async () => data.value } : null; },
} };
const request = (path, body, token = env.RELEASE_TOKEN) => new Request('https://fixture.test/' + path, { method: 'PUT', headers: { authorization: 'Bearer ' + token }, body, ...(body instanceof ReadableStream ? { duplex: 'half' } : {}) });
const path = 'shouse/shouse-99.0.0.zip';
const results = await Promise.all([worker.fetch(request(path, 'first'), env), worker.fetch(request(path, 'different'), env)]);
assert.deepEqual(results.map(r => r.status).sort(), [201, 409]);
assert.equal((await worker.fetch(request(path, 'first'), env)).status, 200);
assert.equal((await worker.fetch(request(path, 'bad'), env)).status, 409);
assert.equal((await worker.fetch(request(path, 'x', env.INGEST_TOKEN), env)).status, 404);
const data = '{}'; const hash = createHash('sha256').update(data).digest('hex');
assert.equal((await worker.fetch(request('shouse/advisories/sha256/' + hash + '.json', data, env.INGEST_TOKEN), env)).status, 201);
assert.equal((await worker.fetch(request('shouse/advisories/sha256/' + hash + '.json', 'wrong', env.INGEST_TOKEN), env)).status, 400);
assert.equal((await worker.fetch(request('shouse/release.json', '{}'), env)).status, 201);
assert.equal((await worker.fetch(request('shouse/advisories/feed.json', '{}', env.INGEST_TOKEN), env)).status, 201);
let emitted = 0, cancelled = false;
const stream = new ReadableStream({ pull(controller) { ++emitted; controller.enqueue(new Uint8Array(1024 * 1024)); }, cancel() { cancelled = true; } });
assert.equal((await worker.fetch(request('shouse/advisories/00.json', stream, env.INGEST_TOKEN), env)).status, 413);
assert.ok(cancelled && emitted <= 7, 'chunked body is stopped while consuming');
assert.ok(!store.has('shouse/advisories/00.json'));
// An old MD5-only object must be compared by its contents, never replaced during migration.
delete store.get(path).checksums.sha256;
assert.equal((await worker.fetch(request(path, 'first'), env)).status, 200);
assert.equal((await worker.fetch(request(path, 'other'), env)).status, 409);
console.log('PASS immutable concurrency, idempotence, channel isolation, hash-addressed shards, atomic routes, bounded chunked body, legacy object migration');
