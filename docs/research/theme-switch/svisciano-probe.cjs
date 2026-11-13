/* Research fixture, not a WordPress integration test. No third-party code is vendored.
 * Usage: node svisciano-probe.cjs /path/to/unpacked/svisciano-light-dark-theme-mode
 * Requires Playwright and its Chromium browser in the caller's environment.
 */
async function probe(page, source) {
    const results = [];
    const errors = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    const settings = {
        prefix: 'svis_ldtm', base_theme: 'sv-light', default_theme: 'sv-auto',
        color_map: { '--e-global-color-primary': '#eeeeee', '--surface': '#111111' },
    };
    let markup = '';
    let releaseHold;
    let hold;
    await page.route('http://dpt-theme-research.test/**', async route => {
        if (new URL(route.request().url()).pathname === '/hold.js') {
            await hold;
            return route.fulfill({ contentType: 'application/javascript', body: '' });
        }
        return route.fulfill({ contentType: 'text/html', body: markup });
    });
    function html(config = {}, variant = 1, includeScript = true, held = false) {
        const data = JSON.stringify({ ...settings, ...config });
        const toggle = source[`toggle${variant}`];
        const baseText = config.base_theme === 'sv-dark' ? '#eeeeee' : '#222222';
        const baseSurface = config.base_theme === 'sv-dark' ? '#111111' : '#ffffff';
        return `<!doctype html><html lang="en" dir="ltr"><head><style>
          :root { --e-global-color-primary: ${baseText}; --surface: ${baseSurface}; }
          body.elementor-kit-123 { --e-global-color-primary: ${baseText}; }
          body { color:var(--e-global-color-primary); background:var(--surface); }
          #scoped { --e-global-color-primary: #cc0000; color:var(--e-global-color-primary); }
          #raw { color:#000000; background:#ffffff; }
          ${source[`css${variant}`]}</style>
          ${held ? '<script defer src="/hold.js"></script>' : ''}
          </head><body class="elementor-kit-123">
          <section id="first">${toggle}</section><section id="second">${toggle}</section>
          <p id="scoped">Scoped variable</p><p id="raw">Fixed colors</p>
          <img id="logo" alt="Logo" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8'%3E%3C/svg%3E">
          <script>window.svis_ldtm_DATA=${data};</script>
          ${includeScript ? `<script>${source.js}</script>` : ''}</body></html>`;
    }
    async function load(config = {}, variant = 1, includeScript = true) {
        await page.context().clearCookies();
        await page.emulateMedia({ colorScheme: 'light' });
        markup = html(config, variant, includeScript);
        await page.goto('http://dpt-theme-research.test/fixture', { waitUntil: 'load' });
    }
    const state = () => page.evaluate(() => ({
        theme: document.documentElement.className,
        toggleClasses: [...document.querySelectorAll('#sv-theme-toggle')].map(el => el.className),
        color: getComputedStyle(document.body).color,
        background: getComputedStyle(document.body).backgroundColor,
        scoped: getComputedStyle(document.querySelector('#scoped')).color,
        raw: getComputedStyle(document.querySelector('#raw')).color,
        colorScheme: getComputedStyle(document.documentElement).colorScheme,
        cookie: document.cookie,
    }));
    const record = (name, observed, confirmed) => results.push({ name, confirmed, observed });
    try {
        await load();
        await page.locator('#first #sv-moon').click();
        let s = await state();
        record('Mapped Elementor-style body token changes; scoped and fixed colors stay unchanged', s,
            s.color === 'rgb(238, 238, 238)' && s.scoped === 'rgb(204, 0, 0)' && s.raw === 'rgb(0, 0, 0)');
        record('Only first switch visual state is updated', s.toggleClasses,
            s.toggleClasses[0].includes('sv-dark') && !s.toggleClasses[1].includes('sv-dark'));
        record('Native color-scheme is not set', s.colorScheme, s.colorScheme === 'normal');
        await page.reload({ waitUntil: 'load' });
        s = await state();
        record('Manual dark selection survives reload', s, s.theme === 'sv-dark' && s.cookie.includes('sv-dark'));
        await page.locator('#first #sv-sun').click();
        s = await state();
        record('Light mode restores base body color', s, s.theme === 'sv-light' && s.color === 'rgb(34, 34, 34)');
        await page.locator('#second #sv-moon').click();
        s = await state();
        record('Second switch click is inert', s.theme, s.theme === 'sv-light');
        const controls = await page.locator('#first .sv-icon-container').evaluateAll(els =>
            els.map(el => ({ tag: el.tagName, tabIndex: el.tabIndex, role: el.getAttribute('role'), label: el.getAttribute('aria-label') })));
        record('Default controls are unfocusable divs with no accessible labels', controls,
            controls.every(el => el.tag === 'DIV' && el.tabIndex === -1 && el.role === null && el.label === null));
        await load({}, 2);
        await page.locator('#first #sv-moon').focus();
        await page.keyboard.press('Enter');
        await page.keyboard.press('Space');
        s = await state();
        record('Glass Pill focuses but Enter and Space do not select dark mode', s.theme, s.theme === 'sv-light');
        await load();
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.waitForFunction(() => document.documentElement.classList.contains('sv-dark'));
        record('Auto mode follows a live device preference change', (await state()).theme, true);
        await page.locator('#first #sv-sun').click();
        await page.emulateMedia({ colorScheme: 'light' });
        await page.emulateMedia({ colorScheme: 'dark' });
        s = await state();
        record('Explicit light selection wins over device changes', s.theme, s.theme === 'sv-light');
        await load({ base_theme: 'sv-dark', default_theme: 'sv-dark',
            color_map: { '--e-global-color-primary': '#222222', '--surface': '#ffffff' } });
        await page.locator('#first #sv-sun').click();
        s = await state();
        record('Dark base applies alternate mapping when light is selected', s,
            s.theme === 'sv-light' && s.color === 'rgb(34, 34, 34)' && s.background === 'rgb(255, 255, 255)');
        await load({}, 1, false);
        await page.addScriptTag({ content: source.js });
        s = await state();
        record('Script executed after DOMContentLoaded never initializes', s.theme, s.theme === '');
        await load();
        await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
        await page.locator('#first #sv-moon').click();
        await page.locator('#first #sv-theme-ball').evaluate(async el => {
            await Promise.all(el.getAnimations().map(animation => animation.finished));
        });
        let rtlClickError = '';
        try {
            await page.locator('#first #sv-sun').click({ timeout: 1500 });
        } catch (error) {
            rtlClickError = error.message;
        }
        s = await state();
        record('RTL default switch ball intercepts the light control after selecting dark',
            { theme: s.theme, pointerInterception: rtlClickError.includes('intercepts pointer events') },
            s.theme === 'sv-dark' && rtlClickError.includes('intercepts pointer events'));
        await page.context().clearCookies();
        hold = new Promise(resolve => { releaseHold = resolve; });
        markup = html({ default_theme: 'sv-dark' }, 1, true, true);
        await page.goto('http://dpt-theme-research.test/held', { waitUntil: 'commit' });
        await page.locator('#raw').waitFor({ state: 'visible' });
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const before = await state();
        releaseHold();
        await page.waitForLoadState('load');
        const after = await state();
        record('Visible base colors precede dark initialization when DOMContentLoaded is held', { before, after },
            before.theme === '' && before.background === 'rgb(255, 255, 255)' &&
            after.theme === 'sv-dark' && after.background === 'rgb(17, 17, 17)');
        record('No uncaught browser errors in fixture', errors, errors.length === 0);
        return { browser: page.context().browser().version(), results };
    } finally {
        if (releaseHold) releaseHold();
        await page.unroute('http://dpt-theme-research.test/**');
        page.off('pageerror', onError);
    }
}
module.exports = probe;

if (require.main === module) {
    const fs = require('node:fs');
    const path = require('node:path');
    const { chromium } = require('playwright');
    const root = process.argv[2];
    if (!root) throw new Error('Pass the unpacked SVisciano plugin directory');
    const read = file => fs.readFileSync(path.join(root, file), 'utf8');
    const source = { js: read('assets/js/svis-light-dark.min.js') };
    for (const variant of [1, 2]) {
        source[`toggle${variant}`] = read(`templates/toggles/theme-toggle-${variant}.php`).replace(/<\?php[\s\S]*?\?>/g, '');
        source[`css${variant}`] = read(`assets/css/theme-toggle-${variant}.min.css`);
    }
    (async () => {
        const browser = await chromium.launch({ headless: true });
        try {
            const page = await browser.newPage();
            const report = await probe(page, source);
            console.log(JSON.stringify(report, null, 2));
            process.exitCode = report.results.every(result => result.confirmed) ? 0 : 1;
        } finally {
            await browser.close();
        }
    })().catch(error => { console.error(error); process.exitCode = 1; });
}
