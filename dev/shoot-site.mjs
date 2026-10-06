// Screenshots of the landing page in headless Chromium (never throttled like a background window).
// Usage: node dev/shoot-site.mjs [outdir]   (needs the page served on :8899, see dev/serve-site.py)
// Playwright is resolved from PLAYWRIGHT_PATH or any node_modules on the way up.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH ?? 'playwright');
const out = process.argv[2] ?? 'build/shots';
mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const shots = [
	{ name: 'desktop', width: 1440, height: 900 },
	{ name: 'mobile', width: 390, height: 844, mobile: true },
];
for (const s of shots) {
	const page = await browser.newPage({ viewport: { width: s.width, height: s.height }, deviceScaleFactor: 1, isMobile: !!s.mobile, hasTouch: !!s.mobile });
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
	await page.goto('http://127.0.0.1:8899/', { waitUntil: 'networkidle' });
	await page.waitForTimeout(3500);
	await page.screenshot({ path: `${out}/${s.name}-hero.png` });
	// Section by section: a full-page capture of this page fails in headless Chromium with WebGL running.
	for (const id of ['robi', 'serwis', 'awaryjny', 'pobierz']) {
		await page.evaluate((id) => {
			const el = document.getElementById(id);
			window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY, behavior: 'instant' });
		}, id);
		await page.waitForTimeout(700);
		await page.screenshot({ path: `${out}/${s.name}-${id}.png` });
	}
	console.log(s.name, errors.length ? `errors: ${errors.join(' | ')}` : 'no errors');
	await page.close();
}
await browser.close();
