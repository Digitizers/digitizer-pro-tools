<?php
/**
 * Content Control - the [dpt_restrict] shortcode: excluded_roles, inline
 * rendering and extra classes, on top of the existing role/show/message
 * behaviour.
 */

require_once __DIR__ . '/bootstrap.php';

// --- the slice of WordPress the shortcode touches (not in bootstrap) ---
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_user']     = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function wpautop( $s ) { return $s; }
function do_shortcode( $c ) { return $c; }
function shortcode_atts( $defaults, $atts, $tag = '' ) {
	$out = $defaults;
	foreach ( (array) $atts as $k => $v ) {
		if ( array_key_exists( $k, $defaults ) ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $c ); }
function wp_doing_ajax() { return false; }
function is_customize_preview() { return false; }

require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-module.php';

$m = new DPT_Content_Control_Module();

/* ---- excluded_roles ---- */

$out = $m->shortcode_restrict( array( 'excluded_roles' => 'editor', 'message' => 'no' ), 'secret' );
dpt_test_ok( false === strpos( $out, 'secret' ), 'excluded editor cannot see the content' );
dpt_test_ok( false !== strpos( $out, 'no' ), 'denial message shown to the excluded role' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 6, 'roles' => array( 'subscriber' ) );
dpt_test_eq( $m->shortcode_restrict( array( 'excluded_roles' => 'editor' ), 'secret' ), 'secret', 'an unlisted role sees the content' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
$out = $m->shortcode_restrict( array( 'excluded_roles' => 'editor', 'message' => 'members' ), 'secret' );
dpt_test_ok( false === strpos( $out, 'secret' ), 'excluded_roles still requires login' );

/* excluded_roles wins over role */
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
$out = $m->shortcode_restrict( array( 'role' => 'editor', 'excluded_roles' => 'editor' ), 'secret' );
dpt_test_ok( false === strpos( $out, 'secret' ), 'excluded_roles takes precedence over role' );

/* ---- existing behaviour unchanged ---- */

dpt_test_eq( $m->shortcode_restrict( array( 'role' => 'editor' ), 'secret' ), 'secret', 'role gate still admits the listed role' );
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
dpt_test_eq( $m->shortcode_restrict( array( 'show' => 'logged_in' ), 'secret' ), '', 'no message still renders nothing' );

/* ---- inline + class ---- */

$out = $m->shortcode_restrict( array( 'show' => 'logged_in', 'message' => 'members', 'inline' => 'true', 'class' => 'promo x<y' ), 'secret' );
dpt_test_ok( 0 === strpos( $out, '<span' ), 'inline renders a span' );
dpt_test_ok( false !== strpos( $out, '</span>' ), 'and closes it' );
dpt_test_ok( false !== strpos( $out, 'promo' ), 'custom class carried' );
dpt_test_ok( false !== strpos( $out, 'xy' ) && false === strpos( $out, 'x<y' ), 'class values are sanitized' );
dpt_test_ok( false !== strpos( $out, 'dpt-cc-restricted' ), 'base class kept alongside custom ones' );

$out = $m->shortcode_restrict( array( 'show' => 'logged_in', 'message' => 'members' ), 'secret' );
dpt_test_ok( 0 === strpos( $out, '<div' ), 'default wrapper stays a div' );

exit( dpt_test_summary() );
