# Theme Switch validation

Related: [digitizer-tasks#284](https://github.com/Digitizers/digitizer-tasks/issues/284).
Implementation follows the [source research](research/2026-11-14-theme-switch.md).

## Contract

The module ships off. When enabled, `[dpt_theme_switch]` renders three native
buttons for Light, Dark and System. The original site palette remains the base;
only administrator-mapped variables change in the opposite mode. Elementor Kit
colors are discovered through `kits_manager->get_active_kit()->get_settings()`.
The adapter reads `system_colors` and `custom_colors`; it never saves Kit or page
data. A global color that is redefined on a descendant is still local to that
descendant, and a fixed widget color is unchanged.

Visitor preference lives in localStorage, namespaced by the site's home URL.
Explicit choices override device preference; selecting System resumes following
the device. Unavailable storage preserves working controls for the current page.
Cached HTML contains no visitor-specific preference. All controls, including
later-inserted ones and controls in other tabs, share that preference.

The dependency-free head script runs synchronously after styles are printed. It
sets the root mode before body content renders and also handles execution after
DOMContentLoaded. CSS opts only the switch into the resolved `color-scheme`;
native form controls elsewhere retain the site's own scheme.
Without JavaScript, the original design remains and inactive controls are hidden.

With no valid alternate colors, the shortcode emits nothing and no frontend
assets are enqueued. The settings screen and enabled module card explain why.
System remains the shipped first-visit default, explicitly described next to the
selector: a dark-device visitor gets the dark palette without clicking a switch.

The document observer starts only when the page contains a switch or Elementor
content. Custom injectors adding the first switch to a previously idle page call
`window.DPTThemeSwitch.refresh()` after insertion; that reveals and synchronizes
the control and starts observation once. Repeated refresh calls are safe.

## Follow-up regressions (2026-11-19, #105)

Before fixing, module tests reported 3 failures for empty palette behavior, admin
tests reported 2 failures for missing explanations/warnings, and the focused
browser probe reproduced native input/select colors changing from white/black
to dark/white plus an observer on a plain page with no controls. The missing
explicit refresh API was also recorded before adding it.

After fixing, the two PHP files pass 23 and 11 assertions respectively, and all
9 focused browser checks pass in Chromium 157.0.8103.79. The browser probe uses
the actual shipped JS/CSS with a fixed white HTML form, real native controls and
an instrumented native MutationObserver (not a mock). It also covers automatic
builder insertion, synchronization and idempotent refresh on a previously idle
page. Run `node tests/theme-switch-followups.cjs` where Playwright is available.

The original WordPress/Elementor checks below are historical results from the
initial implementation, not a claim that those integration checks were repeated
for this follow-up. The existing browser fixture assertion now inspects the
switch's scheme rather than requiring a site-wide scheme.

## Recorded checks (2026-11-14)

- 42 PHP test files, 2,654 assertions: all passed. New tests cover settings/CSS
  validation, cache invalidation, default-off registration, head ordering,
  permission and nonce rejection, shortcode semantics and narrow optimization
  exclusions. The first module test run exposed a missing `home_url` stub in the
  test harness; the stub was added and the suite passed.
- Real WordPress 7.1.4 + Elementor 4.4.0 + Hello Elementor 3.5.2, using SQLite
  Database Integration 3.1.3 on PHP 8.5.7. Chromium 157.0.8103.51: 20/20 browser
  checks passed in an RTL fixture at a 390px viewport. See
  [`tests/theme-switch-browser.cjs`](../tests/theme-switch-browser.cjs).
- A separate real-Kit dark-base check passed: original dark background and text
  restored correctly, while selecting Light applied white background/dark text.
- First-render check held an Elementor footer script: while `readyState` was
  still `loading`, the dark background and global heading already had the correct
  computed colors. This tests ordering, not a network/CPU performance budget.
- Both compiled Hebrew catalogs contain all 17 Theme Switch translations. PHP
  and JS syntax checks passed. `msgfmt --check` reported only the pre-existing
  missing Last-Translator/Language-Team metadata warnings.
- Settings/controls were also inspected visually in the RTL dark fixture.

WordPress accounts are not needed for this fixture: schema/options/roles are
initialized directly with **zero user rows**. The fixture seeds Kit/page data
directly because there is no editor account. WordPress and Elementor still parse
and render the resulting real Kit, global colors and widgets, and WordPress emits
the module's real enqueued assets. Fixture scripts require a marked installation
under `/private/tmp`; they are excluded from the release ZIP.

## Reproduce the integration checks

Use an empty directory under `/private/tmp`, with these unpacked packages:

```
dpt-theme-integration/
  wordpress/                    WordPress 7.1.4
  elementor/                    Elementor 4.4.0
  sqlite-database-integration/   SQLite Database Integration 3.1.3
```

Place Hello Elementor 3.5.2 in `wordpress/wp-content/themes/hello-elementor`.
Symlink the three plugin directories (Elementor, SQLite integration and this DPT
checkout) into `wordpress/wp-content/plugins/`. Copy SQLite integration's
`db.copy` to `wordpress/wp-content/db.php`.

Use this **account-free SQLite fixture configuration** as `wordpress/wp-config.php`
(no database service or credentials are used):

```php
<?php
define('DPT_THEME_FIXTURE', true);
define('DB_NAME', 'dpt_fixture');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
define('WP_HOME', 'http://127.0.0.1:8876');
define('WP_SITEURL', 'http://127.0.0.1:8876');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_EDIT', true);
define('WP_DEBUG', false);
$table_prefix = 'wp_';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
```

From the DPT repository:

```bash
php tests/fixtures/theme-switch-install.php /private/tmp/dpt-theme-integration/wordpress
php tests/fixtures/theme-switch-palette.php /private/tmp/dpt-theme-integration/wordpress
cp tests/fixtures/theme-switch-page.php /private/tmp/dpt-theme-integration/wordpress/probe.php
php -S 127.0.0.1:8876 -t /private/tmp/dpt-theme-integration/wordpress
```

In another terminal, with Playwright/Chromium already available in that caller's
Node environment:

```bash
node tests/theme-switch-browser.cjs http://127.0.0.1:8876/probe.php
```

The exported function can also be run by a Playwright connector with a browser
instance and the fixture URL. No runtime or build dependency is added to DPT.
The fixture page invokes Elementor's frontend renderer explicitly around real
WordPress hooks; it is a controlled integration harness, not an editor UI test.

## Sources and limits

- [WordPress inline script ordering](https://developer.wordpress.org/reference/functions/wp_add_inline_script/)
  and [styles attached to an enqueued handle](https://developer.wordpress.org/reference/functions/wp_add_inline_style/).
- [Elementor Kit-backed global styles](https://developers.elementor.com/docs/data-structure/global-styles/).
  The actual Kit API and `--e-global-color-{id}` generation were also checked in
  the downloaded Elementor 4.4.0 source.
- [localStorage exceptions](https://developer.mozilla.org/en-US/docs/Web/API/Window/localStorage#exceptions)
  and [native color-scheme behavior](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/color-scheme).

No client site or production service was used. WP Rocket filter registration is
covered, but its paid optimization pipeline was not installed or exercised.
Safari/Firefox, screen readers, actual full-page-cache software, Elementor's
editor UI and WooCommerce checkout were not exercised. Site-specific contrast,
fixed widget colors and alternate image artwork still need design review.
