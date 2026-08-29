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

/* ---- the channels that can be named before plugins_loaded ---- */

// Most hosts disable WP-Cron and have a system cron fetch wp-cron.php, which
// is a GET. wp-cron.php defines DOING_CRON (line 42) before it requires
// wp-load.php, so wp_doing_cron() is already true at plugins_loaded and the
// verb must not be allowed to hide the channel.
$_SERVER['REQUEST_METHOD']      = 'GET';
$GLOBALS['dpt_stub_doing_cron'] = true;
dpt_test_ok( DPT_AL_Channel::is_early_channel(), 'a GET-triggered cron run is a channel we can already name' );

$GLOBALS['dpt_stub_doing_cron'] = false;
dpt_test_ok( ! DPT_AL_Channel::is_early_channel(), 'a plain browser request is not' );

// REST is deliberately outside this list: core defines REST_REQUEST in
// rest_api_loaded() on 'parse_request' (wp-includes/rest-api.php line 478),
// long after plugins_loaded, so it cannot be named that early. The stub
// standing in for it being true here proves the list is not just "any
// channel at all".
$GLOBALS['dpt_stub_rest_request'] = true;
dpt_test_ok( ! DPT_AL_Channel::is_early_channel(), 'and REST is not, because REST_REQUEST does not exist yet at plugins_loaded' );
$GLOBALS['dpt_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

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

/* ---- values too long for their column are cut, not dropped ---- */

// Under MySQL strict mode - the default on 5.7 and 8.0 - an overlong value
// errors the whole INSERT, and flush() ignores the return value, so the
// change vanishes from the log with no trace. Plugin basenames routinely
// exceed object_subtype's 60 characters.
$writer->inserted = array();
DPT_AL_Store::insert(
	array(
		'channel'        => str_repeat( 'c', 40 ),
		'app'            => str_repeat( 'a', 200 ),
		'action'         => str_repeat( 'x', 80 ),
		'object_subtype' => 'contact-form-7-extension-for-mailchimp/contact-form-7-extension-for-mailchimp.php',
		'object_name'    => str_repeat( 'n', 400 ),
	)
);
$too_long = $writer->inserted[0]['data'];
dpt_test_eq( strlen( $too_long['object_subtype'] ), 60, 'an 81-character plugin basename is cut to object_subtype(60)' );
dpt_test_eq( strlen( $too_long['object_name'] ), 191, 'a long title is cut to object_name(191)' );
dpt_test_eq( strlen( $too_long['app'] ), 100, 'a long app name is cut to app(100)' );
dpt_test_eq( strlen( $too_long['action'] ), 40, 'a long action is cut to action(40)' );
dpt_test_eq( strlen( $too_long['channel'] ), 20, 'and a long channel to channel(20)' );

// And a value that fits is written whole, so the bound is a bound rather
// than an unconditional trim.
$writer->inserted = array();
DPT_AL_Store::insert( array( 'object_name' => 'About us', 'object_subtype' => 'page' ) );
dpt_test_eq( $writer->inserted[0]['data']['object_name'], 'About us', 'a value inside its column is untouched' );
dpt_test_eq( $writer->inserted[0]['data']['object_subtype'], 'page', 'and so is a short subtype' );

// substr() on multi-byte text cuts mid-character and writes invalid UTF-8,
// which is worse than the overlong value it was fixing.
$writer->inserted = array();
DPT_AL_Store::insert( array( 'object_name' => str_repeat( 'ת', 300 ) ) );
$mb_name = $writer->inserted[0]['data']['object_name'];
dpt_test_eq( mb_strlen( $mb_name, 'UTF-8' ), 191, 'a multi-byte name is cut to 191 characters, not 191 bytes' );
dpt_test_ok( mb_check_encoding( $mb_name, 'UTF-8' ), 'and what is written is still valid UTF-8, not a half character' );
// object_name's bound is 191, an odd number of characters over two-byte
// Hebrew, so byte 191 lands in the middle of the 96th character. substr()
// would stop there and write a lone lead byte; there is no substr() fallback
// any more, because inside WordPress mb_substr() always exists - core
// polyfills it in wp-includes/compat.php, line 256.
dpt_test_eq( strlen( $mb_name ), 382, 'a 191-character Hebrew name is 382 bytes, so the cut was made in characters and not in bytes' );
dpt_test_eq( $mb_name, str_repeat( 'ת', 191 ), 'and what survives is the first 191 characters, whole' );

// A Hebrew title that fits is written exactly as it came, so the multi-byte
// path is a bound and not a mangling.
$writer->inserted = array();
DPT_AL_Store::insert( array( 'object_name' => 'שלום עולם' ) );
dpt_test_eq( $writer->inserted[0]['data']['object_name'], 'שלום עולם', 'a short Hebrew title is untouched' );

/* ---- a field list too long for TEXT is shortened, not left to lose the row ---- */

// The same failure the per-column bounds fixed, on the one column that has no
// character width: fields is TEXT, 65,535 bytes, and one import touching a few
// hundred long meta keys on a single object passes that. Strict mode then
// errors the INSERT and flush() ignores the return value, so the log loses the
// fact that the object changed at all.
$writer->inserted = array();
$many             = array();
for ( $i = 0; $i < 2000; $i++ ) {
	$many[] = '_a_fairly_long_plugin_meta_key_name_number_' . $i;
}
DPT_AL_Store::insert( array( 'fields' => $many ) );
$encoded = $writer->inserted[0]['data']['fields'];
dpt_test_ok( strlen( wp_json_encode( $many ) ) > DPT_AL_Store::MAX_FIELDS_BYTES, 'the unbounded encoding of 2000 long meta keys really does exceed the budget' );
dpt_test_ok( strlen( $encoded ) <= DPT_AL_Store::MAX_FIELDS_BYTES, 'what is written is inside the budget' );
$decoded = json_decode( $encoded, true );
dpt_test_ok( is_array( $decoded ), 'and still decodes to an array, which is what the endpoint and the screen both require' );
dpt_test_ok( count( $decoded ) > 500, 'keeping as many names as fit rather than giving up on the list' );
dpt_test_ok( count( $decoded ) < count( $many ), 'but not all of them, since all of them would not fit' );
dpt_test_eq( $decoded[0], $many[0], 'the names kept are the leading ones, in order' );
dpt_test_eq( $decoded[ count( $decoded ) - 1 ], $many[ count( $decoded ) - 1 ], 'up to wherever the budget ran out' );

// A list that fits is written whole, so the budget is a bound and not a trim.
$writer->inserted = array();
DPT_AL_Store::insert( array( 'fields' => array( 'post_content', 'rank_math_title' ) ) );
dpt_test_eq( $writer->inserted[0]['data']['fields'], '["post_content","rank_math_title"]', 'a field list that fits is written exactly as it came' );

// Bytes, not characters. A Hebrew meta key costs two bytes a character in the
// column and one in mb_strlen(), and on a real site more still: core's
// wp_json_encode() defaults its flags to 0 and escapes non-ASCII to \uXXXX,
// six bytes a character. (The stub in tests/bootstrap.php passes
// JSON_UNESCAPED_UNICODE, so what is measured here is the two-byte case - the
// gentler of the two, which means the real column has more headroom than this
// test proves, not less.) Either way, counting characters, or counting the
// names before they were encoded, leaves the overflow in place.
$writer->inserted = array();
$hebrew           = array();
for ( $i = 0; $i < 4000; $i++ ) {
	$hebrew[] = str_repeat( 'ת', 20 ) . $i;
}
DPT_AL_Store::insert( array( 'fields' => $hebrew ) );
$encoded = $writer->inserted[0]['data']['fields'];
$decoded = json_decode( $encoded, true );
dpt_test_ok( strlen( $encoded ) <= DPT_AL_Store::MAX_FIELDS_BYTES, 'a list of non-ASCII names is bounded by its encoded bytes' );
dpt_test_ok( is_array( $decoded ) && count( $decoded ) > 0, 'and decodes to a non-empty array of names' );
dpt_test_ok( count( $decoded ) < count( $hebrew ), 'with the tail dropped, because the escaped bytes ran out before the names did' );
dpt_test_ok( mb_check_encoding( $decoded[ count( $decoded ) - 1 ], 'UTF-8' ), 'and the last name kept is a whole name, not a cut one' );
dpt_test_eq( $decoded[0], $hebrew[0], 'the first Hebrew name survives intact' );
// What was written fills the byte budget but is nowhere near it in
// characters, which is the whole difference: a bound that counted characters
// would have gone on adding names until the column had long since overflowed.
dpt_test_ok( strlen( $encoded ) > DPT_AL_Store::MAX_FIELDS_BYTES - 200, 'the bytes written fill the budget' );
// Once wp_json_encode() escapes non-ASCII to \uXXXX, the encoded string
// itself is all ASCII, so its own mb_strlen() is no longer a useful
// contrast - it tracks strlen() almost exactly. The contrast that matters is
// against the *names before encoding*: a bound that counted their characters
// instead of the encoded bytes would have believed it had far more room than
// it did, and kept adding names well past where the real, escaped budget ran
// out.
$kept_chars = array_sum( array_map( function ( $name ) { return mb_strlen( $name, 'UTF-8' ); }, $decoded ) );
dpt_test_ok( $kept_chars < DPT_AL_Store::MAX_FIELDS_BYTES - 10000, 'while the character count of the names actually kept is far short of the byte budget, which is the room a character-counting bound would have kept filling' );

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

/* ---- between two state changes of equal rank, the later one is the truth ---- */

// activated, deactivated and switched all rank the same, so rank alone cannot
// separate them: what separates them is that one of them happened last. A
// plugin activated and then deactivated in one request is deactivated at the
// end of it, and a row saying "activated" is the log contradicting the site.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deactivated', 'activated then deactivated in one request keeps deactivated' );

DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'activated', 'Akismet' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'activated', 'and deactivated then activated keeps activated, the other way round' );

// Two switches in one request do not meet in the buffer at all: switch_theme
// carries the theme being switched *to*, and a theme has no id and no
// subtype, so it keys on that name. Two names are two keys, and the tie-break
// never sees them - each row names the theme it is about, which is not a lie,
// just two facts instead of one.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Four' );
DPT_AL_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
$rows  = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
$names = array_column( $rows, 'object_name' );
dpt_test_eq( count( $rows ), 2, 'two switches to different themes key on the theme name, so they are two rows' );
dpt_test_eq( $names, array( 'Twenty Twenty-Four', 'Twenty Twenty-Five' ), 'each naming the theme it switched to, in the order they happened' );

// Where two equal-ranked records DO meet on one key, the later one is kept -
// a theme switched and then reported switched again is still switched, and a
// 'switched' arriving after an 'activated' on the same key replaces it rather
// than being ignored for tying.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'theme', '', 0, 'activated', 'Twenty Twenty-Five' );
DPT_AL_Buffer::record( 'theme', '', 0, 'switched', 'Twenty Twenty-Five' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( count( $rows ), 1, 'two records for one theme name are one row' );
dpt_test_eq( $rows[0]['action'], 'switched', 'and the later of two equal-ranked actions is the one kept' );

// An object created and destroyed inside one request is deleted: it does not
// exist now, and "created" would send a reader looking for something that
// isn't there. The creation is not lost so much as subsumed - a row for an
// object that no longer exists is a row about a deletion.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'post', 'page', 814, 'created', 'Draft' );
DPT_AL_Buffer::record( 'post', 'page', 814, 'deleted', 'Draft' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'created then deleted in one request records the deletion' );

// Ties are broken by order, not by ignoring rank: a weaker action arriving
// later still loses.
DPT_AL_Buffer::reset();
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deleted', 'Akismet' );
DPT_AL_Buffer::record( 'plugin', 'akismet/akismet.php', 0, 'deactivated', 'Akismet' );
$rows = DPT_AL_Buffer::rows( 'rest', '', 5, 1756108800 );
dpt_test_eq( $rows[0]['action'], 'deleted', 'a lower-ranked action arriving later still does not win' );

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
// The offset is the cap itself: OFFSET 20000 is the 20001st-newest row, the
// first one that must go. An off-by-one here silently keeps or drops a row
// on every prune, and the query count alone would not notice.
dpt_test_ok( false !== strpos( $writer->queries[1], 'OFFSET 20000' ), 'the lookup finds the id at exactly the cap, not one either side of it' );
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

// 8. init() registers nothing for a read, and something for a write. It does
// NOT gate on the channel: init() runs on 'plugins_loaded', and core does not
// define REST_REQUEST until rest_api_loaded() on 'parse_request', so a channel
// gate here would read '' for every REST request and hook nothing at all. A
// browser request therefore does register listeners, and flush() - which runs
// at shutdown, when the channel is knowable - is what writes nothing for it.
$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;
$_SERVER['REQUEST_METHOD']        = 'POST';
DPT_AL_Hooks::init();
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_filters'] ), 'init() registers on a write whose channel is not yet knowable, rather than losing every REST request to a gate that runs too early' );

$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_rest_request'] = true;
$_SERVER['REQUEST_METHOD']        = 'GET';
DPT_AL_Hooks::init();
dpt_test_eq( $GLOBALS['dpt_stub_filters'], array(), 'init() on a real channel that is only a read also registers nothing' );

$GLOBALS['dpt_stub_filters'] = array();
$_SERVER['REQUEST_METHOD']   = 'POST';
DPT_AL_Hooks::init();
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_filters'] ), 'init() on a real write registers something' );

// A cron run reached over GET - an external scheduler fetching wp-cron.php,
// which is what most hosts do - must still register. It is a write channel
// whose name is knowable at plugins_loaded, and bailing out on the verb would
// leave every change that cron run makes unrecorded.
$GLOBALS['dpt_stub_filters']    = array();
$GLOBALS['dpt_stub_doing_cron'] = true;
$_SERVER['REQUEST_METHOD']      = 'GET';
DPT_AL_Hooks::init();
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_filters'] ), 'init() registers for a cron run fetched over GET, rather than reading it as a poll and recording nothing all run' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_filters']['shutdown'] ), 'including the shutdown flush, without which nothing buffered is ever written' );

// And the same request without cron registers nothing, so the line above is
// the channel doing the work and not the verb quietly passing.
$GLOBALS['dpt_stub_filters']    = array();
$GLOBALS['dpt_stub_doing_cron'] = false;
DPT_AL_Hooks::init();
dpt_test_eq( $GLOBALS['dpt_stub_filters'], array(), 'while the same GET off any early channel still registers nothing' );

$GLOBALS['dpt_stub_filters']      = array();
$GLOBALS['dpt_stub_rest_request'] = false;
unset( $_SERVER['REQUEST_METHOD'] );

// WP-CLI and XML-RPC announce themselves with constants, which cannot be
// undefined once set - so they run in a child process rather than changing
// the channel every assertion below would see. See the fixture's header.
$dpt_child = dirname( __DIR__ ) . '/tests/agent-log-early-channel-child.php';
$dpt_cases = array();
foreach ( array( 'cli', 'xmlrpc', 'none' ) as $dpt_case ) {
	$dpt_out = trim( (string) shell_exec( 'php ' . escapeshellarg( $dpt_child ) . ' ' . escapeshellarg( $dpt_case ) ) );
	$dpt_cases[ $dpt_case ] = array( 'raw' => $dpt_out, 'early' => null, 'hooks' => null );
	if ( preg_match( '/^early=(\d+) hooks=(\d+)$/', $dpt_out, $dpt_m ) ) {
		$dpt_cases[ $dpt_case ]['early'] = (int) $dpt_m[1];
		$dpt_cases[ $dpt_case ]['hooks'] = (int) $dpt_m[2];
	}
}
dpt_test_eq( $dpt_cases['cli']['early'], 1, 'a WP-CLI run is a channel we can name before plugins_loaded' );
dpt_test_ok( $dpt_cases['cli']['hooks'] > 0, 'so it registers listeners even though it has no HTTP verb of its own' );
dpt_test_eq( $dpt_cases['xmlrpc']['early'], 1, 'so is an XML-RPC request, whose constant is likewise set before wp-load' );
dpt_test_ok( $dpt_cases['xmlrpc']['hooks'] > 0, 'and it registers listeners too' );
// The control: the same child, the same GET, no constant. If this registered
// anything the four above would prove nothing.
dpt_test_eq( $dpt_cases['none']['early'], 0, 'while the same child with neither constant names no channel' );
dpt_test_eq( $dpt_cases['none']['hooks'], 0, 'and registers nothing at all, which is what makes the cases above mean something' );

/* ---- flush(): the only path that writes ---- */

// init() cannot gate on the channel, because core defines REST_REQUEST on
// 'parse_request', long after the 'plugins_loaded' that reaches init(). So
// the whole boundary lives here, at shutdown, and this is what proves it.
$writer = new DPT_AL_Test_Writer();
DPT_AL_Store::set_writer( $writer );
$GLOBALS['dpt_stub_options']           = array();
$GLOBALS['dpt_stub_doing_cron']        = false;
$GLOBALS['dpt_stub_rest_request']      = false;
$GLOBALS['dpt_stub_app_password_uuid'] = null;
$GLOBALS['dpt_stub_app_passwords']     = array();

// A browser request writes nothing - and leaves nothing buffered, so a later
// request in the same process cannot inherit its changes and file them under
// the wrong channel.
DPT_AL_Buffer::reset();
DPT_AL_Hooks::on_option_updated( 'siteurl' );
DPT_AL_Hooks::flush();
dpt_test_eq( $writer->inserted, array(), 'flush() on a browser request writes no row' );
dpt_test_eq( DPT_AL_Buffer::pending(), array(), 'and empties the buffer rather than leaving it for the next request' );
dpt_test_eq( $writer->queries, array(), 'and prunes nothing' );

// A recognised channel with nothing buffered touches neither the database nor
// the throttle: a poll that changed nothing must not push the prune stamp
// forward and starve the prune that a real write would have done.
$GLOBALS['dpt_stub_rest_request'] = true;
DPT_AL_Buffer::reset();
$writer->inserted = array();
$writer->queries  = array();
$GLOBALS['dpt_stub_app_password_lookups'] = 0;
DPT_AL_Hooks::flush();
dpt_test_eq( $writer->inserted, array(), 'a recognised channel with an empty buffer writes no row' );
dpt_test_eq( $writer->queries, array(), 'and issues no query at all' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_options']['dpt_agent_log_last_prune'] ), 'and does not stamp the prune throttle' );
// Resolving the app name reads user meta. A request that changed nothing must
// decide that before paying for it.
dpt_test_eq( $GLOBALS['dpt_stub_app_password_lookups'], 0, 'and never looks the application password up at all' );

// A recognised channel with real changes: one row per object, each carrying
// the channel read at shutdown and the application password's name.
$GLOBALS['dpt_stub_app_password_uuid'] = 'uuid-1';
$GLOBALS['dpt_stub_app_passwords']     = array( 'uuid-1' => array( 'name' => 'ContentEngine' ) );
DPT_AL_Buffer::reset();
$writer->inserted = array();
$writer->queries  = array();
DPT_AL_Hooks::on_option_updated( 'siteurl' );
DPT_AL_Hooks::on_plugin_activated( 'akismet/akismet.php' );
DPT_AL_Hooks::flush();
dpt_test_eq( count( $writer->inserted ), 2, 'a REST request that changed two objects writes two rows' );
$flushed_channels = array_unique( array_column( array_column( $writer->inserted, 'data' ), 'channel' ) );
dpt_test_eq( $flushed_channels, array( 'rest' ), 'every row carrying the channel, read at shutdown when the constant exists' );
$flushed_apps = array_unique( array_column( array_column( $writer->inserted, 'data' ), 'app' ) );
dpt_test_eq( $flushed_apps, array( 'ContentEngine' ), 'and the name of the application password that authenticated it' );
dpt_test_eq( DPT_AL_Buffer::pending(), array(), 'and the buffer is empty afterwards' );
dpt_test_ok( ! empty( $writer->queries ), 'a first flush prunes, since the throttle has never been stamped' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_options']['dpt_agent_log_last_prune'] ), 'stamping the throttle as it goes' );

// A second flush within the hour writes its rows but does not prune again.
$writer->inserted = array();
$writer->queries  = array();
DPT_AL_Hooks::on_option_updated( 'blogname' );
DPT_AL_Hooks::flush();
dpt_test_eq( count( $writer->inserted ), 1, 'a second flush within the hour still writes its row' );
dpt_test_eq( $writer->queries, array(), 'but issues no prune query' );

// Once the throttle has expired, the next flush prunes again.
$GLOBALS['dpt_stub_options']['dpt_agent_log_last_prune'] = time() - HOUR_IN_SECONDS - 1;
$writer->inserted = array();
$writer->queries  = array();
DPT_AL_Hooks::on_option_updated( 'blogname' );
DPT_AL_Hooks::flush();
dpt_test_ok( ! empty( $writer->queries ), 'and a flush after the throttle has expired prunes again' );

DPT_AL_Buffer::reset();
$GLOBALS['dpt_stub_rest_request']      = false;
$GLOBALS['dpt_stub_app_password_uuid'] = null;
$GLOBALS['dpt_stub_app_passwords']     = array();
$GLOBALS['dpt_stub_options']           = array();

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

/* ---- the schema guard skips dbDelta once the version is current ---- */

// dbDelta runs once per schema version, not on every page load.
$GLOBALS['dpt_stub_options'] = array( 'dpt_agent_log_schema' => DPT_AL_Store::SCHEMA_VERSION );
$GLOBALS['dpt_stub_dbdelta_calls'] = 0;
DPT_AL_Store::install_table();
dpt_test_eq( $GLOBALS['dpt_stub_dbdelta_calls'], 0, 'a table already at this schema version is not rebuilt' );

dpt_test_summary();
