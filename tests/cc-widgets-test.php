<?php
/**
 * Content Control - classic widget visibility: the per-instance decision
 * and the sanitization of what the widget form posts.
 */

require_once __DIR__ . '/bootstrap.php';

// --- the slice of WordPress the widget gate touches (not in bootstrap) ---
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_user']     = (object) array( 'ID' => 0, 'roles' => array() );
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function wpautop( $s ) { return $s; }
function is_customize_preview() { return false; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-widgets.php';

$w = new DPT_CC_Widgets();

/* ---- should_show ---- */

dpt_test_ok( $w->should_show( array() ), 'no settings => visible' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => '' ) ), 'empty status => visible' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_in' ) ), 'logged_in hides from an anonymous visitor' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_out' ) ), 'logged_out shows to an anonymous visitor' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'sideways' ) ), 'unknown status fails open - a broken value must not blank a sidebar' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 3, 'roles' => array( 'subscriber' ) );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_in' ) ), 'logged_in shows to a user' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_out' ) ), 'logged_out hides from a user' );
dpt_test_ok( ! $w->should_show( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'editor' ) ) ), 'role gate refuses an unlisted role' );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'subscriber' ) ) ), 'role gate admits a listed role' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) );
dpt_test_ok( $w->should_show( array( 'dpt_cc_status' => 'logged_out' ) ), 'administrators see everything' );

/* ---- sanitize_update ---- */

$inst = $w->sanitize_update( array( 'dpt_cc_status' => 'evil', 'dpt_cc_roles' => array( 'editor', 'x!!' ) ) );
dpt_test_eq( $inst['dpt_cc_status'], '', 'bad status dropped' );
dpt_test_eq( $inst['dpt_cc_roles'], array(), 'roles dropped unless status is logged_in' );

$inst2 = $w->sanitize_update( array( 'dpt_cc_status' => 'logged_in', 'dpt_cc_roles' => array( 'editor', 'x!!' ) ) );
dpt_test_eq( $inst2['dpt_cc_roles'], array( 'editor', 'x' ), 'kept roles are key-sanitized' );

$inst3 = $w->save_fields( array( 'title' => 'Hello' ), array( 'dpt_cc_status' => 'logged_out', 'dpt_cc_roles' => array( 'editor' ) ) );
dpt_test_eq( $inst3['title'], 'Hello', 'other instance fields survive save' );
dpt_test_eq( $inst3['dpt_cc_status'], 'logged_out', 'status stored' );
dpt_test_eq( $inst3['dpt_cc_roles'], array(), 'roles meaningless for logged_out are dropped' );

/* ---- filter_sidebars ---- */

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
$GLOBALS['dpt_stub_options']['widget_text'] = array(
	2 => array( 'title' => 'Open' ),
	3 => array( 'title' => 'Members', 'dpt_cc_status' => 'logged_in' ),
);
$sidebars = array(
	'sidebar-1'           => array( 'text-2', 'text-3', 'unknownformat' ),
	'wp_inactive_widgets' => array( 'text-3' ),
);
$out = $w->filter_sidebars( $sidebars );
dpt_test_eq( $out['sidebar-1'], array( 'text-2', 'unknownformat' ), 'gated widget removed, unknown ids untouched' );
dpt_test_eq( $out['wp_inactive_widgets'], array( 'text-3' ), 'inactive store never filtered' );

exit( dpt_test_summary() );
