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
$GLOBALS['dpt_stub_registered_styles']   = array();
$module->drop_elementor_icon_fonts();
$icon_handles = array( 'elementor-icons-fa-solid', 'elementor-icons-fa-regular', 'elementor-icons-fa-brands', 'elementor-icons' );
dpt_test_eq(
	$GLOBALS['dpt_stub_deregistered_styles'],
	$icon_handles,
	'Font Awesome ships as three stylesheets and eicons as a fourth - all four go, or the saving is imaginary'
);

// Each handle is put back with no source. WordPress skips a style whose
// dependency is missing, and Elementor registers elementor-common with
// elementor-icons as a dependency - so removing the handle outright would take
// the stylesheet above it too, which looks like a broken page rather than a
// missing icon.
foreach ( $icon_handles as $handle ) {
	dpt_test_ok( isset( $GLOBALS['dpt_stub_registered_styles'][ $handle ] ), $handle . ' is registered again' );
	dpt_test_eq( $GLOBALS['dpt_stub_registered_styles'][ $handle ]['src'], false, 'with no source, so it resolves and prints nothing' );
}

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

/* ---- the Elementor editor lock ---- */

dpt_test_ok( array_key_exists( 'elementor_lock', DPT_ST_Settings::defaults() ), 'the lock is a known tweak' );
dpt_test_eq( DPT_ST_Settings::defaults()['elementor_lock'], '0', 'and it is off by default' );

// The decision method is separated from the redirect (which exits), so every
// branch here asserts on the URL the lock would send the request to - '' being
// "leave the request alone".

// A page built with Elementor, opened for editing by a user who is denied the
// bypass capability. This is the request the lock exists for.
$GLOBALS['dpt_stub_posts'][42]               = 'page';
$GLOBALS['dpt_stub_elementor_documents'][42] = true;
$GLOBALS['dpt_stub_denied_caps']             = array( 'manage_options' );
$_GET = array( 'post' => '42' );

// Before Elementor is "installed" (the constant below is not defined yet),
// nothing happens - _elementor_edit_mode survives deactivating Elementor, and
// redirecting into an editor that is not there would take the page away from
// everyone. The constant is defined once and for all below, so this assertion
// has to run first.
$module2 = new DPT_Site_Tweaks_Module();
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'no Elementor, no redirect - whatever the meta still says' );

define( 'ELEMENTOR_VERSION', '3.30.0' );

dpt_test_eq(
	$module2->elementor_lock_redirect_url(),
	'https://example.test/wp-admin/post.php?post=42&action=elementor',
	'a locked user opening an Elementor page is sent to the Elementor editor'
);

// The one line someone will "simplify" later: the Elementor editor itself is
// post.php?post=ID&action=elementor and fires the same load-post.php hook, so
// any action other than a plain edit must pass through - or the redirect chases
// its own tail forever.
$_GET['action'] = 'elementor';
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'the Elementor editor itself is never redirected (the loop guard)' );
$_GET['action'] = 'trash';
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'and trashing from the list still works' );
$_GET['action'] = 'edit';
dpt_test_ok( '' !== $module2->elementor_lock_redirect_url(), 'while an explicit action=edit is the same as no action' );

// The bypass capability is the lockout-proofing: the person who can turn the
// toggle off must never be caught by it.
$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'a user with the bypass capability keeps the native editor' );
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' );

// A user who cannot edit the post at all gets WordPress's own permission
// error, not a redirect into an editor that would refuse them anyway.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 42 );
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'no edit_post capability, no redirect - WordPress answers' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

// A page Elementor documents but that is not built with it stays in Gutenberg.
$GLOBALS['dpt_stub_posts'][43]               = 'page';
$GLOBALS['dpt_stub_elementor_documents'][43] = false;
$_GET['post'] = '43';
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'a non-Elementor page keeps the editor it was made in' );

// A post with no document falls back to the meta for the built-with question,
// but the redirect URL only ever comes from a document - hand-building one
// would drop whatever Elementor appends to it. No document, no redirect.
$GLOBALS['dpt_stub_posts'][44]                          = 'page';
$GLOBALS['dpt_stub_post_meta'][44]['_elementor_edit_mode'] = 'builder';
$_GET['post'] = '44';
dpt_test_ok( $module2->is_built_with_elementor( 44 ), 'the meta fallback still recognises a builder page' );
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'but without a document there is nowhere safe to send it' );

// A site can exempt one post programmatically.
$_GET['post'] = '42';
$dpt_test_exempt_42 = function ( $enabled, $post_id ) {
	return 42 === $post_id ? false : $enabled;
};
add_filter( 'dpt_st_elementor_lock_enabled', $dpt_test_exempt_42 );
dpt_test_eq( $module2->elementor_lock_redirect_url(), '', 'a post the dpt_st_elementor_lock_enabled filter exempts is left alone' );
remove_filter( 'dpt_st_elementor_lock_enabled', $dpt_test_exempt_42 );

/* ---- the switch button, for users who reach Gutenberg anyway ---- */

// Scoped to body.elementor-editor-active on purpose: both mode spans are
// always in the DOM, so a selector keyed on them alone would also hide "Edit
// with Elementor" on pages that are not built with Elementor.
ob_start();
$module2->elementor_lock_switch_css();
$css = ob_get_clean();
dpt_test_ok( false !== strpos( $css, 'body.elementor-editor-active #elementor-switch-mode' ), 'a locked user loses the switch, in builder mode only' );

$GLOBALS['dpt_stub_denied_caps'] = array();
ob_start();
$module2->elementor_lock_switch_css();
dpt_test_eq( ob_get_clean(), '', 'a user with the bypass capability keeps it' );
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' );

// A closure rather than __return_false: the harness defines no WP helper
// functions, and its apply_filters() skips a callback it cannot call.
$dpt_test_keep_switch = function () {
	return false;
};
add_filter( 'dpt_st_elementor_lock_hide_switch', $dpt_test_keep_switch );
ob_start();
$module2->elementor_lock_switch_css();
dpt_test_eq( ob_get_clean(), '', 'and dpt_st_elementor_lock_hide_switch can keep it for everyone' );
remove_filter( 'dpt_st_elementor_lock_hide_switch', $dpt_test_keep_switch );

/* ---- edit links point where the redirect would send them ---- */

// One filter covers the row actions, the title links and the admin bar - they
// all read get_edit_post_link() - so the list never shows a link the redirect
// would immediately bounce.
$native = 'https://example.test/wp-admin/post.php?post=42&action=edit';
dpt_test_eq(
	$module2->elementor_lock_edit_link( $native, 42 ),
	'https://example.test/wp-admin/post.php?post=42&action=elementor',
	'an Elementor page\'s edit link goes straight to Elementor for a locked user'
);
dpt_test_eq( $module2->elementor_lock_edit_link( $native, 43 ), $native, 'a non-Elementor page keeps its native link' );
$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_eq( $module2->elementor_lock_edit_link( $native, 42 ), $native, 'and a bypass user keeps every native link' );

/* ---- the toggle wires the hooks, and only the toggle ---- */

$GLOBALS['dpt_stub_filters']  = array();
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_options']  = array();
DPT_ST_Settings::save( array( 'elementor_lock' => '1' ) );
$module3 = new DPT_Site_Tweaks_Module();
$module3->init();
dpt_test_ok( dpt_stub_has_filter( 'load-post.php' ), 'the lock on wires the redirect' );
dpt_test_ok( dpt_stub_has_filter( 'get_edit_post_link' ), 'and the edit-link rewrite' );

$GLOBALS['dpt_stub_filters'] = array();
DPT_ST_Settings::save( array() );
$module4 = new DPT_Site_Tweaks_Module();
$module4->init();
dpt_test_ok( ! dpt_stub_has_filter( 'load-post.php' ), 'the lock off wires nothing' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
