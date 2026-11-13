/* Run against the disposable WordPress/Elementor fixture described in docs/theme-switch-validation.md.
 * Requires Playwright in the caller's environment. Exported for connector execution too.
 */
async function themeSwitchBrowser(browser, url) {
	const checks = [];
	const check = (name, ok, detail) => {
		checks.push({ name, passed: !!ok, detail });
		if (!ok) { throw new Error(name + ': ' + JSON.stringify(detail)); }
	};
	const context = await browser.newContext({ colorScheme: 'light', viewport: { width: 390, height: 844 } });
	const errors = [];
	try {
		const page = await context.newPage();
		page.on('pageerror', error => errors.push(error.message));
		await page.goto(url);
		const state = () => page.evaluate(() => ({
			mode: document.documentElement.dataset.dptTheme,
			preference: document.documentElement.dataset.dptThemePreference,
			background: getComputedStyle(document.body).backgroundColor,
			global: getComputedStyle(document.querySelector('.elementor-element-abcdef2 h2')).color,
			fixed: getComputedStyle(document.querySelector('.elementor-element-abcdef3 h2')).color,
			local: getComputedStyle(document.querySelector('#child-token')).color,
			selected: [...document.querySelectorAll('.dpt-theme-switch button[aria-pressed="true"]')].map(el => el.dataset.dptThemeChoice),
			colorScheme: getComputedStyle(document.documentElement).colorScheme,
		}));
		let s = await state();
		check('First visit follows light device mode with real Kit colors', s.mode === 'light' && s.global === 'rgb(34, 34, 34)' && s.selected.join(',') === 'system,system', s);
		await page.locator('footer [data-dpt-theme-choice="dark"]').click();
		s = await state();
		check('Dark palette reaches real Elementor global heading and surface', s.global === 'rgb(238, 238, 238)' && s.background === 'rgb(17, 17, 17)' && s.colorScheme === 'dark', s);
		check('Fixed and descendant-local colors remain unchanged', s.fixed === 'rgb(204, 0, 0)' && s.local === 'rgb(0, 170, 0)', s);
		check('Header and footer selections synchronize', s.selected.join(',') === 'dark,dark', s.selected);
		await page.reload();
		s = await state();
		check('Explicit selection survives refresh over a light device preference', s.mode === 'dark' && s.preference === 'dark', s);
		await page.locator('header [data-dpt-theme-choice="light"]').focus();
		await page.keyboard.press('Enter');
		s = await state();
		check('Enter selects light and restores original palette', s.mode === 'light' && s.global === 'rgb(34, 34, 34)' && s.background === 'rgb(255, 255, 255)', s);
		await page.locator('header [data-dpt-theme-choice="dark"]').focus();
		await page.keyboard.press('Space');
		check('Space selects dark with native button semantics', (await state()).mode === 'dark');
		await page.locator('header [data-dpt-theme-choice="system"]').click();
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.waitForFunction(() => document.documentElement.dataset.dptTheme === 'dark');
		check('System mode follows live device changes', (await state()).preference === 'system');
		await page.locator('header [data-dpt-theme-choice="light"]').click();
		await page.emulateMedia({ colorScheme: 'light' });
		await page.emulateMedia({ colorScheme: 'dark' });
		check('Explicit light stays selected across device changes', (await state()).mode === 'light');
		await page.evaluate(() => {
			const group = document.querySelector('.dpt-theme-switch').cloneNode(true);
			group.id = 'dynamic-switch';
			group.hidden = true;
			document.body.appendChild(group);
		});
		await page.locator('#dynamic-switch').waitFor({ state: 'visible' });
		await page.locator('#dynamic-switch [data-dpt-theme-choice="dark"]').click();
		s = await state();
		check('Dynamically inserted control joins shared state', s.selected.join(',') === 'dark,dark,dark', s.selected);
		await page.locator('footer [data-dpt-theme-choice="light"]').click();
		check('RTL mobile controls can return to light after dark', (await state()).mode === 'light');
		const tab = await context.newPage();
		await tab.goto(url);
		await page.locator('header [data-dpt-theme-choice="dark"]').click();
		await tab.waitForFunction(() => document.documentElement.dataset.dptTheme === 'dark');
		check('Preference synchronizes to another open tab', true);
		await tab.close();
		const key = await page.evaluate(() => window.DPT_TS_CONFIG.key);
		await page.evaluate(key => localStorage.setItem(key, 'invalid'), key);
		await page.reload();
		s = await state();
		check('Invalid persisted value falls back to configured system mode', s.preference === 'system', s);
		check('No uncaught browser errors', errors.length === 0, errors);
	} finally { await context.close(); }

	const blocked = await browser.newContext({ colorScheme: 'dark' });
	try {
		await blocked.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage disabled by fixture'); } }));
		const page = await blocked.newPage();
		await page.goto(url);
		check('Blocked storage still follows device mode', await page.evaluate(() => document.documentElement.dataset.dptTheme === 'dark'));
		await page.locator('header [data-dpt-theme-choice="light"]').click();
		check('Blocked storage still permits manual selection', await page.evaluate(() => document.documentElement.dataset.dptTheme === 'light'));
	} finally { await blocked.close(); }

	const late = await browser.newContext();
	try {
		const page = await late.newPage();
		await page.route('**/theme-switch.js*', route => route.fulfill({ contentType: 'application/javascript', body: '' }));
		await page.goto(url);
		check('Unavailable initializer keeps inert controls hidden', await page.locator('.dpt-theme-switch:visible').count() === 0);
		await page.unroute('**/theme-switch.js*');
		await page.addScriptTag({ url: new URL('/wp-content/plugins/digitizer-pro-tools/modules/theme-switch/assets/theme-switch.js', url).href });
		check('Delayed execution after DOMContentLoaded initializes controls', await page.locator('.dpt-theme-switch:visible').count() === 2);
	} finally { await late.close(); }

	const noJS = await browser.newContext({ javaScriptEnabled: false });
	try {
		const page = await noJS.newPage();
		await page.goto(url);
		check('No-JS fallback preserves base colors and hides inactive controls',
			await page.locator('.dpt-theme-switch:visible').count() === 0 &&
			await page.locator('.elementor-element-abcdef2 h2').evaluate(el => getComputedStyle(el).color) === 'rgb(34, 34, 34)');
	} finally { await noJS.close(); }

	const early = await browser.newContext({ colorScheme: 'dark' });
	let release;
	try {
		const page = await early.newPage();
		const hold = new Promise(resolve => { release = resolve; });
		await page.route('**/elementor/assets/js/frontend.min.js*', async route => { await hold; await route.continue(); });
		await page.goto(url, { waitUntil: 'commit' });
		await page.locator('.elementor-element-abcdef2 h2').waitFor({ state: 'visible' });
		const before = await page.evaluate(() => ({
			ready: document.readyState, mode: document.documentElement.dataset.dptTheme,
			background: getComputedStyle(document.body).backgroundColor,
			global: getComputedStyle(document.querySelector('.elementor-element-abcdef2 h2')).color,
		}));
		check('Dark palette already applies while footer script holds DOMContentLoaded',
			before.ready === 'loading' && before.mode === 'dark' && before.background === 'rgb(17, 17, 17)' && before.global === 'rgb(238, 238, 238)', before);
		release();
		await page.waitForLoadState('load');
	} finally { if (release) { release(); } await early.close(); }
	return { browser: browser.version(), checks };
}
module.exports = themeSwitchBrowser;
if (require.main === module) {
	const { chromium } = require('playwright');
	(async () => {
		const browser = await chromium.launch({ headless: true });
		try { console.log(JSON.stringify(await themeSwitchBrowser(browser, process.argv[2]), null, 2)); }
		finally { await browser.close(); }
	})().catch(error => { console.error(error); process.exitCode = 1; });
}
