// SafeHouse update host: serves release files and vulnerability data from the private R2 bucket.
//
// Read-only and deliberately dumb. It answers GET and HEAD for the files sites download and nothing
// else: no listing, no uploads, no other keys. Trust does not depend on it: the plugin checks the
// Ed25519 signatures and SHA-256 checksums, so a tampered file is refused even if it is served here.
// Content-Type and Cache-Control come from the object metadata set at upload (dev/publish.sh and
// the CVE watch workflow).

const PATHS = [
	/^shouse\/manifest\.json(\.sig)?$/,
	/^shouse\/shouse-latest\.zip$/,
	/^shouse\/shouse-\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?\.zip$/,
	/^shouse\/advisories\/index\.json(\.sig)?$/,
	/^shouse\/advisories\/[0-9a-f]{2}\.json$/,
];

const BASE_HEADERS = {
	'x-content-type-options': 'nosniff',
	'referrer-policy': 'no-referrer',
};

function plain(status, text, extra = {}) {
	return new Response(text + '\n', {
		status,
		headers: { ...BASE_HEADERS, 'content-type': 'text/plain; charset=utf-8', 'cache-control': 'no-store', ...extra },
	});
}

export default {
	async fetch(request, env) {
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			return plain(405, 'Method not allowed', { allow: 'GET, HEAD' });
		}
		const key = new URL(request.url).pathname.replace(/^\/+/, '');
		if (!PATHS.some((re) => re.test(key))) { // The patterns admit no %, so encoded paths never match.
			return plain(404, 'Not found');
		}

		const object = request.method === 'HEAD'
			? await env.UPDATES.head(key)
			: await env.UPDATES.get(key, { onlyIf: request.headers, range: request.headers });
		if (object === null) {
			return plain(404, 'Not found');
		}

		const headers = new Headers(BASE_HEADERS);
		object.writeHttpMetadata(headers);
		headers.set('etag', object.httpEtag);
		headers.set('accept-ranges', 'bytes');

		if (request.method === 'HEAD') {
			headers.set('content-length', String(object.size));
			return new Response(null, { headers });
		}
		if (!('body' in object)) {
			return new Response(null, { status: 304, headers }); // If-None-Match / If-Modified-Since matched.
		}
		if (object.range && request.headers.has('range')) {
			const { offset = 0, length = object.size - offset } = object.range;
			headers.set('content-range', `bytes ${offset}-${offset + length - 1}/${object.size}`);
			return new Response(object.body, { status: 206, headers });
		}
		return new Response(object.body, { headers });
	},
};
