<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/site-tweaks/class-dpt-st-settings.php';
require_once dirname( __DIR__ ) . '/modules/site-tweaks/class-dpt-st-module.php';

/* ---- the new tweaks are off until someone asks ---- */

$defaults = DPT_ST_Settings::defaults();
foreach ( array( 'elementor_icon_fonts', 'block_library_css', 'disable_block_editor' ) as $key ) {
	dpt_test_ok( array_key_exists( $key, $defaults ), $key . ' is a known tweak' );
	dpt_test_eq( $defaults[ $key ], '0', 'and it is off by default' );
}

// Each of these breaks something visible when it is wrong - an icon that turns
// into an empty square looks like a broken design, not like a setting - so the
// defaults matter as much as the behaviour.
$GLOBALS['dpt_stub_options'] = array();
DPT_ST_Settings::save( array( 'block_library_css' => '1' ) );
$after = DPT_ST_Settings::all();
dpt_test_eq( $after['block_library_css'], '1', 'a tweak that was asked for is stored' );
dpt_test_eq( $after['elementor_icon_fonts'], '0', 'and one that was not is not' );
dpt_test_eq( $after['x_frame_options'], '0', 'a field absent from the form is off, as the form posts every switch' );

/* ---- what each one actually removes ---- */

$module = new DPT_Site_Tweaks_Module();

$GLOBALS['dpt_stub_deregistered_styles'] = array();
$module->drop_elementor_icon_fonts();
dpt_test_eq(
	$GLOBALS['dpt_stub_deregistered_styles'],
	array( 'elementor-icons-fa-solid', 'elementor-icons-fa-regular', 'elementor-icons-fa-brands', 'elementor-icons' ),
	'Font Awesome ships as three stylesheets and eicons as a fourth - all four go, or the saving is imaginary'
);

$GLOBALS['dpt_stub_dequeued_styles'] = array();
$GLOBALS['dpt_stub_is_admin']        = false;
$module->drop_block_library_css();
dpt_test_eq(
	$GLOBALS['dpt_stub_dequeued_styles'],
	array( 'wp-block-library', 'wp-block-library-theme' ),
	'the block stylesheets go on the front end'
);

// The editor screens are built out of these styles. Taking them there would
// break the thing the operator is looking at while they wonder what happened.
$GLOBALS['dpt_stub_dequeued_styles'] = array();
$GLOBALS['dpt_stub_is_admin']        = true;
$module->drop_block_library_css();
dpt_test_eq( $GLOBALS['dpt_stub_dequeued_styles'], array(), 'and never in the admin' );

/* ---- the classic editor, for what the setting actually says ---- */

// "Posts and pages" has to mean posts and pages. Returning false for
// everything would also take the block editor from post types that have no
// classic equivalent - reusable blocks and template parts are edited with it -
// and from any custom type a plugin registered expecting it.
dpt_test_ok( ! $module->classic_editor_for_post( true, (object) array( 'post_type' => 'post' ) ), 'a post is edited classically' );
dpt_test_ok( ! $module->classic_editor_for_post( true, (object) array( 'post_type' => 'page' ) ), 'and so is a page' );
dpt_test_ok( $module->classic_editor_for_post( true, (object) array( 'post_type' => 'wp_block' ) ), 'a reusable block keeps the only editor it has' );
dpt_test_ok( $module->classic_editor_for_post( true, (object) array( 'post_type' => 'portfolio' ) ), 'and a custom type keeps whatever it asked for' );

// An answer already given by something else is passed through rather than
// overturned, in both directions.
dpt_test_ok( ! $module->classic_editor_for_post( false, (object) array( 'post_type' => 'portfolio' ) ), 'including an answer of no' );
dpt_test_ok( $module->classic_editor_for_post( true, null ), 'and a call with no post at all changes nothing' );

dpt_test_ok( ! $module->classic_editor_for_post_type( true, 'page' ), 'the post-type question is answered the same way' );
dpt_test_ok( $module->classic_editor_for_post_type( true, 'wp_template' ), 'for the types it covers, and only those' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
