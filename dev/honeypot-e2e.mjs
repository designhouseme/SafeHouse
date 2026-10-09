// Real endpoint + browser tests against the isolated site created by honeypot-test.sh.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const U = process.env.U;
const browser = await chromium.launch();
const run = Date.now().toString(36);
let checks = 0;
function check(label, value) { assert.ok(value, label); checks++; console.log(`ok   ${label}`); }
async function fresh() {
	const ctx = await browser.newContext();
	await ctx.route('**/*', route => new URL(route.request().url()).origin === U ? route.continue() : route.abort());
	const page = await ctx.newPage();
	page.on('pageerror', error => { throw error; });
	return { ctx, page };
}
try {
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
	check('comment target binding accepts the intended post', comment.status() < 400 && !(await page.content()).includes('form check did not pass'));
	await ctx.close();

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
	await widgetCtx.close();
	console.log(`PASS: ${checks} honeypot browser/HTTP checks`);
} finally {
	await browser.close();
}
