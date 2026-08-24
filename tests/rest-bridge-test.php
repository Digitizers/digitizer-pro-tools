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

// A later task hooks rest_api_init with add_action() and then asserts on it
// with dpt_stub_has_filter() - WordPress keeps one hook registry for both, so
// the stub must too.
$GLOBALS['dpt_stub_filters'] = array();
add_action( 'rest_api_init', '__return_true' );
dpt_test_ok( dpt_stub_has_filter( 'rest_api_init' ), 'add_action records into the same registry as add_filter' );
$GLOBALS['dpt_stub_filters'] = array();

exit( dpt_test_summary() > 0 ? 1 : 0 );
