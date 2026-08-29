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

/* ---- retention settings ---- */

$GLOBALS['dpt_stub_filters'] = array();
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 30, 'the age bound defaults to thirty days' );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 20000, 'and the row bound to twenty thousand' );

add_filter( 'dpt_agent_log_max_age_days', function () { return 7; } );
dpt_test_eq( DPT_AL_Buffer::max_age_days(), 7, 'both are filterable' );
add_filter( 'dpt_agent_log_max_rows', function () { return 0; } );
dpt_test_eq( DPT_AL_Buffer::max_rows(), 0, 'including down to zero, which disables that bound' );
$GLOBALS['dpt_stub_filters'] = array();

dpt_test_summary();
