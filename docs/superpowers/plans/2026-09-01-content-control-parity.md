# Content Control Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring `modules/content-control` to feature parity with the free Content Control plugin (2.7.3), minus Gutenberg block controls: global restriction rules with a rule engine, redirect/replace protection, archive/query hiding, exclude-role matching, teaser excerpts, classic-widget visibility, richer shortcode.

**Architecture:** Restrictions are rows in one option (`dpt_cc_restrictions`), evaluated first-match by array order. A rule engine (`DPT_CC_Rules`) answers "does this restriction apply to this content?"; `DPT_CC_Access` (extended) answers "may this user see it?"; `DPT_CC_Enforce` wires both into `template_redirect`, `the_posts`, `get_terms`, the existing content filters, and REST. Existing per-post meta, menu visibility, and whole-site protection are untouched and take precedence.

**Tech Stack:** WordPress plugin PHP 7.4+, no build step. Tests: the repo's standalone stub harness (`php tests/<name>-test.php`, bootstrap in `tests/bootstrap.php` — no WordPress, each test stubs the WP functions it touches and requires real module files).

**Spec:** `docs/superpowers/specs/2026-09-01-content-control-parity-design.md`

## Global Constraints

- Text domain: `digitizer-pro-tools`. Class prefix `DPT_CC_`, hooks/filters prefix `dpt_cc_`.
- All new files start with the ABSPATH guard: `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Sanitize on save (allowlists, `sanitize_key`, `absint`, `wp_kses_post`), escape on output (`esc_html`, `esc_attr`, `esc_url`). Nonces + `manage_options` on every admin write.
- Per-post meta beats global restrictions; whole-site protection beats both. Administrators (`DPT_CC_Access::user_can_bypass()`) bypass everything.
- Fail closed: malformed rows sanitize to inert defaults; unknown rule names evaluate false.
- Repo test style: each test file is standalone PHP with `dpt_test_ok`/`dpt_test_eq` + `dpt_test_summary()`; exit code = failures. Run all: `for f in tests/*-test.php; do php "$f" || exit 1; done`.
- Version bump 1.33.0 → 1.34.0 in `digitizer-pro-tools.php` (`Version:` header) and `readme.txt` (`Stable tag:` + prose changelog entry in the repo's narrative style).
- Commits end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: role-match modes in DPT_CC_Access

**Files:**
- Modify: `modules/content-control/class-dpt-cc-access.php`
- Test: `tests/cc-access-test.php`

**Interfaces:**
- Produces: `DPT_CC_Access::can_view( $visibility, $roles = array(), $user = null, $role_match = 'match' )` — `$role_match ∈ {any, match, exclude}`. Existing callers pass 3 args and keep today's behavior (`match`).
- Produces: `DPT_CC_Access::who_allows( array $who, $user = null )` — `$who = ['status'=>'logged_in|logged_out','role_match'=>'any|match|exclude','roles'=>[]]`; returns bool. Used by Tasks 2/4/5.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-access-test.php
require_once __DIR__ . '/bootstrap.php';

// --- WP stubs the access class touches ---
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function apply_filters( $tag, $value ) { return $value; }
function get_post_meta( $id, $key, $single ) { return isset( $GLOBALS['dpt_stub_meta'][ $id ][ $key ] ) ? $GLOBALS['dpt_stub_meta'][ $id ][ $key ] : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function __( $s, $d = null ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function wpautop( $s ) { return $s; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';

$editor = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
$subscriber = (object) array( 'ID' => 6, 'roles' => array( 'subscriber' ) );
$anon = (object) array( 'ID' => 0, 'roles' => array() );

/* role_match = match (default, unchanged behavior) */
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $editor ), 'match: editor sees editor-gated' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber ), 'match: subscriber does not' );

/* role_match = exclude - everyone logged in EXCEPT the listed roles */
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $editor, 'exclude' ), 'exclude: listed role is refused' );
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber, 'exclude' ), 'exclude: unlisted role passes' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $anon, 'exclude' ), 'exclude: still requires login' );

/* role_match = any - login is enough, roles ignored */
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber, 'any' ), 'any: any logged-in user passes' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $anon, 'any' ), 'any: anonymous refused' );

/* who_allows() wrapper */
dpt_test_ok( DPT_CC_Access::who_allows( array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ), $subscriber ), 'who_allows logged_in/any' );
dpt_test_ok( DPT_CC_Access::who_allows( array( 'status' => 'logged_out', 'role_match' => 'any', 'roles' => array() ), $anon ), 'who_allows logged_out for anon' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array( 'status' => 'logged_out', 'role_match' => 'any', 'roles' => array() ), $editor ), 'who_allows logged_out refuses logged-in' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array( 'status' => 'logged_in', 'role_match' => 'exclude', 'roles' => array( 'editor' ) ), $editor ), 'who_allows exclude refuses listed role' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array(), $editor ), 'who_allows empty who fails closed' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cc-access-test.php`
Expected: FAIL lines for exclude/any/who_allows (can_view ignores 4th arg; who_allows undefined → fatal). A fatal also counts as the failing state.

- [ ] **Step 3: Implement**

In `class-dpt-cc-access.php`, replace the `can_view` signature/switch and add `who_allows`:

```php
	public static function sanitize_role_match( $m ) {
		return in_array( $m, array( 'any', 'match', 'exclude' ), true ) ? $m : 'match';
	}

	public static function can_view( $visibility, $roles = array(), $user = null, $role_match = 'match' ) {
		$user       = $user ? $user : wp_get_current_user();
		$visibility = self::sanitize_visibility( $visibility );
		$role_match = self::sanitize_role_match( $role_match );

		if ( self::user_can_bypass( $user ) ) {
			return true;
		}

		$logged_in = $user && ! empty( $user->ID );

		switch ( $visibility ) {
			case 'logged_out':
				$allowed = ! $logged_in;
				break;
			case 'logged_in':
				$allowed = $logged_in;
				break;
			case 'roles':
				if ( ! $logged_in ) {
					$allowed = false;
				} elseif ( 'any' === $role_match || empty( $roles ) ) {
					$allowed = true;
				} elseif ( 'exclude' === $role_match ) {
					$allowed = ! self::user_has_any_role( $user, $roles );
				} else {
					$allowed = self::user_has_any_role( $user, $roles );
				}
				break;
			case 'public':
			default:
				$allowed = true;
				break;
		}
		return (bool) apply_filters( 'dpt_cc_can_view', $allowed, $visibility, $roles, $user, $role_match );
	}

	/**
	 * Restriction-style audience check: status + role_match + roles.
	 * Empty/malformed input fails closed.
	 */
	public static function who_allows( $who, $user = null ) {
		if ( ! is_array( $who ) || empty( $who['status'] ) || ! in_array( $who['status'], array( 'logged_in', 'logged_out' ), true ) ) {
			return false;
		}
		$roles = isset( $who['roles'] ) && is_array( $who['roles'] ) ? $who['roles'] : array();
		$match = isset( $who['role_match'] ) ? self::sanitize_role_match( $who['role_match'] ) : 'any';
		if ( 'logged_out' === $who['status'] ) {
			return self::can_view( 'logged_out', array(), $user );
		}
		return self::can_view( 'roles', $roles, $user, empty( $roles ) ? 'any' : $match );
	}
```

Note: `'roles'` with empty roles + `any` behaves as plain logged-in — same as the plugin. Existing 3-arg callers (`match` default, and `empty($roles)` now allows instead of refuses) — **behavior nuance:** old code refused `roles` mode with empty roles list; keep that for the metabox path by having `who_allows` be the only caller relying on the empty-roles-passes branch? No — simpler and honest: keep old semantics for `match`: `elseif ( 'any' === $role_match )` allows; for `match` with empty roles keep `user_has_any_role` (false). So change the `'any' === $role_match || empty( $roles )` condition to just `'any' === $role_match`, and in `who_allows` map empty roles to `'any'` (already done above). Implement it that way.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cc-access-test.php`
Expected: PASS, 0 failed.

- [ ] **Step 5: Regression: run the whole suite**

Run: `for f in tests/*-test.php; do php "$f" || exit 1; done`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add modules/content-control/class-dpt-cc-access.php tests/cc-access-test.php
git commit -m "Content Control: role-match modes (any/match/exclude) and who_allows()

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: restrictions store

**Files:**
- Create: `modules/content-control/class-dpt-cc-restrictions.php`
- Test: `tests/cc-restrictions-test.php`

**Interfaces:**
- Consumes: `DPT_CC_Access::who_allows()` (Task 1) — not called here, but rows carry the `who` array it reads.
- Produces:
  - `DPT_CC_Restrictions::OPTION = 'dpt_cc_restrictions'`
  - `DPT_CC_Restrictions::defaults_row()` : array (full schema from the spec)
  - `DPT_CC_Restrictions::sanitize_row( $raw )` : array — always-complete row; generates `id` when missing
  - `DPT_CC_Restrictions::all()` : array of sanitized rows (option order = priority)
  - `DPT_CC_Restrictions::enabled()` : only rows with `enabled`
  - `DPT_CC_Restrictions::save_all( array $rows )` : sanitizes each row, `update_option`
  - `DPT_CC_Restrictions::match( array $context )` : first enabled row whose `conditions` match (via `DPT_CC_Rules::check`) — memoized per request by context cache key; returns row array or `null`
  - `DPT_CC_Restrictions::cache_key( array $context )` : `'post-{id}'` / `'term-{id}'` / `'main-' . md5( serialize( $context ) )`
  - `DPT_CC_Restrictions::flush_cache()` (tests / after save)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-restrictions-test.php
require_once __DIR__ . '/bootstrap.php';

function get_option( $k, $d = false ) { return isset( $GLOBALS['dpt_stub_options'][ $k ] ) ? $GLOBALS['dpt_stub_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['dpt_stub_options'][ $k ] = $v; return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_kses_post( $s ) { return (string) $s; }
function esc_url_raw( $u ) { return (string) $u; }
function apply_filters( $tag, $value ) { return $value; }
function wp_unslash( $v ) { return $v; }

// Rule engine substitute: the store only needs check(); real engine tested in Task 3.
class DPT_CC_Rules {
	public static $answers = array();
	public static function check( $conditions, $context ) {
		$id = isset( $context['probe'] ) ? $context['probe'] : '';
		return ! empty( self::$answers[ $id ] );
	}
}

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions.php';

/* sanitize_row: garbage in, complete inert row out */
$row = DPT_CC_Restrictions::sanitize_row( array( 'title' => ' A ', 'protection' => array( 'method' => 'evil' ), 'archive_handling' => 'nope' ) );
dpt_test_eq( $row['title'], 'A', 'title trimmed' );
dpt_test_eq( $row['protection']['method'], 'redirect', 'bad method falls to redirect' );
dpt_test_eq( $row['archive_handling'], 'filter', 'bad archive handling falls to filter' );
dpt_test_ok( '' !== $row['id'], 'id generated' );
dpt_test_eq( $row['conditions'], array( 'operator' => 'and', 'items' => array() ), 'conditions default empty-and' );
dpt_test_eq( $row['who'], array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ), 'who defaults' );

/* save_all + all round trip */
$GLOBALS['dpt_stub_options'] = array();
DPT_CC_Restrictions::save_all( array(
	array( 'id' => 'r_one', 'title' => 'One', 'enabled' => true ),
	array( 'id' => 'r_two', 'title' => 'Two', 'enabled' => false ),
) );
$all = DPT_CC_Restrictions::all();
dpt_test_eq( count( $all ), 2, 'two rows stored' );
dpt_test_eq( wp_list_pluck_ids( $all ), array( 'r_one', 'r_two' ), 'order preserved' );
dpt_test_eq( count( DPT_CC_Restrictions::enabled() ), 1, 'enabled() filters disabled rows' );
function wp_list_pluck_ids( $rows ) { $o = array(); foreach ( $rows as $r ) { $o[] = $r['id']; } return $o; }

/* match(): first enabled match wins, disabled skipped, memoized */
DPT_CC_Restrictions::save_all( array(
	array( 'id' => 'r_off', 'title' => 'Off', 'enabled' => false, 'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'x' ) ) ) ),
	array( 'id' => 'r_a', 'title' => 'A', 'enabled' => true, 'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'x' ) ) ) ),
	array( 'id' => 'r_b', 'title' => 'B', 'enabled' => true, 'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'x' ) ) ) ),
) );
DPT_CC_Rules::$answers = array( 'p1' => true );
DPT_CC_Restrictions::flush_cache();
$m = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) );
dpt_test_eq( $m['id'], 'r_a', 'first enabled match wins (disabled r_off skipped)' );
DPT_CC_Rules::$answers = array(); // engine now says no...
$m2 = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) );
dpt_test_eq( $m2['id'], 'r_a', '...but memoized answer returned for same context' );
DPT_CC_Restrictions::flush_cache();
dpt_test_eq( DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) ), null, 'after flush, no match' );

/* rows with empty conditions never match (plugin parity: no rules => not restricted) */
DPT_CC_Restrictions::save_all( array( array( 'id' => 'r_e', 'enabled' => true ) ) );
DPT_CC_Restrictions::flush_cache();
DPT_CC_Rules::$answers = array( 'p2' => true );
dpt_test_eq( DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 2, 'probe' => 'p2' ) ), null, 'empty conditions never match' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cc-restrictions-test.php`
Expected: fatal — file not found.

- [ ] **Step 3: Implement `class-dpt-cc-restrictions.php`**

```php
<?php
/**
 * Content Control module - global restriction rows: storage, sanitization
 * and first-match resolution. Order in the option is priority.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Restrictions {

	const OPTION = 'dpt_cc_restrictions';

	/** @var array<string, array|null> per-request match memo */
	private static $match_cache = array();

	public static function defaults_row() {
		return array(
			'id'      => '',
			'title'   => '',
			'enabled' => true,
			'who'     => array(
				'status'     => 'logged_in',
				'role_match' => 'any',
				'roles'      => array(),
			),
			'protection' => array(
				'method'           => 'redirect', // redirect | replace
				'redirect_type'    => 'login',    // login | home | custom
				'redirect_url'     => '',
				'replacement_page' => 0,
				'override_message' => false,
				'custom_message'   => '',
				'show_excerpts'    => false,
			),
			'archive_handling'      => 'filter',  // filter | hide | replace_page | redirect
			'archive_page'          => 0,
			'archive_redirect_type' => 'login',
			'archive_redirect_url'  => '',
			'query_handling'        => 'filter',  // filter | hide
			'show_in_search'        => false,
			'conditions'            => array( 'operator' => 'and', 'items' => array() ),
		);
	}

	private static function pick( $raw, $key, $allowed, $fallback ) {
		return ( isset( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ) ? $raw[ $key ] : $fallback;
	}

	public static function sanitize_row( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$d   = self::defaults_row();
		$row = $d;

		$row['id']      = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		if ( '' === $row['id'] ) {
			$row['id'] = 'r_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		$row['title']   = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		$row['enabled'] = ! empty( $raw['enabled'] );

		$who = isset( $raw['who'] ) && is_array( $raw['who'] ) ? $raw['who'] : array();
		$row['who']['status']     = self::pick( $who, 'status', array( 'logged_in', 'logged_out' ), 'logged_in' );
		$row['who']['role_match'] = self::pick( $who, 'role_match', array( 'any', 'match', 'exclude' ), 'any' );
		$row['who']['roles']      = array();
		if ( isset( $who['roles'] ) && is_array( $who['roles'] ) ) {
			$row['who']['roles'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $who['roles'] ) ) ) );
		}

		$p = isset( $raw['protection'] ) && is_array( $raw['protection'] ) ? $raw['protection'] : array();
		$row['protection']['method']           = self::pick( $p, 'method', array( 'redirect', 'replace' ), 'redirect' );
		$row['protection']['redirect_type']    = self::pick( $p, 'redirect_type', array( 'login', 'home', 'custom' ), 'login' );
		$row['protection']['redirect_url']     = isset( $p['redirect_url'] ) ? esc_url_raw( (string) $p['redirect_url'] ) : '';
		$row['protection']['replacement_page'] = isset( $p['replacement_page'] ) ? absint( $p['replacement_page'] ) : 0;
		$row['protection']['override_message'] = ! empty( $p['override_message'] );
		$row['protection']['custom_message']   = isset( $p['custom_message'] ) ? wp_kses_post( (string) $p['custom_message'] ) : '';
		$row['protection']['show_excerpts']    = ! empty( $p['show_excerpts'] );

		$row['archive_handling']      = self::pick( $raw, 'archive_handling', array( 'filter', 'hide', 'replace_page', 'redirect' ), 'filter' );
		$row['archive_page']          = isset( $raw['archive_page'] ) ? absint( $raw['archive_page'] ) : 0;
		$row['archive_redirect_type'] = self::pick( $raw, 'archive_redirect_type', array( 'login', 'home', 'custom' ), 'login' );
		$row['archive_redirect_url']  = isset( $raw['archive_redirect_url'] ) ? esc_url_raw( (string) $raw['archive_redirect_url'] ) : '';
		$row['query_handling']        = self::pick( $raw, 'query_handling', array( 'filter', 'hide' ), 'filter' );
		$row['show_in_search']        = ! empty( $raw['show_in_search'] );

		$row['conditions'] = self::sanitize_conditions( isset( $raw['conditions'] ) ? $raw['conditions'] : array() );
		return $row;
	}

	public static function sanitize_conditions( $raw ) {
		$out = array( 'operator' => 'and', 'items' => array() );
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		if ( isset( $raw['operator'] ) && in_array( $raw['operator'], array( 'and', 'or' ), true ) ) {
			$out['operator'] = $raw['operator'];
		}
		$items = isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['type'] ) && 'group' === $item['type'] ) {
				$group = array( 'type' => 'group', 'operator' => 'or', 'items' => array() );
				if ( isset( $item['operator'] ) && in_array( $item['operator'], array( 'and', 'or' ), true ) ) {
					$group['operator'] = $item['operator'];
				}
				$inner = isset( $item['items'] ) && is_array( $item['items'] ) ? $item['items'] : array();
				foreach ( $inner as $rule ) {
					$rule = self::sanitize_rule( $rule );
					if ( $rule ) {
						$group['items'][] = $rule;
					}
				}
				if ( $group['items'] ) {
					$out['items'][] = $group;
				}
				continue;
			}
			$rule = self::sanitize_rule( $item );
			if ( $rule ) {
				$out['items'][] = $rule;
			}
		}
		return $out;
	}

	private static function sanitize_rule( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['name'] ) ) {
			return null;
		}
		$options = array();
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			foreach ( $raw['options'] as $k => $v ) {
				$options[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
			}
		}
		return array(
			'type'    => 'rule',
			'name'    => sanitize_key( $raw['name'] ),
			'not'     => ! empty( $raw['not'] ),
			'options' => $options,
		);
	}

	public static function all() {
		$rows = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::sanitize_row( $row );
		}
		return $out;
	}

	public static function enabled() {
		return array_values( array_filter( self::all(), static function ( $r ) {
			return ! empty( $r['enabled'] );
		} ) );
	}

	public static function get( $id ) {
		foreach ( self::all() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	public static function save_all( $rows ) {
		$clean = array();
		$seen  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = self::sanitize_row( $row );
			if ( isset( $seen[ $row['id'] ] ) ) {
				continue;
			}
			$seen[ $row['id'] ] = true;
			$clean[]            = $row;
		}
		update_option( self::OPTION, $clean );
		self::flush_cache();
		return $clean;
	}

	public static function cache_key( $context ) {
		if ( isset( $context['type'] ) && 'post' === $context['type'] && ! empty( $context['post_id'] ) ) {
			return 'post-' . (int) $context['post_id'];
		}
		if ( isset( $context['type'] ) && 'term' === $context['type'] && ! empty( $context['term_id'] ) ) {
			return 'term-' . (int) $context['term_id'];
		}
		return 'ctx-' . md5( serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- internal cache key only.
	}

	/**
	 * First enabled restriction whose conditions match this context, or null.
	 * A row with no conditions never matches (parity with the plugin).
	 *
	 * @param array $context See DPT_CC_Rules::check().
	 * @return array|null
	 */
	public static function match( $context ) {
		$key = self::cache_key( $context );
		if ( array_key_exists( $key, self::$match_cache ) ) {
			return self::$match_cache[ $key ];
		}
		$found = null;
		foreach ( self::enabled() as $row ) {
			if ( empty( $row['conditions']['items'] ) ) {
				continue;
			}
			if ( DPT_CC_Rules::check( $row['conditions'], $context ) ) {
				$found = $row;
				break;
			}
		}
		self::$match_cache[ $key ] = $found;
		return $found;
	}

	public static function flush_cache() {
		self::$match_cache = array();
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cc-restrictions-test.php` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/content-control/class-dpt-cc-restrictions.php tests/cc-restrictions-test.php
git commit -m "Content Control: global restriction rows - store, sanitize, first-match

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: rule engine

**Files:**
- Create: `modules/content-control/class-dpt-cc-rules.php`
- Test: `tests/cc-rules-test.php`

**Interfaces:**
- Consumes: condition/rule arrays as sanitized by Task 2.
- Produces:
  - `DPT_CC_Rules::check( array $conditions, array $context )` : bool
  - `DPT_CC_Rules::definitions()` : `array<name, array{label:string, category:string, option:string(''|'ids'|'template'), callback:callable}>` — built lazily, filtered by `dpt_cc_rules`.
  - Context shape consumed by callbacks: `array( 'type' => 'main'|'post'|'term'|'rest', 'post' => object|null (post_type, ID, post_parent, page_template?), 'term' => object|null (term_id, taxonomy, parent), 'main' => array of conditional-tag booleans when type=main: is_front_page, is_home, is_search, is_404, is_post_type_archive => array of types, is_tax => array of taxonomies )`
  - Rule option `ids`: comma-separated ID list in `options['ids']`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-rules-test.php
require_once __DIR__ . '/bootstrap.php';

function apply_filters( $tag, $value ) { return $value; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_post_types( $a = array(), $o = 'names' ) { return array( 'post' => 'post', 'page' => 'page' ); }
function get_taxonomies( $a = array(), $o = 'names' ) { return array( 'category' => 'category' ); }
function is_post_type_hierarchical( $t ) { return 'page' === $t; }
function is_taxonomy_hierarchical( $t ) { return 'category' === $t; }
function get_post_type_object( $t ) { return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( $t ) ), 'has_archive' => ( 'post' === $t ) ); }
function get_taxonomy( $t ) { return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( $t ) ) ); }
function get_post_ancestors( $post ) { return isset( $GLOBALS['dpt_stub_ancestors'][ $post->ID ] ) ? $GLOBALS['dpt_stub_ancestors'][ $post->ID ] : array(); }
function get_ancestors( $id, $tax ) { return isset( $GLOBALS['dpt_stub_term_ancestors'][ $id ] ) ? $GLOBALS['dpt_stub_term_ancestors'][ $id ] : array(); }
function has_term( $ids, $tax, $post ) { return ! empty( array_intersect( (array) $ids, isset( $GLOBALS['dpt_stub_post_terms'][ $post->ID ][ $tax ] ) ? $GLOBALS['dpt_stub_post_terms'][ $post->ID ][ $tax ] : array() ) ); }
function get_page_template_slug( $post ) { return isset( $post->page_template ) ? $post->page_template : ''; }
function __( $s, $d = null ) { return $s; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-rules.php';

$page = (object) array( 'ID' => 10, 'post_type' => 'page', 'post_parent' => 3 );
$post = (object) array( 'ID' => 20, 'post_type' => 'post', 'post_parent' => 0 );
$ctx_page = array( 'type' => 'post', 'post' => $page, 'term' => null );
$ctx_post = array( 'type' => 'post', 'post' => $post, 'term' => null );

function rule( $name, $opts = array(), $not = false ) {
	return array( 'type' => 'rule', 'name' => $name, 'not' => $not, 'options' => $opts );
}
function conds( $op, ...$items ) { return array( 'operator' => $op, 'items' => $items ); }

/* single rules */
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'entire_site' ) ), $ctx_post ), 'entire_site matches anything' );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_page' ) ), $ctx_page ), 'content_is_page on page' );
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'content_is_page' ) ), $ctx_post ), 'content_is_page not on post' );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_selected_page', array( 'ids' => '9, 10' ) ) ), $ctx_page ), 'selected IDs' );
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'content_is_selected_page', array( 'ids' => '9,11' ) ) ), $ctx_page ), 'selected IDs miss' );

/* NOT */
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_page', array(), true ) ), $ctx_post ), 'NOT inverts' );

/* AND / OR */
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'content_is_page' ), rule( 'content_is_selected_page', array( 'ids' => '11' ) ) ), $ctx_page ), 'AND: one false => false' );
dpt_test_ok( DPT_CC_Rules::check( conds( 'or', rule( 'content_is_post' ), rule( 'content_is_page' ) ), $ctx_page ), 'OR: one true => true' );

/* group (one level) */
$group = array( 'type' => 'group', 'operator' => 'or', 'items' => array( rule( 'content_is_post' ), rule( 'content_is_page' ) ) );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'entire_site' ), $group ), $ctx_page ), 'AND(entire_site, OR-group)' );

/* hierarchy */
$GLOBALS['dpt_stub_ancestors'] = array( 10 => array( 3, 2 ) );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_child_of_page', array( 'ids' => '3' ) ) ), $ctx_page ), 'child_of via ancestors' );
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'content_is_child_of_page', array( 'ids' => '7' ) ) ), $ctx_page ), 'child_of miss' );

/* taxonomy on posts */
$GLOBALS['dpt_stub_post_terms'] = array( 20 => array( 'category' => array( 5 ) ) );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_post_with_category', array( 'ids' => '5' ) ) ), $ctx_post ), 'post with term' );

/* main-query rules */
$ctx_main = array( 'type' => 'main', 'post' => null, 'term' => null, 'main' => array( 'is_front_page' => false, 'is_home' => true, 'is_search' => false, 'is_404' => false, 'is_post_type_archive' => array(), 'is_tax' => array() ) );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_blog_index' ) ), $ctx_main ), 'blog index' );
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'content_is_search_results' ) ), $ctx_main ), 'not search' );
$ctx_arch = $ctx_main; $ctx_arch['main']['is_post_type_archive'] = array( 'post' ); $ctx_arch['main']['is_home'] = false;
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_post_archive' ) ), $ctx_arch ), 'post type archive' );

/* term context */
$term = (object) array( 'term_id' => 5, 'taxonomy' => 'category', 'parent' => 4 );
$ctx_term = array( 'type' => 'term', 'post' => null, 'term' => $term );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_selected_tax_category', array( 'ids' => '5' ) ) ), $ctx_term ), 'selected term' );
$GLOBALS['dpt_stub_term_ancestors'] = array( 5 => array( 4 ) );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_child_of_tax_category', array( 'ids' => '4' ) ) ), $ctx_term ), 'child of term' );

/* fail closed */
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'and', rule( 'no_such_rule' ) ), $ctx_page ), 'unknown rule is false' );
dpt_test_ok( ! DPT_CC_Rules::check( conds( 'or' ), $ctx_page ), 'empty conditions do not match' );

/* page template */
$tpl = (object) array( 'ID' => 30, 'post_type' => 'page', 'post_parent' => 0, 'page_template' => 'tpl-landing.php' );
dpt_test_ok( DPT_CC_Rules::check( conds( 'and', rule( 'content_is_page_with_template', array( 'template' => 'tpl-landing.php' ) ) ), array( 'type' => 'post', 'post' => $tpl, 'term' => null ) ), 'page template match' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cc-rules-test.php` — Expected: fatal, file missing.

- [ ] **Step 3: Implement `class-dpt-cc-rules.php`**

```php
<?php
/**
 * Content Control module - rule engine. A restriction's conditions are a
 * query {operator, items[]} of rules and one level of groups. check()
 * answers: does this restriction apply to the given content context?
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Rules {

	/** @var array<string,array>|null */
	private static $definitions = null;

	public static function check( $conditions, $context ) {
		if ( ! is_array( $conditions ) || empty( $conditions['items'] ) ) {
			return false;
		}
		$op = ( isset( $conditions['operator'] ) && 'or' === $conditions['operator'] ) ? 'or' : 'and';
		foreach ( $conditions['items'] as $item ) {
			$result = ( isset( $item['type'] ) && 'group' === $item['type'] )
				? self::check_group( $item, $context )
				: self::check_rule( $item, $context );
			if ( 'or' === $op && $result ) {
				return true;
			}
			if ( 'and' === $op && ! $result ) {
				return false;
			}
		}
		return 'and' === $op;
	}

	private static function check_group( $group, $context ) {
		if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
			return false;
		}
		$op = ( isset( $group['operator'] ) && 'and' === $group['operator'] ) ? 'and' : 'or';
		foreach ( $group['items'] as $rule ) {
			$result = self::check_rule( $rule, $context );
			if ( 'or' === $op && $result ) {
				return true;
			}
			if ( 'and' === $op && ! $result ) {
				return false;
			}
		}
		return 'and' === $op;
	}

	private static function check_rule( $rule, $context ) {
		$defs = self::definitions();
		$name = isset( $rule['name'] ) ? $rule['name'] : '';
		if ( ! isset( $defs[ $name ] ) || ! is_callable( $defs[ $name ]['callback'] ) ) {
			return false; // Unknown rule fails closed; NOT does not rescue it.
		}
		$options = isset( $rule['options'] ) && is_array( $rule['options'] ) ? $rule['options'] : array();
		$result  = (bool) call_user_func( $defs[ $name ]['callback'], $options, $context );
		return ! empty( $rule['not'] ) ? ! $result : $result;
	}

	public static function id_list( $options ) {
		$raw = isset( $options['ids'] ) ? (string) $options['ids'] : '';
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) ) );
	}

	public static function definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}
		$defs = array();

		$defs['entire_site'] = array(
			'label'    => __( 'Entire site', 'digitizer-pro-tools' ),
			'category' => __( 'General', 'digitizer-pro-tools' ),
			'option'   => '',
			'callback' => static function () { return true; },
		);
		$defs['content_is_front_page'] = array(
			'label'    => __( 'The front page', 'digitizer-pro-tools' ),
			'category' => __( 'General', 'digitizer-pro-tools' ),
			'option'   => '',
			'callback' => static function ( $o, $ctx ) { return ! empty( $ctx['main']['is_front_page'] ); },
		);
		$defs['content_is_blog_index'] = array(
			'label'    => __( 'The blog index', 'digitizer-pro-tools' ),
			'category' => __( 'General', 'digitizer-pro-tools' ),
			'option'   => '',
			'callback' => static function ( $o, $ctx ) { return ! empty( $ctx['main']['is_home'] ); },
		);
		$defs['content_is_search_results'] = array(
			'label'    => __( 'Search results', 'digitizer-pro-tools' ),
			'category' => __( 'General', 'digitizer-pro-tools' ),
			'option'   => '',
			'callback' => static function ( $o, $ctx ) { return ! empty( $ctx['main']['is_search'] ); },
		);
		$defs['content_is_404_page'] = array(
			'label'    => __( 'The 404 page', 'digitizer-pro-tools' ),
			'category' => __( 'General', 'digitizer-pro-tools' ),
			'option'   => '',
			'callback' => static function ( $o, $ctx ) { return ! empty( $ctx['main']['is_404'] ); },
		);

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			$obj   = get_post_type_object( $pt );
			$label = $obj && isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $pt;
			$cat   = $label;

			$defs[ "content_is_{$pt}" ] = array(
				'label'    => sprintf( /* translators: %s: post type */ __( 'A %s', 'digitizer-pro-tools' ), $label ),
				'category' => $cat,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) use ( $pt ) {
					return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt;
				},
			);
			if ( 'post' === $pt || ( $obj && ! empty( $obj->has_archive ) ) ) {
				$defs[ "content_is_{$pt}_archive" ] = array(
					'label'    => sprintf( __( '%s archive', 'digitizer-pro-tools' ), $label ),
					'category' => $cat,
					'option'   => '',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( 'post' === $pt && ! empty( $ctx['main']['is_home'] ) ) {
							return true;
						}
						return isset( $ctx['main']['is_post_type_archive'] ) && in_array( $pt, (array) $ctx['main']['is_post_type_archive'], true );
					},
				);
			}
			$defs[ "content_is_selected_{$pt}" ] = array(
				'label'    => sprintf( __( 'Selected %s (IDs)', 'digitizer-pro-tools' ), $label ),
				'category' => $cat,
				'option'   => 'ids',
				'callback' => static function ( $o, $ctx ) use ( $pt ) {
					return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt
						&& in_array( (int) $ctx['post']->ID, DPT_CC_Rules::id_list( $o ), true );
				},
			);
			if ( is_post_type_hierarchical( $pt ) ) {
				$defs[ "content_is_child_of_{$pt}" ] = array(
					'label'    => sprintf( __( 'Child of %s (IDs)', 'digitizer-pro-tools' ), $label ),
					'category' => $cat,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( empty( $ctx['post'] ) || $ctx['post']->post_type !== $pt ) {
							return false;
						}
						$wanted = DPT_CC_Rules::id_list( $o );
						return (bool) array_intersect( $wanted, array_map( 'intval', get_post_ancestors( $ctx['post'] ) ) );
					},
				);
				$defs[ "content_is_ancestor_of_{$pt}" ] = array(
					'label'    => sprintf( __( 'Ancestor of %s (IDs)', 'digitizer-pro-tools' ), $label ),
					'category' => $cat,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( empty( $ctx['post'] ) || $ctx['post']->post_type !== $pt ) {
							return false;
						}
						foreach ( DPT_CC_Rules::id_list( $o ) as $child_id ) {
							$child = (object) array( 'ID' => $child_id );
							if ( in_array( (int) $ctx['post']->ID, array_map( 'intval', get_post_ancestors( $child ) ), true ) ) {
								return true;
							}
						}
						return false;
					},
				);
			}
			if ( 'page' === $pt ) {
				$defs['content_is_page_with_template'] = array(
					'label'    => __( 'Page with template (file name)', 'digitizer-pro-tools' ),
					'category' => $cat,
					'option'   => 'template',
					'callback' => static function ( $o, $ctx ) {
						if ( empty( $ctx['post'] ) || 'page' !== $ctx['post']->post_type ) {
							return false;
						}
						$want = isset( $o['template'] ) ? (string) $o['template'] : '';
						$slug = get_page_template_slug( $ctx['post'] );
						if ( 'default' === $want ) {
							return '' === $slug;
						}
						return '' !== $want && $slug === $want;
					},
				);
			}

			foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
				$defs[ "content_is_{$pt}_with_{$tax}" ] = array(
					'label'    => sprintf( __( '%1$s with %2$s (term IDs)', 'digitizer-pro-tools' ), $label, $tax ),
					'category' => $cat,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt, $tax ) {
						return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt
							&& has_term( DPT_CC_Rules::id_list( $o ), $tax, $ctx['post'] );
					},
				);
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$tobj   = get_taxonomy( $tax );
			$tlabel = $tobj && isset( $tobj->labels->singular_name ) ? $tobj->labels->singular_name : $tax;
			$tcat   = sprintf( __( 'Taxonomy: %s', 'digitizer-pro-tools' ), $tlabel );

			$defs[ "content_is_{$tax}_archive" ] = array(
				'label'    => sprintf( __( 'Any %s archive', 'digitizer-pro-tools' ), $tlabel ),
				'category' => $tcat,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) use ( $tax ) {
					if ( ! empty( $ctx['term'] ) && $ctx['term']->taxonomy === $tax ) {
						return true;
					}
					return isset( $ctx['main']['is_tax'] ) && in_array( $tax, (array) $ctx['main']['is_tax'], true );
				},
			);
			$defs[ "content_is_selected_tax_{$tax}" ] = array(
				'label'    => sprintf( __( 'Selected %s (term IDs)', 'digitizer-pro-tools' ), $tlabel ),
				'category' => $tcat,
				'option'   => 'ids',
				'callback' => static function ( $o, $ctx ) use ( $tax ) {
					return ! empty( $ctx['term'] ) && $ctx['term']->taxonomy === $tax
						&& in_array( (int) $ctx['term']->term_id, DPT_CC_Rules::id_list( $o ), true );
				},
			);
			if ( is_taxonomy_hierarchical( $tax ) ) {
				$defs[ "content_is_child_of_tax_{$tax}" ] = array(
					'label'    => sprintf( __( 'Child of %s (term IDs)', 'digitizer-pro-tools' ), $tlabel ),
					'category' => $tcat,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $tax ) {
						if ( empty( $ctx['term'] ) || $ctx['term']->taxonomy !== $tax ) {
							return false;
						}
						$wanted = DPT_CC_Rules::id_list( $o );
						return (bool) array_intersect( $wanted, array_map( 'intval', get_ancestors( (int) $ctx['term']->term_id, $tax ) ) );
					},
				);
			}
		}

		self::$definitions = apply_filters( 'dpt_cc_rules', $defs );
		return self::$definitions;
	}

	/** For tests / after registering new types. */
	public static function flush_definitions() {
		self::$definitions = null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cc-rules-test.php` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/content-control/class-dpt-cc-rules.php tests/cc-rules-test.php
git commit -m "Content Control: rule engine - AND/OR, one-level groups, NOT, built-in content rules

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: enforcement

**Files:**
- Create: `modules/content-control/class-dpt-cc-enforce.php`
- Modify: `modules/content-control/class-dpt-cc-module.php` (wire hooks; extend `should_hide()` path and message source)
- Test: `tests/cc-enforce-test.php`

**Interfaces:**
- Consumes: `DPT_CC_Restrictions::match()`, `DPT_CC_Access::who_allows()`, `DPT_CC_Rules` context shape.
- Produces:
  - `( new DPT_CC_Enforce() )->init()` — adds hooks: `template_redirect` @5, `init` @999 → `the_posts` @10 + `get_terms` @10, `rest_pre_dispatch` @1.
  - `DPT_CC_Enforce::restriction_for_post( $post )` : row|null — post context match where the **user fails** `who_allows` (returns null when user passes or exempt). Used by module content filter + REST prepare.
  - `DPT_CC_Enforce::filter_posts( $posts, $query )` and `DPT_CC_Enforce::filter_terms( $terms, $taxonomies, $args, $query )` — pure enough to unit test.
  - `DPT_CC_Enforce::handling_for( $row, $is_main, $is_search )` : `'filter'|'hide'` — search + !show_in_search forces `hide`; main uses `archive_handling` (mapped: `replace_page`/`redirect` handled at template_redirect, so here they degrade to `filter`), secondary uses `query_handling`.
  - `DPT_CC_Enforce::denial_message( $row )` : string — row custom message when `override_message`, else module default chain.
  - `DPT_CC_Enforce::exempt_ids()` : replacement/archive page IDs of all enabled rows (never restricted themselves).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-enforce-test.php
require_once __DIR__ . '/bootstrap.php';

function apply_filters( $tag, $value ) { return $value; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['dpt_stub_options'][ $k ] ) ? $GLOBALS['dpt_stub_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['dpt_stub_options'][ $k ] = $v; return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_kses_post( $s ) { return (string) $s; }
function esc_url_raw( $u ) { return (string) $u; }
function wp_unslash( $v ) { return $v; }
function __( $s, $d = null ) { return $s; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function get_post_meta( $id, $key, $single ) { return ''; }
function wpautop( $s ) { return $s; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions.php';

// Rules stub: match by post type recorded in rule name suffix.
class DPT_CC_Rules {
	public static function check( $conditions, $context ) {
		$name = $conditions['items'][0]['name'];
		if ( 'match_page' === $name ) { return ! empty( $context['post'] ) && 'page' === $context['post']->post_type; }
		if ( 'match_term' === $name ) { return ! empty( $context['term'] ); }
		return false;
	}
}

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-enforce.php';

// Minimal WP_Query stand-in.
class WP_Query {
	public $posts = array(); public $post_count = 0; public $qv = array();
	public function __construct( $qv = array() ) { $this->qv = $qv; }
	public function is_main_query() { return ! empty( $this->qv['main'] ); }
	public function is_search() { return ! empty( $this->qv['s'] ); }
	public function get( $k, $d = '' ) { return isset( $this->qv[ $k ] ) ? $this->qv[ $k ] : $d; }
}
function is_post_type_viewable( $t ) { return true; }

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() ); // anonymous
$GLOBALS['dpt_stub_options'] = array();

DPT_CC_Restrictions::save_all( array( array(
	'id' => 'r_pages', 'title' => 'Members pages', 'enabled' => true,
	'who' => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'query_handling' => 'hide', 'archive_handling' => 'filter', 'show_in_search' => false,
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_page', 'not' => false, 'options' => array() ) ) ),
) ) );

$enf = new DPT_CC_Enforce();
$page = (object) array( 'ID' => 1, 'post_type' => 'page' );
$post = (object) array( 'ID' => 2, 'post_type' => 'post' );

/* restriction_for_post: anonymous fails logged_in => row returned */
DPT_CC_Restrictions::flush_cache();
$row = $enf->restriction_for_post( $page );
dpt_test_eq( $row ? $row['id'] : null, 'r_pages', 'restricted page yields its row for anon' );
dpt_test_eq( $enf->restriction_for_post( $post ), null, 'unmatched post yields null' );

/* logged-in user passes => null */
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 9, 'roles' => array( 'subscriber' ) );
DPT_CC_Restrictions::flush_cache();
dpt_test_eq( $enf->restriction_for_post( $page ), null, 'allowed user yields null' );
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
DPT_CC_Restrictions::flush_cache();

/* handling_for */
$r = DPT_CC_Restrictions::get( 'r_pages' );
dpt_test_eq( $enf->handling_for( $r, false, false ), 'hide', 'secondary query uses query_handling' );
dpt_test_eq( $enf->handling_for( $r, true, false ), 'filter', 'main archive uses archive_handling (filter)' );
dpt_test_eq( $enf->handling_for( $r, true, true ), 'hide', 'search forces hide when show_in_search off' );

/* filter_posts hides on secondary query */
$q = new WP_Query( array() );
$q->posts = array( $page, $post ); $q->post_count = 2;
$out = $enf->filter_posts( $q->posts, $q );
dpt_test_eq( count( $out ), 1, 'restricted page hidden from secondary query' );
dpt_test_eq( $out[0]->ID, 2, 'the post survives' );
dpt_test_eq( $q->post_count, 1, 'post_count fixed' );

/* filter_posts leaves main archive alone when handling=filter */
DPT_CC_Restrictions::flush_cache();
$qm = new WP_Query( array( 'main' => 1 ) );
$qm->posts = array( $page, $post ); $qm->post_count = 2;
dpt_test_eq( count( $enf->filter_posts( $qm->posts, $qm ) ), 2, 'main query filter handling leaves posts for content filter' );

/* ignore_restrictions escape hatch */
DPT_CC_Restrictions::flush_cache();
$qi = new WP_Query( array( 'ignore_restrictions' => true ) );
$qi->posts = array( $page ); $qi->post_count = 1;
dpt_test_eq( count( $enf->filter_posts( $qi->posts, $qi ) ), 1, 'ignore_restrictions bypasses' );

/* terms */
DPT_CC_Restrictions::save_all( array( array(
	'id' => 'r_terms', 'title' => 'Terms', 'enabled' => true,
	'who' => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'query_handling' => 'hide',
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_term', 'not' => false, 'options' => array() ) ) ),
) ) );
DPT_CC_Restrictions::flush_cache();
$term = (object) array( 'term_id' => 5, 'taxonomy' => 'category', 'parent' => 0 );
$terms_out = $enf->filter_terms( array( $term, 'not-an-object' ), array( 'category' ), array(), null );
dpt_test_eq( array_values( array_filter( $terms_out, 'is_object' ) ), array(), 'restricted term hidden; non-objects passed through untouched check' );
dpt_test_ok( in_array( 'not-an-object', $terms_out, true ), 'non-object entries survive untouched' );

/* denial message + exemption */
DPT_CC_Restrictions::save_all( array( array(
	'id' => 'r_msg', 'enabled' => true,
	'protection' => array( 'method' => 'replace', 'replacement_page' => 77, 'override_message' => true, 'custom_message' => 'Members only.' ),
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_page', 'not' => false, 'options' => array() ) ) ),
) ) );
DPT_CC_Restrictions::flush_cache();
$rm = DPT_CC_Restrictions::get( 'r_msg' );
dpt_test_eq( $enf->denial_message( $rm ), 'Members only.', 'override message used' );
dpt_test_ok( in_array( 77, $enf->exempt_ids(), true ), 'replacement page is exempt' );
$page77 = (object) array( 'ID' => 77, 'post_type' => 'page' );
dpt_test_eq( $enf->restriction_for_post( $page77 ), null, 'replacement page never restricted (no loop)' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cc-enforce-test.php` — Expected: fatal, file missing.

- [ ] **Step 3: Implement `class-dpt-cc-enforce.php`**

```php
<?php
/**
 * Content Control module - enforcement of global restrictions: main-query
 * redirect/replace, hiding in post/term queries, content filtering hand-off
 * and REST refusal. Per-post meta and whole-site protection run elsewhere
 * and take precedence.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Enforce {

	public function init() {
		// After whole-site protection (@1), before the theme renders.
		add_action( 'template_redirect', array( $this, 'enforce_main_query' ), 5 );
		// Register query filters late so ordinary setup queries never pay for them.
		add_action( 'init', array( $this, 'register_query_filters' ), 999 );
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_rest' ), 10, 3 );
	}

	public function register_query_filters() {
		add_filter( 'the_posts', array( $this, 'filter_posts' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'filter_terms' ), 10, 4 );
	}

	/* ------------------------------------------------------------------ */
	/* Decision helpers                                                    */
	/* ------------------------------------------------------------------ */

	private function enforcement_off() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( DPT_CC_Access::user_can_bypass() ) {
			return true;
		}
		return (bool) apply_filters( 'dpt_cc_enforcement_off', false );
	}

	/**
	 * Pages that must stay reachable: every enabled row's replacement and
	 * archive pages. Restricting the page you land on when refused loops.
	 */
	public function exempt_ids() {
		$ids = array();
		foreach ( DPT_CC_Restrictions::enabled() as $row ) {
			if ( $row['protection']['replacement_page'] ) {
				$ids[] = (int) $row['protection']['replacement_page'];
			}
			if ( $row['archive_page'] ) {
				$ids[] = (int) $row['archive_page'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The restriction that BARS the current user from this post, or null.
	 */
	public function restriction_for_post( $post ) {
		if ( ! $post || empty( $post->ID ) || $this->enforcement_off() ) {
			return null;
		}
		if ( in_array( (int) $post->ID, $this->exempt_ids(), true ) ) {
			return null;
		}
		$row = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => (int) $post->ID, 'post' => $post, 'term' => null ) );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return null;
		}
		return $row;
	}

	public function restriction_for_term( $term ) {
		if ( ! $term || empty( $term->term_id ) || $this->enforcement_off() ) {
			return null;
		}
		$row = DPT_CC_Restrictions::match( array( 'type' => 'term', 'term_id' => (int) $term->term_id, 'post' => null, 'term' => $term ) );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * filter|hide for a matched row inside a post query.
	 */
	public function handling_for( $row, $is_main, $is_search ) {
		if ( $is_search && empty( $row['show_in_search'] ) ) {
			return 'hide';
		}
		if ( $is_main ) {
			return 'hide' === $row['archive_handling'] ? 'hide' : 'filter';
		}
		return 'hide' === $row['query_handling'] ? 'hide' : 'filter';
	}

	public function denial_message( $row ) {
		if ( ! empty( $row['protection']['override_message'] ) && '' !== trim( $row['protection']['custom_message'] ) ) {
			return $row['protection']['custom_message'];
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Main query                                                          */
	/* ------------------------------------------------------------------ */

	public function enforce_main_query() {
		if ( $this->enforcement_off() ) {
			return;
		}
		global $wp_query;

		$context = array(
			'type' => 'main',
			'post' => is_singular() ? get_queried_object() : null,
			'term' => ( is_category() || is_tag() || is_tax() ) ? get_queried_object() : null,
			'main' => array(
				'is_front_page'        => is_front_page(),
				'is_home'              => is_home(),
				'is_search'            => is_search(),
				'is_404'               => is_404(),
				'is_post_type_archive' => $this->queried_archive_types(),
				'is_tax'               => $this->queried_taxonomies(),
			),
		);
		// Singular views reuse the post cache key so the content filter agrees.
		if ( ! empty( $context['post'] ) && ! empty( $context['post']->ID ) ) {
			if ( in_array( (int) $context['post']->ID, $this->exempt_ids(), true ) ) {
				return;
			}
			$context['type']    = 'post';
			$context['post_id'] = (int) $context['post']->ID;
		}

		$row = DPT_CC_Restrictions::match( $context );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return;
		}

		if ( 'redirect' === $row['protection']['method'] ) {
			$this->do_redirect( $row['protection']['redirect_type'], $row['protection']['redirect_url'] );
		}
		if ( 'replace' === $row['protection']['method'] && $row['protection']['replacement_page'] ) {
			$this->replace_with_page( (int) $row['protection']['replacement_page'] );
			return;
		}
		// replace + message: the content filter shows the denial message.

		// Archive-level handling for restricted posts inside the archive.
		if ( ! is_singular() ) {
			$this->enforce_archive_posts( $wp_query );
		}
	}

	private function queried_archive_types() {
		if ( ! is_post_type_archive() ) {
			return array();
		}
		global $wp_query;
		$types = (array) $wp_query->get( 'post_type' );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	private function queried_taxonomies() {
		if ( is_category() ) {
			return array( 'category' );
		}
		if ( is_tag() ) {
			return array( 'post_tag' );
		}
		if ( is_tax() ) {
			$obj = get_queried_object();
			return $obj && ! empty( $obj->taxonomy ) ? array( $obj->taxonomy ) : array();
		}
		return array();
	}

	private function enforce_archive_posts( $query ) {
		if ( empty( $query->posts ) ) {
			return;
		}
		foreach ( $query->posts as $post ) {
			$row = $this->restriction_for_post( $post );
			if ( ! $row ) {
				continue;
			}
			if ( 'redirect' === $row['archive_handling'] ) {
				$this->do_redirect( $row['archive_redirect_type'], $row['archive_redirect_url'] );
			}
			if ( 'replace_page' === $row['archive_handling'] && $row['archive_page'] ) {
				$this->replace_with_page( (int) $row['archive_page'] );
				return;
			}
		}
	}

	private function do_redirect( $type, $url ) {
		if ( 'home' === $type ) {
			$to = home_url( '/' );
		} elseif ( 'custom' === $type && '' !== $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				add_filter( 'allowed_redirect_hosts', static function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return $hosts;
				} );
			}
			$to = $url;
		} else {
			$to = wp_login_url( home_url( add_query_arg( array(), isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL re-assembled for redirect target only.
		}
		wp_safe_redirect( $to );
		exit;
	}

	private function replace_with_page( $page_id ) {
		global $wp_query;
		$page = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return;
		}
		$wp_query->init();
		$wp_query->query( array( 'page_id' => $page_id, 'ignore_restrictions' => true ) );
		status_header( 200 );
	}

	/* ------------------------------------------------------------------ */
	/* Post / term queries                                                 */
	/* ------------------------------------------------------------------ */

	private function query_ignored( $query ) {
		if ( $query && $query->get( 'ignore_restrictions' ) ) {
			return true;
		}
		return (bool) apply_filters( 'dpt_cc_query_ignored', false, $query );
	}

	public function filter_posts( $posts, $query = null ) {
		if ( empty( $posts ) || $this->enforcement_off() || $this->query_ignored( $query ) ) {
			return $posts;
		}
		$is_main   = $query && method_exists( $query, 'is_main_query' ) && $query->is_main_query();
		$is_search = $query && method_exists( $query, 'is_search' ) && $query->is_search();
		$changed   = false;
		foreach ( $posts as $i => $post ) {
			if ( ! is_object( $post ) || empty( $post->ID ) ) {
				continue;
			}
			$row = $this->restriction_for_post( $post );
			if ( ! $row ) {
				continue;
			}
			if ( 'hide' === $this->handling_for( $row, $is_main, $is_search ) ) {
				unset( $posts[ $i ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$posts = array_values( $posts );
			if ( $query && property_exists( $query, 'post_count' ) ) {
				$query->post_count = count( $posts );
			}
		}
		return $posts;
	}

	public function filter_terms( $terms, $taxonomies = array(), $args = array(), $query = null ) {
		if ( empty( $terms ) || ! is_array( $terms ) || $this->enforcement_off() ) {
			return $terms;
		}
		$changed = false;
		foreach ( $terms as $i => $term ) {
			if ( ! is_object( $term ) || empty( $term->term_id ) ) {
				continue; // get_terms may return ids/slugs/counts - only objects are checked.
			}
			$row = $this->restriction_for_term( $term );
			if ( $row && 'hide' === $row['query_handling'] ) {
				unset( $terms[ $i ] );
				$changed = true;
			}
		}
		return $changed ? array_values( $terms ) : $terms;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public function enforce_rest( $result, $server, $request ) {
		if ( null !== $result || DPT_CC_Access::user_can_bypass() ) {
			return $result;
		}
		$route = $request->get_route();
		if ( ! preg_match( '#^/wp/v2/([^/]+)(?:/(\d+))?#', $route, $m ) ) {
			return $result;
		}
		$post_id = isset( $m[2] ) ? (int) $m[2] : 0;
		if ( ! $post_id ) {
			return $result; // Collections stay filtered per item by filter_rest_prepare.
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $result;
		}
		$row = $this->restriction_for_post( $post );
		if ( $row && 'redirect' === $row['protection']['method'] ) {
			return new WP_Error(
				'dpt_cc_forbidden',
				__( 'You do not have permission to view this content.', 'digitizer-pro-tools' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return $result; // replace/message rows are blanked by filter_rest_prepare instead.
	}
}
```

- [ ] **Step 4: Wire into the module**

In `class-dpt-cc-module.php`:
- `require_once` the three new files (restrictions, rules, enforce) next to the existing requires.
- In `init()`: `$this->enforce = new DPT_CC_Enforce(); $this->enforce->init();`
- Extend `should_hide( $post_id )` flow — per-post meta stays first; add a module method:

```php
	private function global_restriction_barring( $post_id ) {
		if ( ! $this->enforce || ! $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		return $post ? $this->enforce->restriction_for_post( $post ) : null;
	}
```

and in `filter_the_content` / `filter_the_excerpt` / `filter_get_the_excerpt` / `filter_feed_content` / `filter_rest_prepare`: when `should_hide()` is false, also check `global_restriction_barring()`; when a row bars, output `DPT_CC_Access::restriction_message( 0 )` unless `denial_message( $row )` is non-empty (then wrap that in the same `<div class="dpt-cc-restricted">`, `wp_kses_post` + `wpautop`), and when `$row['protection']['show_excerpts']` prepend

```php
	$teaser = get_post_field( 'post_excerpt', $post_id );
	if ( '' !== trim( (string) $teaser ) ) {
		$message_html = '<div class="dpt-cc-excerpt">' . wp_kses( wpautop( $teaser ), array( 'a' => array( 'href' => array() ), 'em' => array(), 'strong' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'blockquote' => array() ) ) . '</div>' . $message_html;
	}
```

Factor this into one private `restricted_output( $post_id, $strip = false )` used by all five filters so the logic is not repeated (returns plain text when `$strip`).

- [ ] **Step 5: Run test + suite**

Run: `php tests/cc-enforce-test.php` then `for f in tests/*-test.php; do php "$f" || exit 1; done`
Expected: PASS. (`tests/cc-enforce-test.php` does not load the module class; module wiring is exercised in Task 7's smoke checks and by existing content-filter behavior staying unchanged.)

- [ ] **Step 6: Commit**

```bash
git add modules/content-control/class-dpt-cc-enforce.php modules/content-control/class-dpt-cc-module.php tests/cc-enforce-test.php
git commit -m "Content Control: enforce global restrictions - redirect/replace, query hiding, REST

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: classic widget visibility

**Files:**
- Create: `modules/content-control/class-dpt-cc-widgets.php`
- Modify: `modules/content-control/class-dpt-cc-module.php` (require + instantiate in `init()`)
- Test: `tests/cc-widgets-test.php`

**Interfaces:**
- Consumes: `DPT_CC_Access::who_allows()`.
- Produces: `DPT_CC_Widgets` with `init()` adding `in_widget_form` @5, `widget_update_callback` @5, `sidebars_widgets` @10. Instance keys stored in the widget's own settings: `dpt_cc_status` (`''|logged_in|logged_out`), `dpt_cc_roles` (array). `should_show( array $instance )` : bool (public, unit-tested).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-widgets-test.php
require_once __DIR__ . '/bootstrap.php';

function apply_filters( $tag, $value ) { return $value; }
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_post_meta( $i, $k, $s ) { return ''; }
function __( $s, $d = null ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function wpautop( $s ) { return $s; }
function is_admin() { return false; }
function is_customize_preview() { return false; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-widgets.php';

$w = new DPT_CC_Widgets();
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );

dpt_test_ok( $w->should_show( array() ), 'no settings => visible' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => '' ) ), 'empty status => visible' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_in' ) ), 'logged_in hides from anon' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_out' ) ), 'logged_out shows to anon' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 3, 'roles' => array( 'subscriber' ) );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_in' ) ), 'logged_in shows to user' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_out' ) ), 'logged_out hides from user' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'editor' ) ) ), 'role gate refuses other role' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'subscriber' ) ) ), 'role gate admits listed role' );

/* sanitize on update */
$inst = $w->sanitize_update( array( 'dpt_cc_status' => 'evil', 'dpt_cc_roles' => array( 'editor', 'x!!' ) ) );
dpt_test_eq( $inst['dpt_cc_status'], '', 'bad status dropped' );
dpt_test_eq( $inst['dpt_cc_roles'], array(), 'roles dropped unless status logged_in' );
$inst2 = $w->sanitize_update( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'editor', 'x!!' ) ) );
dpt_test_eq( $inst2['dpt_cc_roles'], array( 'editor', 'x' ), 'roles sanitized when kept' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails** — `php tests/cc-widgets-test.php`, fatal expected.

- [ ] **Step 3: Implement `class-dpt-cc-widgets.php`**

```php
<?php
/**
 * Content Control module - classic widget visibility. Two fields on every
 * widget form; failing widgets are removed from sidebars_widgets so they
 * never render (and their markup never reaches the page).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Widgets {

	public function init() {
		add_action( 'in_widget_form', array( $this, 'render_fields' ), 5, 3 );
		add_filter( 'widget_update_callback', array( $this, 'save_fields' ), 5, 2 );
		add_filter( 'sidebars_widgets', array( $this, 'filter_sidebars' ), 10 );
	}

	public function should_show( $instance ) {
		$status = isset( $instance['dpt_cc_status'] ) ? $instance['dpt_cc_status'] : '';
		if ( '' === $status || ! in_array( $status, array( 'logged_in', 'logged_out' ), true ) ) {
			return true;
		}
		$roles = isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ? $instance['dpt_cc_roles'] : array();
		return DPT_CC_Access::who_allows( array(
			'status'     => $status,
			'role_match' => empty( $roles ) ? 'any' : 'match',
			'roles'      => $roles,
		) );
	}

	public function sanitize_update( $instance ) {
		$status = isset( $instance['dpt_cc_status'] ) && in_array( $instance['dpt_cc_status'], array( '', 'logged_in', 'logged_out' ), true )
			? $instance['dpt_cc_status'] : '';
		$roles = array();
		if ( 'logged_in' === $status && isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ) {
			$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $instance['dpt_cc_roles'] ) ) ) );
		}
		$instance['dpt_cc_status'] = $status;
		$instance['dpt_cc_roles']  = $roles;
		return $instance;
	}

	public function save_fields( $instance, $new_instance ) {
		$instance['dpt_cc_status'] = isset( $new_instance['dpt_cc_status'] ) ? $new_instance['dpt_cc_status'] : '';
		$instance['dpt_cc_roles']  = isset( $new_instance['dpt_cc_roles'] ) ? (array) $new_instance['dpt_cc_roles'] : array();
		return $this->sanitize_update( $instance );
	}

	public function render_fields( $widget, $return, $instance ) {
		$status = isset( $instance['dpt_cc_status'] ) ? $instance['dpt_cc_status'] : '';
		$roles  = isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ? $instance['dpt_cc_roles'] : array();
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		?>
		<p>
			<label for="<?php echo esc_attr( $widget->get_field_id( 'dpt_cc_status' ) ); ?>"><?php esc_html_e( 'Content Control: who sees this widget?', 'digitizer-pro-tools' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $widget->get_field_id( 'dpt_cc_status' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'dpt_cc_status' ) ); ?>">
				<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Everyone', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_in" <?php selected( $status, 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_out" <?php selected( $status, 'logged_out' ); ?>><?php esc_html_e( 'Logged-out visitors', 'digitizer-pro-tools' ); ?></option>
			</select>
		</p>
		<p>
			<?php esc_html_e( 'Roles (with "Logged-in users"; empty = any role):', 'digitizer-pro-tools' ); ?><br>
			<?php foreach ( get_editable_roles() as $key => $role ) : ?>
				<label style="display:inline-block;margin-right:8px;">
					<input type="checkbox" name="<?php echo esc_attr( $widget->get_field_name( 'dpt_cc_roles' ) ); ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $roles, true ) ); ?> />
					<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<?php
	}

	public function filter_sidebars( $sidebars ) {
		if ( is_admin() || is_customize_preview() || ! is_array( $sidebars ) ) {
			return $sidebars;
		}
		global $wp_registered_widgets;
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $i => $widget_id ) {
				$instance = $this->instance_for( $widget_id );
				if ( null !== $instance && ! $this->should_show( $instance ) ) {
					unset( $sidebars[ $sidebar_id ][ $i ] );
				}
			}
			$sidebars[ $sidebar_id ] = array_values( $sidebars[ $sidebar_id ] );
		}
		return $sidebars;
	}

	private function instance_for( $widget_id ) {
		// "{basename}-{index}" -> option widget_{basename}[index]
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			return null;
		}
		$all = get_option( 'widget_' . $m[1], array() );
		$idx = (int) $m[2];
		return ( is_array( $all ) && isset( $all[ $idx ] ) && is_array( $all[ $idx ] ) ) ? $all[ $idx ] : null;
	}
}
```

- [ ] **Step 4: Wire + run** — require + `( new DPT_CC_Widgets() )->init();` in module `init()`. Run `php tests/cc-widgets-test.php` and the suite. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/content-control/class-dpt-cc-widgets.php modules/content-control/class-dpt-cc-module.php tests/cc-widgets-test.php
git commit -m "Content Control: classic widget visibility

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: shortcode upgrades

**Files:**
- Modify: `modules/content-control/class-dpt-cc-module.php` (`shortcode_restrict`)
- Test: `tests/cc-shortcode-test.php`

**Interfaces:**
- Produces: `[dpt_restrict]` attributes: existing `role`, `show`, `message` + new `excluded_roles` (comma list → role_match exclude; wins over `role`), `inline` (`"1"|"true"` → `<span>` wrapper), `class` (extra classes on the wrapper).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/cc-shortcode-test.php
require_once __DIR__ . '/bootstrap.php';

function apply_filters( $tag, $value ) { return $value; }
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $u, $c ) { return in_array( 'administrator', (array) $u->roles, true ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_post_meta( $i, $k, $s ) { return ''; }
function __( $s, $d = null ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function wpautop( $s ) { return $s; }
function do_shortcode( $c ) { return $c; }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ) + $defaults; }
function esc_attr( $s ) { return $s; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $c ); }

require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';
// The shortcode method lives on the module class; requiring the module file
// pulls its siblings, all already stubbed above.
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-settings.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-metabox.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-menu.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-admin.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-rules.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-enforce.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-widgets.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-module.php';

// Stubs the requires above need at load/run time.
function get_option( $k, $d = false ) { return isset( $GLOBALS['dpt_stub_options'][ $k ] ) ? $GLOBALS['dpt_stub_options'][ $k ] : $d; }
function add_option( $k, $v ) { $GLOBALS['dpt_stub_options'][ $k ] = $v; return true; }
function update_option( $k, $v ) { $GLOBALS['dpt_stub_options'][ $k ] = $v; return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_unslash( $v ) { return $v; }

$m = new DPT_Content_Control_Module();
$editor = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
$GLOBALS['dpt_stub_user'] = $editor;

/* excluded_roles refuses the listed role */
$out = $m->shortcode_restrict( array( 'excluded_roles' => 'editor', 'message' => 'no' ), 'secret' );
dpt_test_ok( false === strpos( $out, 'secret' ), 'excluded editor cannot see content' );
dpt_test_ok( false !== strpos( $out, 'no' ), 'denial message shown' );

/* excluded_roles admits others */
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 6, 'roles' => array( 'subscriber' ) );
dpt_test_eq( $m->shortcode_restrict( array( 'excluded_roles' => 'editor' ), 'secret' ), 'secret', 'unlisted role sees content' );

/* inline wrapper */
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
$out = $m->shortcode_restrict( array( 'show' => 'logged_in', 'message' => 'members', 'inline' => 'true', 'class' => 'promo x' ), 'secret' );
dpt_test_ok( 0 === strpos( $out, '<span' ), 'inline renders a span' );
dpt_test_ok( false !== strpos( $out, 'promo' ), 'custom class carried' );

/* default wrapper still a div */
$out = $m->shortcode_restrict( array( 'show' => 'logged_in', 'message' => 'members' ), 'secret' );
dpt_test_ok( 0 === strpos( $out, '<div' ), 'default wrapper div' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run to verify it fails** — `php tests/cc-shortcode-test.php`.

- [ ] **Step 3: Implement** — replace `shortcode_restrict()` in the module:

```php
	public function shortcode_restrict( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'role'           => '',
				'excluded_roles' => '',
				'show'           => 'logged_in', // logged_in | logged_out | roles
				'message'        => '',
				'inline'         => '',
				'class'          => '',
			),
			$atts,
			'dpt_restrict'
		);

		$split = static function ( $csv ) {
			return array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $csv ) ) ) );
		};
		$roles    = $split( $atts['role'] );
		$excluded = $split( $atts['excluded_roles'] );

		if ( $excluded ) {
			$allowed = DPT_CC_Access::can_view( 'roles', $excluded, null, 'exclude' );
		} elseif ( $roles ) {
			$allowed = DPT_CC_Access::can_view( 'roles', $roles );
		} else {
			$allowed = DPT_CC_Access::can_view( DPT_CC_Access::sanitize_visibility( $atts['show'] ) );
		}

		if ( $allowed ) {
			return do_shortcode( $content );
		}

		$message = (string) $atts['message'];
		if ( '' === trim( $message ) ) {
			return '';
		}
		$tag     = in_array( strtolower( (string) $atts['inline'] ), array( '1', 'true', 'yes' ), true ) ? 'span' : 'div';
		$classes = array( 'dpt-cc-restricted' );
		foreach ( preg_split( '/\s+/', (string) $atts['class'] ) as $c ) {
			$c = sanitize_html_class( $c );
			if ( '' !== $c ) {
				$classes[] = $c;
			}
		}
		return '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . wp_kses_post( $message ) . '</' . $tag . '>';
	}
```

- [ ] **Step 4: Run test + suite** — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add modules/content-control/class-dpt-cc-module.php tests/cc-shortcode-test.php
git commit -m "Content Control: shortcode gains excluded_roles, inline and class

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: restrictions admin UI

**Files:**
- Create: `modules/content-control/class-dpt-cc-restrictions-admin.php`
- Modify: `modules/content-control/class-dpt-cc-admin.php` (tab nav on the settings page), `modules/content-control/class-dpt-cc-module.php` (instantiate)
- Test: manual smoke + the store's sanitize tests already cover the data path.

**Interfaces:**
- Consumes: `DPT_CC_Restrictions` (save_all/all/get), `DPT_CC_Rules::definitions()` (rule dropdown: name ⇒ label, grouped by category, `option` tells whether to render an ids/template text input).
- Produces: `DPT_CC_Restrictions_Admin` with `init()`; `admin_post` actions `dpt_cc_restriction_save`, `dpt_cc_restriction_delete`, `dpt_cc_restriction_toggle`, `dpt_cc_restriction_move` (up/down), all nonce-checked (`dpt_cc_restrictions`), `manage_options`. Page = existing `dpt-content-control` page with `&tab=restrictions` (default tab `settings` renders today's form; the admin class gains a two-tab header).

- [ ] **Step 1: Tab header** — in `DPT_CC_Admin::render_page()` add after the `<h1>`:

```php
		$tab = isset( $_GET['tab'] ) && 'restrictions' === $_GET['tab'] ? 'restrictions' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display routing only.
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab ' . ( 'settings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'Site protection & defaults', 'digitizer-pro-tools' ) . '</a>';
		echo '<a class="nav-tab ' . ( 'restrictions' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=restrictions' ) . '">' . esc_html__( 'Restrictions', 'digitizer-pro-tools' ) . '</a>';
		echo '</h2>';
		if ( 'restrictions' === $tab ) {
			DPT_CC_Restrictions_Admin::render();
			echo '</div>';
			return;
		}
```

(the existing form continues below for the `settings` tab; keep the closing `</div>` balanced.)

- [ ] **Step 2: Implement `class-dpt-cc-restrictions-admin.php`**

Full file — list + editor + handlers. Editor conditions builder: repeatable rows, each row = `[NOT checkbox] [rule select] [value input]`, plus a root operator radio (`and`/`or`); one "OR group" textarea-free simplification: a group is added as a second fieldset of rule rows with its own operator. Rows are posted as parallel arrays and reassembled.

```php
<?php
/**
 * Content Control module - admin for global restrictions: list, editor,
 * reorder, toggle, delete. Plain PHP forms; conditions are repeatable
 * rule rows plus an optional one-level group.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Restrictions_Admin {

	const NONCE = 'dpt_cc_restrictions';

	public function init() {
		add_action( 'admin_post_dpt_cc_restriction_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dpt_cc_restriction_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_dpt_cc_restriction_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_dpt_cc_restriction_move', array( $this, 'handle_move' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( self::NONCE );
	}

	private static function back( $args = array() ) {
		$url = admin_url( 'admin.php?page=' . DPT_CC_Admin::PAGE_SLUG . '&tab=restrictions' );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	public function handle_save() {
		self::guard();
		$raw = isset( $_POST['restriction'] ) && is_array( $_POST['restriction'] ) ? wp_unslash( $_POST['restriction'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_row() allowlists every field.
		$raw['conditions'] = self::conditions_from_post();
		$rows = DPT_CC_Restrictions::all();
		$id   = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		$done = false;
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] === $id && '' !== $id ) {
				$raw['id']  = $id;
				$rows[ $i ] = $raw;
				$done       = true;
				break;
			}
		}
		if ( ! $done ) {
			unset( $raw['id'] );
			$rows[] = $raw;
		}
		DPT_CC_Restrictions::save_all( $rows );
		if ( class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		self::back( array( 'dpt_saved' => 1 ) );
	}

	/**
	 * Rebuild the conditions array from parallel POST arrays:
	 * cond_rule[], cond_not[], cond_value[] (root) and gcond_* (the group),
	 * plus cond_op / gcond_op operators.
	 */
	private static function conditions_from_post() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- every element is sanitized in DPT_CC_Restrictions::sanitize_conditions().
		$read = static function ( $key ) {
			return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		};
		$op  = ( isset( $_POST['cond_op'] ) && 'or' === $_POST['cond_op'] ) ? 'or' : 'and';
		$gop = ( isset( $_POST['gcond_op'] ) && 'and' === $_POST['gcond_op'] ) ? 'and' : 'or';
		// phpcs:enable
		$items = array();
		$names  = $read( 'cond_rule' );
		$nots   = $read( 'cond_not' );
		$values = $read( 'cond_value' );
		foreach ( $names as $i => $name ) {
			if ( '' === $name ) {
				continue;
			}
			$items[] = array(
				'type'    => 'rule',
				'name'    => $name,
				'not'     => ! empty( $nots[ $i ] ),
				'options' => array( 'ids' => isset( $values[ $i ] ) ? $values[ $i ] : '', 'template' => isset( $values[ $i ] ) ? $values[ $i ] : '' ),
			);
		}
		$g_items = array();
		$gnames  = $read( 'gcond_rule' );
		$gnots   = $read( 'gcond_not' );
		$gvalues = $read( 'gcond_value' );
		foreach ( $gnames as $i => $name ) {
			if ( '' === $name ) {
				continue;
			}
			$g_items[] = array(
				'type'    => 'rule',
				'name'    => $name,
				'not'     => ! empty( $gnots[ $i ] ),
				'options' => array( 'ids' => isset( $gvalues[ $i ] ) ? $gvalues[ $i ] : '', 'template' => isset( $gvalues[ $i ] ) ? $gvalues[ $i ] : '' ),
			);
		}
		if ( $g_items ) {
			$items[] = array( 'type' => 'group', 'operator' => $gop, 'items' => $g_items );
		}
		return array( 'operator' => $op, 'items' => $items );
	}

	public function handle_delete() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$rows = array_values( array_filter( DPT_CC_Restrictions::all(), static function ( $r ) use ( $id ) {
			return $r['id'] !== $id;
		} ) );
		DPT_CC_Restrictions::save_all( $rows );
		self::back( array( 'dpt_saved' => 1 ) );
	}

	public function handle_toggle() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$rows = DPT_CC_Restrictions::all();
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] === $id ) {
				$rows[ $i ]['enabled'] = ! $row['enabled'];
			}
		}
		DPT_CC_Restrictions::save_all( $rows );
		self::back();
	}

	public function handle_move() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$dir  = isset( $_GET['dir'] ) && 'up' === $_GET['dir'] ? -1 : 1;
		$rows = DPT_CC_Restrictions::all();
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] !== $id ) {
				continue;
			}
			$j = $i + $dir;
			if ( $j >= 0 && $j < count( $rows ) ) {
				$tmp        = $rows[ $j ];
				$rows[ $j ] = $rows[ $i ];
				$rows[ $i ] = $tmp;
			}
			break;
		}
		DPT_CC_Restrictions::save_all( $rows );
		self::back();
	}

	/* ------------------------------------------------------------------ */
	/* Rendering (called from DPT_CC_Admin::render_page on the tab)        */
	/* ------------------------------------------------------------------ */

	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display routing only.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		if ( '' !== $edit_id || isset( $_GET['new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_editor( $edit_id ? DPT_CC_Restrictions::get( $edit_id ) : null );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$rows = DPT_CC_Restrictions::all();
		$base = admin_url( 'admin.php?page=' . DPT_CC_Admin::PAGE_SLUG . '&tab=restrictions' );
		$act  = static function ( $action, $id, $extra = array() ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ), self::NONCE );
		};
		echo '<p><a class="button button-primary" href="' . esc_url( $base . '&new=1' ) . '">' . esc_html__( 'Add restriction', 'digitizer-pro-tools' ) . '</a> ';
		echo esc_html__( 'Order is priority - the first matching restriction wins.', 'digitizer-pro-tools' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Priority', 'digitizer-pro-tools' ), __( 'Title', 'digitizer-pro-tools' ), __( 'Who may view', 'digitizer-pro-tools' ), __( 'Protection', 'digitizer-pro-tools' ), __( 'Enabled', 'digitizer-pro-tools' ), __( 'Actions', 'digitizer-pro-tools' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No restrictions yet.', 'digitizer-pro-tools' ) . '</td></tr>';
		}
		foreach ( $rows as $i => $row ) {
			$who = 'logged_out' === $row['who']['status'] ? __( 'Logged-out visitors', 'digitizer-pro-tools' ) : __( 'Logged-in users', 'digitizer-pro-tools' );
			if ( $row['who']['roles'] ) {
				$who .= ' (' . ( 'exclude' === $row['who']['role_match'] ? __( 'except: ', 'digitizer-pro-tools' ) : '' ) . esc_html( implode( ', ', $row['who']['roles'] ) ) . ')';
			}
			$prot = 'redirect' === $row['protection']['method'] ? __( 'Redirect', 'digitizer-pro-tools' ) : __( 'Replace content', 'digitizer-pro-tools' );
			echo '<tr>';
			echo '<td>' . (int) ( $i + 1 ) . ' <a href="' . esc_url( $act( 'dpt_cc_restriction_move', $row['id'], array( 'dir' => 'up' ) ) ) . '">↑</a> <a href="' . esc_url( $act( 'dpt_cc_restriction_move', $row['id'], array( 'dir' => 'down' ) ) ) . '">↓</a></td>';
			echo '<td><a href="' . esc_url( $base . '&edit=' . $row['id'] ) . '"><strong>' . esc_html( '' !== $row['title'] ? $row['title'] : $row['id'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( $who ) . '</td>';
			echo '<td>' . esc_html( $prot ) . '</td>';
			echo '<td>' . ( $row['enabled'] ? '✔' : '—' ) . ' <a href="' . esc_url( $act( 'dpt_cc_restriction_toggle', $row['id'] ) ) . '">' . esc_html( $row['enabled'] ? __( 'Disable', 'digitizer-pro-tools' ) : __( 'Enable', 'digitizer-pro-tools' ) ) . '</a></td>';
			echo '<td><a href="' . esc_url( $base . '&edit=' . $row['id'] ) . '">' . esc_html__( 'Edit', 'digitizer-pro-tools' ) . '</a> | <a href="' . esc_url( $act( 'dpt_cc_restriction_delete', $row['id'] ) ) . '" onclick="return confirm(' . esc_js( wp_json_encode( __( 'Delete this restriction?', 'digitizer-pro-tools' ) ) ) . ');">' . esc_html__( 'Delete', 'digitizer-pro-tools' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_editor( $row ) {
		$row   = $row ? $row : DPT_CC_Restrictions::sanitize_row( array( 'id' => 'x' ) ); // template defaults; id replaced on save for new rows
		$is_new = isset( $_GET['new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$defs  = DPT_CC_Rules::definitions();
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$rule_select = static function ( $field, $selected ) use ( $defs ) {
			$out = '<select name="' . esc_attr( $field ) . '[]"><option value="">' . esc_html__( '— rule —', 'digitizer-pro-tools' ) . '</option>';
			$by_cat = array();
			foreach ( $defs as $name => $def ) {
				$by_cat[ $def['category'] ][ $name ] = $def['label'];
			}
			foreach ( $by_cat as $cat => $rules ) {
				$out .= '<optgroup label="' . esc_attr( $cat ) . '">';
				foreach ( $rules as $name => $label ) {
					$out .= '<option value="' . esc_attr( $name ) . '" ' . selected( $selected, $name, false ) . '>' . esc_html( $label ) . '</option>';
				}
				$out .= '</optgroup>';
			}
			return $out . '</select>';
		};
		$rule_rows = static function ( $prefix, $items ) use ( $rule_select ) {
			$items   = $items ? $items : array( array( 'name' => '', 'not' => false, 'options' => array() ) );
			$items[] = array( 'name' => '', 'not' => false, 'options' => array() ); // one spare blank row
			foreach ( $items as $item ) {
				$value = isset( $item['options']['ids'] ) && '' !== $item['options']['ids'] ? $item['options']['ids'] : ( isset( $item['options']['template'] ) ? $item['options']['template'] : '' );
				echo '<div class="dpt-cc-rule-row" style="margin:4px 0;">';
				echo '<label><input type="checkbox" name="' . esc_attr( $prefix ) . '_not[]" value="1" ' . checked( ! empty( $item['not'] ), true, false ) . ' /> ' . esc_html__( 'NOT', 'digitizer-pro-tools' ) . '</label> ';
				echo $rule_select( $prefix . '_rule', isset( $item['name'] ) ? $item['name'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				echo ' <input type="text" name="' . esc_attr( $prefix ) . '_value[]" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'IDs / template (if the rule needs one)', 'digitizer-pro-tools' ) . '" />';
				echo '</div>';
			}
		};
		$root_rules  = array();
		$group       = null;
		foreach ( $row['conditions']['items'] as $item ) {
			if ( 'group' === $item['type'] && null === $group ) {
				$group = $item;
			} elseif ( 'rule' === $item['type'] ) {
				$root_rules[] = $item;
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dpt_cc_restriction_save" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php if ( ! $is_new ) : ?><input type="hidden" name="restriction[id]" value="<?php echo esc_attr( $row['id'] ); ?>" /><?php endif; ?>

			<div class="dpt-panel"><h2><?php esc_html_e( 'General', 'digitizer-pro-tools' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Title', 'digitizer-pro-tools' ); ?></th>
					<td><input type="text" class="regular-text" name="restriction[title]" value="<?php echo esc_attr( $row['title'] ); ?>" /></td></tr>
				<tr><th><?php esc_html_e( 'Enabled', 'digitizer-pro-tools' ); ?></th>
					<td><label><input type="checkbox" name="restriction[enabled]" value="1" <?php checked( $row['enabled'] ); ?> /> <?php esc_html_e( 'Active', 'digitizer-pro-tools' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Who may view', 'digitizer-pro-tools' ); ?></th>
					<td>
						<label><input type="radio" name="restriction[who][status]" value="logged_in" <?php checked( $row['who']['status'], 'logged_in' ); ?> /> <?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></label><br>
						<label><input type="radio" name="restriction[who][status]" value="logged_out" <?php checked( $row['who']['status'], 'logged_out' ); ?> /> <?php esc_html_e( 'Logged-out visitors', 'digitizer-pro-tools' ); ?></label>
					</td></tr>
				<tr><th><?php esc_html_e( 'Role match', 'digitizer-pro-tools' ); ?></th>
					<td>
						<select name="restriction[who][role_match]">
							<option value="any" <?php selected( $row['who']['role_match'], 'any' ); ?>><?php esc_html_e( 'Any role (login is enough)', 'digitizer-pro-tools' ); ?></option>
							<option value="match" <?php selected( $row['who']['role_match'], 'match' ); ?>><?php esc_html_e( 'One of the selected roles', 'digitizer-pro-tools' ); ?></option>
							<option value="exclude" <?php selected( $row['who']['role_match'], 'exclude' ); ?>><?php esc_html_e( 'Any role EXCEPT the selected', 'digitizer-pro-tools' ); ?></option>
						</select><br>
						<?php foreach ( get_editable_roles() as $key => $role ) : ?>
							<label style="display:inline-block;margin-right:8px;"><input type="checkbox" name="restriction[who][roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $row['who']['roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
						<?php endforeach; ?>
					</td></tr>
			</table></div>

			<div class="dpt-panel"><h2><?php esc_html_e( 'Protection', 'digitizer-pro-tools' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Method', 'digitizer-pro-tools' ); ?></th>
					<td>
						<label><input type="radio" name="restriction[protection][method]" value="redirect" <?php checked( $row['protection']['method'], 'redirect' ); ?> /> <?php esc_html_e( 'Redirect', 'digitizer-pro-tools' ); ?></label>
						<select name="restriction[protection][redirect_type]">
							<option value="login" <?php selected( $row['protection']['redirect_type'], 'login' ); ?>><?php esc_html_e( 'to login', 'digitizer-pro-tools' ); ?></option>
							<option value="home" <?php selected( $row['protection']['redirect_type'], 'home' ); ?>><?php esc_html_e( 'to home', 'digitizer-pro-tools' ); ?></option>
							<option value="custom" <?php selected( $row['protection']['redirect_type'], 'custom' ); ?>><?php esc_html_e( 'to URL:', 'digitizer-pro-tools' ); ?></option>
						</select>
						<input type="url" name="restriction[protection][redirect_url]" value="<?php echo esc_attr( $row['protection']['redirect_url'] ); ?>" class="regular-text" /><br>
						<label><input type="radio" name="restriction[protection][method]" value="replace" <?php checked( $row['protection']['method'], 'replace' ); ?> /> <?php esc_html_e( 'Replace content', 'digitizer-pro-tools' ); ?></label>
						<?php wp_dropdown_pages( array( 'name' => 'restriction[protection][replacement_page]', 'selected' => (int) $row['protection']['replacement_page'], 'show_option_none' => esc_html__( '— show message instead —', 'digitizer-pro-tools' ), 'option_none_value' => 0 ) ); ?>
					</td></tr>
				<tr><th><?php esc_html_e( 'Message', 'digitizer-pro-tools' ); ?></th>
					<td>
						<label><input type="checkbox" name="restriction[protection][override_message]" value="1" <?php checked( $row['protection']['override_message'] ); ?> /> <?php esc_html_e( 'Use a custom message for this restriction', 'digitizer-pro-tools' ); ?></label><br>
						<textarea name="restriction[protection][custom_message]" rows="3" class="large-text"><?php echo esc_textarea( $row['protection']['custom_message'] ); ?></textarea><br>
						<label><input type="checkbox" name="restriction[protection][show_excerpts]" value="1" <?php checked( $row['protection']['show_excerpts'] ); ?> /> <?php esc_html_e( 'Show the post excerpt above the message (teaser)', 'digitizer-pro-tools' ); ?></label>
					</td></tr>
				<tr><th><?php esc_html_e( 'In archives (main query)', 'digitizer-pro-tools' ); ?></th>
					<td>
						<select name="restriction[archive_handling]">
							<option value="filter" <?php selected( $row['archive_handling'], 'filter' ); ?>><?php esc_html_e( 'Show item with the restriction message', 'digitizer-pro-tools' ); ?></option>
							<option value="hide" <?php selected( $row['archive_handling'], 'hide' ); ?>><?php esc_html_e( 'Hide the item', 'digitizer-pro-tools' ); ?></option>
							<option value="replace_page" <?php selected( $row['archive_handling'], 'replace_page' ); ?>><?php esc_html_e( 'Replace the archive with a page', 'digitizer-pro-tools' ); ?></option>
							<option value="redirect" <?php selected( $row['archive_handling'], 'redirect' ); ?>><?php esc_html_e( 'Redirect away from the archive', 'digitizer-pro-tools' ); ?></option>
						</select>
						<?php wp_dropdown_pages( array( 'name' => 'restriction[archive_page]', 'selected' => (int) $row['archive_page'], 'show_option_none' => esc_html__( '— page —', 'digitizer-pro-tools' ), 'option_none_value' => 0 ) ); ?>
						<select name="restriction[archive_redirect_type]">
							<option value="login" <?php selected( $row['archive_redirect_type'], 'login' ); ?>><?php esc_html_e( 'to login', 'digitizer-pro-tools' ); ?></option>
							<option value="home" <?php selected( $row['archive_redirect_type'], 'home' ); ?>><?php esc_html_e( 'to home', 'digitizer-pro-tools' ); ?></option>
							<option value="custom" <?php selected( $row['archive_redirect_type'], 'custom' ); ?>><?php esc_html_e( 'to URL:', 'digitizer-pro-tools' ); ?></option>
						</select>
						<input type="url" name="restriction[archive_redirect_url]" value="<?php echo esc_attr( $row['archive_redirect_url'] ); ?>" class="regular-text" />
					</td></tr>
				<tr><th><?php esc_html_e( 'In other queries', 'digitizer-pro-tools' ); ?></th>
					<td>
						<select name="restriction[query_handling]">
							<option value="filter" <?php selected( $row['query_handling'], 'filter' ); ?>><?php esc_html_e( 'Show with the restriction message', 'digitizer-pro-tools' ); ?></option>
							<option value="hide" <?php selected( $row['query_handling'], 'hide' ); ?>><?php esc_html_e( 'Hide', 'digitizer-pro-tools' ); ?></option>
						</select>
						<label style="margin-left:12px;"><input type="checkbox" name="restriction[show_in_search]" value="1" <?php checked( $row['show_in_search'] ); ?> /> <?php esc_html_e( 'Allow in search results (otherwise always hidden there)', 'digitizer-pro-tools' ); ?></label>
					</td></tr>
			</table></div>

			<div class="dpt-panel"><h2><?php esc_html_e( 'Which content (conditions)', 'digitizer-pro-tools' ); ?></h2>
				<p><?php esc_html_e( 'A restriction with no rules never applies. Rules needing a value take comma-separated IDs (or a template file name).', 'digitizer-pro-tools' ); ?></p>
				<p><label><input type="radio" name="cond_op" value="and" <?php checked( $row['conditions']['operator'], 'and' ); ?> /> <?php esc_html_e( 'ALL rules must match (AND)', 'digitizer-pro-tools' ); ?></label>
				<label style="margin-left:12px;"><input type="radio" name="cond_op" value="or" <?php checked( $row['conditions']['operator'], 'or' ); ?> /> <?php esc_html_e( 'ANY rule may match (OR)', 'digitizer-pro-tools' ); ?></label></p>
				<?php $rule_rows( 'cond', $root_rules ); ?>
				<h3><?php esc_html_e( 'Group (optional)', 'digitizer-pro-tools' ); ?></h3>
				<p><label><input type="radio" name="gcond_op" value="or" <?php checked( ! $group || 'or' === $group['operator'] ); ?> /> OR</label>
				<label style="margin-left:12px;"><input type="radio" name="gcond_op" value="and" <?php checked( $group && 'and' === $group['operator'] ); ?> /> AND</label></p>
				<?php $rule_rows( 'gcond', $group ? $group['items'] : array() ); ?>
			</div>

			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save restriction', 'digitizer-pro-tools' ); ?></button></p>
		</form>
		<?php
	}
}
```

Also fix `conditions_from_post()`'s options double-write: a rule stores the same posted value under both `ids` and `template`; harmless (`sanitize_rule` keeps both; callbacks read the one they need) — leave as is, note it in the code comment.

- [ ] **Step 3: Wire** — in module `init()`: `( new DPT_CC_Restrictions_Admin() )->init();` and add the `require_once` for the new file. In `install_defaults()` add `add_option( DPT_CC_Restrictions::OPTION, array() );` guarded by `false === get_option(...)`.

- [ ] **Step 4: Verify** — `php -l` every touched file:

```bash
for f in modules/content-control/*.php; do php -l "$f" || exit 1; done
for f in tests/*-test.php; do php "$f" || exit 1; done
```

Expected: no syntax errors, all tests pass. (UI is exercised manually on a dev site during the PR round — list, add, edit, reorder, toggle, delete, and both redirect and replace behaviors.)

- [ ] **Step 5: Commit**

```bash
git add modules/content-control/class-dpt-cc-restrictions-admin.php modules/content-control/class-dpt-cc-admin.php modules/content-control/class-dpt-cc-module.php
git commit -m "Content Control: restrictions admin - list, editor, reorder, toggle

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: version bump, docs, PR

**Files:**
- Modify: `digitizer-pro-tools.php` (Version 1.34.0), `readme.txt` (Stable tag 1.34.0 + changelog), `modules/content-control/class-dpt-cc-module.php` (module `description()` mention of restrictions if it reads naturally)

- [ ] **Step 1: Bump versions** — `Version: 1.34.0` in the plugin header; `Stable tag: 1.34.0` in readme.txt.

- [ ] **Step 2: Changelog entry** — prose style matching existing entries, e.g.:

```
= 1.34.0 =
* Content Control learns the standalone plugin's global restrictions: rules that pick content (post types, IDs, taxonomies, archives, templates - AND/OR with an optional group), an audience with any/match/exclude role matching, and a choice of what refusal looks like - redirect, replace with a page, or the message, with archive hiding, search hiding, and teaser excerpts. Classic widgets gain visibility controls and the shortcode gains excluded_roles, inline and class. Per-page settings, menu visibility and whole-site protection keep working exactly as before, and per-page settings win over global rules.
```

- [ ] **Step 3: Full suite + lint**

```bash
for f in modules/content-control/*.php; do php -l "$f" || exit 1; done
for f in tests/*-test.php; do php "$f" || exit 1; done
```

- [ ] **Step 4: Commit + PR**

```bash
git add digitizer-pro-tools.php readme.txt modules/content-control/class-dpt-cc-module.php
git commit -m "Content Control parity: version 1.34.0 and changelog

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
git push -u origin feature/content-control-parity
gh pr create --title "Content Control: global restrictions, rule engine, full enforcement (v1.34.0)" --body "..."
```

PR body: summary of the feature set, spec + plan paths, test evidence. Then run the Codex review loop (`agent-skills:codex-review-loop`) to convergence before merge.
