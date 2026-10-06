// Upload endpoint for the CVE watch workflow: writes the signed vulnerability data into the update bucket.
//
// Nothing else. PUT only, the advisory file names only, and a bearer token held as a Worker secret.
// Release files (manifest, signature, zips) are out of its reach. Sites check the Ed25519 signature on
// index.json and every shard's SHA-256, so even a leaked token can only make them keep their previous
// data, never accept forged data. Content-Type and Cache-Control are set here, never by the client.

const PATH = /^\/shouse\/advisories\/(index\.json(\.sig)?|[0-9a-f]{2}\.json)$/;
const MAX_BYTES = 4 * 1024 * 1024;

function plain(status, text, extra = {}) {
	return new Response(text + '\n', {
		status,
		headers: { 'content-type': 'text/plain; charset=utf-8', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...extra },
	});
}

async function sha256(text) {
	return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
}

/** Constant-time check of the bearer token (both sides hashed first, so lengths always match). */
async function authorized(request, secret) {
	const header = request.headers.get('authorization') ?? '';
	if (typeof secret !== 'string' || secret.length < 32 || !header.startsWith('Bearer ')) {
		return false;
	}
	const [given, expected] = await Promise.all([sha256(header.slice(7)), sha256(secret)]);
	return crypto.subtle.timingSafeEqual(given, expected);
}

export default {
	async fetch(request, env) {
		if (request.method !== 'PUT') {
			return plain(405, 'Method not allowed', { allow: 'PUT' });
		}
		if (!(await authorized(request, env.INGEST_TOKEN))) {
			return plain(401, 'Unauthorized', { 'www-authenticate': 'Bearer' });
		}
		const { pathname } = new URL(request.url);
		const match = PATH.exec(pathname);
		if (match === null) {
			return plain(404, 'Not found');
		}
		if (Number(request.headers.get('content-length') ?? 0) > MAX_BYTES) {
			return plain(413, 'Too large');
		}
		const body = await request.arrayBuffer();
		if (body.byteLength > MAX_BYTES) {
			return plain(413, 'Too large');
		}
		if (body.byteLength === 0) {
			return plain(400, 'Empty body');
		}
		await env.UPDATES.put(pathname.slice(1), body, {
			httpMetadata: {
				contentType: match[2] ? 'text/plain' : 'application/json',
				cacheControl: 'public, max-age=300',
			},
		});
		return plain(201, 'Stored');
	},
};
