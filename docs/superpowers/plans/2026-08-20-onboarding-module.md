# Onboarding Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Onboarding module — an admin wizard that brings a fresh client site to the Digitizer baseline by installing and activating one theme, one child theme and twelve plugins.

**Architecture:** Five small classes under `modules/onboarding/`. A code-only manifest lists the baseline; a state reader reports what the site already has; a source resolver turns an item into a ZIP URL (WordPress.org API or the GitHub releases API); an installer drives core's own `Plugin_Upgrader`/`Theme_Upgrader` one item at a time; an admin class renders the checklist and exposes a single AJAX endpoint that applies exactly one item per request. Decision logic is deliberately split out as pure functions so it can be tested without WordPress.

**Tech Stack:** PHP 7.4+, WordPress 5.8+, no Composer dependencies, no build step. Tests are plain PHP scripts run with the `php` CLI against hand-written WordPress stubs.

**Spec:** `docs/superpowers/specs/2026-08-20-onboarding-module-design.md`

## Global Constraints

- Target version **1.20.0**. Bump the header and `DPT_VERSION` in `digitizer-pro-tools.php` and `Stable tag` in `readme.txt`, and add a `= 1.20.0 =` changelog entry.
- Branch: `claude/onboarding-module`, already created from `origin/main`.
- Text domain `digitizer-pro-tools`. Every user-facing string is translatable **and** gets a Hebrew translation. Product names (Elementor, Wordfence, …) are data, not translatable strings.
- Prefixes: classes `DPT_ONB_*`, functions/options/hooks `dpt_onb_*`, module id `onboarding`, option key `dpt_onboarding`.
- PHP 7.4 syntax floor — no arrow-function-only constructs that break on 7.4, no `match`, no constructor property promotion, no named arguments.
- **The manifest is code.** There must be no admin field anywhere in this module that accepts a URL, a path, or a slug to install. Anything not found in the manifest is rejected before work begins.
- **Install and activate only.** Never update, downgrade, remove, or write another plugin's options.
- Escaping and input rules follow the repo's existing conventions; `~/.composer/vendor/bin/phpcs` with the four sniffs listed in Task 7 must report zero.
- Verified slugs, do not re-derive them: `hello-elementor` (theme), `angie`, `cloudflare`, `elementor`, `fluent-smtp`, `imagify`, `contact-forms-anti-spam`, `seo-by-rank-math`, `wordfence`, `insert-headers-and-footers`, `digitizer-site-worker`. GitHub repos: `Digitizers/hello-digitizer`, `Digitizers/elementor-mcp`, `WordPress/mcp-adapter`.

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `modules/onboarding/class-dpt-onb-manifest.php` | The baseline list. Pure data, no WordPress calls. |
| `modules/onboarding/class-dpt-onb-state.php` | What the site has now: `missing` / `inactive` / `active` per item. |
| `modules/onboarding/class-dpt-onb-source.php` | Item → downloadable ZIP URL. WordPress.org API and GitHub releases API. |
| `modules/onboarding/class-dpt-onb-installer.php` | Applies one item. Decision logic, upgrader wiring, extracted-directory rename, theme-activation gate. |
| `modules/onboarding/class-dpt-onb-admin.php` | Wizard screen and the one-item AJAX endpoint. |
| `modules/onboarding/class-dpt-onb-module.php` | `DPT_Module` implementation; wires the admin class. |
| `modules/onboarding/assets/js/wizard.js` | Walks the ticked rows, one request per item, updates each row. |
| `modules/onboarding/assets/css/wizard.css` | Wizard table styling. |
| `tests/bootstrap.php` | Shared WordPress stubs and the `ok()` / `eq()` assertion helpers. |
| `tests/onb-manifest-test.php`, `tests/onb-state-test.php`, `tests/onb-source-test.php`, `tests/onb-installer-test.php`, `tests/onb-defaults-test.php` | One test script per unit. |
| `tests/README.md` | How to run the suite. |

**Modified:**

| File | Change |
|---|---|
| `includes/class-dpt-plugin.php` | Register the module; flip every other module's `default` to `'0'`. |
| `includes/class-dpt-module.php` | Remove the dead `enabled_by_default()` method. |
| 11 module classes | Remove their dead `enabled_by_default()` overrides. |
| `uninstall.php` | Delete `dpt_onboarding`. |
| `bin/build-zip.sh` | Exclude `tests/` and `docs/` from the shipped ZIP. |
| `digitizer-pro-tools.php`, `readme.txt` | Version 1.20.0 and changelog. |
| `languages/*` | Hebrew for the new strings. |

**Why tests move into the repo.** This repo's harnesses have lived in a scratchpad directory that is wiped between sessions, so nobody but the author could ever run them. A plan written for an engineer with no context cannot depend on that. Tests go in `tests/`, and `bin/build-zip.sh` grows two exclusions so they never ship to a site.

---

### Task 1: Test harness in the repo

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/README.md`
- Modify: `bin/build-zip.sh:30`

**Interfaces:**
- Consumes: nothing.
- Produces: `dpt_test_ok( bool $cond, string $label )`, `dpt_test_eq( $actual, $expected, string $label )`, `dpt_test_summary()` (prints the tally, returns the failure count). Global stub state arrays `$GLOBALS['dpt_stub_plugins']`, `$GLOBALS['dpt_stub_active_plugins']`, `$GLOBALS['dpt_stub_stylesheet']`, `$GLOBALS['dpt_stub_transients']`, `$GLOBALS['dpt_stub_http']`.

- [ ] **Step 1: Write the bootstrap**

Create `tests/bootstrap.php`:

```php
<?php
/**
 * Shared harness for the Digitizer Pro Tools unit tests.
 *
 * There is no WordPress in this environment, so each test defines the small
 * slice of WordPress its subject touches and requires the real module file.
 * Stub state lives in globals so a test can rearrange the "site" between
 * assertions.
 *
 * Run one:  php tests/onb-manifest-test.php
 * Run all:  for f in tests/*-test.php; do php "$f" || exit 1; done
 */

define( 'ABSPATH', '/tmp/' );
define( 'DPT_VERSION', '1.20.0' );
define( 'DPT_PATH', dirname( __DIR__ ) . '/' );
define( 'DPT_URL', 'https://example.test/wp-content/plugins/digitizer-pro-tools/' );
define( 'DPT_BASENAME', 'digitizer-pro-tools/digitizer-pro-tools.php' );
define( 'DPT_OPTION', 'dpt_settings' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dpt_test_pass']        = 0;
$GLOBALS['dpt_test_fail']        = 0;
$GLOBALS['dpt_stub_plugins']     = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
$GLOBALS['dpt_stub_stylesheet']  = 'twentytwentyfour';
$GLOBALS['dpt_stub_themes']      = array();
$GLOBALS['dpt_stub_transients']  = array();
$GLOBALS['dpt_stub_http']        = array();
$GLOBALS['dpt_stub_options']     = array();

function dpt_test_ok( $cond, $label ) {
    if ( $cond ) {
        $GLOBALS['dpt_test_pass']++;
        return;
    }
    $GLOBALS['dpt_test_fail']++;
    echo "FAIL: $label\n";
}

function dpt_test_eq( $actual, $expected, $label ) {
    if ( $actual === $expected ) {
        $GLOBALS['dpt_test_pass']++;
        return;
    }
    $GLOBALS['dpt_test_fail']++;
    echo "FAIL: $label\n";
    echo '  expected: ' . var_export( $expected, true ) . "\n";
    echo '  actual:   ' . var_export( $actual, true ) . "\n";
}

function dpt_test_summary() {
    printf( "\n%d passed, %d failed\n", $GLOBALS['dpt_test_pass'], $GLOBALS['dpt_test_fail'] );
    return $GLOBALS['dpt_test_fail'];
}

/* ------------------------------------------------------------ WP stubs */

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return is_array( $s ) ? array_map( 'stripslashes', $s ) : stripslashes( (string) $s ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function remove_filter() {}
function did_action() { return 0; }
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE ); }

function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['dpt_stub_options'] ) ? $GLOBALS['dpt_stub_options'][ $key ] : $default;
}
function update_option( $key, $value ) {
    $GLOBALS['dpt_stub_options'][ $key ] = $value;
    return true;
}

function get_transient( $key ) {
    return array_key_exists( $key, $GLOBALS['dpt_stub_transients'] ) ? $GLOBALS['dpt_stub_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
    $GLOBALS['dpt_stub_transients'][ $key ] = $value;
    return true;
}

function get_plugins() { return $GLOBALS['dpt_stub_plugins']; }
function is_plugin_active( $file ) { return in_array( $file, $GLOBALS['dpt_stub_active_plugins'], true ); }
function get_stylesheet() { return $GLOBALS['dpt_stub_stylesheet']; }

class DPT_Stub_Theme {
    private $exists;
    public function __construct( $exists ) { $this->exists = $exists; }
    public function exists() { return $this->exists; }
}
function wp_get_theme( $slug = '' ) {
    return new DPT_Stub_Theme( in_array( $slug, $GLOBALS['dpt_stub_themes'], true ) );
}

/**
 * Canned HTTP. Keys are URLs; values are array( 'code' => int, 'body' => string ).
 * An unknown URL is a hard failure, so a test can never accidentally depend on
 * a real network call.
 */
function wp_remote_get( $url, $args = array() ) {
    if ( ! isset( $GLOBALS['dpt_stub_http'][ $url ] ) ) {
        return new WP_Error( 'stub_miss', 'No canned response for ' . $url );
    }
    return $GLOBALS['dpt_stub_http'][ $url ];
}
function wp_remote_retrieve_response_code( $res ) { return is_array( $res ) ? (int) $res['code'] : 0; }
function wp_remote_retrieve_body( $res ) { return is_array( $res ) ? (string) $res['body'] : ''; }
```

- [ ] **Step 2: Write the runner note**

Create `tests/README.md`:

```markdown
# Tests

Plain PHP scripts, no PHPUnit. Each defines the slice of WordPress its subject
touches (see `bootstrap.php`) and requires the real module file.

Run one:

    php tests/onb-manifest-test.php

Run all:

    for f in tests/*-test.php; do php "$f" || exit 1; done

A script exits non-zero if any assertion failed, so the loop stops on the first
failing file.

These files are excluded from the built ZIP by `bin/build-zip.sh`.
```

- [ ] **Step 3: Exclude tests and docs from the ZIP**

In `bin/build-zip.sh`, the `case` statement currently reads:

```bash
		case "$f" in
			.github/*|bin/*|dist/*|.gitignore|WPORG.md|*.code-workspace) continue ;;
		esac
```

Change it to:

```bash
		case "$f" in
			.github/*|bin/*|dist/*|docs/*|tests/*|.gitignore|WPORG.md|*.code-workspace) continue ;;
		esac
```

- [ ] **Step 4: Verify the harness loads and the ZIP shrinks**

Run:

```bash
php -l tests/bootstrap.php
php -r 'require "tests/bootstrap.php"; dpt_test_ok( true, "harness loads" ); exit( dpt_test_summary() > 0 ? 1 : 0 );'
bin/build-zip.sh && unzip -l dist/digitizer-pro-tools.zip | grep -c -E 'tests/|docs/'
```

Expected: no syntax errors; `1 passed, 0 failed`; the final `grep -c` prints `0`.

- [ ] **Step 5: Commit**

```bash
git add tests/bootstrap.php tests/README.md bin/build-zip.sh
git commit -m "Move the test harness into the repo

The stub harnesses have lived in a scratchpad directory that is wiped between
sessions, so nobody but their author could run them. They move to tests/, with
the shared WordPress stubs in one bootstrap, and bin/build-zip.sh grows two
exclusions so neither tests/ nor docs/ ever reaches a site."
```

---

### Task 2: The manifest

**Files:**
- Create: `modules/onboarding/class-dpt-onb-manifest.php`
- Test: `tests/onb-manifest-test.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `DPT_ONB_Manifest::items(): array` — ordered list of item arrays with keys `id`, `label`, `type` (`'plugin'|'theme'`), `source` (`'wporg'|'github'`), `slug`, and optionally `repo` (github only) and `parent` (child theme only).
  - `DPT_ONB_Manifest::get( string $id ): array|null` — one item by id, `null` when unknown.

- [ ] **Step 1: Write the failing test**

Create `tests/onb-manifest-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';

$items = DPT_ONB_Manifest::items();

dpt_test_eq( count( $items ), 14, 'the baseline has fourteen items' );

// Ids are unique - a duplicate would make get() ambiguous and would let the
// wizard apply the same item twice.
$ids = array_column( $items, 'id' );
dpt_test_eq( count( array_unique( $ids ) ), count( $ids ), 'every id is unique' );

// Slugs are unique per type, since the slug is the directory the item lands in.
foreach ( array( 'plugin', 'theme' ) as $type ) {
    $slugs = array();
    foreach ( $items as $item ) {
        if ( $type === $item['type'] ) {
            $slugs[] = $item['slug'];
        }
    }
    dpt_test_eq( count( array_unique( $slugs ) ), count( $slugs ), "every $type slug is unique" );
}

foreach ( $items as $item ) {
    $id = $item['id'];
    dpt_test_ok( in_array( $item['type'], array( 'plugin', 'theme' ), true ), "$id has a valid type" );
    dpt_test_ok( in_array( $item['source'], array( 'wporg', 'github' ), true ), "$id has a valid source" );
    dpt_test_ok( isset( $item['label'] ) && '' !== $item['label'], "$id has a label" );
    dpt_test_ok( isset( $item['slug'] ) && '' !== $item['slug'], "$id declares the directory it installs into" );
    dpt_test_ok( $item['id'] === sanitize_key( $item['id'] ), "$id is a safe key" );
    if ( 'github' === $item['source'] ) {
        dpt_test_ok(
            isset( $item['repo'] ) && 1 === preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $item['repo'] ),
            "$id declares a well-formed owner/repo"
        );
    }
}

// A child theme must come after its parent: the wizard applies items in this
// order, and installing a child before its parent leaves a broken theme.
$positions = array();
foreach ( $items as $i => $item ) {
    $positions[ $item['slug'] ] = $i;
}
foreach ( $items as $i => $item ) {
    if ( ! empty( $item['parent'] ) ) {
        dpt_test_ok( isset( $positions[ $item['parent'] ] ), $item['id'] . ' names a parent that is in the manifest' );
        dpt_test_ok( $positions[ $item['parent'] ] < $i, $item['id'] . ' comes after its parent' );
    }
}

// Every slug the design fixed, spelled out here so a typo in the manifest is a
// test failure rather than a 404 on a client site.
$expected_slugs = array(
    'hello-elementor', 'hello-digitizer', 'angie', 'cloudflare', 'elementor',
    'fluent-smtp', 'imagify', 'contact-forms-anti-spam', 'seo-by-rank-math',
    'wordfence', 'insert-headers-and-footers', 'digitizer-site-worker',
    'elementor-mcp', 'mcp-adapter',
);
sort( $expected_slugs );
$actual_slugs = array_column( $items, 'slug' );
sort( $actual_slugs );
dpt_test_eq( $actual_slugs, $expected_slugs, 'the manifest holds exactly the agreed slugs' );

dpt_test_ok( null !== DPT_ONB_Manifest::get( 'elementor' ), 'get() finds a known id' );
dpt_test_eq( DPT_ONB_Manifest::get( 'no-such-item' ), null, 'get() returns null for an unknown id' );
dpt_test_eq( DPT_ONB_Manifest::get( '../../evil' ), null, 'get() returns null for a traversal attempt' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/onb-manifest-test.php`
Expected: fatal error, `Failed opening required .../class-dpt-onb-manifest.php`.

- [ ] **Step 3: Write the manifest**

Create `modules/onboarding/class-dpt-onb-manifest.php`:

```php
<?php
/**
 * Onboarding module - the Digitizer baseline.
 *
 * Deliberately code and not a settings screen. An admin field that accepts a
 * ZIP URL or a slug is an arbitrary-code-execution surface, and this list is
 * fixed: adding to it means editing this file and shipping a release, which is
 * the right amount of friction for something that installs code on client
 * sites.
 *
 * Order matters and is part of the contract - the wizard applies items in this
 * order, so a parent theme precedes its child.
 *
 * Labels are product names, not translatable strings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Manifest {

	/**
	 * The baseline, in application order.
	 *
	 * Each item:
	 *   id     - stable key used by the AJAX endpoint and the checklist.
	 *   label  - product name shown in the wizard.
	 *   type   - 'plugin' or 'theme'.
	 *   source - 'wporg' or 'github'.
	 *   slug   - the directory the item must end up in. For wporg items this
	 *            is also the API slug; for github items it is the name we
	 *            rename the extracted directory to.
	 *   repo   - 'owner/name', github items only.
	 *   parent - parent theme slug, child themes only.
	 *
	 * @return array[]
	 */
	public static function items() {
		return array(
			array(
				'id'     => 'hello_elementor',
				'label'  => 'Hello Elementor',
				'type'   => 'theme',
				'source' => 'wporg',
				'slug'   => 'hello-elementor',
			),
			array(
				'id'     => 'hello_digitizer',
				'label'  => 'Hello Digitizer',
				'type'   => 'theme',
				'source' => 'github',
				'repo'   => 'Digitizers/hello-digitizer',
				'slug'   => 'hello-digitizer',
				'parent' => 'hello-elementor',
			),
			array(
				'id'     => 'elementor',
				'label'  => 'Elementor',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'elementor',
			),
			array(
				'id'     => 'angie',
				'label'  => 'Angie - Agentic AI',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'angie',
			),
			array(
				'id'     => 'cloudflare',
				'label'  => 'Cloudflare',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'cloudflare',
			),
			array(
				'id'     => 'fluent_smtp',
				'label'  => 'FluentSMTP',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'fluent-smtp',
			),
			array(
				'id'     => 'imagify',
				'label'  => 'Imagify',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'imagify',
			),
			array(
				'id'     => 'maspik',
				'label'  => 'Maspik',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'contact-forms-anti-spam',
			),
			array(
				'id'     => 'rank_math',
				'label'  => 'Rank Math SEO',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'seo-by-rank-math',
			),
			array(
				'id'     => 'wordfence',
				'label'  => 'Wordfence Security',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'wordfence',
			),
			array(
				'id'     => 'wpcode',
				'label'  => 'WPCode',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'insert-headers-and-footers',
			),
			array(
				'id'     => 'siteagent',
				'label'  => 'SiteAgent for Aura',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'digitizer-site-worker',
			),
			array(
				'id'     => 'elementor_mcp',
				'label'  => 'Elementor MCP',
				'type'   => 'plugin',
				'source' => 'github',
				'repo'   => 'Digitizers/elementor-mcp',
				'slug'   => 'elementor-mcp',
			),
			array(
				'id'     => 'mcp_adapter',
				'label'  => 'MCP Adapter',
				'type'   => 'plugin',
				'source' => 'github',
				'repo'   => 'WordPress/mcp-adapter',
				'slug'   => 'mcp-adapter',
			),
		);
	}

	/**
	 * One item by id.
	 *
	 * Every entry point looks the id up here, so an id that is not in the
	 * manifest can never reach the installer.
	 *
	 * @param string $id Item id.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}
		foreach ( self::items() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/onb-manifest-test.php`
Expected: `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add modules/onboarding/class-dpt-onb-manifest.php tests/onb-manifest-test.php
git commit -m "Onboarding: the baseline manifest

Fourteen items in application order, with the parent theme ahead of its child.
Slugs were verified against the WordPress.org API; the test asserts the exact
set so a typo fails here rather than 404ing on a client site."
```

---

### Task 3: Reading the site's current state

**Files:**
- Create: `modules/onboarding/class-dpt-onb-state.php`
- Test: `tests/onb-state-test.php`

**Interfaces:**
- Consumes: `DPT_ONB_Manifest::items()`, `DPT_ONB_Manifest::get()`.
- Produces:
  - `DPT_ONB_State::of( array $item ): string` — one of `'missing'`, `'inactive'`, `'active'`.
  - `DPT_ONB_State::plugin_file( string $slug ): string|null` — the `dir/file.php` key for an installed plugin, or `null`.
  - `DPT_ONB_State::all(): array` — `[ item_id => state ]` for every manifest item.

- [ ] **Step 1: Write the failing test**

Create `tests/onb-state-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';

$plugin = array( 'id' => 'elementor', 'type' => 'plugin', 'slug' => 'elementor' );
$theme  = array( 'id' => 'hello_elementor', 'type' => 'theme', 'slug' => 'hello-elementor' );

/* ---- plugins ---- */

$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'missing', 'an absent plugin is missing' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'elementor' ), null, 'no file for an absent plugin' );

$GLOBALS['dpt_stub_plugins'] = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'inactive', 'an installed but inactive plugin is inactive' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'elementor' ), 'elementor/elementor.php', 'the plugin file is resolved' );

$GLOBALS['dpt_stub_active_plugins'] = array( 'elementor/elementor.php' );
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'active', 'an active plugin is active' );

// The case a naive slug/slug.php implementation gets wrong. Several of the
// baseline plugins do not name their main file after their directory -
// Rank Math's is seo-by-rank-math/rank-math.php.
$GLOBALS['dpt_stub_plugins']        = array( 'seo-by-rank-math/rank-math.php' => array( 'Name' => 'Rank Math' ) );
$GLOBALS['dpt_stub_active_plugins'] = array();
$rm = array( 'id' => 'rank_math', 'type' => 'plugin', 'slug' => 'seo-by-rank-math' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'seo-by-rank-math' ), 'seo-by-rank-math/rank-math.php', 'the main file need not match the directory' );
dpt_test_eq( DPT_ONB_State::of( $rm ), 'inactive', 'such a plugin is still detected' );

// A single-file plugin sits at the plugins root, where dirname() is '.'. It
// must never be mistaken for a directory match.
$GLOBALS['dpt_stub_plugins'] = array( 'hello.php' => array( 'Name' => 'Hello Dolly' ) );
dpt_test_eq( DPT_ONB_State::plugin_file( 'hello' ), null, 'a root-level single-file plugin is not matched' );
dpt_test_eq( DPT_ONB_State::plugin_file( '.' ), null, 'a dot slug matches nothing' );

/* ---- themes ---- */

$GLOBALS['dpt_stub_themes']     = array();
$GLOBALS['dpt_stub_stylesheet'] = 'twentytwentyfour';
dpt_test_eq( DPT_ONB_State::of( $theme ), 'missing', 'an absent theme is missing' );

$GLOBALS['dpt_stub_themes'] = array( 'hello-elementor' );
dpt_test_eq( DPT_ONB_State::of( $theme ), 'inactive', 'an installed but inactive theme is inactive' );

$GLOBALS['dpt_stub_stylesheet'] = 'hello-elementor';
dpt_test_eq( DPT_ONB_State::of( $theme ), 'active', 'the current stylesheet is the active theme' );

/* ---- all() ---- */

$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
$GLOBALS['dpt_stub_themes']         = array();
$GLOBALS['dpt_stub_stylesheet']     = 'twentytwentyfour';
$all = DPT_ONB_State::all();
dpt_test_eq( count( $all ), count( DPT_ONB_Manifest::items() ), 'all() covers every manifest item' );
dpt_test_eq( $all['elementor'], 'missing', 'all() reports a missing item' );
dpt_test_ok( array_keys( $all ) === array_column( DPT_ONB_Manifest::items(), 'id' ), 'all() preserves manifest order' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/onb-state-test.php`
Expected: fatal error, `Failed opening required .../class-dpt-onb-state.php`.

- [ ] **Step 3: Write the state reader**

Create `modules/onboarding/class-dpt-onb-state.php`:

```php
<?php
/**
 * Onboarding module - what the site already has.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_State {

	const MISSING  = 'missing';
	const INACTIVE = 'inactive';
	const ACTIVE   = 'active';

	/**
	 * The installed plugin whose directory is $slug.
	 *
	 * Matching is on the directory, not on the file name: a plugin's main file
	 * is not reliably <slug>/<slug>.php - Rank Math installs as
	 * seo-by-rank-math/rank-math.php - and guessing the file name reports a
	 * present plugin as missing, which would make the wizard install it a
	 * second time.
	 *
	 * @param string $slug Plugin directory.
	 * @return string|null The dir/file.php key, or null when not installed.
	 */
	public static function plugin_file( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug || '.' === $slug ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			// A single-file plugin lives at the plugins root, where dirname()
			// is '.', and belongs to no slug.
			$dir = dirname( $file );
			if ( '.' !== $dir && $dir === $slug ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * State of one manifest item.
	 *
	 * @param array $item Manifest item.
	 * @return string One of the class constants.
	 */
	public static function of( $item ) {
		if ( 'theme' === $item['type'] ) {
			$theme = wp_get_theme( $item['slug'] );
			if ( ! $theme->exists() ) {
				return self::MISSING;
			}
			return ( get_stylesheet() === $item['slug'] ) ? self::ACTIVE : self::INACTIVE;
		}

		$file = self::plugin_file( $item['slug'] );
		if ( null === $file ) {
			return self::MISSING;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $file ) ? self::ACTIVE : self::INACTIVE;
	}

	/**
	 * State of every manifest item, in manifest order.
	 *
	 * @return array item id => state
	 */
	public static function all() {
		$out = array();
		foreach ( DPT_ONB_Manifest::items() as $item ) {
			$out[ $item['id'] ] = self::of( $item );
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/onb-state-test.php`
Expected: `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add modules/onboarding/class-dpt-onb-state.php tests/onb-state-test.php
git commit -m "Onboarding: read what the site already has

Plugin lookup matches on the directory rather than guessing <slug>/<slug>.php.
Several baseline plugins do not name their main file after their directory -
Rank Math is seo-by-rank-math/rank-math.php - and guessing would report an
installed plugin as missing and install it twice."
```

---

### Task 4: Resolving an item to a ZIP URL

**Files:**
- Create: `modules/onboarding/class-dpt-onb-source.php`
- Test: `tests/onb-source-test.php`

**Interfaces:**
- Consumes: manifest item arrays.
- Produces:
  - `DPT_ONB_Source::pick_asset( array $release ): string|null` — pure; the best ZIP URL in a GitHub release payload.
  - `DPT_ONB_Source::github_zip_url( array $item ): string|WP_Error`
  - `DPT_ONB_Source::zip_url( array $item ): string|WP_Error`
  - `DPT_ONB_Source::TRANSIENT_PREFIX` = `'dpt_onb_gh_'`, `DPT_ONB_Source::CACHE_TTL` = `6 * HOUR_IN_SECONDS`.

- [ ] **Step 1: Write the failing test**

Create `tests/onb-source-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';

/* ---- pick_asset(): pure ---- */

$with_zip = array(
    'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0',
    'assets'      => array(
        array( 'browser_download_url' => 'https://example.test/notes.txt' ),
        array( 'browser_download_url' => 'https://example.test/elementor-mcp.zip' ),
    ),
);
dpt_test_eq(
    DPT_ONB_Source::pick_asset( $with_zip ),
    'https://example.test/elementor-mcp.zip',
    'a release asset ZIP wins over the zipball'
);

// A release whose assets are all source archives: GitHub attaches
// Source code (zip) as zipball_url, not as an asset, so "no ZIP assets" must
// fall back rather than fail.
$no_zip_assets = array(
    'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0',
    'assets'      => array( array( 'browser_download_url' => 'https://example.test/checksums.txt' ) ),
);
dpt_test_eq(
    DPT_ONB_Source::pick_asset( $no_zip_assets ),
    'https://api.github.com/repos/o/r/zipball/v1.0.0',
    'falls back to the zipball when no asset is a ZIP'
);

$no_assets = array( 'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0', 'assets' => array() );
dpt_test_eq( DPT_ONB_Source::pick_asset( $no_assets ), 'https://api.github.com/repos/o/r/zipball/v1.0.0', 'an empty asset list falls back' );

dpt_test_eq( DPT_ONB_Source::pick_asset( array() ), null, 'a payload with neither assets nor a zipball yields null' );

// Case and query strings must not defeat the .zip check.
$upper = array( 'assets' => array( array( 'browser_download_url' => 'https://example.test/Build.ZIP' ) ) );
dpt_test_eq( DPT_ONB_Source::pick_asset( $upper ), 'https://example.test/Build.ZIP', 'the extension check is case-insensitive' );

// A non-https asset must never be chosen.
$insecure = array(
    'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1',
    'assets'      => array( array( 'browser_download_url' => 'http://example.test/plugin.zip' ) ),
);
dpt_test_eq( DPT_ONB_Source::pick_asset( $insecure ), 'https://api.github.com/repos/o/r/zipball/v1', 'a plain-http asset is refused' );

/* ---- github_zip_url(): HTTP + cache ---- */

$item = array( 'id' => 'elementor_mcp', 'type' => 'plugin', 'source' => 'github', 'repo' => 'Digitizers/elementor-mcp', 'slug' => 'elementor-mcp' );
$api  = 'https://api.github.com/repos/Digitizers/elementor-mcp/releases/latest';

$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array(
    $api => array( 'code' => 200, 'body' => wp_json_encode( $with_zip ) ),
);
dpt_test_eq( DPT_ONB_Source::github_zip_url( $item ), 'https://example.test/elementor-mcp.zip', 'a published release resolves to its asset' );

// Second call must not touch HTTP at all.
$GLOBALS['dpt_stub_http'] = array();
dpt_test_eq( DPT_ONB_Source::github_zip_url( $item ), 'https://example.test/elementor-mcp.zip', 'the resolved URL is cached' );

// No releases: GitHub answers 404, and the default-branch zipball is used.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 404, 'body' => '{"message":"Not Found"}' ) );
dpt_test_eq(
    DPT_ONB_Source::github_zip_url( $item ),
    'https://api.github.com/repos/Digitizers/elementor-mcp/zipball',
    'a repository with no releases falls back to the branch zipball'
);

// Rate limiting must surface as an error, not as a silent wrong URL.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 403, 'body' => '{"message":"API rate limit exceeded"}' ) );
$res = DPT_ONB_Source::github_zip_url( $item );
dpt_test_ok( is_wp_error( $res ), 'a rate-limit response is an error' );
dpt_test_eq( is_wp_error( $res ) ? $res->get_error_code() : '', 'dpt_onb_github_http', 'the error names the failing step' );

// A transport failure is an error too.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array();
dpt_test_ok( is_wp_error( DPT_ONB_Source::github_zip_url( $item ) ), 'a transport failure is an error' );

// Malformed JSON must not be treated as an empty release.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 200, 'body' => 'not json' ) );
dpt_test_ok( is_wp_error( DPT_ONB_Source::github_zip_url( $item ) ), 'malformed JSON is an error' );

// An error is never cached - the next attempt must retry.
dpt_test_eq( $GLOBALS['dpt_stub_transients'], array(), 'failures are not cached' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/onb-source-test.php`
Expected: fatal error, `Failed opening required .../class-dpt-onb-source.php`.

- [ ] **Step 3: Write the source resolver**

Create `modules/onboarding/class-dpt-onb-source.php`:

```php
<?php
/**
 * Onboarding module - turning a manifest item into a downloadable ZIP.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Source {

	const TRANSIENT_PREFIX = 'dpt_onb_gh_';
	const CACHE_TTL        = 6 * HOUR_IN_SECONDS;

	/**
	 * The best ZIP URL in a GitHub release payload.
	 *
	 * Prefers a published release asset, because that is a real build; falls
	 * back to the source zipball, which is what a repository without release
	 * assets offers. Pure - no HTTP, no options - so the choice is testable.
	 *
	 * @param array $release Decoded release payload.
	 * @return string|null
	 */
	public static function pick_asset( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$url = (string) $asset['browser_download_url'];
				// https only: this URL is handed to the upgrader, which will
				// download and execute what comes back.
				if ( 0 !== stripos( $url, 'https://' ) ) {
					continue;
				}
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( '.zip' === strtolower( substr( $path, -4 ) ) ) {
					return $url;
				}
			}
		}
		if ( ! empty( $release['zipball_url'] ) ) {
			return (string) $release['zipball_url'];
		}
		return null;
	}

	/**
	 * ZIP URL for a GitHub item.
	 *
	 * Cached for six hours. GitHub allows 60 unauthenticated requests per hour
	 * per IP, and a shared host shares that budget with every other site on it,
	 * so an uncached lookup per item per attempt is not affordable. Failures
	 * are never cached - a rate limit clears, and the next run should retry.
	 *
	 * @param array $item Manifest item.
	 * @return string|WP_Error
	 */
	public static function github_zip_url( $item ) {
		$repo = $item['repo'];
		$key  = self::TRANSIENT_PREFIX . md5( $repo );

		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$res = wp_remote_get( $api, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				// A bare wp_remote_get() sends WordPress's default agent,
				// which embeds the site URL. There is no reason to disclose a
				// client's address to GitHub for a public release lookup.
				'User-Agent' => 'Digitizer Pro Tools/' . DPT_VERSION,
			),
		) );

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'dpt_onb_github_http', $res->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $res );

		// 404 means the repository publishes no releases - not an error, just
		// the other source.
		if ( 404 === $code ) {
			$url = 'https://api.github.com/repos/' . $repo . '/zipball';
			set_transient( $key, $url, self::CACHE_TTL );
			return $url;
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'dpt_onb_github_http',
				sprintf(
					/* translators: 1: repository, 2: HTTP status code */
					__( 'GitHub answered %2$d for %1$s.', 'digitizer-pro-tools' ),
					$repo,
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'dpt_onb_github_parse', __( 'GitHub returned a response that could not be read.', 'digitizer-pro-tools' ) );
		}

		$url = self::pick_asset( $body );
		if ( null === $url ) {
			return new WP_Error( 'dpt_onb_github_asset', __( 'That release has no downloadable archive.', 'digitizer-pro-tools' ) );
		}

		set_transient( $key, $url, self::CACHE_TTL );
		return $url;
	}

	/**
	 * ZIP URL for any manifest item.
	 *
	 * @param array $item Manifest item.
	 * @return string|WP_Error
	 */
	public static function zip_url( $item ) {
		if ( 'github' === $item['source'] ) {
			return self::github_zip_url( $item );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$args = array( 'slug' => $item['slug'], 'fields' => array( 'sections' => false ) );
		$res  = ( 'theme' === $item['type'] )
			? themes_api( 'theme_information', $args )
			: plugins_api( 'plugin_information', $args );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res->download_link ) ) {
			return new WP_Error(
				'dpt_onb_wporg_no_link',
				sprintf(
					/* translators: %s: item slug */
					__( 'WordPress.org returned no download for %s.', 'digitizer-pro-tools' ),
					$item['slug']
				)
			);
		}
		return (string) $res->download_link;
	}
}
```

Add `wp_parse_url` to `tests/bootstrap.php` (it is used by `pick_asset`):

```php
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/onb-source-test.php`
Expected: `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add modules/onboarding/class-dpt-onb-source.php tests/onb-source-test.php tests/bootstrap.php
git commit -m "Onboarding: resolve an item to a ZIP URL

WordPress.org items go through plugins_api()/themes_api(). GitHub items prefer
a release asset and fall back to the branch zipball when a repository has no
releases; the result is cached for six hours because GitHub allows sixty
unauthenticated calls an hour per IP and shared hosts share that budget.
Failures are never cached. Only https assets are accepted, since the URL is
handed to the upgrader to download and execute."
```

---

### Task 5: The installer

**Files:**
- Create: `modules/onboarding/class-dpt-onb-installer.php`
- Test: `tests/onb-installer-test.php`

**Interfaces:**
- Consumes: `DPT_ONB_Manifest::get()`, `DPT_ONB_State::of()`, `DPT_ONB_State::plugin_file()`, `DPT_ONB_Source::zip_url()`.
- Produces:
  - `DPT_ONB_Installer::action_for( string $state ): string` — `'install'|'activate'|'skip'`.
  - `DPT_ONB_Installer::may_activate_theme( string $current_stylesheet ): bool`
  - `DPT_ONB_Installer::desired_source_path( string $source, string $slug ): string`
  - `DPT_ONB_Installer::apply( string $item_id ): array` — `['id'=>, 'outcome'=>, 'message'=>]` where outcome is `installed|activated|skipped|failed`.

- [ ] **Step 1: Write the failing test**

Create `tests/onb-installer-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-installer.php';

/* ---- the decision table ---- */

dpt_test_eq( DPT_ONB_Installer::action_for( 'missing' ), 'install', 'a missing item is installed' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'inactive' ), 'activate', 'an installed item is only activated' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'active' ), 'skip', 'an active item is left alone' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'nonsense' ), 'skip', 'an unknown state is skipped, never installed' );

/* ---- theme activation gate ---- */

dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentytwentyfour' ), 'a default theme may be replaced' );
dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentyten' ), 'an old default theme may be replaced' );
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'astra' ), 'a live custom theme is never replaced' );
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'hello-digitizer' ), 'an already-correct theme is not re-activated here' );
// Guard against a theme that merely starts with the same letters.
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'twenty-something-custom' ), 'only real default theme slugs count' );

/* ---- extracted-directory rename ---- */

dpt_test_eq(
    DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/elementor-mcp-a1b2c3d/', 'elementor-mcp' ),
    '/tmp/wp/upgrade/elementor-mcp/',
    'a zipball directory is renamed to the manifest slug'
);
dpt_test_eq(
    DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/elementor-mcp/', 'elementor-mcp' ),
    '/tmp/wp/upgrade/elementor-mcp/',
    'an already-correct directory is unchanged'
);
dpt_test_eq(
    DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/WordPress-mcp-adapter-9f8e7d6/', 'mcp-adapter' ),
    '/tmp/wp/upgrade/mcp-adapter/',
    'the owner prefix GitHub adds is stripped too'
);

/* ---- apply(): unknown ids never reach the upgrader ---- */

$res = DPT_ONB_Installer::apply( 'no-such-item' );
dpt_test_eq( $res['outcome'], 'failed', 'an unknown id fails' );
dpt_test_eq( $res['id'], 'no-such-item', 'the result echoes the id it was given' );

$res = DPT_ONB_Installer::apply( '../../../wp-config' );
dpt_test_eq( $res['outcome'], 'failed', 'a traversal attempt fails' );

/* ---- apply(): an active item short-circuits before any network work ---- */

$GLOBALS['dpt_stub_plugins']        = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
$GLOBALS['dpt_stub_active_plugins'] = array( 'elementor/elementor.php' );
$GLOBALS['dpt_stub_http']           = array(); // any HTTP call would be a stub miss
$res = DPT_ONB_Installer::apply( 'elementor' );
dpt_test_eq( $res['outcome'], 'skipped', 'an already-active plugin is skipped' );
dpt_test_ok( '' !== $res['message'], 'a skip still carries a message for the summary' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/onb-installer-test.php`
Expected: fatal error, `Failed opening required .../class-dpt-onb-installer.php`.

- [ ] **Step 3: Write the installer**

Create `modules/onboarding/class-dpt-onb-installer.php`:

```php
<?php
/**
 * Onboarding module - applying one baseline item.
 *
 * The decision logic is separated from the WordPress calls so it can be tested
 * without a filesystem: action_for(), may_activate_theme() and
 * desired_source_path() are pure.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Installer {

	/**
	 * Default theme slugs shipped by WordPress. A site still running one of
	 * these has not had its design chosen yet, which is the only situation in
	 * which switching the theme is safe.
	 */
	const DEFAULT_THEMES = array(
		'twentyten', 'twentyeleven', 'twentytwelve', 'twentythirteen',
		'twentyfourteen', 'twentyfifteen', 'twentysixteen', 'twentyseventeen',
		'twentynineteen', 'twentytwenty', 'twentytwentyone', 'twentytwentytwo',
		'twentytwentythree', 'twentytwentyfour', 'twentytwentyfive',
	);

	/**
	 * What to do about an item in a given state.
	 *
	 * An unrecognised state skips rather than installs: the fail-safe here is
	 * to do nothing to a site we cannot describe.
	 *
	 * @param string $state One of the DPT_ONB_State constants.
	 * @return string 'install', 'activate' or 'skip'.
	 */
	public static function action_for( $state ) {
		if ( DPT_ONB_State::MISSING === $state ) {
			return 'install';
		}
		if ( DPT_ONB_State::INACTIVE === $state ) {
			return 'activate';
		}
		return 'skip';
	}

	/**
	 * Whether the child theme may be activated automatically.
	 *
	 * Activating a theme changes what every visitor sees. A site already
	 * running a chosen theme must never have it swapped out by a tool the
	 * operator ran to install plugins, so this is true only while the site is
	 * still on a WordPress default.
	 *
	 * @param string $current_stylesheet Active theme slug.
	 * @return bool
	 */
	public static function may_activate_theme( $current_stylesheet ) {
		return in_array( $current_stylesheet, self::DEFAULT_THEMES, true );
	}

	/**
	 * Where an extracted archive must be moved before it is installed.
	 *
	 * A GitHub zipball extracts to <owner>-<repo>-<sha>, which changes with
	 * every commit. Installed as-is, the plugin lands in a different directory
	 * each time: WordPress sees a new plugin, the previous copy is orphaned and
	 * still loaded, and the site ends up running two versions at once.
	 *
	 * @param string $source Directory the archive extracted to (trailing slash).
	 * @param string $slug   Directory it must become.
	 * @return string
	 */
	public static function desired_source_path( $source, $slug ) {
		return trailingslashit( dirname( untrailingslashit( $source ) ) ) . $slug . '/';
	}

	/**
	 * Apply one item by id.
	 *
	 * @param string $item_id Manifest item id.
	 * @return array id, outcome ('installed'|'activated'|'skipped'|'failed'), message.
	 */
	public static function apply( $item_id ) {
		$item = DPT_ONB_Manifest::get( $item_id );
		if ( null === $item ) {
			return self::result( $item_id, 'failed', __( 'That item is not part of the baseline.', 'digitizer-pro-tools' ) );
		}

		$state  = DPT_ONB_State::of( $item );
		$action = self::action_for( $state );

		if ( 'skip' === $action ) {
			return self::result( $item_id, 'skipped', __( 'Already active.', 'digitizer-pro-tools' ) );
		}

		if ( 'install' === $action ) {
			$installed = self::install( $item );
			if ( is_wp_error( $installed ) ) {
				return self::result( $item_id, 'failed', $installed->get_error_message() );
			}
		}

		$activated = self::activate( $item );
		if ( is_wp_error( $activated ) ) {
			// Reported as a failure on purpose. Calling a half-applied item a
			// success leaves the summary claiming a plugin is in place that is
			// not running.
			return self::result(
				$item_id,
				'failed',
				'install' === $action
					? sprintf(
						/* translators: %s: the underlying error */
						__( 'Installed, but could not be activated: %s', 'digitizer-pro-tools' ),
						$activated->get_error_message()
					)
					: $activated->get_error_message()
			);
		}

		if ( 'deferred' === $activated ) {
			return self::result(
				$item_id,
				'installed',
				__( 'Installed. Not activated, because this site already uses a custom theme - switch it under Appearance > Themes when you are ready.', 'digitizer-pro-tools' )
			);
		}

		return self::result(
			$item_id,
			'install' === $action ? 'installed' : 'activated',
			'install' === $action ? __( 'Installed and activated.', 'digitizer-pro-tools' ) : __( 'Activated.', 'digitizer-pro-tools' )
		);
	}

	/**
	 * Download and unpack one item.
	 *
	 * @param array $item Manifest item.
	 * @return true|WP_Error
	 */
	private static function install( $item ) {
		$url = DPT_ONB_Source::zip_url( $item );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin = new WP_Ajax_Upgrader_Skin();

		// GitHub archives extract to a directory named after the commit, so
		// the extracted folder has to be renamed before it is copied into
		// place. The filter is attached for this one install and detached
		// immediately, so it can never rename an archive belonging to some
		// other plugin's install running in the same request.
		$rename = null;
		if ( 'github' === $item['source'] ) {
			$slug   = $item['slug'];
			$rename = function ( $source, $remote_source, $upgrader, $args = array() ) use ( $slug ) {
				global $wp_filesystem;
				$desired = DPT_ONB_Installer::desired_source_path( $source, $slug );
				if ( $source === $desired ) {
					return $source;
				}
				if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
					return new WP_Error(
						'dpt_onb_rename_failed',
						__( 'Could not normalise the downloaded folder name.', 'digitizer-pro-tools' )
					);
				}
				return $desired;
			};
			add_filter( 'upgrader_source_selection', $rename, 10, 4 );
		}

		if ( 'theme' === $item['type'] ) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			$upgrader = new Plugin_Upgrader( $skin );
		}

		$result = $upgrader->install( $url );

		if ( null !== $rename ) {
			remove_filter( 'upgrader_source_selection', $rename, 10 );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->get_error_message() ) {
				return $errors;
			}
			return new WP_Error( 'dpt_onb_install_failed', __( 'The installation did not complete. The site may not be able to write to its own files - see FS_METHOD.', 'digitizer-pro-tools' ) );
		}
		return true;
	}

	/**
	 * Activate one installed item.
	 *
	 * @param array $item Manifest item.
	 * @return true|string|WP_Error True, the string 'deferred' when a theme was
	 *                              deliberately left inactive, or an error.
	 */
	private static function activate( $item ) {
		if ( 'theme' === $item['type'] ) {
			// The parent exists to be a parent; activating it would undo the
			// child.
			if ( empty( $item['parent'] ) ) {
				return true;
			}
			if ( ! self::may_activate_theme( get_stylesheet() ) ) {
				return 'deferred';
			}
			if ( ! current_user_can( 'switch_themes' ) ) {
				return new WP_Error( 'dpt_onb_cannot_switch', __( 'You are not allowed to switch themes on this site.', 'digitizer-pro-tools' ) );
			}
			switch_theme( $item['slug'] );
			return true;
		}

		$file = DPT_ONB_State::plugin_file( $item['slug'] );
		if ( null === $file ) {
			return new WP_Error( 'dpt_onb_not_found_after_install', __( 'The plugin is not where it was expected after installation.', 'digitizer-pro-tools' ) );
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$activated = activate_plugin( $file );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		return true;
	}

	/**
	 * Shape one result.
	 *
	 * @param string $id      Item id.
	 * @param string $outcome installed|activated|skipped|failed.
	 * @param string $message Human-readable detail.
	 * @return array
	 */
	private static function result( $id, $outcome, $message ) {
		return array(
			'id'      => $id,
			'outcome' => $outcome,
			'message' => $message,
		);
	}
}
```

Add these stubs to `tests/bootstrap.php` so `apply()` can run its short-circuit paths:

```php
function current_user_can( $cap ) { return true; }
function switch_theme( $slug ) { $GLOBALS['dpt_stub_stylesheet'] = $slug; }
function activate_plugin( $file ) { $GLOBALS['dpt_stub_active_plugins'][] = $file; return null; }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/onb-installer-test.php`
Expected: `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add modules/onboarding/class-dpt-onb-installer.php tests/onb-installer-test.php tests/bootstrap.php
git commit -m "Onboarding: apply one baseline item

Decision logic, the theme-activation gate and the extracted-directory rename
are pure functions, so they are tested without a filesystem.

Three choices worth naming. An unrecognised state skips rather than installs -
the fail-safe is to do nothing to a site we cannot describe. Install-succeeded
but activate-failed reports as a failure, because calling it a success leaves
the summary claiming a plugin is running when it is not. And the child theme is
activated only while the site is still on a WordPress default, so a live custom
theme is never swapped out by a tool the operator ran to install plugins."
```

---

### Task 6: The wizard screen

**Files:**
- Create: `modules/onboarding/class-dpt-onb-admin.php`
- Create: `modules/onboarding/class-dpt-onb-module.php`
- Create: `modules/onboarding/assets/js/wizard.js`
- Create: `modules/onboarding/assets/css/wizard.css`
- Modify: `includes/class-dpt-plugin.php` (register the module)
- Modify: `uninstall.php`

**Interfaces:**
- Consumes: everything from Tasks 2-5.
- Produces: `DPT_Onboarding_Module` (id `onboarding`); admin page slug `dpt-onboarding`; AJAX action `dpt_onb_apply`; nonce action `dpt_onb`.

- [ ] **Step 1: Write the admin class**

Create `modules/onboarding/class-dpt-onb-admin.php`:

```php
<?php
/**
 * Onboarding module - the wizard screen and its one-item endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Admin {

	const PAGE_SLUG = 'dpt-onboarding';
	const NONCE     = 'dpt_onb';

	public function __construct() {
		add_action( 'wp_ajax_dpt_onb_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Onboarding', 'digitizer-pro-tools' ),
			__( 'Onboarding', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue() {
		if ( self::PAGE_SLUG !== dpt_current_admin_page() ) {
			return;
		}
		$base = DPT_URL . 'modules/onboarding/assets/';
		wp_enqueue_style( 'dpt-onb-wizard', $base . 'css/wizard.css', array(), DPT_VERSION );
		wp_enqueue_script( 'dpt-onb-wizard', $base . 'js/wizard.js', array(), DPT_VERSION, true );
		wp_localize_script( 'dpt-onb-wizard', 'DPT_ONB', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'strings'  => array(
				'working'  => __( 'Working...', 'digitizer-pro-tools' ),
				'done'     => __( 'Done', 'digitizer-pro-tools' ),
				'failed'   => __( 'Failed', 'digitizer-pro-tools' ),
				'skipped'  => __( 'Skipped', 'digitizer-pro-tools' ),
				'network'  => __( 'The request did not complete. Check the site can reach WordPress.org and GitHub.', 'digitizer-pro-tools' ),
				/* translators: 1: number succeeded, 2: number skipped, 3: number failed */
				'summary'  => __( '%1$d installed, %2$d skipped, %3$d failed.', 'digitizer-pro-tools' ),
			),
		) );
	}

	/**
	 * Apply exactly one item.
	 *
	 * One item per request on purpose: a single request that installs fourteen
	 * things exhausts max_execution_time on ordinary shared hosting, and when
	 * it does the operator has no way to tell how far it got.
	 */
	public function handle_apply() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'digitizer-pro-tools' ) ), 403 );
		}

		$id   = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$item = DPT_ONB_Manifest::get( $id );
		if ( null === $item ) {
			wp_send_json_error( array( 'message' => __( 'That item is not part of the baseline.', 'digitizer-pro-tools' ) ), 400 );
		}

		// Core removes these capabilities when DISALLOW_FILE_MODS is set, which
		// is exactly the signal that this host does not want code installed
		// from the dashboard. Respect it rather than working around it.
		$needed = ( 'theme' === $item['type'] ) ? 'install_themes' : 'install_plugins';
		if ( ! current_user_can( $needed ) ) {
			wp_send_json_error( array( 'message' => __( 'This site does not allow installing from the dashboard.', 'digitizer-pro-tools' ) ), 403 );
		}

		wp_send_json_success( DPT_ONB_Installer::apply( $id ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$states = DPT_ONB_State::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Onboarding', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Installs and activates the Digitizer baseline on this site. Anything already active is left exactly as it is - nothing here updates, downgrades or reconfigures a plugin you already have. Safe to run more than once.', 'digitizer-pro-tools' ); ?></p>

			<table class="widefat dpt-onb-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="dpt-onb-all" checked /></td>
						<th><?php esc_html_e( 'Item', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Source', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Status', 'digitizer-pro-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( DPT_ONB_Manifest::items() as $item ) : ?>
					<?php $state = $states[ $item['id'] ]; ?>
					<tr data-item="<?php echo esc_attr( $item['id'] ); ?>">
						<th scope="row" class="check-column">
							<input type="checkbox" class="dpt-onb-pick" value="<?php echo esc_attr( $item['id'] ); ?>" checked />
						</th>
						<td>
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<code><?php echo esc_html( $item['slug'] ); ?></code>
							<?php if ( 'theme' === $item['type'] ) : ?>
								<span class="dpt-onb-tag"><?php esc_html_e( 'theme', 'digitizer-pro-tools' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'github' === $item['source'] ) : ?>
								<?php echo esc_html( $item['repo'] ); ?>
							<?php else : ?>
								<?php esc_html_e( 'WordPress.org', 'digitizer-pro-tools' ); ?>
							<?php endif; ?>
						</td>
						<td class="dpt-onb-status" data-state="<?php echo esc_attr( $state ); ?>">
							<?php
							if ( DPT_ONB_State::ACTIVE === $state ) {
								esc_html_e( 'Active', 'digitizer-pro-tools' );
							} elseif ( DPT_ONB_State::INACTIVE === $state ) {
								esc_html_e( 'Installed, not active', 'digitizer-pro-tools' );
							} else {
								esc_html_e( 'Not installed', 'digitizer-pro-tools' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="dpt-actions">
				<button type="button" class="button button-primary button-hero" id="dpt-onb-run"><?php esc_html_e( 'Set up this site', 'digitizer-pro-tools' ); ?></button>
			</p>
			<p class="dpt-onb-summary" id="dpt-onb-summary" role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}
```

- [ ] **Step 2: Write the wizard script**

Create `modules/onboarding/assets/js/wizard.js`:

```js
/**
 * Digitizer Pro Tools - Onboarding wizard.
 *
 * Walks the ticked rows one request at a time, in the order the manifest put
 * them on the page, and updates each row as its result comes back. Sequential
 * on purpose: parallel installs race on the same filesystem, and a parent
 * theme has to land before its child.
 */
( function () {
	'use strict';

	var cfg = window.DPT_ONB || {};
	var run = document.getElementById( 'dpt-onb-run' );
	var out = document.getElementById( 'dpt-onb-summary' );
	var all = document.getElementById( 'dpt-onb-all' );

	if ( ! run ) {
		return;
	}

	if ( all ) {
		all.addEventListener( 'change', function () {
			var boxes = document.querySelectorAll( '.dpt-onb-pick' );
			Array.prototype.forEach.call( boxes, function ( b ) {
				b.checked = all.checked;
			} );
		} );
	}

	function setStatus( id, text, state ) {
		var row = document.querySelector( '[data-item="' + id + '"] .dpt-onb-status' );
		if ( row ) {
			row.textContent = text;
			row.setAttribute( 'data-state', state );
		}
	}

	function apply( id ) {
		var body = new FormData();
		body.append( 'action', 'dpt_onb_apply' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'item', id );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var msg = json && json.data && json.data.message ? json.data.message : cfg.strings.failed;
				return { id: id, outcome: 'failed', message: msg };
			}
			return json.data;
		} ).catch( function () {
			return { id: id, outcome: 'failed', message: cfg.strings.network };
		} );
	}

	run.addEventListener( 'click', function () {
		var picked = Array.prototype.map.call(
			document.querySelectorAll( '.dpt-onb-pick:checked' ),
			function ( b ) { return b.value; }
		);
		if ( ! picked.length ) {
			return;
		}

		run.disabled = true;
		out.textContent = '';

		var done = 0;
		var skipped = 0;
		var failed = 0;

		// Reduce over a promise chain so items run strictly one after another.
		picked.reduce( function ( chain, id ) {
			return chain.then( function () {
				setStatus( id, cfg.strings.working, 'working' );
				return apply( id ).then( function ( res ) {
					if ( 'failed' === res.outcome ) {
						failed++;
					} else if ( 'skipped' === res.outcome ) {
						skipped++;
					} else {
						done++;
					}
					setStatus( id, res.message, res.outcome );
				} );
			} );
		}, Promise.resolve() ).then( function () {
			out.textContent = cfg.strings.summary
				.replace( '%1$d', done )
				.replace( '%2$d', skipped )
				.replace( '%3$d', failed );
			run.disabled = false;
		} );
	} );
}() );
```

- [ ] **Step 3: Write the stylesheet**

Create `modules/onboarding/assets/css/wizard.css`:

```css
.dpt-onb-table { margin-top: 16px; }
.dpt-onb-table code { font-size: 11px; opacity: .7; margin-inline-start: 6px; }
.dpt-onb-tag {
	display: inline-block;
	margin-inline-start: 6px;
	padding: 1px 6px;
	border-radius: 3px;
	background: #f0f0f1;
	font-size: 11px;
	text-transform: uppercase;
}
.dpt-onb-status[data-state="active"]    { color: #007017; font-weight: 600; }
.dpt-onb-status[data-state="installed"],
.dpt-onb-status[data-state="activated"] { color: #007017; }
.dpt-onb-status[data-state="failed"]    { color: #b32d2e; }
.dpt-onb-status[data-state="working"]   { color: #646970; font-style: italic; }
.dpt-onb-summary { margin-top: 12px; font-weight: 600; }
```

- [ ] **Step 4: Write the module class**

Create `modules/onboarding/class-dpt-onb-module.php`:

```php
<?php
/**
 * Onboarding module - brings a fresh site to the Digitizer baseline.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-onb-manifest.php';
require_once __DIR__ . '/class-dpt-onb-state.php';
require_once __DIR__ . '/class-dpt-onb-source.php';
require_once __DIR__ . '/class-dpt-onb-installer.php';
require_once __DIR__ . '/class-dpt-onb-admin.php';

class DPT_Onboarding_Module extends DPT_Module {

	/** @var DPT_ONB_Admin */
	private $admin;

	public function id() {
		return 'onboarding';
	}

	public function title() {
		return __( 'Onboarding', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Installs and activates the Digitizer baseline - Hello Elementor with the Digitizer child theme, and the standard plugin set - on a new site. Nothing already installed is updated or reconfigured, and the run can be repeated safely.', 'digitizer-pro-tools' );
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_ONB_Admin();
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
```

- [ ] **Step 5: Register the module**

In `includes/class-dpt-plugin.php`, add this as the **first** entry of the `$modules` array in `registry()`, before `'cookie_banner'` — it is the module a fresh site reaches for first, and the dashboard renders in registry order:

```php
			'onboarding' => array(
				'file'    => DPT_PATH . 'modules/onboarding/class-dpt-onb-module.php',
				'class'   => 'DPT_Onboarding_Module',
				'default' => '1',
			),
```

In `uninstall.php`, add after `delete_option( 'dpt_settings' );`:

```php
delete_option( 'dpt_onboarding' );
```

- [ ] **Step 6: Verify**

Run:

```bash
php -l modules/onboarding/class-dpt-onb-admin.php
php -l modules/onboarding/class-dpt-onb-module.php
php -l includes/class-dpt-plugin.php
php -l uninstall.php
node --check modules/onboarding/assets/js/wizard.js
for f in tests/*-test.php; do php "$f" || exit 1; done
```

Expected: no syntax errors, every test file `0 failed`.

- [ ] **Step 7: Commit**

```bash
git add modules/onboarding includes/class-dpt-plugin.php uninstall.php
git commit -m "Onboarding: the wizard screen

A checklist of the baseline with each item's current state, and one AJAX
endpoint that applies exactly one item. One item per request because a single
request installing fourteen things exhausts max_execution_time on ordinary
shared hosting, and when it does the operator cannot tell how far it got. The
browser walks the list sequentially - parallel installs race on the same
filesystem, and the parent theme has to land before its child.

The endpoint checks install_plugins/install_themes as well as manage_options.
Core strips those capabilities when DISALLOW_FILE_MODS is set, which is the
host saying it does not want code installed from the dashboard."
```

---

### Task 7: Every module off by default

**Files:**
- Modify: `includes/class-dpt-plugin.php` (fifteen `default` values)
- Modify: `includes/class-dpt-module.php` (remove `enabled_by_default()`)
- Modify: 11 module classes (remove their `enabled_by_default()` overrides)
- Test: `tests/onb-defaults-test.php`

**Interfaces:**
- Consumes: `DPT_Plugin::registry()`, `DPT_Plugin::enabled_map()`.
- Produces: no new interface; removes `DPT_Module::enabled_by_default()`.

- [ ] **Step 1: Write the failing test**

Create `tests/onb-defaults-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';

// registry() and enabled_map() need the module files to exist on disk but not
// to be loaded, and DPT_Admin is constructed only in boot(). Pulling the class
// in directly is enough for both.
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-plugin.php';

$plugin   = DPT_Plugin::instance();
$registry = $plugin->registry();

// Onboarding is the single module that ships on. If everything were off a
// fresh site would have no wizard, and the operator would have to find and
// enable a module before using the thing whose job is setting up a fresh site.
foreach ( $registry as $id => $spec ) {
    $expected = ( 'onboarding' === $id ) ? '1' : '0';
    dpt_test_eq( $spec['default'], $expected, "$id ships " . ( '1' === $expected ? 'enabled' : 'disabled' ) );
}
dpt_test_ok( isset( $registry['onboarding'] ), 'the onboarding module is registered' );

// The three that used to ship on are named explicitly, so this test fails
// loudly if one is ever quietly turned back on.
foreach ( array( 'cookie_banner', 'duplicate_post', 'update_emails' ) as $id ) {
    dpt_test_eq( $registry[ $id ]['default'], '0', "$id no longer ships enabled" );
}

/* ---- the part that protects existing sites ---- */

// A site that turned the cookie banner on before this change must keep it on:
// enabled_map() may only fill in ids that are not already saved.
$GLOBALS['dpt_stub_options'] = array(
    'dpt_settings' => array( 'modules' => array( 'cookie_banner' => '1', 'hide_login' => '1' ) ),
);
$map = $plugin->enabled_map();
dpt_test_eq( $map['cookie_banner'], '1', 'a saved enabled module stays enabled' );
dpt_test_eq( $map['hide_login'], '1', 'a second saved enabled module stays enabled' );
dpt_test_eq( $map['duplicate_post'], '0', 'an unsaved module takes the new default' );
dpt_test_eq( $map['onboarding'], '1', 'an unsaved onboarding takes its enabled default' );

// A site that deliberately turned something off keeps it off.
$GLOBALS['dpt_stub_options'] = array(
    'dpt_settings' => array( 'modules' => array( 'onboarding' => '0' ) ),
);
dpt_test_eq( $plugin->enabled_map()['onboarding'], '0', 'a module switched off stays off' );

// No saved option at all - a genuinely fresh install.
$GLOBALS['dpt_stub_options'] = array();
$fresh = $plugin->enabled_map();
dpt_test_eq( $fresh['onboarding'], '1', 'a fresh install gets the wizard' );
dpt_test_eq( $fresh['cookie_banner'], '0', 'a fresh install gets nothing else' );
dpt_test_eq( count( array_filter( $fresh, function ( $v ) { return '1' === $v; } ) ), 1, 'exactly one module is on for a fresh install' );

/* ---- the dead method is gone ---- */

dpt_test_ok( ! method_exists( 'DPT_Module', 'enabled_by_default' ), 'the unused enabled_by_default() is removed from the base class' );

$overrides = array();
foreach ( glob( dirname( __DIR__ ) . '/modules/*/class-dpt-*-module.php' ) as $file ) {
    if ( false !== strpos( file_get_contents( $file ), 'function enabled_by_default' ) ) {
        $overrides[] = basename( $file );
    }
}
dpt_test_eq( $overrides, array(), 'no module still overrides it' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/onb-defaults-test.php`
Expected: failures on `cookie_banner ships disabled`, `duplicate_post`, `update_emails`, and on both `enabled_by_default` assertions.

- [ ] **Step 3: Flip the defaults**

In `includes/class-dpt-plugin.php`, change `'default' => '1'` to `'default' => '0'` for `cookie_banner`, `duplicate_post` and `update_emails`. Every other entry is already `'0'`; `onboarding` stays `'1'`.

- [ ] **Step 4: Remove the dead method**

`DPT_Module::enabled_by_default()` is declared in the base class and overridden by eleven modules, and **nothing ever calls it**. `DPT_Plugin::module_default()` reads the registry's `default` key instead. Two sources of truth for one fact is how a module ends up shipping in a state nobody intended — and this task is exactly when it would bite.

Delete this from `includes/class-dpt-module.php`:

```php
	/**
	 * Whether this module is enabled on fresh installs.
	 */
	public function enabled_by_default() {
		return false;
	}
```

Then delete the override from each of the eleven module classes. Find them with:

```bash
grep -rln 'function enabled_by_default' modules/
```

Each override is a four-line method plus its docblock; remove the whole thing, leaving one blank line between the surrounding methods.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/onb-defaults-test.php`
Expected: `0 failed`.

- [ ] **Step 6: Run everything**

```bash
for f in tests/*-test.php; do php "$f" || exit 1; done
for f in $(git ls-files '*.php'); do php -l "$f" > /dev/null || echo "LINT $f"; done
~/.composer/vendor/bin/phpcs --standard=WordPress \
  --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.WP.I18n \
  --extensions=php --report=summary ./modules ./includes ./digitizer-pro-tools.php ./uninstall.php
```

Expected: every test `0 failed`, no lint output, phpcs prints nothing.

- [ ] **Step 7: Commit**

```bash
git add includes/ modules/ tests/onb-defaults-test.php
git commit -m "Ship every module disabled, except Onboarding

Cookie Banner, Duplicate Post and Update Emails no longer ship enabled. A new
site should start empty and have things turned on deliberately.

Onboarding is the one exception. With everything off, a fresh site would have
no wizard, and the operator would have to find and enable a module before using
the thing whose job is setting up a fresh site.

Existing sites are untouched: enabled_map() fills in a module's default only
for ids not already saved, so a site that turned the banner on keeps it on.
That is load-bearing and now has a test.

Also removes DPT_Module::enabled_by_default(). It was declared on the base
class, overridden by eleven modules, and called by nothing - the registry's
'default' key is what actually decides. Two sources of truth for one fact is
how a module ends up shipping in a state nobody intended, and this was the
change that would have exposed it."
```

---

### Task 8: Translations, docs and the release

**Files:**
- Modify: `languages/digitizer-pro-tools-he_IL.po`, `.pot`, `.mo`, `.l10n.php`
- Modify: `readme.txt`
- Modify: `digitizer-pro-tools.php`

**Interfaces:**
- Consumes: the strings added in Tasks 4-6.
- Produces: nothing consumed by other tasks.

- [ ] **Step 1: Collect the new strings**

Run the repo's cross-check to list every translatable string not yet in the catalog:

```bash
python3 - <<'PY'
import re, glob
pat = re.compile(r"\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|_n)\s*\(\s*(['\"])((?:\\.|(?!\1).)*)\1")
found = set()
for f in glob.glob("**/*.php", recursive=True) + glob.glob("**/*.js", recursive=True):
    if f.startswith(("dist/", "vendor/", "tests/", "docs/")):
        continue
    for m in pat.finditer(open(f, encoding="utf-8").read()):
        found.add(m.group(2).replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\"))
po = set()
for line in open("languages/digitizer-pro-tools-he_IL.po", encoding="utf-8"):
    m = re.match(r'^msgid "(.*)"$', line.rstrip("\n"))
    if m:
        s = m.group(1).replace('\\n','\n').replace('\\t','\t').replace('\\"','"').replace('\\\\','\\')
        if s: po.add(s)
for s in sorted(found - po):
    print(repr(s))
PY
```

- [ ] **Step 2: Add the Hebrew**

Recreate the `build-i18n.php` helper in the scratchpad (it parses the `.po`, merges an `$additions` map of EN => HE, rewrites `.po` and `.pot`, compiles the `.mo` with `msgfmt`, and regenerates the WordPress `.l10n.php` from the `.po`). Put every string listed by Step 1 into `$additions` with its Hebrew, then run it.

Translations for the module's own strings:

| English | Hebrew |
|---|---|
| Onboarding | הקמת אתר |
| Item | פריט |
| Source | מקור |
| Status | סטטוס |
| theme | תבנית |
| Active | פעיל |
| Installed, not active | מותקן, לא פעיל |
| Not installed | לא מותקן |
| Set up this site | הקם את האתר |
| Working... | עובד... |
| Done | הושלם |
| Failed | נכשל |
| Skipped | דולג |
| Already active. | כבר פעיל. |
| Installed and activated. | הותקן והופעל. |
| Activated. | הופעל. |
| That item is not part of the baseline. | הפריט הזה אינו חלק מרשימת הבסיס. |
| This site does not allow installing from the dashboard. | האתר הזה לא מאפשר התקנה מלוח הבקרה. |
| You are not allowed to switch themes on this site. | אין לך הרשאה להחליף תבנית באתר הזה. |
| The plugin is not where it was expected after installation. | התוסף לא נמצא במקום הצפוי אחרי ההתקנה. |
| Could not normalise the downloaded folder name. | לא ניתן היה לתקן את שם התיקייה שהורדה. |
| GitHub returned a response that could not be read. | GitHub החזיר תשובה שלא ניתן לקרוא. |
| That release has no downloadable archive. | לגרסה הזו אין קובץ להורדה. |

The three remaining strings carry placeholders and must keep them exactly:

- `GitHub answered %2$d for %1$s.` → `GitHub החזיר %2$d עבור %1$s.`
- `WordPress.org returned no download for %s.` → `‏WordPress.org לא החזיר קובץ להורדה עבור %s.`
- `%1$d installed, %2$d skipped, %3$d failed.` → `%1$d הותקנו, %2$d דולגו, %3$d נכשלו.`

And the longer ones:

- `The installation did not complete. The site may not be able to write to its own files - see FS_METHOD.` → `ההתקנה לא הושלמה. ייתכן שהאתר לא יכול לכתוב לקבצים של עצמו - ראו FS_METHOD.`
- `Installed. Not activated, because this site already uses a custom theme - switch it under Appearance > Themes when you are ready.` → `הותקן. לא הופעל, כי האתר כבר משתמש בתבנית משלו - החליפו תחת עיצוב > תבניות כשתהיו מוכנים.`
- `Installed, but could not be activated: %s` → `הותקן, אך לא ניתן היה להפעיל: %s`
- `The request did not complete. Check the site can reach WordPress.org and GitHub.` → `הבקשה לא הושלמה. בדקו שהאתר יכול להגיע ל-WordPress.org ול-GitHub.`
- `Installs and activates the Digitizer baseline on this site. Anything already active is left exactly as it is - nothing here updates, downgrades or reconfigures a plugin you already have. Safe to run more than once.` → `מתקין ומפעיל את רשימת הבסיס של Digitizer באתר הזה. כל מה שכבר פעיל נשאר בדיוק כפי שהוא - שום דבר כאן לא מעדכן, לא מוריד גרסה ולא משנה הגדרות של תוסף קיים. אפשר להריץ יותר מפעם אחת.`
- `Installs and activates the Digitizer baseline - Hello Elementor with the Digitizer child theme, and the standard plugin set - on a new site. Nothing already installed is updated or reconfigured, and the run can be repeated safely.` → `מתקין ומפעיל את רשימת הבסיס של Digitizer - Hello Elementor עם תבנית הבת של Digitizer, ואת מערך התוספים הסטנדרטי - באתר חדש. שום דבר שכבר מותקן לא מעודכן ולא משתנה, ואפשר להריץ שוב בבטחה.`

- [ ] **Step 3: Verify zero missing**

Re-run the Step 1 script. Expected: no output.

- [ ] **Step 4: Version and changelog**

In `digitizer-pro-tools.php` set the header `Version:` and `DPT_VERSION` to `1.20.0`. In `readme.txt` set `Stable tag: 1.20.0`, add a `= Module: Onboarding =` section to the description, correct the line that reads *"On activation only three modules are active…"* to say that only Onboarding is active on a new install, and add the changelog entry:

```
= 1.20.0 =
* New module: Onboarding - a wizard that installs and activates the Digitizer baseline (Hello Elementor plus the Digitizer child theme, and twelve plugins) on a new site. Anything already active is left alone; the run is repeatable and reports per item what happened
* Every other module now ships disabled. A new site starts empty and you turn on what that client needs. Existing sites are unaffected - a module you already switched on stays on
* Onboarding is the one module that ships enabled, so a fresh site has a reachable wizard
```

- [ ] **Step 5: Full verification**

```bash
for f in tests/*-test.php; do php "$f" || exit 1; done
for f in $(git ls-files '*.php'); do php -l "$f" > /dev/null || echo "LINT $f"; done
node --check modules/onboarding/assets/js/wizard.js
~/.composer/vendor/bin/phpcs --standard=WordPress \
  --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.WP.I18n \
  --extensions=php --report=summary ./modules ./includes ./digitizer-pro-tools.php ./uninstall.php
bin/build-zip.sh
unzip -l dist/digitizer-pro-tools.zip | grep -c -E 'tests/|docs/'
```

Expected: all tests pass, no lint output, phpcs silent, ZIP builds, the final count is `0`.

- [ ] **Step 6: Commit**

```bash
git add languages/ readme.txt digitizer-pro-tools.php
git commit -m "Onboarding: translations, readme and version 1.20.0"
```

- [ ] **Step 7: Open the pull request**

Write the body to a file first — backticks in a `--body` argument get expanded
by the shell:

```bash
git push -u origin claude/onboarding-module
gh pr create \
  --title "Onboarding module, and every other module off by default (v1.20.0)" \
  --body-file docs/superpowers/plans/pr-body-onboarding.md
gh pr comment <n> --body "@codex review"
```

The body should cover, in this order: what the wizard does and the three
decisions behind it; that the manifest is code with no URL field; the three
non-obvious implementation choices (directory rename for GitHub archives, one
request per item, the theme-activation gate); the defaults change and why
existing sites are unaffected; the removal of the dead `enabled_by_default()`;
and what was verified versus what needs a real site. Delete the body file
before merging — it is scaffolding, not documentation.

Then drive the Codex review loop to a clean verdict before merging.

---

## Manual verification before release

The install path itself cannot be exercised without WordPress. Before tagging a
release, on a throwaway site:

1. Fresh WordPress on a default theme. Install the plugin. Confirm the Modules
   screen shows every module off except Onboarding.
2. Open Onboarding. Every row should read "Not installed".
3. Run it. Watch rows update one at a time. Confirm all fourteen land, that
   `hello-digitizer` is the active theme, and that
   `wp-content/plugins/elementor-mcp/` and `mcp-adapter/` exist with those exact
   names — no commit hash in either.
4. Run it a second time. Everything should report "Already active" and nothing
   should change on disk.
5. On a second site already using a custom theme, run it and confirm the child
   theme installs but the live theme is **not** switched.
