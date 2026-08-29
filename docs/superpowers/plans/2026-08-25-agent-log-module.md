# Agent Log Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Digitizer Pro Tools module that records what automations — REST, cron, WP-CLI, XML-RPC — changed on this site, and exposes that record over the REST API and one admin screen.

**Architecture:** Four pure layers and one wiring layer. Channel detection and field extraction are pure functions with no WordPress state of their own. The store owns the table and takes an **injected writer**, so every query it builds can be exercised without a database. A per-request buffer accumulates changes keyed by object and flushes once on `shutdown`, so a post update with eight meta keys becomes one row with eight field names. The hook layer is the only file that talks to WordPress actions.

**Tech Stack:** PHP 7.2+, WordPress 5.5+, `$wpdb` with `dbDelta`, `register_rest_route`, the repo's stub test harness (`tests/bootstrap.php`, run with plain `php tests/<file>.php`), phpcs with the WordPress standard, gettext catalogs edited by hand plus `msgfmt`.

**Spec:** `docs/superpowers/specs/2026-08-25-agent-log-module-design.md`

## Global Constraints

- Module id is `agent_log`; directory `modules/agent-log/`; class prefix `DPT_AL_`; text domain `digitizer-pro-tools`.
- The module ships **disabled**: its registry entry is `'default' => '0'`, like every other module. Never change another module's default.
- **`enabled_map()` fills defaults only for ids that were never saved.** Do not touch that logic.
- Table name is `{$wpdb->prefix}dpt_agent_log`. Columns and indexes exactly as the spec's DDL.
- **Only writes that did not come from a browser are recorded.** The channel check happens before anything is buffered, not when rendering.
- **Never record a value.** `fields` holds field *names* only, JSON-encoded with `wp_json_encode`.
- **One row per object per request**, flushed on `shutdown`.
- Reads (GET/HEAD REST requests) are never recorded.
- Options are recorded from an allowlist only, filterable via `dpt_agent_log_watched_options`.
- Retention has two bounds, both filterable, each disabled by a value of zero or less: `dpt_agent_log_max_age_days` (default 30) and `dpt_agent_log_max_rows` (default 20000).
- **Switching the module off must not drop the table.** Only uninstalling the plugin may.
- **No delete route.** The REST namespace exposes `GET` only.
- REST route is `GET /digitizer/v1/activity`, permission callback requires `manage_options`, registered independently of the REST Bridge module.
- When the application-password name cannot be determined, `app` is the empty string. **Never** substitute a User-Agent, an IP, or any other guess.
- Every user-facing string is translated with the `digitizer-pro-tools` text domain and added to all three catalog files.
- The harness fails a run on any PHP notice, warning or deprecation.
- Run the whole suite before every commit: `for t in tests/*-test.php; do php "$t" | tail -1; done` — currently 1269 passing, 0 failing.
- phpcs: `~/.composer/vendor/bin/phpcs --standard=WordPress-Core --extensions=php modules/agent-log/`. There is no `phpcs.xml.dist` in this repo; the standard must be named explicitly. The single-line `if ( ! defined( 'ABSPATH' ) ) { exit; }` violation is house style — every file in the repo has it — and is the only acceptable one.
- **Before writing any assertion, confirm it can fail.** Three separate times in this repo a lenient stub made an assertion vacuous. `strpos( $x, '' )` returns `0`, so `false !== strpos( $haystack, $needle )` passes for an empty needle. If a test builds an expected string from an option, set that option in the stub.

---

## File Structure

| File | Responsibility |
|---|---|
| `modules/agent-log/class-dpt-al-module.php` | `DPT_Agent_Log_Module extends DPT_Module`. Identity, description, `init()` wiring, schema upgrade guard. |
| `modules/agent-log/class-dpt-al-channel.php` | Pure. Which channel this request is, or `''` for a browser. No WordPress calls that are not trivially stubbable. |
| `modules/agent-log/class-dpt-al-store.php` | Owns the table: DDL, insert, query, prune. Takes an injected writer so it is testable without a database. |
| `modules/agent-log/class-dpt-al-buffer.php` | Pure accumulation. Collects changes keyed by object during a request and produces the rows to write. |
| `modules/agent-log/class-dpt-al-hooks.php` | The only file that calls `add_action`. Translates WordPress hooks into buffer entries. |
| `modules/agent-log/class-dpt-al-rest.php` | `GET /digitizer/v1/activity`: args schema, permission callback, query, headers. |
| `modules/agent-log/class-dpt-al-admin.php` | One screen, plus the clear-log `admin_post` handler. |
| `tests/agent-log-test.php` | Every pure layer above. |
| `includes/class-dpt-plugin.php` | Registry entry. |
| `languages/*` | Nine-ish new strings across `.pot`, `.po`, `.l10n.php`, then `.mo`. |
| `readme.txt`, `digitizer-pro-tools.php` | Changelog and version 1.30.0. |

## Deviation from the spec, ruled here

The spec says a **daily cron event** prunes the table. This plan uses **opportunistic pruning with an hourly throttle** instead: after a flush, if `dpt_agent_log_last_prune` is more than an hour old, prune and stamp it.

Reason: a module that is switched off never runs `init()`, so it can never unschedule an event it scheduled while on. A scheduled hook whose callback is no longer registered is a leak that survives the module being disabled and is invisible on the Modules screen. The throttle gives the same bound with nothing to leak, at the cost of one extra `DELETE` per hour on a site that is being written to — and a site that is not being written to has nothing to prune.

If the reviewer disagrees, the change is confined to Task 3.

---

### Task 0: Settle the application-password question by reading core

**Files:**
- Create: `docs/superpowers/specs/2026-08-25-agent-log-app-name-finding.md`

**Interfaces:**
- Produces: a written finding that Task 1 implements. No code.

This project has been bitten four times by reasoning about a vendor's behaviour instead of reading it. The spec deliberately left this open. Settle it with the source in front of you.

- [ ] **Step 1: Get a copy of WordPress core**

There is no WordPress installation on this machine. Download one into the scratchpad — not into the repo.

```bash
cd "$(dirname "$(mktemp -u)")" && mkdir -p wpcore && cd wpcore
curl -sL -o wp.zip https://wordpress.org/latest.zip && unzip -q -o wp.zip && ls wordpress/wp-includes/class-wp-application-passwords.php
```

- [ ] **Step 2: Read the authentication path**

Read, and quote what you find:

```bash
grep -n "application_password_did_authenticate\|wp_authenticate_application_password\|rest_application_password" wordpress/wp-includes/user.php wordpress/wp-includes/rest-api.php wordpress/wp-includes/class-wp-application-passwords.php
```

Answer three questions in the finding document:

1. When a REST request authenticates with an application password, what identifies **which** password was used — a global, a filter argument, or user meta?
2. Does that identifier reach a `shutdown` callback, or is it only available during authentication? (This decides whether the module can read it at flush time or must capture it earlier.)
3. What field on the password record holds the human-readable name the user typed when creating it?

- [ ] **Step 3: Write the finding**

Write `docs/superpowers/specs/2026-08-25-agent-log-app-name-finding.md` containing, for each question: the answer, the exact file and line number it came from, and the code quoted. If the answer is "core does not expose this", say so plainly and say what the module will therefore do.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-08-25-agent-log-app-name-finding.md
git commit -m "Read core to settle how an application password names itself"
```

---

### Task 1: Channel detection

**Files:**
- Create: `modules/agent-log/class-dpt-al-channel.php`
- Create: `tests/agent-log-test.php`

**Interfaces:**
- Consumes: the Task 0 finding, for `app_name()` only.
- Produces:
  - `DPT_AL_Channel::current()` → `'cli'|'cron'|'xmlrpc'|'rest'|''`
  - `DPT_AL_Channel::app_name()` → `string` (`''` when unknown)
  - `DPT_AL_Channel::is_read_request()` → `bool`

- [ ] **Step 1: Write the failing test**

Create `tests/agent-log-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-channel.php';

/* ---- channel detection ---- */

// A browser request is the case the whole module turns on: nothing is
// recorded, so this must be '' rather than a channel nobody named.
$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;
dpt_test_eq( DPT_AL_Channel::current(), '', 'a browser request is not a channel' );

$GLOBALS['dpt_stub_rest_request'] = true;
dpt_test_eq( DPT_AL_Channel::current(), 'rest', 'a REST request is' );

// Contexts nest, and the outermost one is the true origin. Cron that runs
// code setting REST_REQUEST is still cron.
$GLOBALS['dpt_stub_doing_cron'] = true;
dpt_test_eq( DPT_AL_Channel::current(), 'cron', 'cron outranks REST when both are true' );

$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;

/* ---- reads are never recorded ---- */

$_SERVER['REQUEST_METHOD'] = 'GET';
dpt_test_ok( DPT_AL_Channel::is_read_request(), 'GET is a read' );
$_SERVER['REQUEST_METHOD'] = 'HEAD';
dpt_test_ok( DPT_AL_Channel::is_read_request(), 'so is HEAD' );
$_SERVER['REQUEST_METHOD'] = 'POST';
dpt_test_ok( ! DPT_AL_Channel::is_read_request(), 'POST is not' );
$_SERVER['REQUEST_METHOD'] = 'DELETE';
dpt_test_ok( ! DPT_AL_Channel::is_read_request(), 'nor is DELETE' );
// An absent method is the CLI and cron case: no HTTP verb at all, and those
// are writes worth recording, so it must not read as a read.
unset( $_SERVER['REQUEST_METHOD'] );
dpt_test_ok( ! DPT_AL_Channel::is_read_request(), 'and a request with no method at all is not a read' );

/* ---- the app name is never guessed ---- */

$_SERVER['HTTP_USER_AGENT'] = 'ContentEngine/1.0';
dpt_test_eq( DPT_AL_Channel::app_name(), '', 'an unidentified caller has no app name, and the User-Agent is not one' );
unset( $_SERVER['HTTP_USER_AGENT'] );

dpt_test_summary();
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php tests/agent-log-test.php
```

Expected: a fatal error, `Failed opening required .../class-dpt-al-channel.php`.

- [ ] **Step 3: Add the stubs the test needs**

The harness has no `wp_doing_cron` or `wp_is_serving_rest_request`. Add them to `tests/bootstrap.php` beside the other WordPress stubs, each reading a global the test sets:

```php
$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;
function wp_doing_cron() { return (bool) $GLOBALS['dpt_stub_doing_cron']; }
function wp_is_serving_rest_request() { return (bool) $GLOBALS['dpt_stub_rest_request']; }
```

- [ ] **Step 4: Write the implementation**

Create `modules/agent-log/class-dpt-al-channel.php`:

```php
<?php
/**
 * Agent Log module - which channel this request arrived on.
 *
 * Pure, and the gate for everything else: a request this file cannot name is
 * a request nothing is recorded for. That is the module's whole boundary -
 * a person working in wp-admin leaves no trace here, by construction rather
 * than by a filter someone could forget to apply.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Channel {

	/**
	 * The channel this request arrived on, or '' for anything else.
	 *
	 * Order is precedence, not preference. Contexts nest - WP-CLI can run
	 * code that sets REST_REQUEST, and a cron run can call into the REST
	 * stack - and the outermost context is the one that describes where the
	 * change really came from.
	 *
	 * @return string
	 */
	public static function current() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			if ( wp_is_serving_rest_request() ) {
				return 'rest';
			}
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// wp_is_serving_rest_request() arrived in WordPress 6.5. On anything
			// older the constant is what there is.
			return 'rest';
		}
		return '';
	}

	/**
	 * Whether this request only reads.
	 *
	 * ContentEngine polls. Recording reads fills the table in a day and
	 * drowns the writes that were the reason to look.
	 *
	 * A request with no method at all is CLI or cron, which are writes worth
	 * recording - so the absent case must answer false, not true.
	 *
	 * @return bool
	 */
	public static function is_read_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_scalar( $_SERVER['REQUEST_METHOD'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the HTTP verb, not request data.
		$method = strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * The name of the application password that authenticated this request.
	 *
	 * Implement this from the finding in
	 * docs/superpowers/specs/2026-08-25-agent-log-app-name-finding.md, and
	 * from nothing else.
	 *
	 * When the name cannot be determined this returns ''. It never falls back
	 * to a User-Agent, an IP or any other string that merely looks like an
	 * identity: a fabricated attribution in a log is worse than an absent one,
	 * because someone will believe it.
	 *
	 * @return string
	 */
	public static function app_name() {
		return '';
	}
}
```

Then replace the body of `app_name()` with what Task 0 found, keeping the `''` fallback and the docblock's promise intact.

- [ ] **Step 5: Run the test and the whole suite**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
```

Expected: the new file passes; every other file is unchanged from 1269 passing, 0 failing.

- [ ] **Step 6: Commit**

```bash
git add modules/agent-log/class-dpt-al-channel.php tests/agent-log-test.php tests/bootstrap.php
git commit -m "Agent Log: name the channel a request arrived on"
```

---

### Task 2: The store, with an injected writer

**Files:**
- Create: `modules/agent-log/class-dpt-al-store.php`
- Modify: `tests/agent-log-test.php` (append)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `DPT_AL_Store::set_writer( $writer )` / `DPT_AL_Store::writer()` — `$writer` is any object exposing `insert( $table, $data, $formats )`, `query( $sql )`, `get_results( $sql )`, `get_var( $sql )`, `prepare( $sql, ...$args )` and a public `$prefix`. `$wpdb` satisfies this; the test supplies a recorder.
  - `DPT_AL_Store::table()` → `string`
  - `DPT_AL_Store::insert( array $row )` → `bool`
  - `DPT_AL_Store::query_args( array $args )` → `array( 'where' => string, 'params' => array, 'limit' => int, 'offset' => int )`
  - `DPT_AL_Store::prune_plan( int $max_age_days, int $max_rows, int $now )` → `array` of `array( 'kind' => 'age'|'rows', ... )`

The point of the injected writer is that every string this class builds can be read back in a test. A storage layer that cannot be exercised is a storage layer whose bugs ship.

- [ ] **Step 1: Write the failing test**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-store.php';

/* ---- the store builds what it says it builds ---- */

class DPT_AL_Test_Writer {
	public $prefix   = 'wp_';
	public $inserted = array();
	public $queries  = array();
	public $rows     = array();
	public $var      = 0;
	public function insert( $table, $data, $formats ) {
		$this->inserted[] = array( 'table' => $table, 'data' => $data, 'formats' => $formats );
		return 1;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return $this->rows;
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return $this->var;
	}
	// Deliberately naive, and deliberately NOT a no-op: a prepare() that
	// returned its first argument unchanged would let a test pass while the
	// real query dropped every parameter.
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}
}

$writer = new DPT_AL_Test_Writer();
DPT_AL_Store::set_writer( $writer );

dpt_test_eq( DPT_AL_Store::table(), 'wp_dpt_agent_log', 'the table is named from the writer prefix' );

DPT_AL_Store::insert(
	array(
		'logged_at'      => '2026-08-25 10:00:00',
		'channel'        => 'rest',
		'app'            => 'ContentEngine',
		'user_id'        => 5,
		'action'         => 'updated',
		'object_type'    => 'post',
		'object_subtype' => 'page',
		'object_id'      => 812,
		'object_name'    => 'About us',
		'fields'         => array( 'post_content', 'rank_math_title' ),
	)
);
dpt_test_eq( count( $writer->inserted ), 1, 'an insert reaches the writer' );
dpt_test_eq( $writer->inserted[0]['table'], 'wp_dpt_agent_log', 'against the right table' );
dpt_test_eq( $writer->inserted[0]['data']['fields'], '["post_content","rank_math_title"]', 'with the field names JSON-encoded, and no values anywhere' );
dpt_test_eq( count( $writer->inserted[0]['formats'] ), count( $writer->inserted[0]['data'] ), 'and a format for every column, so none is passed unescaped' );

// A row missing everything still writes something legal rather than a fatal.
$writer->inserted = array();
DPT_AL_Store::insert( array() );
dpt_test_eq( $writer->inserted[0]['data']['channel'], '', 'a row with nothing in it defaults rather than fails' );
dpt_test_eq( $writer->inserted[0]['data']['object_id'], 0, 'with numeric columns defaulting to zero' );
dpt_test_eq( $writer->inserted[0]['data']['fields'], '[]', 'and no fields encoding as an empty array, not null' );

/* ---- query arguments ---- */

$q = DPT_AL_Store::query_args( array( 'channel' => 'rest', 'object_type' => 'post', 'object_id' => 812, 'per_page' => 20, 'page' => 2 ) );
dpt_test_ok( false !== strpos( $q['where'], 'channel = %s' ), 'a channel filter is a placeholder, never interpolated' );
dpt_test_ok( false !== strpos( $q['where'], 'object_id = %d' ), 'and so is an id' );
dpt_test_eq( $q['params'], array( 'rest', 'post', 812 ), 'with the values carried separately, in the order the placeholders appear' );
dpt_test_eq( $q['limit'], 20, 'per_page becomes the limit' );
dpt_test_eq( $q['offset'], 20, 'and page 2 of 20 starts at 20' );

// The enums are closed. A value outside them is dropped, not passed through.
$q = DPT_AL_Store::query_args( array( 'channel' => 'ftp; DROP TABLE wp_posts' ) );
dpt_test_eq( $q['params'], array(), 'a channel outside the enum contributes no parameter' );
dpt_test_eq( $q['where'], '', 'and no clause' );

$q = DPT_AL_Store::query_args( array( 'per_page' => 5000 ) );
dpt_test_eq( $q['limit'], 100, 'per_page is capped at 100' );
$q = DPT_AL_Store::query_args( array( 'per_page' => 0, 'page' => 0 ) );
dpt_test_eq( $q['limit'], 20, 'a nonsense per_page falls back to the default' );
dpt_test_eq( $q['offset'], 0, 'and a nonsense page starts at the beginning' );

/* ---- retention ---- */

$now = 1756108800; // 2026-08-25 08:00:00 UTC

$plan = DPT_AL_Store::prune_plan( 30, 20000, $now );
dpt_test_eq( count( $plan ), 2, 'both bounds produce work when both are set' );

$plan = DPT_AL_Store::prune_plan( 0, 20000, $now );
dpt_test_eq( count( $plan ), 1, 'an age bound of zero disables that bound only' );
dpt_test_eq( $plan[0]['kind'], 'rows', 'leaving the row bound in force' );

$plan = DPT_AL_Store::prune_plan( 30, -5, $now );
dpt_test_eq( count( $plan ), 1, 'a negative row bound disables that bound the same way a zero does' );
dpt_test_eq( $plan[0]['kind'], 'age', 'leaving the age bound in force' );

dpt_test_eq( DPT_AL_Store::prune_plan( 0, 0, $now ), array(), 'and both disabled means no work at all, rather than a delete with no bound' );

$plan = DPT_AL_Store::prune_plan( 30, 0, $now );
dpt_test_eq( $plan[0]['cutoff'], gmdate( 'Y-m-d H:i:s', $now - ( 30 * 86400 ) ), 'the age cutoff is 30 days before now, in UTC' );
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php tests/agent-log-test.php
```

Expected: fatal, `Failed opening required .../class-dpt-al-store.php`.

- [ ] **Step 3: Write the implementation**

Create `modules/agent-log/class-dpt-al-store.php`:

```php
<?php
/**
 * Agent Log module - the table.
 *
 * Every query is built here and nowhere else, and the thing that runs them is
 * injected rather than reached for. $wpdb satisfies the interface; so does a
 * recorder in a test. A storage layer that cannot be exercised is a storage
 * layer whose bugs ship, and this one is written by automated clients whose
 * mistakes nobody watches in real time.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Store {

	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 100;

	/** @var object|null */
	private static $writer = null;

	/**
	 * The channels a row may carry. Closed on purpose: a value from a query
	 * string that is not one of these contributes no clause at all, so a
	 * caller cannot widen the query by inventing a channel.
	 */
	private static $channels = array( 'rest', 'cron', 'cli', 'xmlrpc' );

	private static $object_types = array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' );

	/**
	 * @param object $writer Anything with $prefix, insert(), query(),
	 *                       get_results(), get_var() and prepare().
	 * @return void
	 */
	public static function set_writer( $writer ) {
		self::$writer = $writer;
	}

	/**
	 * @return object
	 */
	public static function writer() {
		if ( null === self::$writer ) {
			global $wpdb;
			self::$writer = $wpdb;
		}
		return self::$writer;
	}

	/**
	 * @return string
	 */
	public static function table() {
		return self::writer()->prefix . 'dpt_agent_log';
	}

	/**
	 * The columns, their defaults and their printf formats, in one place so
	 * insert() cannot pass a value without a format - which is what makes a
	 * value reach the database unescaped.
	 *
	 * @return array
	 */
	private static function columns() {
		return array(
			'logged_at'      => array( '', '%s' ),
			'channel'        => array( '', '%s' ),
			'app'            => array( '', '%s' ),
			'user_id'        => array( 0, '%d' ),
			'action'         => array( '', '%s' ),
			'object_type'    => array( '', '%s' ),
			'object_subtype' => array( '', '%s' ),
			'object_id'      => array( 0, '%d' ),
			'object_name'    => array( '', '%s' ),
			'fields'         => array( array(), '%s' ),
		);
	}

	/**
	 * Write one row.
	 *
	 * @param array $row Partial row; anything missing takes its default.
	 * @return bool
	 */
	public static function insert( $row ) {
		$data    = array();
		$formats = array();

		foreach ( self::columns() as $name => $spec ) {
			list( $default, $format ) = $spec;
			$value = array_key_exists( $name, $row ) ? $row[ $name ] : $default;

			if ( 'fields' === $name ) {
				$value = wp_json_encode( array_values( array_map( 'strval', (array) $value ) ) );
				if ( ! is_string( $value ) ) {
					// wp_json_encode() can fail on malformed UTF-8. An
					// unwritable field list must not lose the row that says
					// the object changed at all.
					$value = '[]';
				}
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} else {
				$value = is_scalar( $value ) ? (string) $value : '';
			}

			$data[ $name ]    = $value;
			$formats[]        = $format;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this module's own table.
		return (bool) self::writer()->insert( self::table(), $data, $formats );
	}

	/**
	 * Turn request arguments into a WHERE clause, its parameters and a window.
	 *
	 * Nothing is interpolated. Every value that survives becomes a
	 * placeholder and a matching entry in 'params', so the caller cannot
	 * build a query this class did not intend.
	 *
	 * @param array $args Raw arguments.
	 * @return array
	 */
	public static function query_args( $args ) {
		$clauses = array();
		$params  = array();

		if ( isset( $args['channel'] ) && in_array( $args['channel'], self::$channels, true ) ) {
			$clauses[] = 'channel = %s';
			$params[]  = $args['channel'];
		}
		if ( isset( $args['object_type'] ) && in_array( $args['object_type'], self::$object_types, true ) ) {
			$clauses[] = 'object_type = %s';
			$params[]  = $args['object_type'];
		}
		if ( isset( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
			$clauses[] = 'object_id = %d';
			$params[]  = (int) $args['object_id'];
		}
		if ( isset( $args['app'] ) && is_scalar( $args['app'] ) && '' !== (string) $args['app'] ) {
			$clauses[] = 'app = %s';
			$params[]  = (string) $args['app'];
		}
		foreach ( array( 'after' => '>=', 'before' => '<=' ) as $key => $operator ) {
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) ) {
				continue;
			}
			$stamp = strtotime( (string) $args[ $key ] . ' UTC' );
			if ( ! $stamp ) {
				continue;
			}
			$clauses[] = 'logged_at ' . $operator . ' %s';
			$params[]  = gmdate( 'Y-m-d H:i:s', $stamp );
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		if ( $per_page < 1 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}
		$per_page = min( $per_page, self::MAX_PER_PAGE );

		$page = isset( $args['page'] ) ? (int) $args['page'] : 1;
		if ( $page < 1 ) {
			$page = 1;
		}

		return array(
			'where'  => $clauses ? implode( ' AND ', $clauses ) : '',
			'params' => $params,
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * What pruning would do, without doing it.
	 *
	 * Two bounds, and each is disabled by a value of zero or less - the same
	 * code path for "the operator turned this off" and "the setting is
	 * nonsense", on purpose. Both disabled returns no work rather than a
	 * DELETE with no bound, which would empty the table.
	 *
	 * @param int $max_age_days Days to keep, 0 or less to disable.
	 * @param int $max_rows     Rows to keep, 0 or less to disable.
	 * @param int $now          Current timestamp.
	 * @return array
	 */
	public static function prune_plan( $max_age_days, $max_rows, $now ) {
		$plan = array();

		if ( (int) $max_age_days > 0 ) {
			$plan[] = array(
				'kind'   => 'age',
				'cutoff' => gmdate( 'Y-m-d H:i:s', (int) $now - ( (int) $max_age_days * DAY_IN_SECONDS ) ),
			);
		}
		if ( (int) $max_rows > 0 ) {
			$plan[] = array(
				'kind' => 'rows',
				'keep' => (int) $max_rows,
			);
		}

		return $plan;
	}
}
```

- [ ] **Step 4: Run the test and the whole suite**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
```

Expected: the new file passes; the other nine are unchanged.

- [ ] **Step 5: Commit**

```bash
git add modules/agent-log/class-dpt-al-store.php tests/agent-log-test.php
git commit -m "Agent Log: the table, and an injected writer that makes it testable"
```

---

### Task 3: The per-request buffer, and pruning

**Files:**
- Create: `modules/agent-log/class-dpt-al-buffer.php`
- Modify: `tests/agent-log-test.php` (append)

**Interfaces:**
- Consumes: `DPT_AL_Store::insert()`, `DPT_AL_Store::prune_plan()`, `DPT_AL_Channel::current()`.
- Produces:
  - `DPT_AL_Buffer::record( $type, $subtype, $id, $action, $name = '', $fields = array() )` → `void`
  - `DPT_AL_Buffer::pending()` → `array` keyed `"$type:$id"`
  - `DPT_AL_Buffer::reset()` → `void`
  - `DPT_AL_Buffer::rows( $channel, $app, $user_id, $now )` → `array` of rows ready for `DPT_AL_Store::insert()`
  - `DPT_AL_Buffer::max_age_days()` / `DPT_AL_Buffer::max_rows()` → `int`

Aggregation is the point: eight meta writes on one post are **one row with eight names**, not nine rows.

- [ ] **Step 1: Write the failing test**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-buffer.php';

/* ---- one row per object per request ---- */

DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_content' ) );
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'rank_math_title' ) );
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( '_elementor_data', 'post_content' ) );

$rows = DPT_AL_Buffer::rows( 'rest', 'ContentEngine', 5, 1756108800 );
dpt_test_eq( count( $rows ), 1, 'three writes to one post are one row' );
dpt_test_eq( count( $rows[0]['fields'] ), 3, 'carrying every distinct field name' );
dpt_test_ok( in_array( 'post_content', $rows[0]['fields'], true ), 'including one named twice' );
dpt_test_eq( count( array_unique( $rows[0]['fields'] ) ), 3, 'and named once each, not twice' );
dpt_test_eq( $rows[0]['logged_at'], gmdate( 'Y-m-d H:i:s', 1756108800 ), 'stamped in UTC from the clock it was handed' );
dpt_test_eq( $rows[0]['channel'], 'rest', 'carrying the channel' );
dpt_test_eq( $rows[0]['app'], 'ContentEngine', 'and the app' );

// Two objects are two rows, however interleaved the writes were.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_title' ) );
DPT_AL_Buffer::record( 'term', 'category', 4, 'updated', 'News', array( 'name' ) );
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_excerpt' ) );
dpt_test_eq( count( DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 ) ), 2, 'two objects are two rows' );

// A delete outranks an update: the object is gone, and saying it was
// "updated" because an update came first in the same request is a lie the
// log would tell forever.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'post', 'page', 812, 'updated', 'About us', array( 'post_title' ) );
DPT_AL_Buffer::record( 'post', 'page', 812, 'deleted', 'About us' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'a delete in the same request wins over an update' );

// And a create outranks an update for the same reason, in the other
// direction: the request that made the object is the one worth recording.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'post', 'page', 813, 'created', 'New page' );
DPT_AL_Buffer::record( 'post', 'page', 813, 'updated', 'New page', array( 'post_content' ) );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'created', 'a create earlier in the request wins over the update that followed it' );
dpt_test_eq( count( $rows[0]['fields'] ), 1, 'while still collecting the fields the update touched' );

DPT_AL_Buffer::reset();
dpt_test_eq( DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'a request that changed nothing writes nothing' );

/* ---- retention settings ---- */

$GLOBALS['dpt_stub_filters'] = array();
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 30, 'the age bound defaults to thirty days' );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 20000, 'and the row bound to twenty thousand' );

add_filter( 'dpt_agent_log_max_age_days', function () { return 7; } );
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 7, 'both are filterable' );
add_filter( 'dpt_agent_log_max_rows', function () { return 0; } );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 0, 'including down to zero, which disables that bound' );
$GLOBALS['dpt_stub_filters'] = array();
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php tests/agent-log-test.php
```

Expected: fatal, `Failed opening required .../class-dpt-al-buffer.php`.

- [ ] **Step 3: Write the implementation**

Create `modules/agent-log/class-dpt-al-buffer.php`:

```php
<?php
/**
 * Agent Log module - what this request changed, gathered before it is written.
 *
 * A single REST call that updates a post fires save_post once and the meta
 * hooks once per key. Writing a row per hook would turn one edit into nine
 * rows and make the log something you reassemble by eye rather than read. So
 * changes accumulate here, keyed by object, and the request writes once.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Buffer {

	const DEFAULT_MAX_AGE_DAYS = 30;
	const DEFAULT_MAX_ROWS     = 20000;

	/** @var array */
	private static $pending = array();

	/**
	 * How strongly an action describes what happened to an object, when more
	 * than one reached the buffer in one request. Creation and deletion are
	 * facts about the object's existence; an update is a fact about its
	 * contents, and the weaker claim.
	 */
	private static $rank = array(
		'updated' => 1,
		'created' => 2,
		'deleted' => 3,
	);

	/**
	 * Note that something changed.
	 *
	 * @param string $type    post|term|attachment|user|plugin|theme|option.
	 * @param string $subtype Post type, taxonomy, plugin file - '' when none.
	 * @param int    $id      Object id, 0 for objects without one.
	 * @param string $action  created|updated|deleted|activated|deactivated|switched.
	 * @param string $name    Human-readable name.
	 * @param array  $fields  Field names touched. Never values.
	 * @return void
	 */
	public static function record( $type, $subtype, $id, $action, $name = '', $fields = array() ) {
		$key = $type . ':' . (int) $id;

		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array(
				'object_type'    => (string) $type,
				'object_subtype' => (string) $subtype,
				'object_id'      => (int) $id,
				'object_name'    => (string) $name,
				'action'         => (string) $action,
				'fields'         => array(),
			);
		} else {
			$held = self::$pending[ $key ]['action'];
			$new  = (string) $action;
			$a    = isset( self::$rank[ $held ] ) ? self::$rank[ $held ] : 0;
			$b    = isset( self::$rank[ $new ] ) ? self::$rank[ $new ] : 0;
			if ( $b > $a ) {
				self::$pending[ $key ]['action'] = $new;
			}
			if ( '' !== (string) $name ) {
				self::$pending[ $key ]['object_name'] = (string) $name;
			}
		}

		foreach ( (array) $fields as $field ) {
			if ( is_scalar( $field ) && '' !== (string) $field ) {
				self::$pending[ $key ]['fields'][ (string) $field ] = true;
			}
		}
	}

	/**
	 * @return array
	 */
	public static function pending() {
		return self::$pending;
	}

	/**
	 * @return void
	 */
	public static function reset() {
		self::$pending = array();
	}

	/**
	 * The rows this request should write.
	 *
	 * @param string $channel Channel name.
	 * @param string $app     Application name, '' when unknown.
	 * @param int    $user_id Acting user, 0 under cron.
	 * @param int    $now     Current timestamp.
	 * @return array
	 */
	public static function rows( $channel, $app, $user_id, $now ) {
		$rows = array();
		foreach ( self::$pending as $entry ) {
			$entry['logged_at'] = gmdate( 'Y-m-d H:i:s', (int) $now );
			$entry['channel']   = (string) $channel;
			$entry['app']       = (string) $app;
			$entry['user_id']   = (int) $user_id;
			$entry['fields']    = array_keys( $entry['fields'] );
			$rows[]             = $entry;
		}
		return $rows;
	}

	/**
	 * @return int Days to keep, 0 or less to keep forever.
	 */
	public static function max_age_days() {
		/**
		 * Filter how many days of agent activity are kept.
		 *
		 * @param int $days Days, 0 or less to disable the age bound.
		 */
		return (int) apply_filters( 'dpt_agent_log_max_age_days', self::DEFAULT_MAX_AGE_DAYS );
	}

	/**
	 * @return int Rows to keep, 0 or less for no limit.
	 */
	public static function max_rows() {
		/**
		 * Filter how many rows of agent activity are kept.
		 *
		 * @param int $rows Rows, 0 or less to disable the row bound.
		 */
		return (int) apply_filters( 'dpt_agent_log_max_rows', self::DEFAULT_MAX_ROWS );
	}
}
```

- [ ] **Step 4: Run the test and the whole suite**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
```

- [ ] **Step 5: Commit**

```bash
git add modules/agent-log/class-dpt-al-buffer.php tests/agent-log-test.php
git commit -m "Agent Log: gather a request's changes into one row per object"
```

---

### Task 4: The hook layer

**Files:**
- Create: `modules/agent-log/class-dpt-al-hooks.php`
- Modify: `tests/agent-log-test.php` (append)

**Interfaces:**
- Consumes: `DPT_AL_Channel`, `DPT_AL_Buffer`, `DPT_AL_Store`.
- Produces:
  - `DPT_AL_Hooks::init()` → `void`
  - `DPT_AL_Hooks::watched_options()` → `array`
  - `DPT_AL_Hooks::post_field_diff( $after, $before )` → `array` of column names
  - `DPT_AL_Hooks::flush()` → `void`

This is the only file that calls `add_action`. Everything it computes lives in the two pure statics above so that both can be tested.

- [ ] **Step 1: Write the failing test**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-hooks.php';

/* ---- which post columns changed ---- */

$before = (object) array( 'post_title' => 'Old', 'post_content' => 'Body', 'post_status' => 'draft', 'post_modified' => '2026-01-01 00:00:00' );
$after  = (object) array( 'post_title' => 'New', 'post_content' => 'Body', 'post_status' => 'publish', 'post_modified' => '2026-08-25 00:00:00' );

$diff = DPT_AL_Hooks::post_field_diff( $after, $before );
dpt_test_ok( in_array( 'post_title', $diff, true ), 'a changed title is reported' );
dpt_test_ok( in_array( 'post_status', $diff, true ), 'and a changed status' );
dpt_test_ok( ! in_array( 'post_content', $diff, true ), 'an unchanged column is not' );
// post_modified changes on every save by definition. Reporting it would put
// a field in every single row that means nothing.
dpt_test_ok( ! in_array( 'post_modified', $diff, true ), 'and neither is post_modified, which changes on every save' );

// A create has no "before". Every column would look changed, which is true
// and useless - the action already says the object is new.
dpt_test_eq( DPT_AL_Hooks::post_field_diff( $after, null ), array(), 'a create reports no field diff at all' );

/* ---- the option allowlist ---- */

$GLOBALS['dpt_stub_filters'] = array();
$watched = DPT_AL_Hooks::watched_options();
dpt_test_ok( in_array( 'siteurl', $watched, true ), 'siteurl is watched' );
dpt_test_ok( in_array( 'active_plugins', $watched, true ), 'and so is active_plugins' );
// updated_option fires for every transient. Without an allowlist the table
// fills with noise in a day and buries the writes worth seeing.
dpt_test_ok( ! in_array( '_transient_doing_cron', $watched, true ), 'a transient is not' );

add_filter( 'dpt_agent_log_watched_options', function ( $list ) { $list[] = 'my_option'; return $list; } );
dpt_test_ok( in_array( 'my_option', DPT_AL_Hooks::watched_options(), true ), 'and a site may add one by filter' );
// A filter returning something that is not a list must not disarm the
// allowlist into "watch everything".
add_filter( 'dpt_agent_log_watched_options', function () { return 'nonsense'; } );
dpt_test_ok( in_array( 'siteurl', DPT_AL_Hooks::watched_options(), true ), 'while a filter returning nonsense leaves the defaults standing' );
$GLOBALS['dpt_stub_filters'] = array();
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php tests/agent-log-test.php
```

Expected: fatal, `Failed opening required .../class-dpt-al-hooks.php`.

- [ ] **Step 3: Write the implementation**

Create `modules/agent-log/class-dpt-al-hooks.php`:

```php
<?php
/**
 * Agent Log module - the WordPress wiring.
 *
 * The only file here that hooks anything. What it decides lives in static
 * methods that take their inputs as arguments, so the decisions can be tested
 * without a request.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Hooks {

	/**
	 * Columns whose change says nothing. post_modified moves on every save by
	 * definition, so reporting it would put one meaningless name in every row.
	 */
	private static $ignored_columns = array( 'post_modified', 'post_modified_gmt' );

	/**
	 * @return void
	 */
	public static function init() {
		// Nothing at all is hooked for a browser request or a read. The
		// module's boundary is enforced before any listener exists, rather
		// than by each listener remembering to check.
		if ( '' === DPT_AL_Channel::current() || DPT_AL_Channel::is_read_request() ) {
			return;
		}

		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 10, 4 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ), 10, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'created_term', array( __CLASS__, 'on_term_created' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_deleted' ), 10, 4 );
		add_action( 'user_register', array( __CLASS__, 'on_user_created' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_user_updated' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_role' ), 10, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switched' ), 10, 2 );
		add_action( 'updated_option', array( __CLASS__, 'on_option_updated' ) );

		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/**
	 * The options worth a row.
	 *
	 * @return array
	 */
	public static function watched_options() {
		$default = array(
			'siteurl',
			'home',
			'blogname',
			'users_can_register',
			'default_role',
			'permalink_structure',
			'template',
			'stylesheet',
			'active_plugins',
		);
		/**
		 * Filter which options are recorded when they change.
		 *
		 * @param array $options Option names.
		 */
		$filtered = apply_filters( 'dpt_agent_log_watched_options', $default );
		// A filter that returns something other than a list would otherwise
		// turn the allowlist into "watch everything", which is the one
		// outcome it exists to prevent.
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $default;
	}

	/**
	 * Which columns of a post actually changed.
	 *
	 * @param object      $after  Post after the save.
	 * @param object|null $before Post before it, null on a create.
	 * @return array
	 */
	public static function post_field_diff( $after, $before ) {
		if ( ! is_object( $after ) || ! is_object( $before ) ) {
			// A create has no before. Every column would read as changed,
			// which is true and useless: the action already says it is new.
			return array();
		}
		$changed = array();
		foreach ( get_object_vars( $after ) as $column => $value ) {
			if ( in_array( $column, self::$ignored_columns, true ) ) {
				continue;
			}
			if ( ! property_exists( $before, $column ) ) {
				continue;
			}
			if ( $before->$column !== $value ) {
				$changed[] = $column;
			}
		}
		return $changed;
	}

	public static function on_post_saved( $post_id, $post, $update, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$type = ( isset( $post->post_type ) && 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record(
			$type,
			isset( $post->post_type ) ? $post->post_type : '',
			$post_id,
			$update ? 'updated' : 'created',
			isset( $post->post_title ) ? $post->post_title : '',
			self::post_field_diff( $post, $post_before )
		);
	}

	public static function on_post_deleted( $post_id, $post = null ) {
		$type = ( is_object( $post ) && isset( $post->post_type ) && 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record(
			$type,
			is_object( $post ) && isset( $post->post_type ) ? $post->post_type : '',
			$post_id,
			'deleted',
			is_object( $post ) && isset( $post->post_title ) ? $post->post_title : ''
		);
	}

	public static function on_post_meta( $meta_id, $object_id, $meta_key ) {
		$post = get_post( $object_id );
		if ( ! $post ) {
			return;
		}
		$type = ( 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record( $type, $post->post_type, $object_id, 'updated', $post->post_title, array( $meta_key ) );
	}

	public static function on_term_created( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'created' );
	}

	public static function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'updated' );
	}

	public static function on_term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		DPT_AL_Buffer::record(
			'term',
			(string) $taxonomy,
			$term_id,
			'deleted',
			is_object( $deleted_term ) && isset( $deleted_term->name ) ? $deleted_term->name : ''
		);
	}

	private static function record_term( $term_id, $taxonomy, $action ) {
		$term = get_term( $term_id, $taxonomy );
		DPT_AL_Buffer::record(
			'term',
			(string) $taxonomy,
			$term_id,
			$action,
			( $term && ! is_wp_error( $term ) && isset( $term->name ) ) ? $term->name : ''
		);
	}

	public static function on_user_created( $user_id ) {
		self::record_user( $user_id, 'created' );
	}

	public static function on_user_updated( $user_id ) {
		self::record_user( $user_id, 'updated' );
	}

	public static function on_user_role( $user_id, $role ) {
		DPT_AL_Buffer::record( 'user', (string) $role, $user_id, 'updated', self::user_login( $user_id ), array( 'role' ) );
	}

	public static function on_user_deleted( $user_id ) {
		DPT_AL_Buffer::record( 'user', '', $user_id, 'deleted' );
	}

	private static function record_user( $user_id, $action ) {
		DPT_AL_Buffer::record( 'user', '', $user_id, $action, self::user_login( $user_id ) );
	}

	private static function user_login( $user_id ) {
		$user = get_userdata( $user_id );
		return ( $user && isset( $user->user_login ) ) ? $user->user_login : '';
	}

	public static function on_plugin_activated( $plugin ) {
		DPT_AL_Buffer::record( 'plugin', (string) $plugin, 0, 'activated', (string) $plugin );
	}

	public static function on_plugin_deactivated( $plugin ) {
		DPT_AL_Buffer::record( 'plugin', (string) $plugin, 0, 'deactivated', (string) $plugin );
	}

	public static function on_theme_switched( $new_name, $new_theme = null ) {
		DPT_AL_Buffer::record( 'theme', '', 0, 'switched', (string) $new_name );
	}

	public static function on_option_updated( $option ) {
		if ( ! in_array( $option, self::watched_options(), true ) ) {
			return;
		}
		DPT_AL_Buffer::record( 'option', '', 0, 'updated', (string) $option, array( (string) $option ) );
	}

	/**
	 * Write this request's rows, then prune at most once an hour.
	 *
	 * Pruning here rather than on a scheduled event: a module that is
	 * switched off never runs init(), so it can never unschedule an event it
	 * scheduled while on, and a scheduled hook whose callback is gone is a
	 * leak nothing on the Modules screen would show. A site that is not being
	 * written to has nothing to prune.
	 *
	 * @return void
	 */
	public static function flush() {
		$rows = DPT_AL_Buffer::rows(
			DPT_AL_Channel::current(),
			DPT_AL_Channel::app_name(),
			get_current_user_id(),
			time()
		);
		DPT_AL_Buffer::reset();

		if ( empty( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			DPT_AL_Store::insert( $row );
		}

		$last = (int) get_option( 'dpt_agent_log_last_prune', 0 );
		if ( ( time() - $last ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( 'dpt_agent_log_last_prune', time(), false );
		DPT_AL_Store::prune( DPT_AL_Buffer::max_age_days(), DPT_AL_Buffer::max_rows(), time() );
	}
}
```

- [ ] **Step 4: Add `DPT_AL_Store::prune()`**

`flush()` calls a method Task 2 did not write, because Task 2 stopped at the plan and left the execution to the layer that owns the writer. Add it to `modules/agent-log/class-dpt-al-store.php`:

```php
	/**
	 * Carry out what prune_plan() described.
	 *
	 * @param int $max_age_days Days to keep, 0 or less to disable.
	 * @param int $max_rows     Rows to keep, 0 or less to disable.
	 * @param int $now          Current timestamp.
	 * @return void
	 */
	public static function prune( $max_age_days, $max_rows, $now ) {
		$writer = self::writer();
		$table  = self::table();

		foreach ( self::prune_plan( $max_age_days, $max_rows, $now ) as $step ) {
			if ( 'age' === $step['kind'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, name from the writer prefix; the value is prepared.
				$writer->query( $writer->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $step['cutoff'] ) );
				continue;
			}
			// Keep the newest $keep rows: find the id at that depth and drop
			// everything below it. One comparison against an indexed column,
			// rather than a subquery over the whole table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; the value is prepared.
			$floor = $writer->get_var( $writer->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", (int) $step['keep'] ) );
			if ( $floor ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; the value is prepared.
				$writer->query( $writer->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $floor ) );
			}
		}
	}
```

- [ ] **Step 5: Test the pruning execution**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
$writer = new DPT_AL_Test_Writer();
DPT_AL_Store::set_writer( $writer );
$writer->var = 900;
DPT_AL_Store::prune( 30, 20000, 1756108800 );
dpt_test_eq( count( $writer->queries ), 3, 'both bounds run: one delete by age, one lookup and one delete by id' );
dpt_test_ok( false !== strpos( $writer->queries[0], 'logged_at <' ), 'the age bound deletes by date' );
dpt_test_ok( false !== strpos( $writer->queries[2], 'id <= 900' ), 'and the row bound deletes below the id at the cap' );

// Nothing below the cap means nothing to delete, and no DELETE issued.
$writer->queries = array();
$writer->var     = null;
DPT_AL_Store::prune( 0, 20000, 1756108800 );
dpt_test_eq( count( $writer->queries ), 1, 'a table under the row cap issues the lookup and no delete' );

$writer->queries = array();
DPT_AL_Store::prune( 0, 0, 1756108800 );
dpt_test_eq( $writer->queries, array(), 'and both bounds disabled runs no query at all, rather than an unbounded delete' );
```

- [ ] **Step 6: Run the test and the whole suite**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
```

- [ ] **Step 7: Commit**

```bash
git add modules/agent-log/class-dpt-al-hooks.php modules/agent-log/class-dpt-al-store.php tests/agent-log-test.php
git commit -m "Agent Log: turn WordPress hooks into buffered rows, and prune on a throttle"
```

---

### Task 5: The REST endpoint

**Files:**
- Create: `modules/agent-log/class-dpt-al-rest.php`
- Modify: `modules/agent-log/class-dpt-al-store.php` (add `query()` and `count()`)
- Modify: `tests/agent-log-test.php` (append)

**Interfaces:**
- Consumes: `DPT_AL_Store::query_args()`.
- Produces:
  - `DPT_AL_Store::query( array $args )` → `array` of row objects
  - `DPT_AL_Store::count( array $args )` → `int`
  - `DPT_AL_Rest::init()`, `DPT_AL_Rest::args()`, `DPT_AL_Rest::may_read()`, `DPT_AL_Rest::handle( $request )`

- [ ] **Step 1: Add the two store methods**

Append inside `DPT_AL_Store`:

```php
	/**
	 * @param array $args Request arguments.
	 * @return array
	 */
	public static function query( $args ) {
		$parts  = self::query_args( $args );
		$writer = self::writer();
		$table  = self::table();
		$sql    = "SELECT * FROM {$table}";
		if ( '' !== $parts['where'] ) {
			$sql .= ' WHERE ' . $parts['where'];
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params = array_merge( $parts['params'], array( $parts['limit'], $parts['offset'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; every value is a placeholder filled by prepare().
		$rows = $writer->get_results( $writer->prepare( $sql, ...$params ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array $args Request arguments.
	 * @return int
	 */
	public static function count( $args ) {
		$parts  = self::query_args( $args );
		$writer = self::writer();
		$table  = self::table();
		$sql    = "SELECT COUNT(*) FROM {$table}";
		if ( '' === $parts['where'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- own table, no user input in the statement.
			return (int) $writer->get_var( $sql );
		}
		$sql .= ' WHERE ' . $parts['where'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; every value is a placeholder filled by prepare().
		return (int) $writer->get_var( $writer->prepare( $sql, ...$parts['params'] ) );
	}
```

- [ ] **Step 2: Write the failing test**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-rest.php';

/* ---- the endpoint's argument schema ---- */

$args = DPT_AL_Rest::args();
dpt_test_eq( $args['per_page']['default'], 20, 'per_page defaults to twenty' );
dpt_test_eq( $args['per_page']['maximum'], 100, 'and is capped at a hundred in the schema, not only in the store' );
dpt_test_eq( $args['channel']['enum'], array( 'rest', 'cron', 'cli', 'xmlrpc' ), 'the channel is an enum the core validator can reject against' );
dpt_test_ok( isset( $args['object_type']['enum'] ), 'and so is the object type' );

/* ---- who may read it ---- */

// The log names who changed what. edit_posts is not enough to see that.
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' );
dpt_test_ok( ! DPT_AL_Rest::may_read(), 'a user without manage_options may not read the log' );
$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_ok( DPT_AL_Rest::may_read(), 'and one with it may' );

/* ---- there is no way to erase it over the API ---- */

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_AL_Rest::init();
$methods = array();
foreach ( $GLOBALS['dpt_stub_rest_routes'] as $route ) {
	$methods[] = isset( $route['args']['methods'] ) ? $route['args']['methods'] : '';
}
dpt_test_ok( in_array( 'GET', $methods, true ), 'the route is registered for GET' );
dpt_test_ok( ! in_array( 'DELETE', $methods, true ), 'and for nothing else - a log erasable over the API is a log an attacker erases' );
```

- [ ] **Step 3: Add the route-recording stub**

The harness has no `register_rest_route`. Add to `tests/bootstrap.php` beside the other stubs:

```php
$GLOBALS['dpt_stub_rest_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['dpt_stub_rest_routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
		return true;
	}
}
```

Guard it with `function_exists` only if the REST Bridge tests already declare one — check first with `grep -n "register_rest_route" tests/bootstrap.php`. If it is already there, use it as is and do not add a second.

- [ ] **Step 4: Write the implementation**

Create `modules/agent-log/class-dpt-al-rest.php`:

```php
<?php
/**
 * Agent Log module - reading the log from outside.
 *
 * The API is the product and the screen is the bonus: the question "did the
 * agent do what I think it did" is usually asked by something that is not a
 * person sitting at wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Rest {

	const NAMESPACE_V1 = 'digitizer/v1';

	/**
	 * Registered independently of the REST Bridge module: the namespace is
	 * shared, the registration is not, so this endpoint exists whether or not
	 * that module is switched on.
	 *
	 * @return void
	 */
	public static function init() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/activity',
			array(
				// GET only. There is deliberately no route that deletes: a
				// log that can be erased through the API is a log an attacker
				// erases on the way out.
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'may_read' ),
				'args'                => self::args(),
			)
		);
	}

	/**
	 * @return array
	 */
	public static function args() {
		return array(
			'after'       => array( 'type' => 'string' ),
			'before'      => array( 'type' => 'string' ),
			'channel'     => array( 'type' => 'string', 'enum' => array( 'rest', 'cron', 'cli', 'xmlrpc' ) ),
			'object_type' => array( 'type' => 'string', 'enum' => array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' ) ),
			'object_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
			'app'         => array( 'type' => 'string' ),
			'page'        => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page'    => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
		);
	}

	/**
	 * The log names who changed what, which is more than edit_posts should
	 * reveal.
	 *
	 * @return bool
	 */
	public static function may_read() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$args = array();
		foreach ( array_keys( self::args() ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$args[ $key ] = $value;
			}
		}

		$rows  = DPT_AL_Store::query( $args );
		$total = DPT_AL_Store::count( $args );
		$parts = DPT_AL_Store::query_args( $args );

		$items = array();
		foreach ( $rows as $row ) {
			$fields = json_decode( isset( $row->fields ) ? (string) $row->fields : '[]', true );
			$items[] = array(
				'id'             => isset( $row->id ) ? (int) $row->id : 0,
				'logged_at'      => isset( $row->logged_at ) ? (string) $row->logged_at : '',
				'channel'        => isset( $row->channel ) ? (string) $row->channel : '',
				'app'            => isset( $row->app ) ? (string) $row->app : '',
				'user_id'        => isset( $row->user_id ) ? (int) $row->user_id : 0,
				'action'         => isset( $row->action ) ? (string) $row->action : '',
				'object_type'    => isset( $row->object_type ) ? (string) $row->object_type : '',
				'object_subtype' => isset( $row->object_subtype ) ? (string) $row->object_subtype : '',
				'object_id'      => isset( $row->object_id ) ? (int) $row->object_id : 0,
				'object_name'    => isset( $row->object_name ) ? (string) $row->object_name : '',
				'fields'         => is_array( $fields ) ? $fields : array(),
			);
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $parts['limit'] > 0 ? (int) ceil( $total / $parts['limit'] ) : 0 ) );
		return $response;
	}
}
```

- [ ] **Step 5: Run the test and the whole suite, then commit**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
git add modules/agent-log/class-dpt-al-rest.php modules/agent-log/class-dpt-al-store.php tests/agent-log-test.php tests/bootstrap.php
git commit -m "Agent Log: GET /digitizer/v1/activity, and nothing that erases it"
```

---

### Task 6: The module class, the schema guard and the screen

**Files:**
- Create: `modules/agent-log/class-dpt-al-module.php`
- Create: `modules/agent-log/class-dpt-al-admin.php`
- Modify: `modules/agent-log/class-dpt-al-store.php` (add `install_table()`)
- Modify: `tests/agent-log-test.php` (append)

**Interfaces:**
- Consumes: everything above.
- Produces: `DPT_Agent_Log_Module`, `DPT_AL_Admin`, `DPT_AL_Store::install_table()`, `DPT_AL_Store::SCHEMA_VERSION`.

- [ ] **Step 1: Add the schema installer to the store**

Append inside `DPT_AL_Store`, and add `const SCHEMA_VERSION = '1';` beside the other constants:

```php
	/**
	 * Create or upgrade the table.
	 *
	 * Called from the module's init(), not from the plugin's activation hook:
	 * a module that has never been switched on should not leave a table
	 * behind on every site the plugin is installed on. Guarded by a stored
	 * version so dbDelta does not run on every page load.
	 *
	 * @return void
	 */
	public static function install_table() {
		if ( get_option( 'dpt_agent_log_schema', '' ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- this module's own table, created through dbDelta.
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				logged_at DATETIME NOT NULL,
				channel VARCHAR(20) NOT NULL DEFAULT '',
				app VARCHAR(100) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(40) NOT NULL DEFAULT '',
				object_type VARCHAR(40) NOT NULL DEFAULT '',
				object_subtype VARCHAR(60) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				object_name VARCHAR(191) NOT NULL DEFAULT '',
				fields TEXT NULL,
				PRIMARY KEY  (id),
				KEY logged_at (logged_at),
				KEY object (object_type, object_id),
				KEY channel (channel)
			) {$collate};"
		);

		update_option( 'dpt_agent_log_schema', self::SCHEMA_VERSION );
	}
```

- [ ] **Step 2: Write the module class**

Create `modules/agent-log/class-dpt-al-module.php`:

```php
<?php
/**
 * Agent Log module - what the automations did to this site.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-al-channel.php';
require_once __DIR__ . '/class-dpt-al-store.php';
require_once __DIR__ . '/class-dpt-al-buffer.php';
require_once __DIR__ . '/class-dpt-al-hooks.php';
require_once __DIR__ . '/class-dpt-al-rest.php';
require_once __DIR__ . '/class-dpt-al-admin.php';

class DPT_Agent_Log_Module extends DPT_Module {

	/** @var DPT_AL_Admin */
	private $admin;

	public function id() {
		return 'agent_log';
	}

	public function title() {
		return __( 'Agent Log', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Records what automations changed on this site - anything that arrived over the REST API, WP-Cron, WP-CLI or XML-RPC. A change made by a person in the admin is not recorded at all. Each entry names who, what and when, and which fields were touched; it never stores the values. Readable at /digitizer/v1/activity and on its own screen.', 'digitizer-pro-tools' );
	}

	public function init() {
		DPT_AL_Store::install_table();
		DPT_AL_Hooks::init();
		add_action( 'rest_api_init', array( 'DPT_AL_Rest', 'init' ) );

		if ( is_admin() ) {
			$this->admin = new DPT_AL_Admin();
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
```

- [ ] **Step 3: Write the screen**

Create `modules/agent-log/class-dpt-al-admin.php`:

```php
<?php
/**
 * Agent Log module - the screen.
 *
 * The API is the product; this exists so that a person looking at a site can
 * answer the question without a terminal. Read-only apart from the one
 * button that empties the log.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Admin {

	const PAGE_SLUG = 'dpt-agent-log';

	public function __construct() {
		add_action( 'admin_post_dpt_al_clear', array( $this, 'handle_clear' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Agent Log', 'digitizer-pro-tools' ),
			__( 'Agent Log', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * A filter value from the query string, or '' when absent or malformed.
	 *
	 * sanitize_key() would hand an array-valued parameter to string functions
	 * and raise a TypeError on PHP 8, so the scalar check comes first - the
	 * same guard dpt_current_admin_page() uses for the same reason.
	 *
	 * @param string $key Parameter name.
	 * @return string
	 */
	private function filter_arg( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}

		$args = array( 'per_page' => 100 );
		foreach ( array( 'channel', 'object_type' ) as $key ) {
			$value = $this->filter_arg( $key );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		// Values outside the enums contribute nothing to the query, so a
		// hand-edited URL narrows the list or does nothing - never widens it.
		$rows   = DPT_AL_Store::query( $args );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Log', 'digitizer-pro-tools' ); ?></h1>
			<p><?php esc_html_e( 'Changes that arrived from somewhere other than a browser: the REST API, WP-Cron, WP-CLI or XML-RPC. Work done by a person in the admin is not recorded here.', 'digitizer-pro-tools' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="channel">
					<option value=""><?php esc_html_e( 'Every channel', 'digitizer-pro-tools' ); ?></option>
					<?php foreach ( array( 'rest', 'cron', 'cli', 'xmlrpc' ) as $channel ) : ?>
						<option value="<?php echo esc_attr( $channel ); ?>" <?php selected( isset( $args['channel'] ) ? $args['channel'] : '', $channel ); ?>><?php echo esc_html( $channel ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="object_type">
					<option value=""><?php esc_html_e( 'Everything', 'digitizer-pro-tools' ); ?></option>
					<?php foreach ( array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' ) as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( isset( $args['object_type'] ) ? $args['object_type'] : '', $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'digitizer-pro-tools' ); ?></button>
			</form>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Application', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'What', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'digitizer-pro-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet. Either no automation has changed anything here, or the module was switched on after it did.', 'digitizer-pro-tools' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$fields = json_decode( isset( $row->fields ) ? (string) $row->fields : '[]', true );
						$stamp  = strtotime( ( isset( $row->logged_at ) ? $row->logged_at : '' ) . ' UTC' );
						?>
						<tr>
							<td><?php echo esc_html( $stamp ? date_i18n( $format, $stamp ) : '' ); ?></td>
							<td><?php echo esc_html( isset( $row->channel ) ? $row->channel : '' ); ?></td>
							<td><?php echo esc_html( isset( $row->app ) && '' !== $row->app ? $row->app : '-' ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: action, 2: object type, 3: object name or id */
									esc_html__( '%1$s %2$s %3$s', 'digitizer-pro-tools' ),
									esc_html( isset( $row->action ) ? $row->action : '' ),
									esc_html( isset( $row->object_subtype ) && '' !== $row->object_subtype ? $row->object_subtype : ( isset( $row->object_type ) ? $row->object_type : '' ) ),
									esc_html( isset( $row->object_name ) && '' !== $row->object_name ? $row->object_name : '#' . ( isset( $row->object_id ) ? (int) $row->object_id : 0 ) )
								);
								?>
							</td>
							<td><?php echo esc_html( is_array( $fields ) ? implode( ', ', array_map( 'strval', $fields ) ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dpt_al_clear' ); ?>
				<input type="hidden" name="action" value="dpt_al_clear" />
				<p><button type="submit" class="button"><?php esc_html_e( 'Clear the log', 'digitizer-pro-tools' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_al_clear' );

		DPT_AL_Store::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
```

Add the method it calls to `DPT_AL_Store`:

```php
	/**
	 * Empty the log.
	 *
	 * DELETE rather than TRUNCATE: TRUNCATE is a schema change on some
	 * configurations, cannot be rolled back, and this is reached from a
	 * button on an admin screen.
	 *
	 * @return void
	 */
	public static function clear() {
		$writer = self::writer();
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- own table, no user input in the statement.
		$writer->query( "DELETE FROM {$table}" );
	}
```

- [ ] **Step 4: Test the schema guard**

Append to `tests/agent-log-test.php`, above `dpt_test_summary();`:

```php
// dbDelta runs once per schema version, not on every page load.
$GLOBALS['dpt_stub_options'] = array( 'dpt_agent_log_schema' => DPT_AL_Store::SCHEMA_VERSION );
$GLOBALS['dpt_stub_dbdelta_calls'] = 0;
DPT_AL_Store::install_table();
dpt_test_eq( $GLOBALS['dpt_stub_dbdelta_calls'], 0, 'a table already at this schema version is not rebuilt' );
```

Add to `tests/bootstrap.php`:

```php
$GLOBALS['dpt_stub_dbdelta_calls'] = 0;
function dbDelta( $sql ) { $GLOBALS['dpt_stub_dbdelta_calls']++; return array(); }
```

- [ ] **Step 5: Run everything and commit**

```bash
php tests/agent-log-test.php
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
~/.composer/vendor/bin/phpcs --standard=WordPress-Core --extensions=php modules/agent-log/
git add modules/agent-log tests
git commit -m "Agent Log: the module, its table and its screen"
```

---

### Task 7: Register the module, translate it, ship 1.30.0

**Files:**
- Modify: `includes/class-dpt-plugin.php`
- Modify: `languages/digitizer-pro-tools.pot`, `-he_IL.po`, `-he_IL.l10n.php`, `-he_IL.mo`
- Modify: `readme.txt`, `digitizer-pro-tools.php`

- [ ] **Step 1: Add the registry entry**

In `includes/class-dpt-plugin.php`, inside `registry()`, beside the others:

```php
			'agent_log' => array(
				'file'    => DPT_PATH . 'modules/agent-log/class-dpt-al-module.php',
				'class'   => 'DPT_Agent_Log_Module',
				'default' => '0',
			),
```

- [ ] **Step 2: Extract the new strings**

```bash
python3 - <<'PY'
import re, io, glob
found = []
for path in glob.glob('modules/agent-log/*.php'):
    src = io.open(path, encoding='utf-8').read()
    found += re.findall(r"(?:__|esc_html__|esc_attr__|esc_html_e|_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'digitizer-pro-tools'\s*\)", src)
pot = io.open('languages/digitizer-pro-tools.pot', encoding='utf-8').read()
have = set(re.findall(r'^msgid "(.*)"$', pot, re.M))
for f in dict.fromkeys(found):
    if f.replace("\\'", "'") not in have:
        print(f)
PY
```

- [ ] **Step 3: Add each string to all three catalogs**

Append to `.pot` as `msgid "..."` / `msgstr ""`, to `-he_IL.po` as `msgid` / `msgstr "<Hebrew>"`, and to the `messages` array in `-he_IL.l10n.php` as `'<en>' => '<he>',`. Escape `'` and `\` for the PHP file and `"` and `\` for the PO files. Use the vocabulary already in the catalog: WordPress is `וורדפרס`, a major release is `גרסת מייג'ור`.

**No plural forms.** This catalog has none; adding the first one puts a new shape into three hand-maintained files. Word every string so it does not need one.

- [ ] **Step 4: Compile and verify the three catalogs agree**

```bash
msgfmt -o languages/digitizer-pro-tools-he_IL.mo languages/digitizer-pro-tools-he_IL.po
msgfmt --statistics -o /dev/null languages/digitizer-pro-tools-he_IL.po
php -l languages/digitizer-pro-tools-he_IL.l10n.php
ls messages.mo 2>/dev/null && echo "STRAY CATALOG - delete it, it must never be committed"
```

Then confirm the key sets are identical, exactly as `.github`-less repos have to do by hand:

```bash
php -r '
$po = file_get_contents("languages/digitizer-pro-tools-he_IL.po");
$pot = file_get_contents("languages/digitizer-pro-tools.pot");
preg_match_all("/^msgid \"(.*)\"$/m", $po, $a); preg_match_all("/^msgid \"(.*)\"$/m", $pot, $b);
$po_keys = array_slice($a[1],1); $pot_keys = array_slice($b[1],1);
$l = require "languages/digitizer-pro-tools-he_IL.l10n.php";
sort($po_keys); sort($pot_keys);
$lk = array_map(function($k){ return addcslashes($k, "\"\\\\"); }, array_keys($l["messages"])); sort($lk);
printf("po=%d pot=%d l10n=%d  po==pot:%s  po==l10n:%s\n", count($po_keys), count($pot_keys), count($lk), $po_keys===$pot_keys?"yes":"NO", $po_keys===$lk?"yes":"NO");'
```

Expected: all three counts equal, both comparisons `yes`.

- [ ] **Step 5: Bump the version and write the changelog**

In `digitizer-pro-tools.php` set the header `Version:` and `DPT_VERSION` to `1.30.0`. In `readme.txt` set `Stable tag: 1.30.0` and add above `= 1.29.0 =`:

```
= 1.30.0 =
* New module: Agent Log - records what automations changed on this site. Anything that arrived over the REST API, WP-Cron, WP-CLI or XML-RPC is recorded; a change made by a person in the admin is not recorded at all
* Each entry names who, what and when, and which fields were touched - never the values, so the log never becomes a second copy of the site's content
* Readable at /digitizer/v1/activity, which requires the capability to manage options, and on the module's own screen. Nothing erases it over the API
* Entries are kept for thirty days or twenty thousand rows, whichever comes first. Both limits are filterable, and switching the module off leaves the log where it is

```

- [ ] **Step 6: Run everything, then commit**

```bash
for t in tests/*-test.php; do printf '%-30s ' "$t"; php "$t" | tail -1; done
~/.composer/vendor/bin/phpcs --standard=WordPress-Core --extensions=php modules/agent-log/
git add -A
git commit -m "Register the Agent Log module and release 1.30.0"
```

---

## Self-review

**Spec coverage.** Storage and DDL: Task 2 and Task 6 Step 1. Three indexes, no IP: the DDL in Task 6. Two retention bounds, each disabled by zero: Task 2 `prune_plan()`, Task 3 `max_age_days()`/`max_rows()`, Task 4 `prune()`. Deactivation not dropping the table: nothing in the plan drops it — there is no deactivation hook at all, which is the requirement. Fact plus field names, never values: Task 2 `insert()` and Task 3 `record()`. One row per object per request: Task 3. Scope table: Task 4 `init()` covers post, attachment, term, user, plugin, theme, option. Reads never recorded: Task 1 `is_read_request()`, enforced in Task 4 `init()`. Option allowlist and its filter: Task 4. Channel detection and precedence: Task 1. Application-password verification: Task 0, implemented in Task 1 Step 4. REST endpoint, permission, parameters, headers, no delete: Task 5. Screen: Task 6. Registry, i18n, version: Task 7.

**The one spec item this plan changes:** daily cron becomes an hourly throttle on flush, argued above under *Deviation from the spec*.

**The one spec item this plan cannot close:** the application-password mechanism. Task 0 exists to close it, and Task 1 ships a correct-but-empty `app_name()` if it cannot be closed — which is the spec's stated fallback, not a silent failure.

**Type consistency.** `DPT_AL_Store::query_args()` returns `where`/`params`/`limit`/`offset` and is consumed under those names in `query()`, `count()` and `handle()`. `prune_plan()` returns entries with `kind` plus `cutoff` or `keep`, consumed under those names in `prune()`. `DPT_AL_Buffer::record()` takes `( $type, $subtype, $id, $action, $name, $fields )` in that order at every one of its fifteen call sites in Task 4. `rows()` returns entries whose keys are exactly the column names `insert()` expects.
