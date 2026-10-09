// Real endpoint + browser tests against the isolated site created by honeypot-test.sh.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const U = process.env.U || 'http://127.0.0.1:8897';
const browser = await chromium.launch();
const run = Date.now().toString(36);
let checks = 0;
function check(label, value, detail = '') { assert.ok(value, detail ? `${label}: ${detail}` : label); checks++; console.log(`ok   ${label}`); }
async function responseDetail(page, response) {
	return `HTTP ${response?.status() ?? 'unknown'}: ${(await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 700)}`;
}
async function fresh() {
	const ctx = await browser.newContext();
	await ctx.route('**/*', route => new URL(route.request().url()).origin === U ? route.continue() : route.abort());
	const page = await ctx.newPage();
	page.on('pageerror', error => { throw error; });
	return { ctx, page };
}
async function issueQuotaFixture() {
	const remaining = 600000 - (Date.now() % 600000);
	if (remaining < 10000) await new Promise(resolve => setTimeout(resolve, Math.min(10000, remaining + 100)));
	const { ctx } = await fresh();
	try {
		const issue = () => ctx.request.post(`${U}/wp-admin/admin-ajax.php`, {
			form: { action: 'shouse_challenge', form: 'register', target: '0' }, headers: { 'X-SHouse-Form': '1' },
		});
		const first = await issue();
		check('issuance quota fixture establishes the browser cookie', first.status() === 200 && (await ctx.cookies()).some(cookie => cookie.name.startsWith('shouse_')));
		// The initial request consumed one slot. All concurrent requests retain that same cookie.
		const responses = await Promise.all(Array.from({ length: 20 }, issue));
		const accepted = responses.filter(response => response.status() === 200);
		const rejected = responses.filter(response => response.status() === 429);
		check('concurrent issuance allows exactly the remaining nineteen challenges', accepted.length === 19 && rejected.length === 1,
			`statuses: ${responses.map(response => response.status()).join(', ')}`);
		const retryHeader = Number(rejected[0].headers()['retry-after']);
		const failure = await rejected[0].json();
		check('real issuance limit returns Retry-After and a bounded JSON reason', Number.isFinite(retryHeader) && retryHeader > 0 && retryHeader <= 600 &&
			failure.success === false && failure.data?.reason === 'rate_limited' && Number.isFinite(failure.data.retryAfter) && failure.data.retryAfter > 0 && failure.data.retryAfter <= 600);
	} finally {
		await ctx.close();
	}
}
async function browserFixtures() {
	for (const failure of [429, 503, 403, 'network']) {
		const { ctx, page } = await fresh();
		let mode = failure;
		let requests = 0;
		await page.route(`${U}/fixture`, route => route.fulfill({ contentType: 'text/html', body: `
			<form><input name="author"><input name="email"><textarea name="comment"></textarea>
			<div class="shouse-hp" data-form="comments" data-target="1"><input name="shouse_challenge" type="hidden"><input data-shouse-trap hidden></div>
			<p class="shouse-hp-status" hidden role="status"></p><button type="submit">Send</button></form>` }));
		await page.route(`${U}/challenge`, async route => {
			requests++;
			if (mode === 'network') return route.abort();
			if (mode !== 200) return route.fulfill({ status: mode, contentType: 'application/json',
				headers: { 'Retry-After': mode === 503 ? new Date(Date.now() + 60000).toUTCString() : '3' },
				body: JSON.stringify({ success: false, data: { reason: 'fixture', retryAfter: 2 } }),
			});
			return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
				data: { challenge: `fixture-${requests}`, trap: `field_${requests}`, expiresIn: 1200, wait: 0 },
			}) });
		});
		await page.goto(`${U}/fixture`);
		await page.evaluate(url => {
			window.shouseBots = { challengeUrl: url, challengeError: 'Check unavailable. Please try again.', challengeRateError: 'Too many attempts. Please wait and try again.', challengeUnavailableError: 'The form check is unavailable right now. Please try again in a few minutes.' };
			window.submissions = [];
			document.querySelector('form').addEventListener('submit', event => {
				event.preventDefault();
				const immediate = new FormData(event.target).get('shouse_challenge');
				setTimeout(() => window.submissions.push({ immediate, deferred: new FormData(event.target).get('shouse_challenge') }), 0);
			});
		}, `${U}/challenge`);
		await page.addScriptTag({ path: new URL('../plugin/assets/bots.js', import.meta.url).pathname });
		await page.fill('[name="author"]', 'Selective browser bot');
		await page.waitForFunction(() => document.querySelector('.shouse-hp').shouseHoneypot.manualRetry);
		check(`${failure}: readable failure message`, await page.locator('.shouse-hp-status').isVisible());
		if (failure === 503) check('503: availability message explains the server failure', (await page.locator('.shouse-hp-status').innerText()).includes('unavailable right now'));
		await page.evaluate(() => {
			for (let i = 0; i < 20; i++) document.querySelector('[name="author"]').dispatchEvent(new Event('input', { bubbles: true }));
		});
		check(`${failure}: input events do not repeat failed issuance`, requests === 1);
		if (failure === 429 || failure === 503) {
			check(`${failure}: server cooldown is retained`, await page.evaluate(() => document.querySelector('.shouse-hp').shouseHoneypot.retryAt > Date.now() + 2000));
			await page.click('button');
			check(`${failure}: early explicit retry respects cooldown`, requests === 1);
			await page.evaluate(() => { document.querySelector('.shouse-hp').shouseHoneypot.retryAt = Date.now() - 1; });
			await page.fill('[name="author"]', 'More typing after cooldown');
			check(`${failure}: cooldown expiry still requires explicit retry`, requests === 1);
		}
		mode = 200;
		await page.click('button');
		await page.waitForFunction(() => window.submissions.length === 1);
		const first = await page.evaluate(() => window.submissions[0]);
		check(`${failure}: explicit retry recovers`, requests === 2 && !!first.immediate);
		check(`${failure}: canceled submission retains proof for deferred FormData`, first.immediate === first.deferred && first.immediate === await page.locator('[name="shouse_challenge"]').inputValue());
		await page.fill('[name="comment"]', 'AJAX form remains editable');
		check(`${failure}: typing after submit preserves its payload`, requests === 2 && first.immediate === await page.locator('[name="shouse_challenge"]').inputValue());
		await page.click('button');
		await page.waitForFunction(() => window.submissions.length === 2);
		check(`${failure}: canceled/AJAX retry obtains a fresh proof`, requests === 3 && first.immediate !== await page.locator('[name="shouse_challenge"]').inputValue());
		if (failure === 429) {
			for (const lifecycle of ['reset', 'pageshow']) {
				await page.evaluate(() => document.querySelector('.shouse-hp').shouseHoneypot.prepare(true));
				const before = await page.locator('[name="shouse_challenge"]').inputValue();
				await page.evaluate(kind => {
					if (kind === 'reset') document.querySelector('form').reset();
					else window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
				}, lifecycle);
				check(`${lifecycle}: restored/reset form cannot reuse its previous proof`, await page.evaluate(() => !document.querySelector('.shouse-hp').shouseHoneypot.valid()));
				const count = await page.evaluate(() => window.submissions.length);
				await page.click('button');
				await page.waitForFunction(n => window.submissions.length > n, count);
				check(`${lifecycle}: next submit obtains a different proof`, before !== await page.locator('[name="shouse_challenge"]').inputValue());
			}
		}
		await ctx.close();
	}
}
try {
	if (!process.env.SHOUSE_HP_FIXTURES_ONLY) await issueQuotaFixture();
	if (!process.env.SHOUSE_HP_ISSUE_FIXTURE_ONLY) await browserFixtures();
	if (!process.env.SHOUSE_HP_FIXTURES_ONLY && !process.env.SHOUSE_HP_ISSUE_FIXTURE_ONLY) {
	const { ctx, page } = await fresh();
	const url = `${U}/wp-admin/admin-ajax.php`;
	const issue = (form = 'register', extra = {}) => ctx.request.post(url, {
		form: { action: 'shouse_challenge', form, target: '0' }, headers: { 'X-SHouse-Form': '1' }, ...extra,
	});
	check('challenge rejects GET', (await ctx.request.get(`${url}?action=shouse_challenge`)).status() === 405);
	check('challenge rejects simple cross-origin form posts', (await issue('register', { headers: {} })).status() === 403);
	check('challenge rejects cross-site metadata', (await issue('register', { headers: { 'X-SHouse-Form': '1', 'Sec-Fetch-Site': 'cross-site' } })).status() === 403);
	check('challenge rejects foreign Origin', (await issue('register', { headers: { 'X-SHouse-Form': '1', Origin: 'https://foreign.example' } })).status() === 403);
	const response = await issue();
	check('challenge is not cacheable', /no-store/i.test(response.headers()['cache-control'] || ''));
	const cookie = (await ctx.cookies()).find(c => c.name.startsWith('shouse_'));
	check('browser binding uses HttpOnly SameSite cookie', cookie?.httpOnly && cookie.sameSite === 'Lax');
	const first = (await response.json()).data;
	const second = (await (await issue()).json()).data;
	check('fresh challenges have different trap names', first.trap !== second.trap && first.challenge !== second.challenge);
	await page.goto(`${U}/wp-login.php?action=register`);
	check('cached HTML carries no static proof', !(await page.content()).includes('data-proof='));
	await page.fill('#user_login', `hp${run}`);
	await page.fill('#user_email', `hp${run}@example.test`);
	await Promise.all([page.waitForURL(/checkemail=registered/), page.click('#wp-submit')]);
	check('fast registration waits then succeeds', page.url().includes('checkemail=registered'));
	await page.goto(`${U}/?p=1`);
	await page.fill('#comment', `Browser challenge ${run}`);
	await page.fill('#author', 'Visitor');
	await page.fill('#email', `visitor${run}@example.test`);
	const [comment] = await Promise.all([page.waitForNavigation(), page.click('#submit')]);
	check('comment target binding accepts the intended post', comment.status() < 400 && !(await page.content()).includes('form check did not pass'), await responseDetail(page, comment));
	await ctx.close();

	// Simultaneous requests share one issued ticket and browser cookie, but distinct registration
	// details: a successful single-use check must allow exactly one request to create an account.
	const { ctx: replayCtx } = await fresh();
	const replayIssued = await replayCtx.request.post(url, {
		form: { action: 'shouse_challenge', form: 'register', target: '0' }, headers: { 'X-SHouse-Form': '1' },
	});
	assert.equal(replayIssued.status(), 200);
	const replayTicket = (await replayIssued.json()).data;
	await new Promise(resolve => setTimeout(resolve, replayTicket.wait + 150));
	const replayResults = await Promise.all(Array.from({ length: 4 }, (_, i) => replayCtx.request.post(`${U}/wp-login.php?action=register`, {
		form: { user_login: `replay${i}${run}`, user_email: `replay${i}${run}@example.test`,
			shouse_challenge: replayTicket.challenge, [replayTicket.trap]: '', 'wp-submit': 'Register' },
		maxRedirects: 0,
	})));
	const replayAccepted = replayResults.filter(result => result.status() === 302 && (result.headers().location || '').includes('checkemail=registered'));
	check('concurrent reuse of one token creates exactly one registration', replayAccepted.length === 1);
	const replayRejected = await Promise.all(replayResults.filter(result => !replayAccepted.includes(result)).map(result => result.text()));
	check('remaining simultaneous requests fail the form check', replayRejected.every(body => body.includes('form check did not pass')));
	await replayCtx.close();

	// Replay exactly the same cached HTML in two independent browsers: cookies and tickets must differ.
	const cachedContext = await browser.newContext();
	const cached = await (await cachedContext.request.get(`${U}/wp-login.php?action=register`)).text();
	await cachedContext.close();
	const challenges = [];
	for (const suffix of ['a', 'b']) {
		const { ctx: cachedCtx, page: cachedPage } = await fresh();
		await cachedPage.route('**/wp-login.php?action=register', async route => {
			if (route.request().method() === 'GET') await route.fulfill({ contentType: 'text/html', body: cached });
			else await route.continue();
		});
		await cachedPage.goto(`${U}/wp-login.php?action=register`);
		await cachedPage.fill('#user_login', `cache${suffix}${run}`);
		await cachedPage.fill('#user_email', `cache${suffix}${run}@example.test`);
		await cachedPage.waitForFunction(() => document.querySelector('[name="shouse_challenge"]').value.length > 0);
		challenges.push(await cachedPage.locator('[name="shouse_challenge"]').inputValue());
		// Simulate an expired UI ticket: a fresh fetch must be obtained at submit.
		await cachedPage.evaluate(() => { document.querySelector('.shouse-hp').shouseHoneypot.expires = 0; });
		await Promise.all([cachedPage.waitForURL(/checkemail=registered/), cachedPage.locator('#wp-submit').press('Enter')]);
		check(`cached HTML + expired challenge refresh + keyboard submit (${suffix})`, cachedPage.url().includes('checkemail=registered'));
		await cachedCtx.close();
	}
	check('shared cached HTML gets browser-specific challenges', challenges[0] !== challenges[1]);

	const { ctx: retryCtx, page: retryPage } = await fresh();
	await retryPage.route('**/admin-ajax.php', route => route.abort());
	await retryPage.goto(`${U}/wp-login.php?action=register`);
	await retryPage.fill('#user_login', `retry${run}`);
	await retryPage.fill('#user_email', `retry${run}@example.test`);
	await retryPage.click('#wp-submit');
	await retryPage.waitForFunction(() => document.querySelector('.shouse-hp-status').textContent.length > 0);
	check('network failure leaves a readable retry message', await retryPage.locator('.shouse-hp-status').isVisible());
	await retryPage.unroute('**/admin-ajax.php');
	await Promise.all([retryPage.waitForURL(/checkemail=registered/), retryPage.click('#wp-submit')]);
	check('retry after network recovery succeeds', retryPage.url().includes('checkemail=registered'));
	await retryCtx.close();

	const { ctx: botCtx, page: botPage } = await fresh();
	await botPage.goto(`${U}/wp-login.php?action=register`);
	await botPage.fill('#user_login', `trap${run}`);
	await botPage.fill('#user_email', `trap${run}@example.test`);
	await botPage.evaluate(() => { document.querySelector('[data-shouse-trap]').value = 'spam'; });
	await Promise.all([botPage.waitForNavigation(), botPage.click('#wp-submit')]);
	check('populated rotating trap is rejected', !botPage.url().includes('checkemail=registered') && (await botPage.content()).includes('form check did not pass'));
	await botCtx.close();

	const { ctx: disabledCtx, page: disabledPage } = await fresh();
	await disabledPage.route('**/admin-ajax.php', route => route.fulfill({ status: 400, body: '0' }));
	await disabledPage.goto(`${U}/wp-login.php?action=register`);
	await disabledPage.fill('#user_login', `disabled${run}`);
	await disabledPage.fill('#user_email', `disabled${run}@example.test`);
	await Promise.all([disabledPage.waitForNavigation(), disabledPage.click('#wp-submit')]);
	check('stale page with missing AJAX action defers to server, which still rejects when protection is on', (await disabledPage.content()).includes('form check did not pass'));
	await disabledCtx.close();

	const { ctx: widgetCtx, page: widgetPage } = await fresh();
	let widgetIssues = 0;
	widgetPage.on('request', request => { if (request.url().includes('admin-ajax.php') && (request.postData() || '').includes('action=shouse_challenge')) widgetIssues++; });
	await widgetPage.goto(`${U}/wp-login.php?action=register`);
	// Local delayed widget fixture exercises both submit interceptors; no outside service.
	await widgetPage.evaluate(() => {
		window.turnstile = { render(el, options) {
			setTimeout(() => {
				const input = document.createElement('input');
				input.type = 'hidden'; input.name = 'cf-turnstile-response'; input.value = 'local-widget';
				el.appendChild(input); options.callback();
			}, 1800);
			return 1;
		}, reset() {} };
		const widget = document.createElement('div'); widget.className = 'shouse-turnstile';
		document.querySelector('#registerform').appendChild(widget);
	});
	await widgetPage.fill('#user_login', `widget${run}`);
	await widgetPage.fill('#user_email', `widget${run}@example.test`);
	const [widgetRequest] = await Promise.all([
		widgetPage.waitForRequest(request => request.method() === 'POST' && request.url().includes('wp-login.php')),
		widgetPage.waitForURL(/checkemail=registered/), widgetPage.click('#wp-submit'),
	]);
	check('honeypot and delayed Turnstile submit handlers cooperate', widgetRequest.postData().includes('cf-turnstile-response=local-widget'));
	check('waiting for Turnstile does not issue a second challenge', widgetIssues === 1);
	await widgetCtx.close();

	// Mirrors a selective Selenium bot: use the real browser and fill only the known visible fields.
	// Fresh legitimate challenges must not allow an unlimited comment stream in one browser session.
	// Keep the short burst inside one fixed UTC quota window; a legitimate reset is not a failure.
	const quotaWindowRemaining = 600000 - (Date.now() % 600000);
	if (quotaWindowRemaining < 30000) await new Promise(resolve => setTimeout(resolve, Math.min(30000, quotaWindowRemaining + 100)));
	const { ctx: selectiveCtx, page: selectivePage } = await fresh();
	const selectiveProofs = [];
	let limited = false;
	for (let attempt = 1; attempt <= 11; attempt++) {
		await selectivePage.goto(`${U}/?p=1`);
		await selectivePage.fill('#comment', `Selective browser fixture ${run}-${attempt}`);
		await selectivePage.fill('#author', 'Selective Visitor');
		await selectivePage.fill('#email', `selective${run}@example.test`);
		await selectivePage.waitForFunction(() => document.querySelector('[name="shouse_challenge"]').value.length > 0);
		selectiveProofs.push(await selectivePage.locator('[name="shouse_challenge"]').inputValue());
		const [response] = await Promise.all([selectivePage.waitForNavigation(), selectivePage.click('#submit')]);
		if ((await selectivePage.content()).includes('Too many attempts. Please wait a few minutes and try again.')) { limited = true; break; }
		check(`selective browser submission ${attempt} uses a valid fresh challenge`, response.status() < 400 && !(await selectivePage.content()).includes('form check did not pass'), await responseDetail(selectivePage, response));
	}
	check('selective browser eventually hits the server comment limit despite fresh proofs', limited);
	check('selective browser used distinct challenges for every attempt', new Set(selectiveProofs).size === selectiveProofs.length);
	await selectiveCtx.close();
	}
	console.log(`PASS: ${checks} honeypot browser/HTTP checks`);
} finally {
	await browser.close();
}
