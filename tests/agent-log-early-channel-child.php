<?php
/**
 * Child process for the WP-CLI and XML-RPC halves of the Agent Log's early
 * channel test.
 *
 * WP_CLI and XMLRPC_REQUEST are constants. Once defined they cannot be
 * undefined, so asserting on them inside agent-log-test.php would change the
 * channel every later assertion in that file sees. A separate process defines
 * exactly one of them - or neither, which is the control - and reports what
 * DPT_AL_Hooks::init() did.
 *
 * The HTTP verb is GET in every case on purpose: that is the verb an external
 * scheduler fetches wp-cron.php with, and it is the verb that used to make
 * init() bail out and leave a whole channel unrecorded.
 *
 * Usage: php tests/agent-log-early-channel-child.php cli|xmlrpc|none
 * Prints: early=<0|1> hooks=<count>
 *
 * Not named *-test.php: it is a fixture, and the suite runner globs for tests.
 */

$dpt_case = isset( $argv[1] ) ? (string) $argv[1] : 'none';

if ( 'cli' === $dpt_case ) {
	define( 'WP_CLI', true );
}
if ( 'xmlrpc' === $dpt_case ) {
	define( 'XMLRPC_REQUEST', true );
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-channel.php';
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-store.php';
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-buffer.php';
require_once dirname( __DIR__ ) . '/modules/agent-log/class-dpt-al-hooks.php';

$_SERVER['REQUEST_METHOD']        = 'GET';
$GLOBALS['dpt_stub_doing_cron']   = false;
$GLOBALS['dpt_stub_rest_request'] = false;
$GLOBALS['dpt_stub_filters']      = array();

$dpt_early = DPT_AL_Channel::is_early_channel() ? 1 : 0;
DPT_AL_Hooks::init();

printf( "early=%d hooks=%d\n", $dpt_early, count( $GLOBALS['dpt_stub_filters'] ) );
