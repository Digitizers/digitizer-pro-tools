# Digitizer Cookie Consent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Digitizer Pro Tools Cookie Banner module as a standalone wordpress.org plugin, "Digitizer Cookie Consent". Digitizer Pro Tools steps aside when the plugin is active.

**Architecture:**
- A new public repository, `Digitizers/digitizer-cookie-consent`, with the tooling of `digitizer-shabbat-keeper`.
- The four module classes are ported by a deterministic rename script, `bin/port-from-dpt.php`. That script is also how later module changes are mirrored.
- After the port, the standalone-only pieces are added by hand:
  - core lifecycle and import;
  - its own settings screen;
  - inline output through the core APIs;
  - an opt-in credit.
- Digitizer Pro Tools gets a stand-down change, as Shabbat Keeper's in 1.40.0.

**Tech Stack:**
- PHP 7.4+ and WordPress 5.7+.
- Vanilla ES5 in `frontend.js`; jQuery only in the admin (color picker, media).
- Tests: the stub harness (`tests/*-test.php`, `php` CLI), plus Node for the inline scripts.
- GitHub Actions; 10up deploy actions.

**Spec:** `docs/superpowers/specs/2026-10-17-cookie-consent-standalone-design.md` in `Digitizers/digitizer-pro-tools`. Read it before any task.

## Global Constraints

- **Name and slug:** "Digitizer Cookie Consent", slug `digitizer-cookie-consent`, repository `Digitizers/digitizer-cookie-consent` (public). Version `1.0.0`. Requires WordPress 5.7, PHP 7.4.
- **Kept names, never renamed:** cookie `dpt_consent`, localStorage `dpt_consent_v1`, `#dpt-cookie-settings`, `.dpt-cb-open`, `[dpt_cookie_settings]`, event `dpt:consent`, and every HTML id or class starting `dpt-cb-`.
- **Renamed** per the spec's "What is renamed" table:
  - class prefix `Digitizer_Cookie_Consent_`;
  - option `digitizer_cookie_consent`;
  - text domain `digitizer-cookie-consent`;
  - JS globals `window.DigitizerCookieConsent`, `DigitizerCookieConsentConfig`, `window.digitizerCookieConsentPurge`;
  - handles `digitizer-cookie-consent`;
  - constants `DIGITIZER_COOKIE_CONSENT_VERSION`, `_URL`, `_PATH`, `_FILE`;
  - query args `digitizer_cc_preview` (nonce) and `digitizer_cc_debug`;
  - admin-post actions `digitizer_cc_save`, `_add_lang`, `_remove_lang`;
  - hooks `digitizer_cookie_consent_open_selector`, `_rtl_langs`, `_purge_caches`.
- **No `window.DPTCB` alias.** No raw `<script>` or `<style>` printed by PHP, except the admin-snippet path and the admin screen.
- **No legal claims** in the readme or the UI: never "compliant", "GDPR compliant" or "Amendment 13 compliant".
- **No external requests.** No telemetry. No paid tier.
- **Commits** end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. **PR bodies** end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- **Every PR** goes through `@codex review` until it is clean on the exact head, then squash-merge.
- **No public action without Ben's explicit "כן" in that turn:** no GitHub Release, no wordpress.org submission, no DPT release.

## Review Focus

The ways this most likely bites a real site, and the test that pins each:

1. **A site switches from the module to the plugin, and every visitor who already chose is asked again.** That happens if the import misses `consent_version`, or the cookie name drifts. Pinned in Task 4: import copies `consent_version`; the precheck reads `dpt_consent`.
2. **A page cache or optimizer defers the head precheck, the banner flashes for visitors who answered, and Consent Mode defaults land after the tags.** Pinned in Task 3: the precheck prints through `wp_print_inline_script_tag()` at `wp_head` priority 1, with `data-no-defer` and `data-cfasync="false"`.
3. **The admin screen loses the color picker or the media picker** because DPT's shared `admin.js` is not there. Pinned in Task 5: `admin.js` initialises `.dpt-color` and the background-image picker on the plugin's screen only.
4. **Both the DPT module and the plugin are active, and the page gets two banners.** Pinned in Task 8: the module's `init()` registers nothing when `Digitizer_Cookie_Consent_Core` exists.
5. **A site added a link with `#dpt-cookie-settings`, or CSS on `#dpt-cb-banner`, and it stops working after the switch.** Pinned in Task 2: the port leaves those strings untouched, and the open-link and design tests run against the ported code.

---

### Task 1: Repository scaffold

**Files (all new, in `~/Documents/GitHub/digitizer-cookie-consent`):**
- `digitizer-cookie-consent.php`, `LICENSE`, `.gitignore`, `.distignore`, `uninstall.php` (a stub for now, filled in Task 4).
- `bin/build-zip.sh`, `bin/build-i18n.php`, `bin/build-assets.sh`.
- `.github/workflows/ci.yml`, `deploy.yml`, `assets.yml`.
- `tests/bootstrap.php`, `tests/scaffold-test.php`.

**Interfaces:**
- Produces:
  - the constants `DIGITIZER_COOKIE_CONSENT_VERSION` (`'1.0.0'`), `_FILE`, `_PATH`, `_URL`;
  - `tests/bootstrap.php` with the stubs and `dpt_test_ok( $cond, $msg )`, `dpt_test_eq( $a, $b, $msg )`, `dpt_test_summary()`.

- [ ] **Step 1: Create the repository** (public, approved with the spec):

```bash
gh repo create Digitizers/digitizer-cookie-consent --public --description "Cookie consent banner for WordPress: categories, blocking until consent, Google Consent Mode v2, Hebrew and multilingual." --clone
cd ~/Documents/GitHub/digitizer-cookie-consent
```

- [ ] **Step 2: Copy the tooling from Shabbat Keeper, with the slug changed:**

```bash
SK=~/Documents/GitHub/digitizer-shabbat-keeper
mkdir -p bin .github/workflows tests languages includes assets/css assets/js .wordpress-org
cp $SK/LICENSE $SK/.distignore $SK/.gitignore .
cp $SK/bin/build-zip.sh $SK/bin/build-i18n.php $SK/bin/build-assets.sh bin/
cp $SK/.github/workflows/*.yml .github/workflows/
grep -rl 'digitizer-shabbat-keeper\|Digitizer Shabbat Keeper\|digitizer_shabbat_keeper' bin .github .distignore | xargs sed -i '' \
  -e 's/digitizer-shabbat-keeper/digitizer-cookie-consent/g' \
  -e 's/Digitizer Shabbat Keeper/Digitizer Cookie Consent/g' \
  -e 's/digitizer_shabbat_keeper/digitizer_cookie_consent/g'
sed -i '' 's#includes/\*.php views/\*.php#includes/*.php#' .github/workflows/ci.yml
grep -rn 'shabbat\|views/' bin .github .distignore || echo "clean"
```

Expected: `clean`. Also, in `bin/build-zip.sh`, remove `design/*|` from the excluded paths only if no `design/` directory is created. Leaving it in is harmless.

- [ ] **Step 3: Write `digitizer-cookie-consent.php`:**

```php
<?php
/**
 * Plugin Name:       Digitizer Cookie Consent
 * Plugin URI:        https://github.com/Digitizers/digitizer-cookie-consent
 * Description:       A cookie consent banner for Hebrew and multilingual sites: categories, scripts blocked until consent, Google Consent Mode v2, the WP Consent API, and the cookies of a refused category deleted. Nothing is sent anywhere.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Digitizer
 * Author URI:        https://www.digitizer.studio
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       digitizer-cookie-consent
 * Domain Path:       /languages
 *
 * @package Digitizer_Cookie_Consent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGITIZER_COOKIE_CONSENT_VERSION', '1.0.0' );
define( 'DIGITIZER_COOKIE_CONSENT_FILE', __FILE__ );
define( 'DIGITIZER_COOKIE_CONSENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGITIZER_COOKIE_CONSENT_URL', plugin_dir_url( __FILE__ ) );
```

The `require_once` lines and the boot hooks come in Tasks 2 and 4.

- [ ] **Step 4: Build `tests/bootstrap.php` from Digitizer Pro Tools' bootstrap.** It is self-contained and already carries every stub the three ported tests use.

```bash
cp ~/Documents/GitHub/digitizer-pro-tools/tests/bootstrap.php tests/bootstrap.php
sed -i '' -e "s/define( 'DPT_VERSION', '[0-9.]*' );/define( 'DPT_VERSION', '0' );/" \
  -e "s#define( 'DPT_BASENAME', 'digitizer-pro-tools/digitizer-pro-tools.php' );#define( 'DPT_BASENAME', 'digitizer-cookie-consent/digitizer-cookie-consent.php' );#" tests/bootstrap.php
cat >> tests/bootstrap.php <<'PHP'

// The plugin's own constants, as the main file defines them.
define( 'DIGITIZER_COOKIE_CONSENT_VERSION', '1.0.0' );
define( 'DIGITIZER_COOKIE_CONSENT_FILE', dirname( __DIR__ ) . '/digitizer-cookie-consent.php' );
define( 'DIGITIZER_COOKIE_CONSENT_PATH', dirname( __DIR__ ) . '/' );
define( 'DIGITIZER_COOKIE_CONSENT_URL', 'https://example.test/wp-content/plugins/digitizer-cookie-consent/' );
PHP
```

- [ ] **Step 5: Write the scaffold test** `tests/scaffold-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
$main = (string) file_get_contents( dirname( __DIR__ ) . '/digitizer-cookie-consent.php' );
dpt_test_ok( false !== strpos( $main, 'Text Domain:       digitizer-cookie-consent' ), 'the text domain is the slug' );
dpt_test_ok( false !== strpos( $main, 'Requires at least: 5.7' ), 'WordPress 5.7, for wp_print_inline_script_tag()' );
dpt_test_ok( false !== strpos( $main, "define( 'DIGITIZER_COOKIE_CONSENT_VERSION', '1.0.0' );" ), 'version 1.0.0' );
foreach ( array( '.github/workflows/deploy.yml', '.github/workflows/assets.yml' ) as $f ) {
	dpt_test_ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/' . $f ), 'SLUG: digitizer-cookie-consent' ), "$f deploys this slug" );
}
exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 6: Run it.** Command: `for f in tests/*-test.php; do php "$f" || exit 1; done`. Expected: `5 passed, 0 failed`.

- [ ] **Step 7: Commit to `main`** (the empty repository's first commit), then branch:

```bash
git add -A && git commit -m "Scaffold: plugin header, tooling from Shabbat Keeper, test harness

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" && git push -u origin main
git checkout -b claude/port
```

---

### Task 2: Port the module by script

**Files:**
- Create `bin/port-from-dpt.php`. It is dev-only, already excluded by `bin/*`.
- Generated:
  - `includes/class-digitizer-cookie-consent-settings.php`, `includes/class-digitizer-cookie-consent-frontend.php`, `includes/class-digitizer-cookie-consent-admin.php`;
  - `assets/css/frontend.css`, `assets/js/frontend.js`;
  - `tests/design-test.php`, `tests/consent-signals-test.php`, `tests/cookie-cleanup-test.php`.
- Modify `digitizer-cookie-consent.php` to add the requires.

**Interfaces:**
- Consumes: the Task 1 constants and bootstrap.
- Produces: the classes `Digitizer_Cookie_Consent_Settings` (`OPTION = 'digitizer_cookie_consent'`, `all()`, `defaults()`, `get()`, `save()`, `install_defaults()`, `cookie_patterns()`), `Digitizer_Cookie_Consent_Frontend` (the same public methods as `DPT_CB_Frontend`) and `Digitizer_Cookie_Consent_Admin`.

- [ ] **Step 1: Write `bin/port-from-dpt.php`.** It is ordered: longer and more specific patterns first. It refuses to run if the result still holds a DPT identifier that the spec renames.

```php
<?php
/**
 * Regenerate the plugin's code from Digitizer Pro Tools' Cookie Banner
 * module. The standalone is a renamed copy; this is the rename, so a module
 * change is mirrored by re-running it and reviewing the diff.
 *
 *   php bin/port-from-dpt.php [path-to-digitizer-pro-tools]
 *
 * Names the spec keeps (the consent cookie, #dpt-cookie-settings,
 * .dpt-cb-open, dpt:consent, the dpt-cb-* HTML ids and classes) are not
 * touched. Development only - not shipped.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
$src  = rtrim( $argv[1] ?? dirname( __DIR__, 2 ) . '/digitizer-pro-tools', '/' );
$root = dirname( __DIR__ );
$map  = array(
	'modules/cookie-banner/class-dpt-cb-settings.php' => 'includes/class-digitizer-cookie-consent-settings.php',
	'modules/cookie-banner/class-dpt-cb-frontend.php' => 'includes/class-digitizer-cookie-consent-frontend.php',
	'modules/cookie-banner/class-dpt-cb-admin.php'    => 'includes/class-digitizer-cookie-consent-admin.php',
	'modules/cookie-banner/assets/css/frontend.css'   => 'assets/css/frontend.css',
	'modules/cookie-banner/assets/js/frontend.js'     => 'assets/js/frontend.js',
	'tests/cb-design-test.php'                        => 'tests/design-test.php',
	'tests/cb-consent-signals-test.php'               => 'tests/consent-signals-test.php',
	'tests/cb-cookie-cleanup-test.php'                => 'tests/cookie-cleanup-test.php',
);
// Order matters: specific before general.
$rename = array(
	"dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-settings.php'" => "dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-settings.php'",
	"dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php'" => "dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-frontend.php'",
	"dirname( __DIR__ ) . '/modules/cookie-banner/assets/js/frontend.js'"     => "dirname( __DIR__ ) . '/assets/js/frontend.js'",
	"dirname( __DIR__ ) . '/modules/cookie-banner/assets/css/frontend.css'"   => "dirname( __DIR__ ) . '/assets/css/frontend.css'",
	"DPT_URL . 'modules/cookie-banner/assets/'" => "DIGITIZER_COOKIE_CONSENT_URL . 'assets/'",
	'DPT_CB_Settings'           => 'Digitizer_Cookie_Consent_Settings',
	'DPT_CB_Frontend'           => 'Digitizer_Cookie_Consent_Frontend',
	'DPT_CB_Admin'              => 'Digitizer_Cookie_Consent_Admin',
	"'dpt_cookie_banner'"       => "'digitizer_cookie_consent'",
	'DPT_CB_CONFIG'             => 'DigitizerCookieConsentConfig',
	// strtr() matches the longest key first, so the bare forms below also
	// cover window.DPTCB, '[DPTCB]' log text, the 'DPTCB' optimizer
	// exclusion marker, and the tests' dptCbPurge assertions.
	'DPTCB'                     => 'DigitizerCookieConsent',
	'dptCbPurge'                => 'digitizerCookieConsentPurge',
	'dpt_cb_open_selector'      => 'digitizer_cookie_consent_open_selector',
	'dpt_cb_rtl_langs'          => 'digitizer_cookie_consent_rtl_langs',
	'dpt_cb_purge_caches'       => 'digitizer_cookie_consent_purge_caches',
	'dpt_cb_preview'            => 'digitizer_cc_preview',
	'dpt_debug'                 => 'digitizer_cc_debug',
	'admin_post_dpt_cb_'        => 'admin_post_digitizer_cc_',
	"'dpt_cb_save'"             => "'digitizer_cc_save'",
	"'dpt_cb_add_lang'"         => "'digitizer_cc_add_lang'",
	"'dpt_cb_remove_lang'"      => "'digitizer_cc_remove_lang'",
	"'dpt-cb-frontend'"         => "'digitizer-cookie-consent'",
	'DPT_VERSION'               => 'DIGITIZER_COOKIE_CONSENT_VERSION',
	"'digitizer-pro-tools'"     => "'digitizer-cookie-consent'",
	'@package Digitizer_Pro_Tools' => '@package Digitizer_Cookie_Consent',
);
foreach ( $map as $from => $to ) {
	$code = file_get_contents( "$src/$from" );
	if ( false === $code ) { fwrite( STDERR, "missing $src/$from\n" ); exit( 1 ); }
	$code = strtr( $code, $rename );
	// Every DPT_ identifier the spec renames must be gone; DPT_BASENAME is
	// replaced in Task 4, DPT_ in comments is allowed.
	foreach ( array( 'DPT_CB_', 'DPTCB', 'dptCbPurge', "'dpt_cookie_banner'", 'DPT_URL', 'DPT_VERSION' ) as $left ) {
		if ( false !== strpos( $code, $left ) ) { fwrite( STDERR, "$to still contains $left\n" ); exit( 1 ); }
	}
	@mkdir( dirname( "$root/$to" ), 0777, true );
	file_put_contents( "$root/$to", $code );
	echo "$from -> $to\n";
}
```

- [ ] **Step 2: Run it, and require the files.**

```bash
php bin/port-from-dpt.php
```

Expected: 8 lines `... -> ...`, exit 0.

Add to `digitizer-cookie-consent.php`, after the defines:

```php
require_once DIGITIZER_COOKIE_CONSENT_PATH . 'includes/class-digitizer-cookie-consent-settings.php';
require_once DIGITIZER_COOKIE_CONSENT_PATH . 'includes/class-digitizer-cookie-consent-frontend.php';
if ( is_admin() ) {
	require_once DIGITIZER_COOKIE_CONSENT_PATH . 'includes/class-digitizer-cookie-consent-admin.php';
}
```

- [ ] **Step 3: Check the kept names survived, and the renamed ones are gone.**

```bash
grep -c "dpt_consent\b\|dpt-cookie-settings\|dpt-cb-open\|dpt:consent" assets/js/frontend.js includes/class-digitizer-cookie-consent-frontend.php
grep -rn "DPT_CB_\|DPTCB\|dptCbPurge\|'dpt_cookie_banner'\|DPT_URL\|DPT_VERSION" includes assets tests | grep -v bootstrap.php || echo "renamed"
```

Expected: non-zero counts on the first grep, and `renamed` on the second.

- [ ] **Step 4: Run the ported tests.** Command: `for f in tests/*-test.php; do php "$f" || exit 1; done`. Expected: all pass, with the same counts as in DPT 1.46.1 (design 67, consent-signals 39, cookie-cleanup 23), plus the scaffold test's 5.

If a test fails on a DPT-only reference, fix the test's path or name, not the behaviour. Then add to `tests/scaffold-test.php` the Review Focus 5 check:

```php
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' );
foreach ( array( "'dpt_consent'", "'dpt_consent_v1'", "'dpt:consent'" ) as $kept ) {
	dpt_test_ok( false !== strpos( $js, $kept ), "kept for sites moving from Digitizer Pro Tools: $kept" );
}
dpt_test_ok( false === strpos( $js, 'DPTCB' ), 'no window.DPTCB alias' );
```

- [ ] **Step 5: Commit.**

```bash
git add -A && git commit -m "Port the Cookie Banner module from Digitizer Pro Tools 1.46.1 by script

bin/port-from-dpt.php renames every PHP and JS global the spec lists and keeps the consent cookie, links, event and dpt-cb-* markup. The module's three test files run against the ported code.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Inline output through the core APIs

**Files:**
- Modify `includes/class-digitizer-cookie-consent-frontend.php`: `inject_inline_consent_check()`, `render_inline_css()`, `render_float_button()`, `enqueue()`.
- Modify `tests/consent-signals-test.php` and `tests/cookie-cleanup-test.php`, so their harness extracts the script from the new tag.
- Create `tests/inline-output-test.php`.
- Add stubs to `tests/bootstrap.php`: `wp_print_inline_script_tag()`, `wp_add_inline_style()`, `wp_register_style()`, `wp_enqueue_style()`.

**Interfaces:**
- Consumes: `Digitizer_Cookie_Consent_Frontend::inject_inline_consent_check()`, which still prints the precheck.
- Produces:
  - `Digitizer_Cookie_Consent_Frontend::precheck_js(): string` (the precheck script body, no tags);
  - `::precheck_css(): string`;
  - `::design_css( array $o ): string` (what `render_inline_css()` printed, without `<style>`).

- [ ] **Step 1: Add the stubs to `tests/bootstrap.php`:**

```php
$GLOBALS['dpt_stub_inline_styles'] = array();
function wp_print_inline_script_tag( $js, $attributes = array() ) {
	$attr = '';
	foreach ( $attributes as $k => $v ) {
		$attr .= true === $v ? ' ' . $k : ' ' . $k . '="' . htmlspecialchars( (string) $v, ENT_QUOTES ) . '"';
	}
	echo '<script' . $attr . ">\n" . $js . "\n</script>\n";
}
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( $h, $src = false, $deps = array(), $ver = false ) { return true; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false ) {} }
function wp_add_inline_style( $handle, $css ) { $GLOBALS['dpt_stub_inline_styles'][ $handle ][] = $css; return true; }
```

- [ ] **Step 2: Write the failing test** `tests/inline-output-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'do_action' ) ) { function do_action( $t ) {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $c ) {} }
require_once dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-frontend.php';
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_no_user']  = true;

$f = new Digitizer_Cookie_Consent_Frontend();
ob_start(); $f->inject_inline_consent_check(); $head = (string) ob_get_clean();
dpt_test_ok( 1 === preg_match( '#<script id="digitizer-cookie-consent-precheck" data-no-defer data-cfasync="false">#', $head ), 'the precheck goes through wp_print_inline_script_tag(), keeping the attributes that stop WP Rocket and Cloudflare deferring it' );
dpt_test_ok( false === strpos( $head, '<style' ), 'no raw <style> in the head' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_inline_styles']['digitizer-cookie-consent-precheck'] ) && false !== strpos( implode( '', $GLOBALS['dpt_stub_inline_styles']['digitizer-cookie-consent-precheck'] ), 'html[data-dpt-cb-resolved="1"] #dpt-cb-banner' ), 'the CSS that hides the banner for a visitor who answered is an inline style on its own handle' );

$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-frontend.php' );
dpt_test_ok( 0 === preg_match_all( '/^\s*<(script|style)[ >]/m', $src ), 'no raw <script> or <style> tag left in the front-end class' );
dpt_test_ok( false === strpos( $src, 'onclick=' ), 'the floating button has no inline onclick; frontend.js binds it' );

global $wp_filter;
dpt_test_ok( dpt_stub_has_filter( 'wp_head' ), 'the precheck is hooked to wp_head' );
exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 3: Run it to see it fail.** Command: `php tests/inline-output-test.php`. Expected: FAIL on the first four assertions.

- [ ] **Step 4: Implement.**
  - **The precheck.** Move the body of the `<script id="dpt-cb-precheck">` block, the PHP-built purge IIFE and the consent IIFE, into `precheck_js()`, which returns the string. Build it with `ob_start()`, then echo the same PHP that is there today, then `ob_get_clean()`, so the escaping contract is unchanged. `inject_inline_consent_check()` becomes:

```php
public function inject_inline_consent_check() {
	if ( is_admin() ) {
		return;
	}
	wp_register_style( 'digitizer-cookie-consent-precheck', false, array(), DIGITIZER_COOKIE_CONSENT_VERSION );
	wp_enqueue_style( 'digitizer-cookie-consent-precheck' );
	wp_add_inline_style( 'digitizer-cookie-consent-precheck', self::precheck_css() );
	wp_print_inline_script_tag(
		$this->precheck_js(),
		array(
			'id'            => 'digitizer-cookie-consent-precheck',
			'data-no-defer' => true,
			'data-cfasync'  => 'false',
		)
	);
}
public static function precheck_css() {
	return 'html[data-dpt-cb-resolved="1"] #dpt-cb-banner,html[data-dpt-cb-resolved="1"] #dpt-cb-overlay{display:none !important;}';
}
```

  - **Printing the precheck style in time.** Keep the hook at `wp_head` priority 1. Core prints enqueued styles at `wp_head` priority 8 (`wp_print_styles`), so a handle enqueued at 1 is printed at 8, still in `<head>` and long before the banner in the footer.
  - **The design CSS.** `render_inline_css( $o )` becomes `design_css( $o )` and returns the CSS string (`ob_start()` around the existing block, minus `<style id="dpt-cb-inline-css">` and `</style>`). `enqueue()` calls `wp_add_inline_style( 'digitizer-cookie-consent', $this->design_css( $o ) )` after enqueueing the stylesheet. `render_banner()` no longer prints CSS.
  - **The floating button.** In `render_float_button()`, delete the `onclick="..."` attribute and the `$inline_open` variable. `frontend.js` already binds `#dpt-cb-float-button`; check with `grep -n "floatBtn.addEventListener" assets/js/frontend.js`.
  - **The test harnesses.** In `tests/consent-signals-test.php` and `tests/cookie-cleanup-test.php`, replace the regex `#<script id="dpt-cb-precheck"[^>]*>(.*?)</script>#s` with `#<script id="digitizer-cookie-consent-precheck"[^>]*>(.*?)</script>#s`.

- [ ] **Step 5: Run all tests.** Command: `for f in tests/*-test.php; do php "$f" || exit 1; done`. Expected: all pass.

- [ ] **Step 6: Commit.**

```bash
git add -A && git commit -m "Inline output through the core APIs: the precheck by wp_print_inline_script_tag(), CSS by wp_add_inline_style(), no inline onclick

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Core: boot, activation import, deactivation, uninstall, first-save notice, credit

**Files:**
- Create `includes/class-digitizer-cookie-consent-core.php`.
- Modify `digitizer-cookie-consent.php`, `uninstall.php`, `includes/class-digitizer-cookie-consent-settings.php` (the `saved` and `credit` defaults), and `includes/class-digitizer-cookie-consent-frontend.php` (the credit line, and replacing `DPT_BASENAME`).
- Test: `tests/core-test.php`.

**Interfaces:**
- Consumes: `Digitizer_Cookie_Consent_Settings::OPTION`, `::sanitize()`. If the module's sanitizer is named differently, use the one `save()` calls; check with `grep -n "function save\|function sanitize" includes/class-digitizer-cookie-consent-settings.php`.
- Produces:
  - `Digitizer_Cookie_Consent_Core::boot()`, `::activate( $network_wide )`, `::deactivate( $network_wide )`, `::import_from_dpt(): bool`, `::seed_site()`;
  - `const DPT_OPTION = 'dpt_cookie_banner'`;
  - `Digitizer_Cookie_Consent_Settings` gains the defaults `'saved' => '0'` and `'credit' => '0'`.

- [ ] **Step 1: Write the failing test** `tests/core-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'do_action' ) ) { function do_action( $t ) {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $c ) {} }
require_once dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-frontend.php';
require_once dirname( __DIR__ ) . '/includes/class-digitizer-cookie-consent-core.php';

/* Import from Digitizer Pro Tools: once, one way, consent version included. */
$GLOBALS['dpt_stub_options'] = array( 'dpt_cookie_banner' => array( 'consent_version' => '7', 'cleanup_analytics' => '_ga', 'btn_stretch' => '1' ) );
Digitizer_Cookie_Consent_Core::activate( false );
$o = get_option( 'digitizer_cookie_consent' );
dpt_test_eq( $o['consent_version'], '7', 'the consent version comes across - a new one would ask every visitor again' );
dpt_test_eq( array( $o['cleanup_analytics'], $o['btn_stretch'], $o['saved'] ), array( '_ga', '1', '1' ), 'settings come across, and count as saved' );
// Once: with the plugin's option in place, a changed source is not imported again.
$GLOBALS['dpt_stub_options']['dpt_cookie_banner']['consent_version'] = '8';
Digitizer_Cookie_Consent_Core::activate( false );
dpt_test_eq( get_option( 'digitizer_cookie_consent' )['consent_version'], '7', 'once: a second activation does not import again' );

// A language the site added in the module comes across with its texts.
$GLOBALS['dpt_stub_options'] = array( 'dpt_cookie_banner' => array( 'languages' => array( 'he', 'en', 'fr' ), 'default_lang' => 'fr', 'texts' => array( 'fr' => array( 'title' => 'Nous utilisons des cookies' ) ) ) );
Digitizer_Cookie_Consent_Core::activate( false );
$o = get_option( 'digitizer_cookie_consent' );
dpt_test_eq( array( $o['languages'], $o['default_lang'], $o['texts']['fr']['title'] ), array( array( 'he', 'en', 'fr' ), 'fr', 'Nous utilisons des cookies' ), 'an added language, its default status and its texts survive the import' );
// The whole option, not a subset: a reset switch, category or lifetime would change tracking or re-ask visitors.
$GLOBALS['dpt_stub_options'] = array( 'dpt_cookie_banner' => array( 'block_scripts' => '0', 'cat_marketing_enabled' => '0', 'consent_mode' => '1', 'consent_days' => '365', 'bg_color' => '#123456', 'policy_url' => 'https://example.test/p/' ) );
Digitizer_Cookie_Consent_Core::activate( false );
$o = get_option( 'digitizer_cookie_consent' );
dpt_test_eq( array( $o['block_scripts'], $o['cat_marketing_enabled'], $o['consent_mode'], $o['consent_days'], $o['bg_color'], $o['policy_url'] ), array( '0', '0', '1', '365', '#123456', 'https://example.test/p/' ), 'every setting comes across: switches, categories, Consent Mode, lifetime, design, policy link' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_options']['dpt_cookie_banner'] ), 'the module\'s option is left alone' );

/* A fresh site: defaults, not saved. */
$GLOBALS['dpt_stub_options'] = array();
Digitizer_Cookie_Consent_Core::activate( false );
$o = get_option( 'digitizer_cookie_consent' );
dpt_test_eq( array( $o['enabled'], $o['saved'], $o['credit'] ), array( '1', '0', '0' ), 'on, not yet saved, no credit' );

/* The credit: absent by default, one line when on. */
$GLOBALS['dpt_stub_is_admin'] = false; $GLOBALS['dpt_stub_no_user'] = true;
$f = new Digitizer_Cookie_Consent_Frontend();
$m = new ReflectionMethod( 'Digitizer_Cookie_Consent_Frontend', 'render_banner_markup' );
if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
ob_start(); $m->invoke( $f, Digitizer_Cookie_Consent_Settings::all(), Digitizer_Cookie_Consent_Settings::get_texts( 'en' ), 'en', 'ltr' ); $html = (string) ob_get_clean();
dpt_test_ok( false === strpos( $html, 'digitizer.studio' ), 'no credit unless switched on' );
$GLOBALS['dpt_stub_options']['digitizer_cookie_consent']['credit'] = '1';
ob_start(); $m->invoke( $f, Digitizer_Cookie_Consent_Settings::all(), Digitizer_Cookie_Consent_Settings::get_texts( 'en' ), 'en', 'ltr' ); $html = (string) ob_get_clean();
dpt_test_ok( 1 === preg_match( '#<p class="dpt-cb-credit"><a href="https://www\.digitizer\.studio" target="_blank" rel="noopener">Cookie consent by Digitizer</a></p>#', $html ), 'switched on: one line under the buttons' );

/* Uninstall removes the option. */
$GLOBALS['dpt_stub_options'] = array( 'digitizer_cookie_consent' => array( 'enabled' => '1' ) );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', true ); }
include dirname( __DIR__ ) . '/uninstall.php';
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_options']['digitizer_cookie_consent'] ), 'uninstall deletes the option' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to see it fail.** Command: `php tests/core-test.php`. Expected: fatal, `Class "Digitizer_Cookie_Consent_Core" not found`.

- [ ] **Step 3: Implement the core.** Model it on `~/Documents/GitHub/digitizer-shabbat-keeper/includes/class-digitizer-shabbat-keeper-core.php` (`boot`, `load_textdomain`, `activate`, `seed_site`, `on_each_site`, `import_from_dpt`, `deactivate`), with these differences:
  - **`boot()`** seeds a site with no option, then `new Digitizer_Cookie_Consent_Frontend();`. Under `is_admin()` it calls `new Digitizer_Cookie_Consent_Admin();`. It hooks `load_textdomain` to `init`, and the first-save notice (Task 5) through the admin class.
  - **`import_from_dpt()` needs an import-specific sanitiser.** `save()` deliberately ignores `languages` (it is managed by add or remove language), and its `sanitize_texts()` keeps texts only for languages already in the destination. Fed through `save()`, a migrated site's added language (say `fr`) and its texts would be lost.
    - Refactor the body of `Digitizer_Cookie_Consent_Settings::save()` into `public static function sanitize( array $raw, array $base ): array`. It returns the clean option and does not write it. `save()` becomes `update_option( self::OPTION, self::sanitize( $raw, self::all() ) )` plus its existing cache purge.
    - Add `Digitizer_Cookie_Consent_Settings::sanitize_import( array $theirs ): array`:

```php
public static function sanitize_import( array $theirs ) {
	$base  = self::defaults();
	$langs = array();
	foreach ( (array) ( $theirs['languages'] ?? array() ) as $code ) {
		$code = self::normalize_lang_code( (string) $code );
		if ( $code && ! in_array( $code, $langs, true ) ) {
			$langs[] = $code;
		}
	}
	if ( $langs ) {
		$base['languages'] = $langs;
		$base['texts']     = array();
		foreach ( $langs as $code ) {
			$base['texts'][ $code ] = self::default_texts( $code );
		}
	}
	// Texts are sanitised against $base, which now holds every source language.
	$clean = self::sanitize( $theirs, $base );
	$clean['languages'] = $base['languages'];
	if ( isset( $theirs['default_lang'] ) && in_array( $theirs['default_lang'], $clean['languages'], true ) ) {
		$clean['default_lang'] = $theirs['default_lang'];
	}
	return $clean;
}
```

    - `import_from_dpt()` calls `sanitize_import( get_option( self::DPT_OPTION ) )`, sets `$clean['saved'] = '1'` and `add_option()`s it.
  - **Legacy hooks (spec: "The module's hooks keep working").**
    - In `Digitizer_Cookie_Consent_Frontend::open_selector()`, after the new filter:
      `$selector = (string) apply_filters( 'dpt_cb_open_selector', $selector );`
    - Likewise where `digitizer_cookie_consent_rtl_langs` is applied: `apply_filters( 'dpt_cb_rtl_langs', $langs )`.
    - After each `do_action( 'digitizer_cookie_consent_purge_caches' )`: `do_action( 'dpt_cb_purge_caches' );`.
    - Tests in `tests/core-test.php`: a callback on `dpt_cb_open_selector` changes `open_selector()`, and one on `dpt_cb_rtl_langs` changes `lang_dir()`.
  - **`deactivate()`** calls `do_action( 'digitizer_cookie_consent_purge_caches' );` on each site. Use the settings class's existing cache-purge helper if it has one (`grep -n "purge" includes/class-digitizer-cookie-consent-settings.php`) and call it directly.
  - **Main file** after the requires:

```php
require_once DIGITIZER_COOKIE_CONSENT_PATH . 'includes/class-digitizer-cookie-consent-core.php';
register_activation_hook( __FILE__, array( 'Digitizer_Cookie_Consent_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Digitizer_Cookie_Consent_Core', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Digitizer_Cookie_Consent_Core', 'boot' ) );
```

  - **Settings.** Add `'saved' => '0'` and `'credit' => '0'` to `defaults()`, and `'credit'` to the checkbox list. `save()` sets `saved` to `'1'`.
  - **Credit.** In `render_banner_markup()`, after the closing `</div>` of `.dpt-cb-buttons` in the main view:

```php
<?php if ( '1' === $o['credit'] ) : ?>
	<p class="dpt-cb-credit"><a href="https://www.digitizer.studio" target="_blank" rel="noopener"><?php esc_html_e( 'Cookie consent by Digitizer', 'digitizer-cookie-consent' ); ?></a></p>
<?php endif; ?>
```

  and in `assets/css/frontend.css`: `#dpt-cb-banner .dpt-cb-credit { margin: 10px 0 0; font-size: 11px; opacity: .7; text-align: center; } #dpt-cb-banner .dpt-cb-credit a { color: inherit; }`.
  - **`DPT_BASENAME`** in the WP Consent API registration becomes `plugin_basename( DIGITIZER_COOKIE_CONSENT_FILE )`. Change the `defined( 'DPT_BASENAME' )` check to `defined( 'DIGITIZER_COOKIE_CONSENT_FILE' )`. In `tests/consent-signals-test.php`, the expected filter name becomes `wp_consent_api_registered_digitizer-cookie-consent/digitizer-cookie-consent.php`. Add a `plugin_basename()` stub to the bootstrap if it is missing: `function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }`.
  - **`uninstall.php`** follows `digitizer-shabbat-keeper/uninstall.php`'s closure-per-site shape, deleting `digitizer_cookie_consent`.

- [ ] **Step 4: Run all tests.** Command: `for f in tests/*-test.php; do php "$f" || exit 1; done`. Expected: all pass.

- [ ] **Step 5: Commit.**

```bash
git add -A && git commit -m "Core: boot, import from Digitizer Pro Tools on activation (consent version included), cache purge on deactivation, uninstall, opt-in credit

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The plugin's own settings screen

**Files:**
- Modify `includes/class-digitizer-cookie-consent-admin.php`.
- Create `assets/css/admin.css` and `assets/js/admin.js`.
- Test: `tests/admin-test.php`.

**Interfaces:**
- Consumes: `Digitizer_Cookie_Consent_Settings::all()`, `::save()`; the `saved` flag from Task 4.
- Produces:
  - `Digitizer_Cookie_Consent_Admin::__construct()`, which hooks `admin_menu`, `admin_enqueue_scripts`, `plugin_action_links_{basename}`, `admin_notices`, and the three admin-post actions;
  - `::screen_hook(): string`;
  - `::preview_url(): string` (with a nonce).

- [ ] **Step 1: Write the failing test** `tests/admin-test.php`. It asserts:
  - `admin_menu` registers an options page with slug `digitizer-cookie-consent` (stub `add_options_page`, recording its arguments);
  - the row link contains `options-general.php?page=digitizer-cookie-consent`;
  - `admin.js` and `admin.css` are enqueued only when `$hook === Digitizer_Cookie_Consent_Admin::screen_hook()`;
  - `admin.js` contains `.wpColorPicker()` on `.dpt-color`, and `wp.media` for `bg_image_url`;
  - the notice shows while `saved` is `'0'`, and not once it is `'1'`;
  - `preview_url()` contains `_wpnonce=`;
  - the front end shows the forced preview only when `wp_verify_nonce()` passes (stub it to return the global `$GLOBALS['dpt_stub_nonce_ok']`).

  Write each assertion with `dpt_test_ok`, in the style of the earlier tasks.

- [ ] **Step 2: Run it to see it fail.** Command: `php tests/admin-test.php`.

- [ ] **Step 3: Implement.**
  - `register_menu( $parent_slug )` becomes `add_options_page( __( 'Cookie Consent', 'digitizer-cookie-consent' ), __( 'Cookie Consent', 'digitizer-cookie-consent' ), 'manage_options', 'digitizer-cookie-consent', array( $this, 'render_page' ) )` on `admin_menu`.
  - Every link that pointed at the DPT submenu now points at `options-general.php?page=digitizer-cookie-consent`: the tabs, the redirects after save, and add or remove language. Find them with `grep -n "admin.php?page=\|page=dpt" includes/class-digitizer-cookie-consent-admin.php`.
  - **Copy into `assets/js/admin.js`** the colour picker init and the background-image media picker from `~/Documents/GitHub/digitizer-pro-tools/assets/js/admin.js` (the `.dpt-color` and `dpt_cb[bg_image_*]` parts). Rename `dpt_cb[` to `digitizer_cc[` only if the form's field names changed. They did not, so keep `dpt_cb[`.
  - **Copy into `assets/css/admin.css`** the rules the screen's markup uses: the switch, tabs, `.dpt-section-heading`, `.dpt-preview-*`, `.dpt-info-box`, `.dpt-big-desc`, `.dpt-version`. Find them with `grep -n "dpt-switch\|dpt-tab\|dpt-section-heading\|dpt-preview\|dpt-info-box\|dpt-big-desc\|dpt-version" ~/Documents/GitHub/digitizer-pro-tools/assets/css/admin.css`.
  - **Enqueue** on `admin_enqueue_scripts` when `$hook === $this->screen_hook`:
    - `wp_enqueue_style( 'wp-color-picker' )`;
    - `wp_enqueue_media()`;
    - `wp_enqueue_style( 'digitizer-cookie-consent-admin', … )`;
    - `wp_enqueue_script( 'digitizer-cookie-consent-admin', …, array( 'jquery', 'wp-color-picker' ), …, true )`.
  - **Row link:** `add_filter( 'plugin_action_links_' . plugin_basename( DIGITIZER_COOKIE_CONSENT_FILE ), … )`.
  - **Notice:** on `admin_notices`, on the `plugins` and `dashboard` screens only, when `saved` is `'0'`: "Digitizer Cookie Consent is showing the banner with its default texts. Review the texts and categories." It links to the screen.
  - **Preview:** `preview_url()` returns `wp_nonce_url( home_url( '/?digitizer_cc_preview=1' ), 'digitizer_cc_preview' )`. On the front end, the preview check becomes `isset( $_GET['digitizer_cc_preview'] ) && current_user_can( 'manage_options' ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'digitizer_cc_preview' )`.

- [ ] **Step 4: Run all tests.** Expected: all pass.

- [ ] **Step 5: Commit** with the message `"Settings → Cookie Consent: own admin assets, row link, first-save notice, preview nonce"` and the Co-Authored-By line.

---

### Task 6: Translations

**Files:**
- `languages/digitizer-cookie-consent.pot`, `-he_IL.po`, `.mo`, `.l10n.php`, generated by `bin/build-i18n.php`.
- `tests/i18n-test.php`.

- [ ] **Step 1: Seed `languages/digitizer-cookie-consent-he_IL.po`** with every msgid the plugin uses and its Hebrew from Digitizer Pro Tools' catalog. The new strings get Hebrew here:
  - `Cookie Consent` → `הסכמה לעוגיות`
  - `Cookie consent by Digitizer` → `הסכמה לעוגיות מבית דיגיטייזר`
  - `Digitizer Cookie Consent is showing the banner with its default texts. Review the texts and categories.` → `Digitizer Cookie Consent מציג את הבאנר עם טקסטי ברירת המחדל. כדאי לעבור על הטקסטים והקטגוריות.`
  - `Settings` → `הגדרות`
  - `Show "Cookie consent by Digitizer" under the buttons` → `הצגת "הסכמה לעוגיות מבית דיגיטייזר" מתחת לכפתורים`

  The script that pulls the rest:

```bash
php -r '
$po = file_get_contents(getenv("HOME")."/Documents/GitHub/digitizer-pro-tools/languages/digitizer-pro-tools-he_IL.po");
preg_match_all("/^msgid \"((?:[^\"\\\\]|\\\\.)*)\"\nmsgstr \"((?:[^\"\\\\]|\\\\.)*)\"/m", $po, $m, PREG_SET_ORDER);
$out = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: he_IL\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
foreach ($m as $x) { if ($x[1] !== "") { $out .= "\nmsgid \"$x[1]\"\nmsgstr \"$x[2]\"\n"; } }
file_put_contents("languages/digitizer-cookie-consent-he_IL.po", $out);'
```

  Then append the five new pairs.

- [ ] **Step 2: Build.** `bin/build-i18n.php` keeps only the msgids found in the source, and fails on any string without Hebrew:

```bash
php bin/build-i18n.php && msgfmt -o languages/digitizer-cookie-consent-he_IL.mo languages/digitizer-cookie-consent-he_IL.po
```

  Expected: no "missing" error. If a string is reported missing, add its Hebrew to the `.po` and rerun.

- [ ] **Step 3: Test** `tests/i18n-test.php`:
  - every `__`, `esc_html__`, `esc_attr__`, `esc_html_e` and `esc_attr_e` call in `includes/*.php` uses the domain `'digitizer-cookie-consent'`: `0 === preg_match_all("/(__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\( '(?:[^'\\\\]|\\\\.)*', '(?!digitizer-cookie-consent')/", $src)`;
  - the `.l10n.php` returns an array whose `messages` has an entry for `Cookie consent by Digitizer`.

- [ ] **Step 4: Core loads translations.** In `Digitizer_Cookie_Consent_Core::boot()`, copy `load_textdomain()` from Shabbat Keeper's core with the domain changed. On WordPress 6.8+ it returns early, and below that it calls `load_plugin_textdomain( 'digitizer-cookie-consent', false, dirname( plugin_basename( DIGITIZER_COOKIE_CONSENT_FILE ) ) . '/languages' )` with the same phpcs comment.

- [ ] **Step 5: Run all tests, then commit** with the message `"Hebrew catalog, built from the module's and the new strings"`.

---

### Task 7: Readme, directory assets, Plugin Check, PR

**Files:**
- `readme.txt`;
- `.wordpress-org/` (banner 772×250 and 1544×500, icon 128 and 256, `screenshot-1..4.png`);
- `bin/build-assets.sh`.

- [ ] **Step 1: Write `readme.txt`.**
  - **Header:** `Contributors: benkalsky`, `Tags: cookies, consent, privacy, gdpr, cookie banner`, `Requires at least: 5.7`, `Tested up to:` the value in `~/Documents/GitHub/digitizer-shabbat-keeper/readme.txt`, `Requires PHP: 7.4`, `Stable tag: 1.0.0`, `License: GPLv2 or later`.
  - **Short description, at most 150 characters:** `A cookie consent banner for Hebrew and multilingual sites: categories, blocking until consent, Google Consent Mode v2, and cookies deleted on refusal.`
  - **Sections:**
    - Description: the features the spec lists, and the cache-proof display.
    - "Built for Hebrew and multilingual sites": texts per language, RTL.
    - "Admin-provided scripts": what is stored and that it needs `unfiltered_html`.
    - "External services": none; Google's tags are the site's own.
    - FAQ:
      - "Does it make my site compliant?": it gives you the tools; whether a site complies depends on what it does; check with your own legal advice.
      - "Does it send data anywhere?": no.
      - "Moving from Digitizer Pro Tools?": the settings and visitors' choices come across.
    - Screenshots 1–4, and a 1.0.0 changelog.
  - **No legal claims.** Check with: `grep -in "complian" readme.txt` must show only the FAQ question.

- [ ] **Step 2: Directory assets.**
  - **Banner and icon:** adapt `bin/build-assets.sh` from Shabbat Keeper with the text "Digitizer Cookie Consent" and a cookie motif. The script renders HTML to PNG.
  - **Screenshots:** capture them from WordPress Playground, `https://playground.wordpress.net/?plugin=…&language=he_IL&url=…`, using the built zip uploaded to a temporary location, or a Blueprint that installs from the GitHub branch zip. Four shots:
    - the banner in Hebrew;
    - the banner in English;
    - the preferences view;
    - the settings screen.
  - Save them at 1280 px wide.

- [ ] **Step 3: Build and check.**

```bash
bin/build-zip.sh && unzip -l dist/digitizer-cookie-consent.zip | grep -c "tests/\|bin/\|.github" # expect 0
```

  Push the branch; CI's `plugin-check` job runs WordPress.org's Plugin Check on the built files. Expected: no errors. A `load_plugin_textdomain` notice is acceptable, as it was for Shabbat Keeper.

- [ ] **Step 4: Open the PR, then Codex.**

```bash
git push -u origin claude/port
gh pr create --title "Digitizer Cookie Consent 1.0.0" --body "<summary of tasks 2-7, the test counts, the Plugin Check result>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
gh pr comment --body "@codex review"
```

  Loop until it is clean on the exact head, then squash-merge, then `git checkout main && git pull`.

- [ ] **Step 5: Stop.** Submitting to wordpress.org is Ben's action from his account. Report the built zip's path and ask.

---

### Task 8: Digitizer Pro Tools stands down

**Repository:** `~/Documents/GitHub/digitizer-pro-tools`, branch `claude/cb-stand-down`.

**Files:**
- Modify `modules/cookie-banner/class-dpt-cb-module.php`;
- the catalogs (one string);
- `readme.txt`;
- `digitizer-pro-tools.php` (version 1.47.0).
- Test: `tests/cb-module-test.php`.

**Interfaces:**
- Produces: `DPT_Cookie_Banner_Module::standalone_active(): bool`, and the overrides `user_can_toggle()` and `toggle_denied_reason()`, mirroring `DPT_Shabbat_Keeper_Module`.

- [ ] **Step 1: Write the failing test** `tests/cb-module-test.php`. Copy `tests/sk-module-test.php` and replace:
  - `DPT_Shabbat_Keeper_Module` → `DPT_Cookie_Banner_Module`;
  - `Digitizer_Shabbat_Keeper_Core` → `Digitizer_Cookie_Consent_Core`;
  - the hook assertions:
    - without the standalone, `new DPT_CB_Frontend()` registers `wp_footer`;
    - with it, `init()` leaves `$GLOBALS['dpt_stub_filters']` empty;
  - the reason, which must contain `Digitizer Cookie Consent` and `Settings`.

  Keep the hoisting comment and the `register_admin_menu` assertions.

- [ ] **Step 2: Run it to see it fail.**

- [ ] **Step 3: Implement**, copying `modules/shabbat-keeper/class-dpt-sk-module.php`'s `standalone_active()`, `user_can_toggle()`, `toggle_denied_reason()` and the early returns in `init()` and `register_admin_menu()`.
  - **Reason:** `The standalone Digitizer Cookie Consent plugin is active and does this module's work, so the switch is locked - its settings are under Settings → Cookie Consent.`
  - **Hebrew:** `התוסף הנפרד Digitizer Cookie Consent פעיל ועושה את העבודה של המודול הזה, ולכן המתג נעול - ההגדרות שלו נמצאות תחת הגדרות ← הסכמה לעוגיות.`
  - Add the pair to `.pot`, `.po` and `.l10n.php`, then `msgfmt`.

- [ ] **Step 4: Version and changelog.**
  - Bump to 1.47.0 in the header, `DPT_VERSION` and `Stable tag`.
  - Readme: `= 1.47.0 = * Cookie Banner: stands down when the standalone Digitizer Cookie Consent plugin is active - the module's switch is locked and says where the plugin's settings are. The plugin imports the module's settings and keeps visitors' choices.`

- [ ] **Step 5: Run the full suite, open the PR, Codex until clean, merge.** Release 1.47.0 only on Ben's "כן".

---

## Execution order and gates

1. Tasks 1–7 in the new repository, in order. Task 7 ends at a merged PR and a built zip.
2. **Gate, Ben:** submission to wordpress.org from his account.
3. Task 8 in Digitizer Pro Tools, during the directory review.
4. **Gate, Ben:** the DPT 1.47.0 release.
5. **After directory approval, gate, Ben:** GitHub Release 1.0.0 of the plugin, which deploys to SVN, then `assets.yml` for the banner, icon and screenshots.
