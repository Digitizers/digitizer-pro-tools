# REST Bridge Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the standalone `digitizer-api-extensions` plugin with a DPT module that exposes JetEngine fields to the REST API by discovering them from JetEngine's own stored definitions, and carries over the Elementor endpoints, Rank Math fields and an info endpoint.

**Architecture:** Six small classes under `modules/rest-bridge/`. `DPT_RB_Definitions` parses the `jet_engine_meta_boxes` option into flat field descriptors. `DPT_RB_Schema` turns one descriptor into a REST schema and a sanitizer. `DPT_RB_Fields` registers those as REST fields plus a compatibility layer for the old plugin's hard-coded names. `DPT_RB_Elementor`, `DPT_RB_Rankmath` and `DPT_RB_Info` are ports of the old plugin's remaining surface. `DPT_RB_Module` wires them and stands down when the old plugin is active.

**Tech Stack:** PHP 7.2+, WordPress REST API (`register_rest_field`, `register_rest_route`, `register_post_meta`), the repo's stub test harness (`tests/bootstrap.php`, plain `php tests/<file>.php`), phpcs with the WordPress standard, gettext catalogs built by hand + `msgfmt`.

**Spec:** `docs/superpowers/specs/2026-08-24-rest-bridge-module-design.md`

## Global Constraints

- Branch `claude/rest-bridge-module`; target version **1.27.0** (`digitizer-pro-tools.php` header, `DPT_VERSION`, `readme.txt` Stable tag + changelog).
- Module id `rest_bridge`, directory `modules/rest-bridge/`, class prefix `DPT_RB_`, registry `'default' => '0'` like every other module.
- Every file starts with `<?php`, a docblock, and `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Tabs for indentation, WordPress brace style, Yoda conditions where phpcs asks.
- Every user-facing string wrapped in `__()` / `esc_html__()` with text domain `digitizer-pro-tools`.
- phpcs must report **zero**: `~/.composer/vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.WP.I18n --extensions=php --report=summary ./modules ./includes ./digitizer-pro-tools.php ./uninstall.php`
- Catalog parity: `languages/digitizer-pro-tools.pot`, `-he_IL.po`, `-he_IL.l10n.php` must hold the identical key set as the code, and `-he_IL.mo` is rebuilt with `msgfmt -o languages/digitizer-pro-tools-he_IL.mo languages/digitizer-pro-tools-he_IL.po`.
- Run all tests with `for f in tests/*-test.php; do php "$f" || exit 1; done`.
- The module stores **nothing**: no options, no transients, no `uninstall.php` entry, no admin screen.

---

## File Structure

| File | Responsibility |
|---|---|
| `modules/rest-bridge/class-dpt-rb-definitions.php` | Read + normalize `jet_engine_meta_boxes` into descriptors; record skips |
| `modules/rest-bridge/class-dpt-rb-schema.php` | Descriptor → REST schema; value sanitizing per JetEngine type |
| `modules/rest-bridge/class-dpt-rb-fields.php` | `register_rest_field` for discovered fields + compatibility layer; read/write callbacks |
| `modules/rest-bridge/class-dpt-rb-elementor.php` | `/digitizer/v1/elementor/{id}` GET + POST and their helpers |
| `modules/rest-bridge/class-dpt-rb-rankmath.php` | The 12 `rank_math_*` post meta registrations |
| `modules/rest-bridge/class-dpt-rb-info.php` | `/digitizer/v1/info` |
| `modules/rest-bridge/class-dpt-rb-module.php` | `DPT_Module` implementation, requires, stand-down |
| `tests/rest-bridge-test.php` | The module's whole test suite |
| `tests/bootstrap.php` | Extended with REST/meta/post-type stubs (Task 1) |
| `includes/class-dpt-plugin.php` | Registry entry (Task 7) |

---

### Task 1: Test harness stubs for REST, meta and post types

The harness has no `register_rest_field`, no post/term meta and no post-type
objects. Every later task needs them, so they land first and alone.

**Files:**
- Modify: `tests/bootstrap.php` (append at end of file)

**Interfaces:**
- Produces, for every later task:
  - `$GLOBALS['dpt_stub_rest_fields']` — `array<string /*object type*/, array<string /*field*/, array /*args*/>>`
  - `$GLOBALS['dpt_stub_rest_routes']` — `array<string /*"{ns}/{route}"*/, array /*list of endpoint arg arrays*/>`
  - `$GLOBALS['dpt_stub_post_meta']`, `$GLOBALS['dpt_stub_term_meta']` — `array<int, array<string, mixed>>`
  - `$GLOBALS['dpt_stub_registered_post_meta']` — `array<string, array<string, array>>`
  - `$GLOBALS['dpt_stub_rest_post_types']`, `$GLOBALS['dpt_stub_rest_taxonomies']` — lists of names that are REST-enabled
  - `$GLOBALS['dpt_stub_posts']` — `array<int, string /*post type*/>` (a post "exists" when its id is a key)
  - `$GLOBALS['dpt_stub_denied_post_caps']` — `array<int>` post ids the current user may not edit
  - `$GLOBALS['dpt_stub_meta_write_fails']` — `array<string>` meta keys whose writes/deletes return false
  - `$GLOBALS['dpt_stub_elementor_cache_cleared']` — `int` counter
  - `current_user_can( $cap, $id = null )` — now accepts the second argument

- [ ] **Step 1: Write the failing test**

Create `tests/rest-bridge-test.php` with only the harness assertions for now:

```php
<?php
require_once __DIR__ . '/bootstrap.php';

/* ---- the harness itself ---- */

// Every later assertion in this file rests on these stubs behaving like the
// WordPress functions they stand in for, so they are checked first.
$GLOBALS['dpt_stub_post_meta'] = array();
update_post_meta( 7, 'colour', 'blue' );
dpt_test_eq( get_post_meta( 7, 'colour', true ), 'blue', 'post meta round-trips' );
dpt_test_eq( get_post_meta( 7, 'missing', true ), '', 'an absent single meta reads as an empty string' );
dpt_test_ok( delete_post_meta( 7, 'colour' ), 'deleting meta that exists succeeds' );
dpt_test_ok( ! delete_post_meta( 7, 'colour' ), 'and deleting it again does not' );

$GLOBALS['dpt_stub_meta_write_fails'] = array( 'stubborn' );
dpt_test_ok( ! update_post_meta( 7, 'stubborn', 'x' ), 'a write the site refuses reports failure' );
$GLOBALS['dpt_stub_meta_write_fails'] = array();

$GLOBALS['dpt_stub_term_meta'] = array();
update_term_meta( 3, 'bio', 'hello' );
dpt_test_eq( get_term_meta( 3, 'bio', true ), 'hello', 'term meta round-trips' );

$GLOBALS['dpt_stub_rest_fields'] = array();
register_rest_field( 'post', 'thing', array( 'schema' => array( 'type' => 'string' ) ) );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['thing'] ), 'a registered REST field is recorded' );

$GLOBALS['dpt_stub_denied_post_caps'] = array( 9 );
dpt_test_ok( current_user_can( 'edit_post', 8 ), 'a post the user may edit' );
dpt_test_ok( ! current_user_can( 'edit_post', 9 ), 'and one they may not' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

exit( dpt_test_summary() > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Uncaught Error: Call to undefined function update_post_meta()`

- [ ] **Step 3: Write the stubs**

Append to `tests/bootstrap.php`:

```php
/* ------------------------------------------------- REST, meta, post types */

/**
 * Post and term meta.
 *
 * A meta key listed in dpt_stub_meta_write_fails refuses writes and deletes,
 * which is how a test reaches the branch where WordPress says no.
 */
$GLOBALS['dpt_stub_post_meta']        = array();
$GLOBALS['dpt_stub_term_meta']        = array();
$GLOBALS['dpt_stub_meta_write_fails'] = array();

function dpt_stub_meta_get( &$store, $id, $key, $single ) {
	$id = (int) $id;
	if ( ! isset( $store[ $id ] ) || ! array_key_exists( $key, $store[ $id ] ) ) {
		return $single ? '' : array();
	}
	return $single ? $store[ $id ][ $key ] : array( $store[ $id ][ $key ] );
}

function dpt_stub_meta_update( &$store, $id, $key, $value ) {
	if ( in_array( $key, $GLOBALS['dpt_stub_meta_write_fails'], true ) ) {
		return false;
	}
	$id = (int) $id;
	if ( ! isset( $store[ $id ] ) ) {
		$store[ $id ] = array();
	}
	$store[ $id ][ $key ] = $value;
	return true;
}

function dpt_stub_meta_delete( &$store, $id, $key ) {
	if ( in_array( $key, $GLOBALS['dpt_stub_meta_write_fails'], true ) ) {
		return false;
	}
	$id = (int) $id;
	if ( ! isset( $store[ $id ] ) || ! array_key_exists( $key, $store[ $id ] ) ) {
		return false;
	}
	unset( $store[ $id ][ $key ] );
	return true;
}

function get_post_meta( $id, $key = '', $single = false ) {
	return dpt_stub_meta_get( $GLOBALS['dpt_stub_post_meta'], $id, $key, $single );
}
function update_post_meta( $id, $key, $value ) {
	return dpt_stub_meta_update( $GLOBALS['dpt_stub_post_meta'], $id, $key, $value );
}
function delete_post_meta( $id, $key ) {
	return dpt_stub_meta_delete( $GLOBALS['dpt_stub_post_meta'], $id, $key );
}
function get_term_meta( $id, $key = '', $single = false ) {
	return dpt_stub_meta_get( $GLOBALS['dpt_stub_term_meta'], $id, $key, $single );
}
function update_term_meta( $id, $key, $value ) {
	return dpt_stub_meta_update( $GLOBALS['dpt_stub_term_meta'], $id, $key, $value );
}
function delete_term_meta( $id, $key ) {
	return dpt_stub_meta_delete( $GLOBALS['dpt_stub_term_meta'], $id, $key );
}

/** REST registration, recorded rather than performed. */
$GLOBALS['dpt_stub_rest_fields']          = array();
$GLOBALS['dpt_stub_rest_routes']          = array();
$GLOBALS['dpt_stub_registered_post_meta'] = array();

function register_rest_field( $object_type, $attribute, $args = array() ) {
	foreach ( (array) $object_type as $type ) {
		$GLOBALS['dpt_stub_rest_fields'][ $type ][ $attribute ] = $args;
	}
}
function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['dpt_stub_rest_routes'][ $namespace . $route ][] = $args;
	return true;
}
function register_post_meta( $post_type, $key, $args = array() ) {
	$GLOBALS['dpt_stub_registered_post_meta'][ $post_type ][ $key ] = $args;
	return true;
}

/** Which post types and taxonomies are visible to the REST API. */
$GLOBALS['dpt_stub_rest_post_types'] = array( 'post', 'page' );
$GLOBALS['dpt_stub_rest_taxonomies'] = array( 'category', 'post_tag', 'authors' );

function dpt_stub_rest_object( $names ) {
	return (object) array( 'show_in_rest' => true, 'name' => $names );
}
function get_post_type_object( $name ) {
	return in_array( $name, $GLOBALS['dpt_stub_rest_post_types'], true )
		? dpt_stub_rest_object( $name )
		: null;
}
function taxonomy_exists( $name ) {
	return in_array( $name, $GLOBALS['dpt_stub_rest_taxonomies'], true );
}
function get_taxonomy( $name ) {
	return taxonomy_exists( $name ) ? dpt_stub_rest_object( $name ) : false;
}

/** Posts exist when the test says they do. */
$GLOBALS['dpt_stub_posts'] = array();
function get_post( $id = 0 ) {
	$id = (int) $id;
	return isset( $GLOBALS['dpt_stub_posts'][ $id ] )
		? (object) array( 'ID' => $id, 'post_type' => $GLOBALS['dpt_stub_posts'][ $id ] )
		: null;
}

$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;

function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function wp_kses_post( $s ) { return is_scalar( $s ) ? (string) $s : ''; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_slash( $v ) { return $v; }
function rest_ensure_response( $v ) { return $v; }
```

Then replace the existing one-argument `current_user_can` (line ~155) with:

```php
/**
 * Capabilities. A bare capability is granted unless denied; a post-specific
 * one - edit_post, say - is granted unless that post id was denied, which is
 * how a test reaches the "not your post" branch.
 */
$GLOBALS['dpt_stub_denied_post_caps'] = array();
function current_user_can( $cap, $id = null ) {
	if ( null !== $id ) {
		return ! in_array( (int) $id, $GLOBALS['dpt_stub_denied_post_caps'], true );
	}
	return ! in_array( $cap, $GLOBALS['dpt_stub_denied_caps'], true );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/rest-bridge-test.php` → Expected: PASS, 0 failed.
Run: `for f in tests/*-test.php; do php "$f" || exit 1; done` → all nine existing files still pass (the `current_user_can` change is backward compatible: existing callers pass one argument).

- [ ] **Step 5: Commit**

```bash
git add tests/bootstrap.php tests/rest-bridge-test.php
git commit -m "tests: REST, meta and post-type stubs for the REST Bridge module"
```

---

### Task 2: DPT_RB_Definitions — discovery from the JetEngine option

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-definitions.php`
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: `get_option()`, `sanitize_key()` from the harness.
- Produces:
  - `DPT_RB_Definitions::all()` → `array` list of descriptors, each
    `array( 'meta_key' => string, 'title' => string, 'object' => 'post'|'taxonomy', 'targets' => string[], 'type' => string, 'fields' => array /* repeater sub-descriptors, same shape minus object/targets */ )`
  - `DPT_RB_Definitions::skipped()` → `string[]` human-readable reasons
  - `DPT_RB_Definitions::reset()` → `void`, clears the per-request memo (tests call it between fixtures)

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`, before the final `exit(...)` line:

```php
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-definitions.php';

/* ---- discovery reads JetEngine's own definitions ---- */

// This is the shape JetEngine's admin writes: a list of meta boxes, each with
// args describing what it is attached to and meta_fields describing the
// fields themselves. Tabs and accordions appear in the same list and are not
// data, so they are skipped without complaint.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-extras',
			'args'        => array(
				'name'              => 'Post Extras',
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post', 'page' ),
			),
			'meta_fields' => array(
				array( 'name' => 'reading_time', 'title' => 'Reading time', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'layout_tab', 'title' => 'Layout', 'object_type' => 'tab', 'type' => 'tab' ),
				array(
					'name'             => 'qna',
					'title'            => 'FAQ',
					'object_type'      => 'field',
					'type'             => 'repeater',
					'repeater-fields'  => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg' ),
					),
				),
				array( 'name' => 'mystery', 'title' => 'Mystery', 'object_type' => 'field', 'type' => 'nonesuch' ),
				array( 'title' => 'Nameless', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'author-extras',
			'args'        => array(
				'name'        => 'Author Extras',
				'object_type' => 'taxonomy',
				'allowed_tax' => array( 'authors' ),
			),
			'meta_fields' => array(
				array( 'name' => 'linkedin', 'title' => 'LinkedIn', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array( 'id' => 'broken' ),
		'not-even-an-array',
	),
);
DPT_RB_Definitions::reset();
$defs = DPT_RB_Definitions::all();

$by_key = array();
foreach ( $defs as $d ) {
	$by_key[ $d['meta_key'] ] = $d;
}

dpt_test_eq( count( $defs ), 3, 'three usable fields were found' );
dpt_test_ok( isset( $by_key['reading_time'] ), 'a plain post field' );
dpt_test_eq( $by_key['reading_time']['object'], 'post', 'attached to posts' );
dpt_test_eq( $by_key['reading_time']['targets'], array( 'post', 'page' ), 'on the post types the meta box names' );
dpt_test_eq( $by_key['reading_time']['type'], 'text', 'with its JetEngine type' );

dpt_test_ok( isset( $by_key['qna'] ), 'the repeater' );
dpt_test_eq( count( $by_key['qna']['fields'] ), 2, 'carrying its sub-fields' );
dpt_test_eq( $by_key['qna']['fields'][0]['meta_key'], 'question', 'named as JetEngine names them' );
dpt_test_eq( $by_key['qna']['fields'][1]['type'], 'wysiwyg', 'each with its own type' );

dpt_test_ok( isset( $by_key['linkedin'] ), 'a taxonomy field' );
dpt_test_eq( $by_key['linkedin']['object'], 'taxonomy', 'attached to a taxonomy' );
dpt_test_eq( $by_key['linkedin']['targets'], array( 'authors' ), 'the one the meta box names' );

// What was skipped has to be sayable, because a field that silently fails to
// appear in the API looks like a bug in the API.
$skipped = DPT_RB_Definitions::skipped();
dpt_test_eq( count( $skipped ), 3, 'three rows were skipped' );
$joined = implode( ' | ', $skipped );
dpt_test_ok( false !== strpos( $joined, 'mystery' ), 'the unknown type is named' );
dpt_test_ok( false !== strpos( $joined, 'nonesuch' ), 'along with the type itself' );
dpt_test_ok( false !== strpos( $joined, 'broken' ), 'and the meta box with no fields' );
dpt_test_ok( false === strpos( $joined, 'layout_tab' ), 'while chrome is skipped quietly - it was never data' );

// A site without JetEngine has no option at all, and that is not an error.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
dpt_test_eq( DPT_RB_Definitions::all(), array(), 'no JetEngine, no fields' );
dpt_test_eq( DPT_RB_Definitions::skipped(), array(), 'and nothing to report' );

// A corrupt option must not be fatal.
$GLOBALS['dpt_stub_options'] = array( 'jet_engine_meta_boxes' => 'garbage' );
DPT_RB_Definitions::reset();
dpt_test_eq( DPT_RB_Definitions::all(), array(), 'a corrupt option yields nothing' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-definitions.php'`

- [ ] **Step 3: Write the implementation**

Create `modules/rest-bridge/class-dpt-rb-definitions.php`:

```php
<?php
/**
 * REST Bridge - what JetEngine has been told to store.
 *
 * JetEngine's admin writes its meta box definitions to a single option. That
 * option is the source of truth here: read it, and the fields a site actually
 * has are known without a line of per-site code. Fields registered in PHP by
 * another plugin are not in it and are not seen - a deliberate trade for not
 * depending on JetEngine's undocumented internals.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reads and normalizes the JetEngine meta box definitions.
 */
class DPT_RB_Definitions {

	const OPTION = 'jet_engine_meta_boxes';

	/**
	 * Field descriptors, or null before the option has been read.
	 *
	 * @var array|null
	 */
	private static $defs = null;

	/**
	 * Reasons rows were passed over, for the info endpoint.
	 *
	 * @var array
	 */
	private static $skipped = array();

	/**
	 * Types this bridge knows how to expose. Anything else is skipped and
	 * said out loud rather than guessed at.
	 *
	 * @var array
	 */
	private static $known = array(
		'text',
		'textarea',
		'wysiwyg',
		'number',
		'switcher',
		'checkbox',
		'select',
		'radio',
		'date',
		'time',
		'datetime-local',
		'media',
		'repeater',
	);

	/**
	 * Forget what was read. Only tests need this; a request reads once.
	 */
	public static function reset() {
		self::$defs    = null;
		self::$skipped = array();
	}

	/**
	 * Every field this site has defined, flattened.
	 *
	 * @return array List of descriptors.
	 */
	public static function all() {
		if ( null === self::$defs ) {
			self::read();
		}
		return self::$defs;
	}

	/**
	 * Why anything was left out.
	 *
	 * @return array List of sentences.
	 */
	public static function skipped() {
		if ( null === self::$defs ) {
			self::read();
		}
		return self::$skipped;
	}

	/**
	 * Whether a type is one this bridge can expose.
	 *
	 * @param string $type JetEngine field type.
	 * @return bool
	 */
	public static function known_type( $type ) {
		return in_array( $type, self::$known, true );
	}

	/**
	 * Parse the option into descriptors.
	 */
	private static function read() {
		self::$defs    = array();
		self::$skipped = array();

		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = isset( $row['id'] ) ? (string) $row['id'] : '(unnamed meta box)';
			$args   = isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array();
			$fields = isset( $row['meta_fields'] ) && is_array( $row['meta_fields'] ) ? $row['meta_fields'] : array();

			if ( ! $fields ) {
				self::$skipped[] = sprintf( 'meta box %s: no fields', $id );
				continue;
			}

			$object = isset( $args['object_type'] ) ? (string) $args['object_type'] : '';
			if ( 'post' === $object ) {
				$targets = isset( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array();
			} elseif ( 'taxonomy' === $object ) {
				$targets = isset( $args['allowed_tax'] ) ? (array) $args['allowed_tax'] : array();
			} else {
				self::$skipped[] = sprintf( 'meta box %1$s: object type %2$s is not exposed', $id, '' === $object ? '(none)' : $object );
				continue;
			}

			$targets = array_values( array_filter( array_map( 'sanitize_key', $targets ) ) );
			if ( ! $targets ) {
				self::$skipped[] = sprintf( 'meta box %s: attached to nothing', $id );
				continue;
			}

			foreach ( $fields as $field ) {
				$descriptor = self::descriptor( $field, $id );
				if ( null === $descriptor ) {
					continue;
				}
				$descriptor['object']  = $object;
				$descriptor['targets'] = $targets;
				self::$defs[]          = $descriptor;
			}
		}
	}

	/**
	 * One field row to a descriptor, or null when it is not one.
	 *
	 * @param mixed  $field The raw row.
	 * @param string $box   Meta box id, for the skip message.
	 * @return array|null
	 */
	private static function descriptor( $field, $box ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		// Tabs, accordions and the like share the list with real fields and
		// carry no value of their own.
		$kind = isset( $field['object_type'] ) ? (string) $field['object_type'] : 'field';
		if ( 'field' !== $kind ) {
			return null;
		}

		$name = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
		if ( '' === $name ) {
			self::$skipped[] = sprintf( 'meta box %s: a field with no name', $box );
			return null;
		}

		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		if ( ! self::known_type( $type ) ) {
			self::$skipped[] = sprintf( 'field %1$s: type %2$s is not exposed', $name, '' === $type ? '(none)' : $type );
			return null;
		}

		$descriptor = array(
			'meta_key' => $name,
			'title'    => isset( $field['title'] ) ? (string) $field['title'] : $name,
			'type'     => $type,
			'fields'   => array(),
		);

		if ( 'repeater' === $type ) {
			$subs = isset( $field['repeater-fields'] ) && is_array( $field['repeater-fields'] )
				? $field['repeater-fields']
				: array();
			foreach ( $subs as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sub_name = isset( $sub['name'] ) ? sanitize_key( $sub['name'] ) : '';
				$sub_type = isset( $sub['type'] ) ? (string) $sub['type'] : '';
				// A repeater inside a repeater is more than this bridge
				// promises, and an unknown type is a guess it will not make.
				if ( '' === $sub_name || 'repeater' === $sub_type || ! self::known_type( $sub_type ) ) {
					self::$skipped[] = sprintf( 'field %1$s: sub-field %2$s is not exposed', $name, '' === $sub_name ? '(unnamed)' : $sub_name );
					continue;
				}
				$descriptor['fields'][] = array(
					'meta_key' => $sub_name,
					'title'    => isset( $sub['title'] ) ? (string) $sub['title'] : $sub_name,
					'type'     => $sub_type,
					'fields'   => array(),
				);
			}
		}

		return $descriptor;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-definitions.php tests/rest-bridge-test.php
git commit -m "REST Bridge: discover JetEngine fields from the stored definitions"
```

---

### Task 3: DPT_RB_Schema — types to schema and sanitizers

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-schema.php`
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: descriptors from `DPT_RB_Definitions::all()` (Task 2).
- Produces:
  - `DPT_RB_Schema::for_descriptor( array $descriptor )` → `array` JSON-Schema fragment with `description`, `type`, `context`, and for repeaters `items.properties`
  - `DPT_RB_Schema::sanitize( array $descriptor, $value )` → sanitized value, or `WP_Error` when the shape is wrong
  - `DPT_RB_Schema::normalize_read( array $descriptor, $stored )` → the value as the API should present it

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`:

```php
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-schema.php';

/* ---- one field's type decides its schema and its sanitizer ---- */

$text  = array( 'meta_key' => 'reading_time', 'title' => 'Reading time', 'type' => 'text', 'fields' => array() );
$rich  = array( 'meta_key' => 'bio', 'title' => 'Bio', 'type' => 'wysiwyg', 'fields' => array() );
$num   = array( 'meta_key' => 'weight', 'title' => 'Weight', 'type' => 'number', 'fields' => array() );
$media = array( 'meta_key' => 'photo', 'title' => 'Photo', 'type' => 'media', 'fields' => array() );
$sw    = array( 'meta_key' => 'featured', 'title' => 'Featured', 'type' => 'switcher', 'fields' => array() );
$rep   = array(
	'meta_key' => 'qna',
	'title'    => 'FAQ',
	'type'     => 'repeater',
	'fields'   => array(
		array( 'meta_key' => 'question', 'title' => 'Question', 'type' => 'text', 'fields' => array() ),
		array( 'meta_key' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg', 'fields' => array() ),
	),
);

$schema = DPT_RB_Schema::for_descriptor( $text );
dpt_test_eq( $schema['type'], 'string', 'a text field is a string' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $num )['type'], 'number', 'a number is a number' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media )['type'], 'integer', 'media is an attachment id' );

$rs = DPT_RB_Schema::for_descriptor( $rep );
dpt_test_eq( $rs['type'], 'array', 'a repeater is an array' );
dpt_test_eq( $rs['items']['type'], 'object', 'of objects' );
dpt_test_ok( isset( $rs['items']['properties']['question'] ), 'whose properties are the sub-fields' );
dpt_test_eq( $rs['items']['properties']['answer']['type'], 'string', 'each typed from its own definition' );

// Sanitizing. Markup survives a wysiwyg and does not survive a text field:
// that difference is the whole reason the type is carried this far.
dpt_test_eq( DPT_RB_Schema::sanitize( $text, '<b>ten</b> minutes' ), 'ten minutes', 'text is stripped' );
dpt_test_eq( DPT_RB_Schema::sanitize( $rich, '<b>hello</b>' ), '<b>hello</b>', 'a wysiwyg keeps its markup' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media, '-42' ), 42, 'media is a positive integer' );
dpt_test_eq( DPT_RB_Schema::sanitize( $sw, true ), 'true', 'a switcher stores a string' );
dpt_test_eq( DPT_RB_Schema::sanitize( $sw, false ), 'false', 'either way' );
dpt_test_eq( DPT_RB_Schema::sanitize( $text, '0' ), '0', 'a value of "0" is content, not emptiness' );

// A repeater accepts a list of objects, keeps only the keys it knows, and
// says no to anything else.
$clean = DPT_RB_Schema::sanitize( $rep, array(
	array( 'question' => ' Why? ', 'answer' => '<b>Because</b>', 'sneaky' => 'x' ),
) );
dpt_test_eq( $clean[0]['question'], 'Why?', 'sub-values are sanitized by their own type' );
dpt_test_eq( $clean[0]['answer'], '<b>Because</b>', 'including the rich one' );
dpt_test_ok( ! isset( $clean[0]['sneaky'] ), 'a key the definition does not have is dropped' );
dpt_test_eq( DPT_RB_Schema::sanitize( $rep, array() ), array(), 'an empty list stays empty - that is how a field is cleared' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, 'nope' ) ), 'a scalar is not a repeater' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, array( 'nope' ) ) ), 'nor is a list of scalars' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, false ) ), 'and false is not an empty list' );

// Reading. JetEngine has stored repeaters as arrays, as JSON and as PHP
// serialization over the years; all three have to come back as an array.
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, array( array( 'question' => 'q', 'answer' => 'a' ) ) )[0]['question'], 'q', 'an array reads back' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, '[{"question":"q","answer":"a"}]' )[0]['question'], 'q', 'so does JSON' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, serialize( array( array( 'question' => 'q', 'answer' => 'a' ) ) ) )[0]['question'], 'q', 'so does serialized data' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, 'garbage' ), array(), 'and garbage reads as empty' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, '' ), array(), 'as does nothing at all' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $text, null ), '', 'a scalar field with nothing stored reads as an empty string' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $text, '0' ), '0', 'and "0" reads back as "0"' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-schema.php'`

- [ ] **Step 3: Write the implementation**

Create `modules/rest-bridge/class-dpt-rb-schema.php`:

```php
<?php
/**
 * REST Bridge - what a field's type means to the REST API.
 *
 * One place decides three things per JetEngine type: the schema the API
 * advertises, how a written value is cleaned, and how a stored value is
 * presented. Keeping them together is what stops a field being advertised as
 * one thing and sanitized as another.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Maps JetEngine field types onto REST schemas and sanitizers.
 */
class DPT_RB_Schema {

	/**
	 * The schema for one descriptor.
	 *
	 * @param array $descriptor Field descriptor from DPT_RB_Definitions.
	 * @return array
	 */
	public static function for_descriptor( $descriptor ) {
		$schema = array(
			'description' => $descriptor['title'],
			'context'     => array( 'view', 'edit' ),
		);

		if ( 'repeater' === $descriptor['type'] ) {
			$properties = array();
			foreach ( $descriptor['fields'] as $sub ) {
				$properties[ $sub['meta_key'] ] = array(
					'description' => $sub['title'],
					'type'        => self::json_type( $sub['type'] ),
				);
			}
			$schema['type']  = 'array';
			$schema['items'] = array(
				'type'       => 'object',
				'properties' => $properties,
			);
			return $schema;
		}

		$schema['type'] = self::json_type( $descriptor['type'] );
		return $schema;
	}

	/**
	 * The JSON Schema type one JetEngine type presents as.
	 *
	 * @param string $type JetEngine type.
	 * @return string
	 */
	private static function json_type( $type ) {
		switch ( $type ) {
			case 'number':
				return 'number';
			case 'media':
				return 'integer';
			case 'checkbox':
				return 'object';
			case 'repeater':
				return 'array';
			default:
				// text, textarea, wysiwyg, select, radio, switcher, dates.
				// A switcher is stored as the string 'true' or 'false' by
				// JetEngine, so it is advertised as what it is.
				return 'string';
		}
	}

	/**
	 * Clean a written value, or refuse it.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Whatever the request sent.
	 * @return mixed|WP_Error
	 */
	public static function sanitize( $descriptor, $value ) {
		if ( 'repeater' !== $descriptor['type'] ) {
			return self::sanitize_scalar( $descriptor['type'], $value );
		}

		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'dpt_rb_invalid_repeater',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s must be an array of items.', 'digitizer-pro-tools' ),
					$descriptor['meta_key']
				),
				array( 'status' => 400 )
			);
		}

		$known = array();
		foreach ( $descriptor['fields'] as $sub ) {
			$known[ $sub['meta_key'] ] = $sub['type'];
		}

		$clean = array();
		foreach ( $value as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error(
					'dpt_rb_invalid_repeater_item',
					sprintf(
						/* translators: 1: field name, 2: item number */
						__( 'Item %2$d of the field %1$s must be an object.', 'digitizer-pro-tools' ),
						$descriptor['meta_key'],
						(int) $index
					),
					array( 'status' => 400 )
				);
			}
			$row = array();
			foreach ( $known as $key => $type ) {
				if ( array_key_exists( $key, $item ) ) {
					$row[ $key ] = self::sanitize_scalar( $type, $item[ $key ] );
				}
			}
			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Clean one non-repeater value.
	 *
	 * @param string $type  JetEngine type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_scalar( $type, $value ) {
		switch ( $type ) {
			case 'wysiwyg':
				return wp_kses_post( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'number':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'media':
				return absint( $value );
			case 'switcher':
				// JetEngine stores a switcher as a string, and a REST client
				// may send a real boolean; both end up saying the same thing.
				return ( $value && 'false' !== $value && '0' !== $value ) ? 'true' : 'false';
			case 'checkbox':
				$out = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $on ) {
						$key = sanitize_key( $key );
						if ( '' !== $key ) {
							$out[ $key ] = ( $on && 'false' !== $on && '0' !== $on ) ? 'true' : 'false';
						}
					}
				}
				return $out;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Present a stored value the way the API promises it.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $stored     Whatever the database held.
	 * @return mixed
	 */
	public static function normalize_read( $descriptor, $stored ) {
		if ( 'repeater' !== $descriptor['type'] ) {
			if ( 'checkbox' === $descriptor['type'] ) {
				return is_array( $stored ) ? $stored : array();
			}
			return ( null === $stored || is_array( $stored ) ) ? '' : (string) $stored;
		}

		if ( is_array( $stored ) ) {
			return array_values( $stored );
		}
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );
		if ( is_array( $decoded ) ) {
			return array_values( $decoded );
		}

		// JetEngine has stored repeaters as PHP serialization. Unserializing
		// without allowing classes keeps a crafted string from building one.
		$unserialized = @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore
		return is_array( $unserialized ) ? array_values( $unserialized ) : array();
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-schema.php tests/rest-bridge-test.php
git commit -m "REST Bridge: schemas and sanitizers driven by the JetEngine type"
```

---

### Task 4: DPT_RB_Fields — registration, read/write callbacks, compatibility

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-fields.php`
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: `DPT_RB_Definitions::all()` (Task 2), `DPT_RB_Schema::for_descriptor()` / `::sanitize()` / `::normalize_read()` (Task 3).
- Produces:
  - `DPT_RB_Fields::register()` → `void`, performs every `register_rest_field` call
  - `DPT_RB_Fields::registered()` → `array<string /*"object:target"*/, string[] /*field names*/>` — what the info endpoint reports
  - `DPT_RB_Fields::compat()` → `string[]` names added by the compatibility layer
  - `DPT_RB_Fields::read( array $descriptor, $object )` / `::write( array $descriptor, $value, $object )` — the callbacks, public so tests reach them without a REST request

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`:

```php
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-fields.php';

/* ---- discovered fields become REST fields under their own names ---- */

$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-extras',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post', 'ghost' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
		array(
			'id'          => 'author-extras',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'linkedin', 'title' => 'LinkedIn', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['qna'] ), 'the repeater is exposed under its real name' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin'] ), 'and the taxonomy field on its taxonomy' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['ghost'] ), 'a post type the site does not expose to REST is left alone' );

$args = $GLOBALS['dpt_stub_rest_fields']['post']['qna'];
dpt_test_eq( $args['schema']['type'], 'array', 'carrying the schema its type earns' );
dpt_test_ok( is_callable( $args['get_callback'] ), 'with a reader' );
dpt_test_ok( is_callable( $args['update_callback'] ), 'and a writer' );

/* ---- the old plugin's names keep working ---- */

// ContentEngine writes jet_qna. The field is really called qna, and both must
// reach the same meta key, or a working automation breaks on upgrade day.
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'jet_qna is still there' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'and is named as a compatibility alias' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['reading_time'] ), 'so is reading_time, which JetEngine here does not define' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['author_description'] ), 'and the author fields the old plugin promised' );

// But a name the discovery already produced is never taken over by the
// compatibility layer - the real definition wins.
dpt_test_ok( ! in_array( 'linkedin', DPT_RB_Fields::compat(), true ), 'a discovered field is not also a compatibility field' );

/* ---- reading and writing through the callbacks ---- */

$defs = DPT_RB_Definitions::all();
$qna  = null;
foreach ( $defs as $d ) {
	if ( 'qna' === $d['meta_key'] ) {
		$qna = $d;
	}
}

$GLOBALS['dpt_stub_post_meta'] = array();
$post_object                   = array( 'id' => 11 );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object ), array(), 'a post with no FAQ reads as an empty list' );

$written = DPT_RB_Fields::write( $qna, array( array( 'question' => 'Why?', 'answer' => 'Because' ) ), (object) array( 'ID' => 11 ) );
dpt_test_ok( true === $written, 'a valid FAQ is written' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object )[0]['question'], 'Why?', 'and reads back' );

dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, 'nope', (object) array( 'ID' => 11 ) ) ), 'a malformed FAQ is refused' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object )[0]['question'], 'Why?', 'and the stored one is untouched' );

dpt_test_ok( true === DPT_RB_Fields::write( $qna, array(), (object) array( 'ID' => 11 ) ), 'an empty list clears the field' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object ), array(), 'which reads back as empty' );
dpt_test_ok( true === DPT_RB_Fields::write( $qna, array(), (object) array( 'ID' => 11 ) ), 'clearing an already-empty field is still a success' );

// A site that refuses the write must not be told it succeeded.
$GLOBALS['dpt_stub_meta_write_fails'] = array( 'qna' );
dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, array( array( 'question' => 'q', 'answer' => 'a' ) ), (object) array( 'ID' => 11 ) ) ), 'a refused write is an error, not a shrug' );
$GLOBALS['dpt_stub_meta_write_fails'] = array();

// Taxonomy fields live in term meta, and the callbacks are handed terms
// rather than posts.
$linkedin = null;
foreach ( $defs as $d ) {
	if ( 'linkedin' === $d['meta_key'] ) {
		$linkedin = $d;
	}
}
$GLOBALS['dpt_stub_term_meta'] = array();
DPT_RB_Fields::write( $linkedin, 'https://example.test/x', (object) array( 'term_id' => 4 ) );
dpt_test_eq( DPT_RB_Fields::read( $linkedin, array( 'id' => 4 ) ), 'https://example.test/x', 'a taxonomy field round-trips through term meta' );

/* ---- what the site is told it exposes ---- */

$registered = DPT_RB_Fields::registered();
dpt_test_ok( isset( $registered['post/post'] ), 'the report knows about posts' );
dpt_test_ok( in_array( 'qna', $registered['post/post'], true ), 'and lists the field there' );
dpt_test_ok( isset( $registered['taxonomy/authors'] ), 'and about the taxonomy' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-fields.php'`

- [ ] **Step 3: Write the implementation**

Create `modules/rest-bridge/class-dpt-rb-fields.php`:

```php
<?php
/**
 * REST Bridge - putting the discovered fields on the REST API.
 *
 * Discovery says what exists; this says it out loud to the API, under the
 * names JetEngine actually uses. A small compatibility layer keeps the names
 * the plugin this module replaces had invented, because automations were
 * written against those and an upgrade is not the moment to break them.
 *
 * Capabilities are not checked here on purpose: these are fields on core's
 * own post and term controllers, which have already established that the
 * request may edit the object before any update callback runs.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers REST fields for discovered and legacy meta.
 */
class DPT_RB_Fields {

	/**
	 * object/target => list of field names, for the info endpoint.
	 *
	 * @var array
	 */
	private static $registered = array();

	/**
	 * Names the compatibility layer added.
	 *
	 * @var array
	 */
	private static $compat = array();

	/**
	 * The legacy fields the replaced plugin promised, kept because
	 * automations use them and they may not be JetEngine fields at all.
	 *
	 * @var array
	 */
	private static function legacy() {
		return array(
			array(
				'meta_key' => 'reading_time',
				'title'    => 'Estimated reading time',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'post',
				'targets'  => array( 'post' ),
			),
			array(
				'meta_key' => 'author_description',
				'title'    => 'Author bio description',
				'type'     => 'wysiwyg',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			array(
				'meta_key' => 'author_image',
				'title'    => 'Author avatar image URL',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			array(
				'meta_key' => 'linkedin',
				'title'    => 'Author LinkedIn URL',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
		);
	}

	/**
	 * Register everything. Called on rest_api_init.
	 */
	public static function register() {
		self::$registered = array();
		self::$compat     = array();

		$discovered = DPT_RB_Definitions::all();
		foreach ( $discovered as $descriptor ) {
			self::register_one( $descriptor, $descriptor['meta_key'] );
		}

		// The alias: one name the old plugin invented for a repeater whose
		// real name is qna. It writes the same meta key, so both names are
		// the same field seen twice rather than two fields to keep in step.
		foreach ( $discovered as $descriptor ) {
			if ( 'qna' === $descriptor['meta_key'] && 'repeater' === $descriptor['type'] ) {
				self::register_one( $descriptor, 'jet_qna' );
				self::$compat[] = 'jet_qna';
			}
		}

		// And the fields the old plugin hard-coded. Each is registered only
		// where discovery did not already produce that name: a real
		// definition knows more than this list does.
		foreach ( self::legacy() as $descriptor ) {
			if ( self::already( $descriptor ) ) {
				continue;
			}
			self::register_one( $descriptor, $descriptor['meta_key'] );
			self::$compat[] = $descriptor['meta_key'];
		}

		// If nothing was discovered there is no qna to alias, yet ContentEngine
		// still writes jet_qna. Give it the shape the old plugin gave it.
		if ( ! in_array( 'jet_qna', self::$compat, true ) && ! self::name_taken( 'post', 'post', 'jet_qna' ) ) {
			self::register_one( self::fallback_qna(), 'jet_qna' );
			self::$compat[] = 'jet_qna';
		}
	}

	/**
	 * The FAQ repeater as the replaced plugin defined it, for a site whose
	 * JetEngine definitions this module cannot see.
	 *
	 * @return array
	 */
	private static function fallback_qna() {
		return array(
			'meta_key' => 'qna',
			'title'    => 'FAQ (question and answer pairs)',
			'type'     => 'repeater',
			'fields'   => array(
				array( 'meta_key' => 'question', 'title' => 'Question', 'type' => 'text', 'fields' => array() ),
				array( 'meta_key' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg', 'fields' => array() ),
			),
			'object'   => 'post',
			'targets'  => array( 'post' ),
		);
	}

	/**
	 * Whether discovery already produced this name on any of its targets.
	 *
	 * @param array $descriptor Legacy descriptor.
	 * @return bool
	 */
	private static function already( $descriptor ) {
		foreach ( $descriptor['targets'] as $target ) {
			if ( self::name_taken( $descriptor['object'], $target, $descriptor['meta_key'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a name is already registered on one target.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy.
	 * @param string $name   Field name.
	 * @return bool
	 */
	private static function name_taken( $object, $target, $name ) {
		$key = $object . '/' . $target;
		return isset( self::$registered[ $key ] ) && in_array( $name, self::$registered[ $key ], true );
	}

	/**
	 * Register one descriptor under one name, on every target that the site
	 * actually exposes to the REST API.
	 *
	 * @param array  $descriptor Field descriptor.
	 * @param string $name       The name to expose it under.
	 */
	private static function register_one( $descriptor, $name ) {
		$schema = DPT_RB_Schema::for_descriptor( $descriptor );

		foreach ( $descriptor['targets'] as $target ) {
			if ( ! self::exposed( $descriptor['object'], $target ) ) {
				continue;
			}

			register_rest_field(
				$target,
				$name,
				array(
					'get_callback'    => function ( $object ) use ( $descriptor ) {
						return DPT_RB_Fields::read( $descriptor, $object );
					},
					'update_callback' => function ( $value, $object ) use ( $descriptor ) {
						return DPT_RB_Fields::write( $descriptor, $value, $object );
					},
					'schema'          => $schema,
				)
			);

			$key                       = $descriptor['object'] . '/' . $target;
			self::$registered[ $key ]  = isset( self::$registered[ $key ] ) ? self::$registered[ $key ] : array();
			self::$registered[ $key ][] = $name;
		}
	}

	/**
	 * Whether a post type or taxonomy is on the REST API at all. Registering
	 * a field on something invisible would only be a lie in the info report.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @return bool
	 */
	private static function exposed( $object, $target ) {
		if ( 'taxonomy' === $object ) {
			$tax = get_taxonomy( $target );
			return $tax && ! empty( $tax->show_in_rest );
		}
		$type = get_post_type_object( $target );
		return $type && ! empty( $type->show_in_rest );
	}

	/**
	 * What was registered where.
	 *
	 * @return array
	 */
	public static function registered() {
		return self::$registered;
	}

	/**
	 * Names owed to the compatibility layer rather than to a definition.
	 *
	 * @return array
	 */
	public static function compat() {
		return self::$compat;
	}

	/**
	 * The id of the object a REST callback was handed. Core passes an array
	 * for a read and an object for a write, and terms and posts name their
	 * id differently.
	 *
	 * @param mixed $object Post or term, as array or object.
	 * @return int
	 */
	private static function object_id( $object ) {
		if ( is_array( $object ) ) {
			if ( isset( $object['id'] ) ) {
				return (int) $object['id'];
			}
			return isset( $object['term_id'] ) ? (int) $object['term_id'] : 0;
		}
		if ( is_object( $object ) ) {
			if ( isset( $object->ID ) ) {
				return (int) $object->ID;
			}
			return isset( $object->term_id ) ? (int) $object->term_id : 0;
		}
		return 0;
	}

	/**
	 * Read a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $object     Post or term.
	 * @return mixed
	 */
	public static function read( $descriptor, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return DPT_RB_Schema::normalize_read( $descriptor, null );
		}
		$stored = 'taxonomy' === $descriptor['object']
			? get_term_meta( $id, $descriptor['meta_key'], true )
			: get_post_meta( $id, $descriptor['meta_key'], true );

		return DPT_RB_Schema::normalize_read( $descriptor, $stored );
	}

	/**
	 * Write a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Incoming value.
	 * @param mixed $object     Post or term.
	 * @return true|WP_Error
	 */
	public static function write( $descriptor, $value, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return new WP_Error(
				'dpt_rb_no_object',
				__( 'The object to update could not be identified.', 'digitizer-pro-tools' ),
				array( 'status' => 400 )
			);
		}

		$clean = DPT_RB_Schema::sanitize( $descriptor, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$is_tax = 'taxonomy' === $descriptor['object'];
		$key    = $descriptor['meta_key'];

		// An empty repeater means "clear this", which is a delete rather than
		// a write of nothing.
		if ( 'repeater' === $descriptor['type'] && array() === $clean ) {
			$deleted = $is_tax ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
			if ( $deleted ) {
				return true;
			}
			// A delete also reports false when there was nothing there, which
			// is the outcome that was asked for.
			$still = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( '' === $still || array() === $still ) {
				return true;
			}
			return new WP_Error(
				'dpt_rb_not_cleared',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s could not be cleared.', 'digitizer-pro-tools' ),
					$key
				),
				array( 'status' => 500 )
			);
		}

		$updated = $is_tax ? update_term_meta( $id, $key, $clean ) : update_post_meta( $id, $key, $clean );
		if ( false === $updated ) {
			// update_*_meta returns false for an unchanged value as well as
			// for a refusal; only a value that did not land is a failure.
			$stored = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( $stored !== $clean ) {
				return new WP_Error(
					'dpt_rb_not_saved',
					sprintf(
						/* translators: %s: field name */
						__( 'The field %s could not be saved.', 'digitizer-pro-tools' ),
						$key
					),
					array( 'status' => 500 )
				);
			}
		}

		return true;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-fields.php tests/rest-bridge-test.php
git commit -m "REST Bridge: register discovered fields, with the old plugin's names kept working"
```

---

### Task 5: DPT_RB_Elementor — the ported endpoints

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-elementor.php`
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: harness stubs `get_post`, `get_post_meta`, `update_post_meta`, `register_rest_route`, `current_user_can( 'edit_post', $id )`.
- Produces:
  - `DPT_RB_Elementor::register()` → `void`, registers both routes
  - `DPT_RB_Elementor::may_edit( $request )` → `bool` permission callback
  - `DPT_RB_Elementor::get_tree( $request )` → response array or `WP_Error`
  - `DPT_RB_Elementor::update( $request )` → response array or `WP_Error`
  - The harness needs a tiny `DPT_Stub_Request` (added in this task's test) exposing `offsetGet`/`get_param`.

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`:

```php
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-elementor.php';

/**
 * The slice of WP_REST_Request these endpoints touch: array access for the
 * URL parameter and get_param for the body.
 */
class DPT_Stub_Request implements ArrayAccess {
	private $params;
	public function __construct( $params ) { $this->params = $params; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
	#[\ReturnTypeWillChange]
	public function offsetExists( $key ) { return isset( $this->params[ $key ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $key ) { return $this->get_param( $key ); }
	#[\ReturnTypeWillChange]
	public function offsetSet( $key, $value ) { $this->params[ $key ] = $value; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $key ) { unset( $this->params[ $key ] ); }
}

/* ---- reading an Elementor page ---- */

$GLOBALS['dpt_stub_posts']     = array( 20 => 'page' );
$GLOBALS['dpt_stub_post_meta'] = array();
$layout                        = array(
	array(
		'id'       => 'sec1',
		'elType'   => 'section',
		'elements' => array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Old title', 'align' => 'center' ),
			),
			array(
				'id'         => 'w2',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => str_repeat( 'x', 250 ) ),
			),
		),
	),
);
update_post_meta( 20, '_elementor_data', wp_json_encode( $layout ) );

$response = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 20 ) ) );
dpt_test_eq( $response['widget_count'], 2, 'both widgets are counted' );
dpt_test_eq( $response['tree'][0]['type'], 'section', 'the section is the root' );
dpt_test_eq( $response['tree'][0]['children'][0]['widget'], 'heading', 'with the widget under it' );
dpt_test_eq( $response['tree'][0]['children'][0]['title'], 'Old title', 'and its text pulled out' );
dpt_test_eq( strlen( $response['tree'][0]['children'][1]['editor'] ), 203, 'long content is truncated for readability' );

dpt_test_ok( is_wp_error( DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 99 ) ) ) ), 'a post that does not exist is a 404' );

$GLOBALS['dpt_stub_posts'][21] = 'page';
dpt_test_ok( is_wp_error( DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ) ), 'a post with no Elementor data is a 404 too' );

/* ---- updating one widget without disturbing the rest ---- */

$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;
$result = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'New title' ) ),
		array( 'widget_id' => 'nope', 'settings' => array( 'title' => 'x' ) ),
	),
) ) );

dpt_test_eq( $result['updates_applied'], 1, 'the widget that exists was updated' );
dpt_test_eq( $result['not_found'], array( 'nope' ), 'and the one that does not is reported' );

$saved = json_decode( get_post_meta( 20, '_elementor_data', true ), true );
dpt_test_eq( $saved[0]['elements'][0]['settings']['title'], 'New title', 'the setting changed' );
dpt_test_eq( $saved[0]['elements'][0]['settings']['align'], 'center', 'and the settings around it did not' );
dpt_test_eq( get_post_meta( 20, '_elementor_css', true ), '', 'the stale CSS is gone' );

dpt_test_ok( is_wp_error( DPT_RB_Elementor::update( new DPT_Stub_Request( array( 'post_id' => 20, 'updates' => array() ) ) ) ), 'an empty update list is refused' );
dpt_test_ok( is_wp_error( DPT_RB_Elementor::update( new DPT_Stub_Request( array( 'post_id' => 20, 'updates' => array( array( 'widget_id' => 'w1' ) ) ) ) ), 'an update with no settings is refused' );

/* ---- and only for someone allowed to edit that post ---- */

// The plugin this replaces asked only whether the user could edit something,
// which let anyone with an author's rights rewrite every page on the site.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 20 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a post this user may not edit is refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'and one they may is allowed' );

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_RB_Elementor::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/elementor/(?P<post_id>\d+)'] ), 'the route is registered where the old plugin had it' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-elementor.php'`

- [ ] **Step 3: Write the implementation**

Create `modules/rest-bridge/class-dpt-rb-elementor.php`:

```php
<?php
/**
 * REST Bridge - reading and editing Elementor content over REST.
 *
 * Ported from the Digitizer API Extensions plugin, with the response shapes
 * kept byte for byte: the automations that call these routes were written
 * against them. What changed is the permission check, which used to ask only
 * whether the caller could edit anything at all.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The /digitizer/v1/elementor/{id} endpoints.
 */
class DPT_RB_Elementor {

	const NAMESPACE_V1 = 'digitizer/v1';
	const ROUTE        = '/elementor/(?P<post_id>\d+)';

	/**
	 * Settings keys that hold text worth showing in a tree.
	 *
	 * @var array
	 */
	private static $content_keys = array(
		'title',
		'editor',
		'text',
		'title_text',
		'heading_title',
		'tab_title',
		'description_text',
	);

	/**
	 * Register both endpoints.
	 */
	public static function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_tree' ),
				'permission_callback' => array( __CLASS__, 'may_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => array( __CLASS__, 'may_edit' ),
				'args'                => array(
					'updates' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'A list of { widget_id, settings } objects.', 'digitizer-pro-tools' ),
					),
				),
			)
		);
	}

	/**
	 * Whether this request may edit the post it names.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function may_edit( $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * The Elementor layout of one post, decoded.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error
	 */
	private static function layout( $post_id ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'digitizer-pro-tools' ), array( 'status' => 404 ) );
		}

		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) ) {
			return new WP_Error( 'no_elementor', __( 'This post has no Elementor data.', 'digitizer-pro-tools' ), array( 'status' => 404 ) );
		}

		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', __( 'The stored Elementor data is not valid JSON.', 'digitizer-pro-tools' ), array( 'status' => 500 ) );
		}

		return $data;
	}

	/**
	 * GET: the widget tree.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	public static function get_tree( $request ) {
		$post_id = (int) $request['post_id'];
		$data    = self::layout( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response(
			array(
				'post_id'      => $post_id,
				'widget_count' => self::count_widgets( $data ),
				'tree'         => self::tree( $data ),
			)
		);
	}

	/**
	 * POST: merge settings into named widgets.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	public static function update( $request ) {
		$post_id = (int) $request['post_id'];
		$updates = $request->get_param( 'updates' );

		if ( ! is_array( $updates ) || ! $updates ) {
			return new WP_Error( 'invalid_updates', __( 'Updates must be a non-empty array.', 'digitizer-pro-tools' ), array( 'status' => 400 ) );
		}

		$data = self::layout( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$map = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) || ! isset( $update['widget_id'] ) || ! isset( $update['settings'] ) || ! is_array( $update['settings'] ) ) {
				return new WP_Error( 'invalid_update', __( 'Each update must have a widget_id and a settings object.', 'digitizer-pro-tools' ), array( 'status' => 400 ) );
			}
			$map[ (string) $update['widget_id'] ] = $update['settings'];
		}

		$applied = 0;
		$data    = self::apply( $data, $map, $applied );

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

		// The rendered CSS is now describing a page that no longer exists.
		delete_post_meta( $post_id, '_elementor_css' );
		self::clear_cache();

		return rest_ensure_response(
			array(
				'success'          => true,
				'post_id'          => $post_id,
				'updates_requested' => count( $updates ),
				'updates_applied'  => $applied,
				'not_found'        => array_values( array_keys( array_diff_key( $map, array_flip( self::collect_ids( $data ) ) ) ) ),
			)
		);
	}

	/**
	 * Ask Elementor to forget its generated files, when Elementor is here.
	 *
	 * Deleting the post's own _elementor_css is not enough on its own: the
	 * global and per-post files are managed elsewhere, and a page can keep
	 * serving the old CSS until they are cleared.
	 */
	private static function clear_cache() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		$elementor = \Elementor\Plugin::instance();
		if ( isset( $elementor->files_manager ) && is_callable( array( $elementor->files_manager, 'clear_cache' ) ) ) {
			$elementor->files_manager->clear_cache();
		}
	}

	/**
	 * A readable tree of what is on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private static function tree( $elements ) {
		$tree = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$node = array(
				'id'   => isset( $element['id'] ) ? $element['id'] : '',
				'type' => isset( $element['elType'] ) ? $element['elType'] : 'unknown',
			);

			if ( ! empty( $element['widgetType'] ) ) {
				$node['widget'] = $element['widgetType'];
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			foreach ( self::$content_keys as $key ) {
				if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || '' === $settings[ $key ] ) {
					continue;
				}
				$value = $settings[ $key ];
				if ( strlen( $value ) > 200 ) {
					$value = substr( $value, 0, 200 ) . '...';
				}
				$node[ $key ] = $value;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$node['children'] = self::tree( $element['elements'] );
			}

			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * How many widgets are on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return int
	 */
	private static function count_widgets( $elements ) {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$count++;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::count_widgets( $element['elements'] );
			}
		}
		return $count;
	}

	/**
	 * Merge the update map into the tree, counting what landed.
	 *
	 * @param array $elements Elementor elements.
	 * @param array $map      widget id => settings to merge.
	 * @param int   $applied  Running count, by reference.
	 * @return array
	 */
	private static function apply( $elements, $map, &$applied ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';

			if ( '' !== $id && isset( $map[ $id ] ) ) {
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
					$element['settings'] = array();
				}
				foreach ( $map[ $id ] as $key => $value ) {
					$element['settings'][ $key ] = $value;
				}
				$applied++;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::apply( $element['elements'], $map, $applied );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Every element id on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private static function collect_ids( $elements ) {
		$ids = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, self::collect_ids( $element['elements'] ) );
			}
		}
		return $ids;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-elementor.php tests/rest-bridge-test.php
git commit -m "REST Bridge: port the Elementor endpoints with per-post permission checks"
```

---

### Task 6: DPT_RB_Rankmath and DPT_RB_Info

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-rankmath.php`
- Create: `modules/rest-bridge/class-dpt-rb-info.php`
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: `DPT_RB_Definitions::skipped()` (Task 2), `DPT_RB_Fields::registered()` / `::compat()` (Task 4).
- Produces:
  - `DPT_RB_Rankmath::register()` → `void`; `DPT_RB_Rankmath::active()` → `bool`
  - `DPT_RB_Info::register()` → `void`; `DPT_RB_Info::payload()` → `array`

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`:

```php
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-rankmath.php';
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-info.php';

/* ---- Rank Math fields, only when Rank Math is here ---- */

$GLOBALS['dpt_stub_registered_post_meta'] = array();
dpt_test_ok( ! DPT_RB_Rankmath::active(), 'without Rank Math the module knows it' );
DPT_RB_Rankmath::register();
dpt_test_eq( $GLOBALS['dpt_stub_registered_post_meta'], array(), 'and registers nothing for a plugin that is not installed' );

// Declared inside a block so PHP does not hoist it above the assertion above.
if ( ! class_exists( 'RankMath' ) ) {
	class RankMath {}
}
dpt_test_ok( DPT_RB_Rankmath::active(), 'with Rank Math loaded it is seen' );
DPT_RB_Rankmath::register();
dpt_test_eq( count( $GLOBALS['dpt_stub_registered_post_meta']['post'] ), 12, 'all twelve fields land on posts' );
dpt_test_eq( count( $GLOBALS['dpt_stub_registered_post_meta']['page'] ), 12, 'and on pages, which the old plugin forgot' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_focus_keyword'] ), 'including the focus keyword' );
dpt_test_ok( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_title']['show_in_rest'], 'each visible to REST' );

/* ---- the info endpoint tells an agent what this site exposes ---- */

$info = DPT_RB_Info::payload();
dpt_test_eq( $info['version'], DPT_VERSION, 'the payload names the plugin version' );
dpt_test_ok( isset( $info['fields']['post/post'] ), 'lists the fields per object' );
dpt_test_ok( in_array( 'jet_qna', $info['compat'], true ), 'names the compatibility aliases' );
dpt_test_ok( is_array( $info['skipped'] ), 'reports what discovery passed over' );
dpt_test_ok( $info['rank_math'], 'and whether Rank Math is here' );
dpt_test_ok( in_array( '/digitizer/v1/info', $info['routes'], true ), 'while naming its own route' );

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_RB_Info::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'] ), 'the info route is registered' );

// The old plugin's info endpoint was public and advertised versions to
// anyone who asked.
$route = $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'][0];
$GLOBALS['dpt_stub_denied_caps'] = array( 'edit_posts' );
dpt_test_ok( ! call_user_func( $route['permission_callback'] ), 'a visitor may not read it' );
$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_ok( call_user_func( $route['permission_callback'] ), 'an editor may' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-rankmath.php'`

- [ ] **Step 3: Write the implementations**

Create `modules/rest-bridge/class-dpt-rb-rankmath.php`:

```php
<?php
/**
 * REST Bridge - Rank Math's SEO fields on the REST API.
 *
 * Rank Math stores these as ordinary post meta and does not expose them to
 * REST itself, so anything writing SEO metadata over the API needs them
 * registered. Pages get them as well as posts: the plugin this replaces
 * registered posts only, which left every landing page unreachable.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the Rank Math meta keys for REST.
 */
class DPT_RB_Rankmath {

	/**
	 * Whether Rank Math is running here.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'RankMath' );
	}

	/**
	 * The keys, and what each one is.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'rank_math_title'               => __( 'SEO title override', 'digitizer-pro-tools' ),
			'rank_math_description'         => __( 'SEO meta description', 'digitizer-pro-tools' ),
			'rank_math_focus_keyword'       => __( 'Focus keyword(s)', 'digitizer-pro-tools' ),
			'rank_math_robots'              => __( 'Robot meta directives', 'digitizer-pro-tools' ),
			'rank_math_canonical_url'       => __( 'Canonical URL override', 'digitizer-pro-tools' ),
			'rank_math_primary_category'    => __( 'Primary category ID', 'digitizer-pro-tools' ),
			'rank_math_seo_score'           => __( 'SEO score (0-100)', 'digitizer-pro-tools' ),
			'rank_math_og_title'            => __( 'Open Graph title', 'digitizer-pro-tools' ),
			'rank_math_og_description'      => __( 'Open Graph description', 'digitizer-pro-tools' ),
			'rank_math_og_image'            => __( 'Open Graph image URL', 'digitizer-pro-tools' ),
			'rank_math_twitter_title'       => __( 'Twitter card title', 'digitizer-pro-tools' ),
			'rank_math_twitter_description' => __( 'Twitter card description', 'digitizer-pro-tools' ),
		);
	}

	/**
	 * Register them, when there is something to register them for.
	 */
	public static function register() {
		if ( ! self::active() ) {
			return;
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			foreach ( self::fields() as $key => $description ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'string',
						'description'   => $description,
						'auth_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}
}
```

Create `modules/rest-bridge/class-dpt-rb-info.php`:

```php
<?php
/**
 * REST Bridge - what this site exposes, in one request.
 *
 * The agents that use this API are handed a site they have never seen. This
 * endpoint is the map: the fields that were discovered and their schemas, the
 * legacy names still honoured, what was passed over and why, and the routes
 * that exist. It replaces the old plugin's faq/info, which was public and
 * described a fixed feature list rather than the site in front of it.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The /digitizer/v1/info endpoint.
 */
class DPT_RB_Info {

	/**
	 * Register the route.
	 */
	public static function register() {
		register_rest_route(
			DPT_RB_Elementor::NAMESPACE_V1,
			'/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'respond' ),
				'permission_callback' => function () {
					// It describes the site's editing surface, so it is for
					// people who edit the site.
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * The response.
	 *
	 * @return array
	 */
	public static function respond() {
		return rest_ensure_response( self::payload() );
	}

	/**
	 * What the endpoint says.
	 *
	 * @return array
	 */
	public static function payload() {
		$fields = array();
		foreach ( DPT_RB_Fields::registered() as $where => $names ) {
			$fields[ $where ] = array_values( $names );
		}

		return array(
			'module'    => 'Digitizer Pro Tools - REST Bridge',
			'version'   => DPT_VERSION,
			'fields'    => $fields,
			'compat'    => array_values( DPT_RB_Fields::compat() ),
			'skipped'   => array_values( DPT_RB_Definitions::skipped() ),
			'rank_math' => DPT_RB_Rankmath::active(),
			'routes'    => array(
				'/digitizer/v1/elementor/{post_id}',
				'/digitizer/v1/info',
			),
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-rankmath.php modules/rest-bridge/class-dpt-rb-info.php tests/rest-bridge-test.php
git commit -m "REST Bridge: Rank Math fields on posts and pages, and a site-shaped info endpoint"
```

---

### Task 7: DPT_RB_Module and the registry entry

**Files:**
- Create: `modules/rest-bridge/class-dpt-rb-module.php`
- Modify: `includes/class-dpt-plugin.php` (registry array, after the `update_policy` entry)
- Modify: `tests/rest-bridge-test.php`

**Interfaces:**
- Consumes: everything above; `DPT_Module` from `includes/class-dpt-module.php`.
- Produces:
  - `DPT_Rest_Bridge_Module` with `id()` = `'rest_bridge'`, `title()`, `description()`, `init()`, `standing_down_reason()`
  - `DPT_Rest_Bridge_Module::legacy_plugin_active()` → `bool`
  - `DPT_Rest_Bridge_Module::boot()` → `void` — the `rest_api_init` body, called directly by tests

- [ ] **Step 1: Write the failing test**

Append to `tests/rest-bridge-test.php`:

```php
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-module.php';

/* ---- the module itself ---- */

$module = new DPT_Rest_Bridge_Module();
dpt_test_eq( $module->id(), 'rest_bridge', 'the module has the id the registry uses' );
dpt_test_ok( '' !== $module->title(), 'and a title' );
dpt_test_ok( '' !== $module->description(), 'and a description' );

$GLOBALS['dpt_stub_filters']     = array();
$GLOBALS['dpt_stub_rest_routes'] = array();
$GLOBALS['dpt_stub_rest_fields'] = array();

dpt_test_ok( ! DPT_Rest_Bridge_Module::legacy_plugin_active(), 'without the old plugin the module is in charge' );
dpt_test_eq( $module->standing_down_reason(), '', 'and has nothing to explain' );

$module->init();
dpt_test_ok( dpt_stub_has_filter( 'rest_api_init' ), 'so it hooks the REST API' );

// Booting registers the whole surface at once.
DPT_RB_Definitions::reset();
DPT_Rest_Bridge_Module::boot();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'] ), 'the info route is up' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/elementor/(?P<post_id>\d+)'] ), 'so are the Elementor routes' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'and the fields' );

/* ---- but not while the plugin it replaces is running ---- */

// Two registrations of the same field name on the same post type is a race
// nobody can debug from the outside, so the module steps back and says so.
if ( ! function_exists( 'digitizer_elementor_build_tree' ) ) {
	function digitizer_elementor_build_tree( $elements ) { return $elements; }
}
dpt_test_ok( DPT_Rest_Bridge_Module::legacy_plugin_active(), 'the old plugin is recognised' );
dpt_test_ok( '' !== $module->standing_down_reason(), 'and the Modules screen is told why nothing happens' );

$GLOBALS['dpt_stub_filters'] = array();
$module->init();
dpt_test_ok( ! dpt_stub_has_filter( 'rest_api_init' ), 'the module registers nothing at all' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rest-bridge-test.php`
Expected: FAIL — `Failed opening required '.../class-dpt-rb-module.php'`

- [ ] **Step 3: Write the implementation**

Create `modules/rest-bridge/class-dpt-rb-module.php`:

```php
<?php
/**
 * REST Bridge module - exposes this site's own custom fields to the REST API.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-rb-definitions.php';
require_once __DIR__ . '/class-dpt-rb-schema.php';
require_once __DIR__ . '/class-dpt-rb-fields.php';
require_once __DIR__ . '/class-dpt-rb-elementor.php';
require_once __DIR__ . '/class-dpt-rb-rankmath.php';
require_once __DIR__ . '/class-dpt-rb-info.php';

/**
 * Wires the REST bridge.
 */
class DPT_Rest_Bridge_Module extends DPT_Module {

	public function id() {
		return 'rest_bridge';
	}

	public function title() {
		return __( 'REST Bridge', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Puts this site\'s own custom fields on the REST API, so an automation can read and write them the way it reads and writes a post title. The fields are found by reading the definitions JetEngine already stores, which means a field added in JetEngine appears in the API without anyone writing code for it - repeaters included, with their sub-fields described. Also exposes Rank Math\'s SEO fields, and endpoints for reading and editing Elementor content without disturbing a page\'s design.', 'digitizer-pro-tools' );
	}

	/**
	 * Whether the plugin this module replaces is running here.
	 *
	 * Both would register the same field names on the same post types and
	 * the same routes in the same namespace; which one answered would be a
	 * matter of load order. The plugin wins, because it is the one somebody
	 * installed on purpose, and this module stands aside and says so.
	 *
	 * @return bool
	 */
	public static function legacy_plugin_active() {
		return function_exists( 'digitizer_elementor_build_tree' );
	}

	public function init() {
		if ( self::legacy_plugin_active() ) {
			return;
		}

		// Everything here answers REST requests and nothing else, so nothing
		// is registered until a REST request is being served.
		add_action( 'rest_api_init', array( __CLASS__, 'boot' ) );
	}

	/**
	 * Register the whole surface. Public so a test can call it without a
	 * REST request.
	 */
	public static function boot() {
		DPT_RB_Fields::register();
		DPT_RB_Elementor::register();
		DPT_RB_Rankmath::register();
		DPT_RB_Info::register();
	}

	/**
	 * @return string
	 */
	public function standing_down_reason() {
		return self::legacy_plugin_active()
			? __( 'The Digitizer API Extensions plugin is active, so this module is standing down - the two would register the same fields and the same routes. Deactivate that plugin to let this module take over.', 'digitizer-pro-tools' )
			: '';
	}
}
```

Add to the registry in `includes/class-dpt-plugin.php`, immediately after the
`update_policy` entry:

```php
			'rest_bridge' => array(
				'file'    => DPT_PATH . 'modules/rest-bridge/class-dpt-rb-module.php',
				'class'   => 'DPT_Rest_Bridge_Module',
				'default' => '0',
			),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/rest-bridge-test.php` → Expected: PASS.
Run: `for f in tests/*-test.php; do php "$f" || exit 1; done` → all files pass.
Run: `php -l includes/class-dpt-plugin.php` → no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add modules/rest-bridge/class-dpt-rb-module.php includes/class-dpt-plugin.php tests/rest-bridge-test.php
git commit -m "REST Bridge: the module, registered and standing down for the plugin it replaces"
```

---

### Task 8: Version, catalog, readme

**Files:**
- Modify: `digitizer-pro-tools.php` (header `Version:` and `DPT_VERSION`)
- Modify: `readme.txt` (Stable tag, changelog, module list)
- Modify: `languages/digitizer-pro-tools.pot`, `languages/digitizer-pro-tools-he_IL.po`, `languages/digitizer-pro-tools-he_IL.l10n.php`, `languages/digitizer-pro-tools-he_IL.mo`

**Interfaces:**
- Consumes: every `__()` string added in Tasks 2-7.
- Produces: a release-ready tree — the catalogs and the code agree, phpcs is clean.

- [ ] **Step 1: Find every new string**

Run this extractor (it is the same check the repo has used since the catalog
was cleaned; it prints the three key sets and their differences):

```bash
python3 - <<'EOF'
import re,glob
code=set()
pat=re.compile(r"""(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\(\s*'((?:[^'\\]|\\.)*)'\s*,""")
for f in glob.glob('modules/**/*.php',recursive=True)+glob.glob('includes/*.php')+['digitizer-pro-tools.php','uninstall.php']:
    for m in pat.finditer(open(f).read()):
        code.add(m.group(1).replace("\\'","'"))
def po(p):
    s=open(p).read()
    return set(m.group(1).replace('\\"','"') for m in re.finditer(r'^msgid "((?:[^"\\]|\\.)*)"$',s,re.M) if m.group(1))
pos=po('languages/digitizer-pro-tools-he_IL.po'); pot=po('languages/digitizer-pro-tools.pot')
print('code',len(code),'po',len(pos),'pot',len(pot))
print('missing from po:'); [print(' ',s) for s in sorted(code-pos)]
print('orphaned in po:'); [print(' ',s) for s in sorted(pos-code)]
print('po^pot:',sorted(pos^pot))
EOF
```

Expected before the catalog work: `missing from po` lists every new string
(the module title and description, the WP_Error messages, the Rank Math field
descriptions, the Elementor messages, the stand-down sentence); `orphaned in
po` is empty.

- [ ] **Step 2: Add each new string to the three catalogs**

For every string the extractor reported as missing, append to
`languages/digitizer-pro-tools.pot`:

```
msgid "<the exact English string>"
msgstr ""
```

and to `languages/digitizer-pro-tools-he_IL.po`:

```
msgid "<the exact English string>"
msgstr "<the Hebrew translation>"
```

and add `'<English>' => '<Hebrew>',` to the array in
`languages/digitizer-pro-tools-he_IL.l10n.php`.

Translations to use (these are the strings this plan introduces):

| English | Hebrew |
|---|---|
| `REST Bridge` | `גשר REST` |
| The module description (Task 7) | `חושף את השדות המותאמים של האתר ל-REST API, כך שאוטומציה יכולה לקרוא ולכתוב אותם כמו שהיא קוראת וכותבת כותרת של פוסט. השדות מתגלים מתוך ההגדרות ש-JetEngine כבר שומר, ולכן שדה שנוסף ב-JetEngine מופיע ב-API בלי שאיש כתב עבורו קוד - כולל ריפיטרים, עם תת-השדות שלהם. חושף גם את שדות ה-SEO של Rank Math, ונקודות קצה לקריאה ולעריכה של תוכן אלמנטור בלי לפגוע בעיצוב של העמוד.` |
| The stand-down sentence (Task 7) | `התוסף Digitizer API Extensions פעיל, ולכן המודול הזה נסוג - שניהם היו רושמים את אותם שדות ואת אותם מסלולים. יש לכבות את התוסף כדי שהמודול יוכל לקחת פיקוד.` |
| `The field %s must be an array of items.` | `השדה %s חייב להיות מערך של פריטים.` |
| `Item %2$d of the field %1$s must be an object.` | `פריט %2$d בשדה %1$s חייב להיות אובייקט.` |
| `The object to update could not be identified.` | `לא ניתן לזהות את האובייקט לעדכון.` |
| `The field %s could not be cleared.` | `לא ניתן היה לרוקן את השדה %s.` |
| `The field %s could not be saved.` | `לא ניתן היה לשמור את השדה %s.` |
| `A list of { widget_id, settings } objects.` | `רשימה של אובייקטי { widget_id, settings }.` |
| `Post not found.` | `הפוסט לא נמצא.` |
| `This post has no Elementor data.` | `לפוסט הזה אין נתוני אלמנטור.` |
| `The stored Elementor data is not valid JSON.` | `נתוני האלמנטור השמורים אינם JSON תקין.` |
| `Updates must be a non-empty array.` | `העדכונים חייבים להיות מערך לא ריק.` |
| `Each update must have a widget_id and a settings object.` | `כל עדכון חייב לכלול widget_id ואובייקט settings.` |
| `SEO title override` | `כותרת SEO חלופית` |
| `SEO meta description` | `תיאור מטא ל-SEO` |
| `Focus keyword(s)` | `מילות מפתח מרכזיות` |
| `Robot meta directives` | `הנחיות מטא לרובוטים` |
| `Canonical URL override` | `כתובת קנונית חלופית` |
| `Primary category ID` | `מזהה קטגוריה ראשית` |
| `SEO score (0-100)` | `ציון SEO (0-100)` |
| `Open Graph title` | `כותרת Open Graph` |
| `Open Graph description` | `תיאור Open Graph` |
| `Open Graph image URL` | `כתובת תמונת Open Graph` |
| `Twitter card title` | `כותרת כרטיס טוויטר` |
| `Twitter card description` | `תיאור כרטיס טוויטר` |

- [ ] **Step 3: Rebuild the binary catalog and check parity**

```bash
msgfmt -o languages/digitizer-pro-tools-he_IL.mo languages/digitizer-pro-tools-he_IL.po
msgfmt --statistics languages/digitizer-pro-tools-he_IL.po
php -l languages/digitizer-pro-tools-he_IL.l10n.php
```

Then re-run the extractor from Step 1. Expected: `missing from po` and
`orphaned in po` both empty, `po^pot` empty, and the three counts equal.

- [ ] **Step 4: Bump the version and write the readme**

In `digitizer-pro-tools.php`, set the header `Version:` and the
`DPT_VERSION` constant to `1.27.0`.

In `readme.txt`, set `Stable tag: 1.27.0`, and add at the top of the
changelog:

```
= 1.27.0 =
* New module: REST Bridge - puts this site's own custom fields on the REST API, discovered from the definitions JetEngine already stores, repeaters included. Also exposes Rank Math's SEO fields on posts and pages, and endpoints for reading and editing Elementor content.
* The module replaces the standalone Digitizer API Extensions plugin and stands down while that plugin is active.
```

Add a module section describing REST Bridge alongside the other modules'
sections, in the same style as the Update Policy section already there.

- [ ] **Step 5: Run everything**

```bash
for f in tests/*-test.php; do php "$f" || exit 1; done
~/.composer/vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.WP.I18n --extensions=php --report=summary ./modules ./includes ./digitizer-pro-tools.php ./uninstall.php
```

Expected: every test file passes; phpcs reports zero errors and zero warnings.

- [ ] **Step 6: Commit and open the pull request**

```bash
git add -A
git commit -m "REST Bridge: catalog, readme and version 1.27.0"
git push -u origin claude/rest-bridge-module
gh pr create --title "New module: REST Bridge - the site's own fields on the REST API (v1.27.0)" --body "..."
gh pr comment <PR> --body "@codex review"
```

Drive Codex to a clean verdict on the exact HEAD commit before merging, as
every other module PR in this repo has been.

---

## Self-Review

**Spec coverage.** Each spec section maps to a task: discovery → Task 2;
schema/sanitizers → Task 3; registration + compatibility → Task 4; Elementor →
Task 5; Rank Math + info → Task 6; module glue, stand-down, registry,
multisite (nothing to do) → Task 7; catalog/readme/version → Task 8. The
harness the spec's test plan assumes → Task 1.

**One deliberate addition beyond the spec.** The spec's compatibility layer
registers `jet_qna` as an alias of a discovered `qna`. Task 4 also handles the
case where nothing was discovered at all - a site with the module on and
JetEngine off, or a JetEngine whose definitions live in PHP - by registering
the old plugin's hard-coded `{question, answer}` repeater under `jet_qna`.
Without it, deactivating the old plugin on such a site would silently break
ContentEngine's required field, which is precisely what the compatibility
layer exists to prevent.

**Placeholders.** None: every step carries the code or the exact command it
needs, and the catalog task lists every string with its translation.

**Type consistency.** Descriptor keys (`meta_key`, `title`, `object`,
`targets`, `type`, `fields`) are identical in Tasks 2, 3, 4 and 7. Method
names used across tasks - `DPT_RB_Definitions::all()/skipped()/reset()`,
`DPT_RB_Schema::for_descriptor()/sanitize()/normalize_read()`,
`DPT_RB_Fields::register()/registered()/compat()/read()/write()`,
`DPT_RB_Elementor::register()/may_edit()/get_tree()/update()` and its
`NAMESPACE_V1` constant used by `DPT_RB_Info`, `DPT_RB_Rankmath::register()/active()`,
`DPT_RB_Info::register()/payload()` - are each defined in exactly one task and
referenced under the same name everywhere else.
