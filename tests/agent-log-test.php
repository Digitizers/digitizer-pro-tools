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

// A UUID that resolves to a record: the name comes back, and a User-Agent
// header present at the same time proves it was ignored rather than used.
$_SERVER['HTTP_USER_AGENT']            = 'ContentEngine/1.0';
$GLOBALS['dpt_stub_app_password_uuid'] = 'uuid-1';
$GLOBALS['dpt_stub_app_passwords']     = array(
	'uuid-1' => array( 'name' => 'ContentEngine' ),
);
dpt_test_eq( DPT_AL_Channel::app_name(), 'ContentEngine', 'a resolved application password returns its name, not the User-Agent' );

// A UUID that authenticated the request but no longer resolves to a record
// (e.g. deleted between authentication and shutdown).
$GLOBALS['dpt_stub_app_password_uuid'] = 'uuid-missing';
$GLOBALS['dpt_stub_app_passwords']     = array();
dpt_test_eq( DPT_AL_Channel::app_name(), '', 'a UUID with no matching record has no app name' );

// No application password authenticated this request at all.
$GLOBALS['dpt_stub_app_password_uuid'] = null;
dpt_test_eq( DPT_AL_Channel::app_name(), '', 'an unidentified caller has no app name, and the User-Agent is not one' );

unset( $_SERVER['HTTP_USER_AGENT'] );
$GLOBALS['dpt_stub_app_password_uuid'] = null;
$GLOBALS['dpt_stub_app_passwords']     = array();

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

/* ---- objects without an id key on what identifies them, not on 0 ---- */

// Two plugins activated in one request are two objects, not one - keying on
// id 0 for both would collapse the second's name onto the first's row.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'hello-dolly/hello.php', 0, 'activated', 'Hello Dolly' );
$rows  = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
dpt_test_eq( count( $rows ), 2, 'two plugins activated in one request are two rows' );
dpt_test_ok( in_array( 'Akismet', $names, true ) && in_array( 'Hello Dolly', $names, true ), 'each carrying its own name' );

// Two watched options updated in one request are likewise two rows.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'option', '', 0, 'updated', 'siteurl' );
DPT_AL_Buffer::record( 'option', '', 0, 'updated', 'blogname' );
$rows  = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
dpt_test_eq( count( $rows ), 2, 'two options updated in one request are two rows' );
dpt_test_ok( in_array( 'siteurl', $names, true ) && in_array( 'blogname', $names, true ), 'one per option name' );

/* ---- state-change actions outrank a later update, same as create/delete ---- */

DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'updated', 'Akismet' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'activated', 'activated recorded before updated on the same key keeps activated' );

DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
DPT_AL_Buffer::record( 'theme', '', 0, 'updated', 'Twenty Twenty-Five' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'switched', 'switched is not overwritten by a later updated' );

DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'updated', 'Akismet' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deactivated', 'deactivated is not overwritten by a later updated' );

/* ---- retention settings ---- */

$GLOBALS['dpt_stub_filters'] = array();
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 30, 'the age bound defaults to thirty days' );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 20000, 'and the row bound to twenty thousand' );

add_filter( 'dpt_agent_log_max_age_days', function () { return 7; } );
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 7, 'both are filterable' );
add_filter( 'dpt_agent_log_max_rows', function () { return 0; } );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 0, 'including down to zero, which disables that bound' );
$GLOBALS['dpt_stub_filters'] = array();

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

/* ---- pruning execution ---- */

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

/* ---- hook callbacks, smoke-tested through the buffer ---- */
// The brief only required tests for the two pure statics and Store::prune().
// The design spec's Testing section additionally names "meta names
// accumulated across several hook fires in one request collapsing to one
// row" as required coverage, and nothing proves the record() call sites pass
// their arguments in the right positions - which is exactly what the
// buffer's id-less keying (rewritten a task ago) depends on getting right.

// 1. on_post_saved() on an update.
DPT_AL_Buffer::reset();
$post_before = (object) array(
	'post_title'    => 'Old Title',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-01-01 00:00:00',
);
$post_after = (object) array(
	'post_type'     => 'post',
	'post_title'    => 'New Title',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-08-25 00:00:00',
);
DPT_AL_Hooks::on_post_saved( 601, $post_after, true, $post_before );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( count( $rows ), 1, 'on_post_saved() writes one row' );
dpt_test_eq( $rows[0]['object_type'], 'post', 'as an object_type of post' );
dpt_test_eq( $rows[0]['object_subtype'], 'post', 'the post type as subtype' );
dpt_test_eq( $rows[0]['object_id'], 601, 'the post id' );
dpt_test_eq( $rows[0]['action'], 'updated', 'action updated for $update = true' );
dpt_test_eq( $rows[0]['object_name'], 'New Title', 'the title as object_name' );
dpt_test_eq( $rows[0]['fields'], array( 'post_title' ), 'and only the column that actually changed' );

// 2. The accumulation case the spec names by name: a save plus three meta
// writes on the same post collapse to one row carrying all four field names.
DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_posts'][602] = array(
	'post_type'   => 'post',
	'post_title'  => 'Accumulated',
	'post_status' => 'publish',
);
$before = (object) array(
	'post_title'    => 'Was Accumulated',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-01-01 00:00:00',
);
$after = (object) array(
	'post_type'     => 'post',
	'post_title'    => 'Accumulated',
	'post_content'  => 'Body',
	'post_status'   => 'publish',
	'post_modified' => '2026-08-25 00:00:00',
);
DPT_AL_Hooks::on_post_saved( 602, $after, true, $before );
DPT_AL_Hooks::on_post_meta( 101, 602, 'rank_math_title' );
DPT_AL_Hooks::on_post_meta( 102, 602, '_elementor_data' );
DPT_AL_Hooks::on_post_meta( 103, 602, 'custom_field' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( count( $rows ), 1, 'a save plus three meta writes on one post collapse to one row' );
dpt_test_eq( count( $rows[0]['fields'] ), 4, 'carrying all four field names' );
foreach ( array( 'post_title', 'rank_math_title', '_elementor_data', 'custom_field' ) as $expected_field ) {
	dpt_test_ok( in_array( $expected_field, $rows[0]['fields'], true ), "including {$expected_field}" );
}

// 3. on_post_saved() is silent on a revision, and on an autosave - and test 1
// above already proves the guard is not simply an always-return.
DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_posts'][603] = array( 'post_type' => 'revision', 'post_parent' => 602 );
DPT_AL_Hooks::on_post_saved( 603, (object) array( 'post_type' => 'revision' ), false, null );
dpt_test_eq( DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'a revision records nothing' );

DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_autosaves'] = array( 604 );
DPT_AL_Hooks::on_post_saved( 604, (object) array( 'post_type' => 'post' ), false, null );
dpt_test_eq( DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'and an autosave records nothing either' );
$GLOBALS['dpt_stub_autosaves'] = array();

// 4. on_post_deleted() records deleted, and an attachment is object_type
// attachment rather than post - through both on_post_saved and on_post_deleted.
DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_post_deleted( 605, (object) array( 'post_type' => 'post', 'post_title' => 'Gone' ) );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'on_post_deleted() records deleted' );
dpt_test_eq( $rows[0]['object_type'], 'post', 'a plain post stays object_type post' );

DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_post_deleted( 606, (object) array( 'post_type' => 'attachment', 'post_title' => 'Image' ) );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['object_type'], 'attachment', 'a deleted attachment is object_type attachment' );

DPT_AL_Buffer::reset();
$attachment_after = (object) array(
	'post_type'     => 'attachment',
	'post_title'    => 'New Image',
	'post_content'  => '',
	'post_status'   => 'inherit',
	'post_modified' => '2026-08-25 00:00:00',
);
DPT_AL_Hooks::on_post_saved( 607, $attachment_after, false, null );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['object_type'], 'attachment', 'a saved attachment is object_type attachment too' );
dpt_test_eq( $rows[0]['action'], 'created', 'and action created for $update = false' );

// 5. Term hooks: edited records term with the taxonomy and the term's current
// name; deleted takes its name from the object it is handed, since the term
// is already gone by the time delete_term fires.
DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_terms'][30] = array( 'name' => 'News' );
DPT_AL_Hooks::on_term_edited( 30, 300, 'category' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['object_type'], 'term', 'on_term_edited() records object_type term' );
dpt_test_eq( $rows[0]['object_subtype'], 'category', 'the taxonomy as subtype' );
dpt_test_eq( $rows[0]['object_name'], 'News', "the term's current name" );
dpt_test_eq( $rows[0]['action'], 'updated', 'action updated' );

DPT_AL_Buffer::reset();
$deleted_term = (object) array( 'name' => 'Old Category' );
DPT_AL_Hooks::on_term_deleted( 31, 301, 'category', $deleted_term );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'on_term_deleted() records deleted' );
dpt_test_eq( $rows[0]['object_name'], 'Old Category', 'taking the name from the deleted-term object, not a lookup' );

// 6. User hooks.
DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_users'][70] = array( 'user_login' => 'agent-seven' );
DPT_AL_Hooks::on_user_role( 70, 'editor' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['object_subtype'], 'editor', 'on_user_role() records the role as subtype' );
dpt_test_eq( $rows[0]['fields'], array( 'role' ), "and 'role' in fields" );
dpt_test_eq( $rows[0]['object_name'], 'agent-seven', "the user's login" );

DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_user_created( 71 );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'created', 'on_user_created() records created' );

DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_user_updated( 71 );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'updated', 'on_user_updated() records updated' );

DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_user_deleted( 71 );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'on_user_deleted() records deleted' );

// 7. The id-less keying, proven through the real call sites: two plugins
// activated in one request are two rows with different names, and so are two
// watched options updated in one request. This is the exact thing that broke
// silently a task ago.
DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_plugin_activated( 'akismet/akismet.php' );
DPT_AL_Hooks::on_plugin_activated( 'hello-dolly/hello.php' );
$rows  = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
dpt_test_eq( count( $rows ), 2, 'two plugins activated through the real hooks are two rows' );
dpt_test_ok( in_array( 'akismet/akismet.php', $names, true ) && in_array( 'hello-dolly/hello.php', $names, true ), 'each carrying its own file as its name' );

DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_option_updated( 'siteurl' );
DPT_AL_Hooks::on_option_updated( 'blogname' );
$rows  = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
dpt_test_eq( count( $rows ), 2, 'two watched options updated through the real hook are two rows' );
dpt_test_ok( in_array( 'siteurl', $names, true ) && in_array( 'blogname', $names, true ), 'each carrying its own option name' );

// An unwatched option updated through the real hook records nothing at all.
DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_option_updated( '_transient_doing_cron' );
dpt_test_eq( DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 ), array(), 'an option outside the allowlist records nothing' );

// 8. init() registers nothing for a browser request, nothing for a read, and
// something for a real write - each checked against a reset registry so
// "registers nothing" cannot pass vacuously.
$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );
DPT_AL_Hooks::init();
dpt_test_eq( $GLOBALS['dpt_stub_filters'], array(), 'init() on a browser request (no channel) registers nothing' );

$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_rest_request'] = true;
$_SERVER['REQUEST_METHOD']        = 'GET';
DPT_AL_Hooks::init();
dpt_test_eq( $GLOBALS['dpt_stub_filters'], array(), 'init() on a real channel that is only a read also registers nothing' );

$GLOBALS['dpt_stub_filters'] = array();
$_SERVER['REQUEST_METHOD']   = 'POST';
DPT_AL_Hooks::init();
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_filters'] ), 'init() on a real write registers something' );

$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

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
$key = 'digitizer/v1/activity';
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes'][ $key ] ), 'the route is registered under digitizer/v1/activity' );
$methods = array();
foreach ( $GLOBALS['dpt_stub_rest_routes'][ $key ] as $registered ) {
	$methods[] = isset( $registered['methods'] ) ? $registered['methods'] : '';
}
dpt_test_eq( $methods, array( 'GET' ), 'for GET and nothing else - a log erasable over the API is a log an attacker erases' );

/* ---- handle(): the endpoint's actual output shape ---- */

$writer = new DPT_AL_Test_Writer();
DPT_AL_Store::set_writer( $writer );

// A normal row, a row whose fields column is malformed JSON, and a row
// whose fields column is null - the two ways a column that is only ever
// written by this module's own insert() could still arrive broken.
$row_normal = (object) array(
	'id'             => 5,
	'logged_at'      => '2026-08-25 10:00:00',
	'channel'        => 'rest',
	'app'            => 'seo-bot',
	'user_id'        => '812', // a numeric string on purpose: handle() must cast it.
	'action'         => 'updated',
	'object_type'    => 'post',
	'object_subtype' => 'page',
	'object_id'      => '20',
	'object_name'    => 'Homepage',
	'fields'         => wp_json_encode( array( 'post_content', 'rank_math_title' ) ),
);
$row_malformed = (object) array(
	'id'             => 6,
	'logged_at'      => '2026-08-25 10:05:00',
	'channel'        => 'cron',
	'app'            => '',
	'user_id'        => 0,
	'action'         => 'created',
	'object_type'    => 'option',
	'object_subtype' => '',
	'object_id'      => 0,
	'object_name'    => 'siteurl',
	'fields'         => 'not json at all',
);
$row_null_fields = (object) array(
	'id'             => 7,
	'logged_at'      => '2026-08-25 10:06:00',
	'channel'        => 'cron',
	'app'            => '',
	'user_id'        => 0,
	'action'         => 'created',
	'object_type'    => 'option',
	'object_subtype' => '',
	'object_id'      => 0,
	'object_name'    => 'blogname',
	'fields'         => null,
);

$writer->rows = array( $row_normal, $row_malformed, $row_null_fields );
$writer->var  = 45; // deliberately not a multiple of the 20-per-page default.

$response = DPT_AL_Rest::handle( new WP_REST_Request( array() ) );
$data     = $response->get_data();

dpt_test_eq( $data[0]['fields'], array( 'post_content', 'rank_math_title' ), 'fields comes back as the decoded array of names, not the JSON string' );
dpt_test_eq( $data[1]['fields'], array(), 'malformed JSON in the fields column comes back as an empty array' );
dpt_test_eq( $data[2]['fields'], array(), 'and a null fields column comes back as an empty array too, not null or false' );

dpt_test_eq( $data[0]['id'], 5, 'id is an int' );
dpt_test_eq( $data[0]['object_id'], 20, 'so is a numeric-string object_id, cast rather than passed through' );
dpt_test_eq( $data[0]['user_id'], 812, 'and a numeric-string user_id casts to an int too' );
dpt_test_eq( $data[0]['channel'], 'rest', 'while channel stays a string' );
dpt_test_eq( $data[0]['object_name'], 'Homepage', 'and object_name carries the value untouched' );

$headers = $response->get_headers();
dpt_test_eq( $headers['X-WP-Total'], '45', 'X-WP-Total carries the store total' );
dpt_test_eq( $headers['X-WP-TotalPages'], '3', '45 rows at 20 per page is 3 pages, rounded up rather than floored' );

/* ---- only the parameters actually present reach the store ---- */

$writer->queries = array();
DPT_AL_Rest::handle( new WP_REST_Request( array() ) );
$no_param_sql = implode( ' ', $writer->queries );
dpt_test_ok( false === strpos( $no_param_sql, 'WHERE' ), 'a request with no parameters builds no WHERE clause' );

$writer->queries = array();
DPT_AL_Rest::handle( new WP_REST_Request( array( 'channel' => 'cron' ) ) );
$channel_param_sql = implode( ' ', $writer->queries );
dpt_test_ok( false !== strpos( $channel_param_sql, "WHERE channel = 'cron'" ), 'and one carrying a channel filters the store query by it' );

dpt_test_summary();
