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
			{ path: /^shouse\/advisories\/index\.json$/, type: 'application/json', cache: SHORT },
			{ path: /^shouse\/advisories\/index\.json\.sig$/, type: 'text/plain', cache: SHORT },
			{ path: /^shouse\/advisories\/[0-9a-f]{2}\.json$/, type: 'application/json', cache: SHORT },
		],
	},
	{
		secret: 'RELEASE_TOKEN',
		maxBytes: 20 * 1024 * 1024,
		files: [
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
		const body = await request.arrayBuffer();
		if (body.byteLength > channel.maxBytes) {
			return plain(413, 'Too large');
		}
		if (body.byteLength === 0) {
			return plain(400, 'Empty body');
		}
		const md5 = await crypto.subtle.digest('MD5', body);
		if (file.immutable) {
			const existing = await env.UPDATES.head(key);
			if (existing !== null) {
				const stored = existing.checksums?.md5 ? hex(existing.checksums.md5) : existing.etag;
				return stored === hex(md5) ? plain(200, 'Unchanged') : plain(409, 'This version is already published with different contents');
			}
		}
		await env.UPDATES.put(key, body, { md5, httpMetadata: { contentType: file.type, cacheControl: file.cache } });
		return plain(201, 'Stored');
	},
};
