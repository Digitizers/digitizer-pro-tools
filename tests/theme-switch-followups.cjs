/* Focused browser regressions using the real shipped JS/CSS, no WordPress needed. */
async function themeSwitchFollowups(browser, assets) {
	const results = [];
	const context = await browser.newContext({ colorScheme: 'light' });
	const group = '<div class="dpt-theme-switch" hidden><button type="button" data-dpt-theme-choice="light">Light</button><button type="button" data-dpt-theme-choice="dark">Dark</button><button type="button" data-dpt-theme-choice="system">System</button></div>';
	const page = await context.newPage();
	const check = (name, pass, detail) => results.push({ name, passed: !!pass, detail });
	let body = '';
	await page.addInitScript(() => {
		window.observerStarts = 0;
		const Original = window.MutationObserver;
		window.MutationObserver = class extends Original {
			observe(...args) { window.observerStarts++; return super.observe(...args); }
		};
	});
	await page.route('http://dpt-followups.test/**', route => route.fulfill({ contentType: 'text/html', body }));
	async function load(content) {
		body = '<!doctype html><html><head><style>' + assets.css + '</style><script>window.DPT_TS_CONFIG={base:"light",default:"system",key:"followup"};</script><script>' + assets.js + '</script></head><body>' + content + '</body></html>';
		await page.goto('http://dpt-followups.test/');
	}
	try {
		await load(group + '<form style="background:#fff;color:#000"><input id="fixed-input"><select id="fixed-select"><option>Fixed light form</option></select></form>');
		const baseline = await page.evaluate(() => ['fixed-input', 'fixed-select'].map(id => {
			const s = getComputedStyle(document.getElementById(id)); return [s.colorScheme, s.backgroundColor, s.color];
		}));
		await page.locator('[data-dpt-theme-choice="dark"]').click();
		const after = await page.evaluate(() => ['fixed-input', 'fixed-select'].map(id => {
			const s = getComputedStyle(document.getElementById(id)); return [s.colorScheme, s.backgroundColor, s.color];
		}));
		check('Fixed light form controls retain their native colors in dark palette mode', JSON.stringify(baseline) === JSON.stringify(after), { baseline, after });
		check('Switch itself retains dark native color-scheme', await page.locator('.dpt-theme-switch').evaluate(el => getComputedStyle(el).colorScheme) === 'dark');
		await page.evaluate(() => localStorage.clear());
		await load('<p>A page with no switch or builder</p>');
		check('No observer is started on a page without switch/builder content', await page.evaluate(() => window.observerStarts) === 0);
		await page.evaluate(group => document.body.insertAdjacentHTML('beforeend', group), group);
		const canRefresh = await page.evaluate(() => typeof window.DPTThemeSwitch.refresh === 'function');
		check('A custom injector can explicitly activate controls on an initially idle page', canRefresh);
		if (canRefresh) {
			await page.evaluate(() => { window.DPTThemeSwitch.refresh(); window.DPTThemeSwitch.refresh(); });
			check('Explicit refresh starts only one observer', await page.evaluate(() => window.observerStarts) === 1);
			check('Explicit refresh reveals controls', await page.locator('.dpt-theme-switch:visible').count() === 1);
		}
		await load('<main class="elementor"></main>');
		check('Builder content enables later switch discovery', await page.evaluate(() => window.observerStarts) === 1);
		await page.evaluate(group => document.querySelector('.elementor').insertAdjacentHTML('beforeend', group), group);
		await page.locator('.dpt-theme-switch').waitFor({ state: 'visible' });
		await page.locator('[data-dpt-theme-choice="dark"]').click();
		check('A switch inserted later by a builder works', await page.locator('html').getAttribute('data-dpt-theme') === 'dark');
		await page.evaluate(group => document.body.insertAdjacentHTML('beforeend', group), group);
		await page.waitForFunction(() => document.querySelectorAll('.dpt-theme-switch:not([hidden])').length === 2);
		check('Dynamically inserted controls still synchronize', await page.locator('[data-dpt-theme-choice="dark"][aria-pressed="true"]').count() === 2);
		return { browser: browser.version(), results };
	} finally { await context.close(); }
}
module.exports = themeSwitchFollowups;
if (require.main === module) {
	const { chromium } = require('playwright');
	const fs = require('node:fs');
	const path = require('node:path');
	const root = path.join(__dirname, '../modules/theme-switch/assets');
	(async () => {
		const browser = await chromium.launch({ headless: true });
		try {
			const report = await themeSwitchFollowups(browser, { js: fs.readFileSync(path.join(root, 'theme-switch.js'), 'utf8'), css: fs.readFileSync(path.join(root, 'theme-switch.css'), 'utf8') });
			console.log(JSON.stringify(report, null, 2));
			process.exitCode = report.results.every(result => result.passed) ? 0 : 1;
		} finally { await browser.close(); }
	})().catch(error => { console.error(error); process.exitCode = 1; });
}
