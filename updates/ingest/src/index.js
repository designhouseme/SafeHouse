// Upload endpoint for the update bucket, with two tokens whose files never overlap:
//   INGEST_TOKEN   CVE watch workflow (SafeHouseCVE): the vulnerability data under shouse/advisories/;
//   RELEASE_TOKEN  release workflow (SafeHouse, behind a manually approved environment): the release files.
//
// PUT only. Content-Type and Cache-Control are set here, never by the client. A published version's zip is
// never replaced: the same bytes again are accepted (a re-run), different bytes are refused. Sites still
// check every signature and checksum themselves; this only narrows what each token can touch.

const SHORT = 'public, max-age=300';

const CHANNELS = [
	{
		secret: 'INGEST_TOKEN',
		maxBytes: 4 * 1024 * 1024,
		files: [
			{ path: /^shouse\/advisories\/feed\.json$/, type: 'application/json', cache: SHORT },
			{ path: /^shouse\/advisories\/sha256\/[0-9a-f]{64}\.json$/, type: 'application/json', cache: 'public, max-age=31536000, immutable', immutable: true, addressed: true },
			{ path: /^shouse\/advisories\/index\.json$/, type: 'application/json', cache: SHORT },
			{ path: /^shouse\/advisories\/index\.json\.sig$/, type: 'text/plain', cache: SHORT },
			{ path: /^shouse\/advisories\/[0-9a-f]{2}\.json$/, type: 'application/json', cache: SHORT },
		],
	},
	{
		secret: 'RELEASE_TOKEN',
		maxBytes: 20 * 1024 * 1024,
		files: [
			{ path: /^shouse\/release\.json$/, type: 'application/json', cache: SHORT },
			{ path: /^shouse\/shouse-\d+\.\d+\.\d+\.zip$/, type: 'application/zip', cache: 'public, max-age=31536000, immutable', immutable: true },
			{ path: /^shouse\/shouse-latest\.zip$/, type: 'application/zip', cache: SHORT },
			{ path: /^shouse\/manifest\.json$/, type: 'application/json', cache: SHORT },
			{ path: /^shouse\/manifest\.json\.sig$/, type: 'text/plain', cache: SHORT },
		],
	},
];

function plain(status, text, extra = {}) {
	return new Response(text + '\n', {
		status,
		headers: { 'content-type': 'text/plain; charset=utf-8', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...extra },
	});
}

const hex = (buffer) => [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('');

/** Enforce the limit during consumption, including chunked bodies and missing Content-Length. */
async function limitedBody(request, limit) {
	if (request.body === null) return new Uint8Array();
	const reader = request.body.getReader();
	const chunks = [];
	let size = 0;
	try {
		for (;;) {
			const { done, value } = await reader.read();
			if (done) break;
			size += value.byteLength;
			if (size > limit) {
				await reader.cancel();
				return null;
			}
			chunks.push(value);
		}
	} finally {
		reader.releaseLock();
	}
	const body = new Uint8Array(size);
	let offset = 0;
	for (const chunk of chunks) {
		body.set(chunk, offset);
		offset += chunk.byteLength;
	}
	return body;
}

async function unchanged(bucket, key, sha256, size) {
	const existing = await bucket.head(key);
	if (existing === null || existing.size !== size) return false;
	if (existing.checksums?.sha256) return hex(existing.checksums.sha256) === hex(sha256);
	// Objects published before SHA-256 metadata was introduced remain immutable too.
	const object = await bucket.get(key);
	return object !== null && hex(await crypto.subtle.digest('SHA-256', await object.arrayBuffer())) === hex(sha256);
}

/** The channel whose token was presented, compared in constant time (both sides hashed to equal length). */
async function channelFor(request, env) {
	const header = request.headers.get('authorization') ?? '';
	if (!header.startsWith('Bearer ')) {
		return null;
	}
	const given = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(header.slice(7)));
	let found = null;
	for (const channel of CHANNELS) {
		const secret = env[channel.secret];
		if (typeof secret !== 'string' || secret.length < 32) {
			continue;
		}
		const expected = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(secret));
		if (crypto.subtle.timingSafeEqual(given, expected)) {
			found = channel;
		}
	}
	return found;
}

export default {
	async fetch(request, env) {
		if (request.method !== 'PUT') {
			return plain(405, 'Method not allowed', { allow: 'PUT' });
		}
		const channel = await channelFor(request, env);
		if (channel === null) {
			return plain(401, 'Unauthorized', { 'www-authenticate': 'Bearer' });
		}
		const key = new URL(request.url).pathname.replace(/^\/+/, '');
		const file = channel.files.find((f) => f.path.test(key));
		if (file === undefined) {
			return plain(404, 'Not found');
		}
		if (Number(request.headers.get('content-length') ?? 0) > channel.maxBytes) {
			return plain(413, 'Too large');
		}
		const body = await limitedBody(request, channel.maxBytes);
		if (body === null) {
			return plain(413, 'Too large');
		}
		if (body.byteLength === 0) {
			return plain(400, 'Empty body');
		}
		const sha256 = await crypto.subtle.digest('SHA-256', body);
		if (file.addressed && !key.endsWith(`/${hex(sha256)}.json`)) {
			return plain(400, 'Content does not match its address');
		}
		const stored = await env.UPDATES.put(key, body, {
			sha256,
			httpMetadata: { contentType: file.type, cacheControl: file.cache },
			...(file.immutable ? { onlyIf: new Headers({ 'if-none-match': '*' }) } : {}),
		});
		if (stored === null) {
			return await unchanged(env.UPDATES, key, sha256, body.byteLength)
				? plain(200, 'Unchanged') : plain(409, 'This version is already published with different contents');
		}
		return plain(201, 'Stored');
	},
};
