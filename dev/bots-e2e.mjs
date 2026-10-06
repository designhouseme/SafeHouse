// Browser checks for assets/bots.js, run by dev/bots-e2e.sh (which prepares the site).
// Env: U (site URL), PRODUCT_ID, CLASSIC_PATH (page with [woocommerce_checkout]), OUT (screenshots).
// Playwright is resolved from PLAYWRIGHT_PATH or any node_modules on the way up.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH ?? 'playwright');
const U = process.env.U;
const OUT = process.env.OUT ?? 'build/e2e';
const n = process.env.RUN_ID;
mkdirSync(OUT, { recursive: true });

let failed = 0;
const check = (name, ok, extra = '') => {
	if (!ok) failed++;
	console.log(`${ok ? 'ok   ' : 'FAIL '} ${name}${ok || !extra ? '' : ' (' + extra + ')'}`);
};

const browser = await chromium.launch();
async function fresh() {
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const page = await ctx.newPage();
	page.errors = [];
	page.on('pageerror', (e) => page.errors.push(e.message));
	return { ctx, page };
}

{
	// Submit at once, before Turnstile has a token: bots.js must hold the form and send it later.
	const { ctx, page } = await fresh();
	await page.goto(`${U}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForURL(/wp-admin/, { timeout: 30000 }).catch(() => {}), page.click('#wp-submit')]);
	check('wp-login: early submit waits for the token', page.url().includes('/wp-admin'), page.url());
	check('wp-login: no script errors', page.errors.length === 0, page.errors.join(' | '));
	await ctx.close();
}

{
	const { ctx, page } = await fresh();
	await page.goto(`${U}/wp-login.php?action=register`);
	await page.type('#user_login', `e2e${n}`);
	await page.type('#user_email', `e2e${n}@example.test`);
	await Promise.all([page.waitForURL(/checkemail=registered/, { timeout: 30000 }).catch(() => {}), page.click('#wp-submit')]);
	check('registration: honeypot proof and Turnstile pass', page.url().includes('checkemail=registered'), page.url());
	await ctx.close();
}

{
	const { ctx, page } = await fresh();
	await page.goto(`${U}/?p=1`);
	await page.type('#comment', `e2e comment ${n}`);
	await page.type('#author', 'e2e');
	await page.type('#email', `c${n}@example.test`);
	const [resp] = await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => null), page.click('#submit')]);
	check('comment: accepted', !!resp && resp.status() < 400 && !/anti-bot|automated submission/i.test(await page.content()), page.url());
	await ctx.close();
}

{
	// Block checkout: widget placed by bots.js, token sent in the X-WPHouse-Turnstile header.
	const { ctx, page } = await fresh();
	await page.goto(`${U}/?add-to-cart=${process.env.PRODUCT_ID}`);
	await page.goto(`${U}/checkout/`);
	await page.waitForSelector('.wc-block-checkout__actions', { timeout: 30000 });
	await page.fill('#email', `b${n}@example.test`);
	for (const [field, value] of [['first_name', 'Anna'], ['last_name', 'Test'], ['address_1', '1 Main St'], ['city', 'San Francisco'], ['postcode', '94103'], ['phone', '5005550006']]) {
		const input = page.locator(`#billing-${field}, #shipping-${field}`);
		if (await input.count()) {
			await input.first().fill(value);
		}
	}
	check('block checkout: widget placed', await page.waitForSelector('.wphouse-turnstile', { state: 'attached', timeout: 20000 }).then(() => true).catch(() => false));
	let header = null;
	page.on('request', (r) => {
		if (r.method() === 'POST' && /wc\/store(\/v\d+)?\/checkout/.test(r.url())) {
			header = r.headers()['x-wphouse-turnstile'] ?? '';
		}
	});
	await Promise.all([page.waitForURL(/order-received/, { timeout: 45000 }).catch(() => {}), page.click('.wc-block-components-checkout-place-order-button')]);
	check('block checkout: token header sent', !!header, String(header));
	check('block checkout: order placed', page.url().includes('order-received'), page.url());
	check('block checkout: no script errors', page.errors.length === 0, page.errors.join(' | '));
	if (!page.url().includes('order-received')) {
		await page.screenshot({ path: `${OUT}/block-checkout.png`, fullPage: true });
	}
	await ctx.close();
}

{
	const { ctx, page } = await fresh();
	await page.goto(`${U}/?add-to-cart=${process.env.PRODUCT_ID}`);
	await page.goto(`${U}${process.env.CLASSIC_PATH}`);
	await page.waitForSelector('#billing_first_name', { timeout: 30000 });
	for (const [field, value] of [['first_name', 'Jan'], ['last_name', 'Test'], ['address_1', '1 Main St'], ['city', 'San Francisco'], ['postcode', '94103'], ['phone', '5005550006'], ['email', `k${n}@example.test`]]) {
		await page.fill(`#billing_${field}`, value);
	}
	check('classic checkout: widget in the payment box', await page.waitForSelector('#payment .wphouse-turnstile', { state: 'attached', timeout: 20000 }).then(() => true).catch(() => false));
	await Promise.all([page.waitForURL(/order-received/, { timeout: 45000 }).catch(() => {}), page.click('#place_order')]);
	check('classic checkout: order placed', page.url().includes('order-received'), page.url());
	check('classic checkout: no script errors', page.errors.length === 0, page.errors.join(' | '));
	if (!page.url().includes('order-received')) {
		await page.screenshot({ path: `${OUT}/classic-checkout.png`, fullPage: true });
	}
	await ctx.close();
}

await browser.close();
console.log(failed ? `${failed} check(s) failed.` : 'All browser checks passed.');
process.exit(failed);
