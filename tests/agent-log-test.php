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

dpt_test_summary();
