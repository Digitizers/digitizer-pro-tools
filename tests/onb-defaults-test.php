<?php
require_once __DIR__ . '/bootstrap.php';

// registry() and enabled_map() need the module files to exist on disk but not
// to be loaded, and DPT_Admin is constructed only in boot(). Pulling the class
// in directly is enough for both.
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-plugin.php';

$plugin   = DPT_Plugin::instance();
$registry = $plugin->registry();

// Onboarding is the single module that ships on. If everything were off a
// fresh site would have no wizard, and the operator would have to find and
// enable a module before using the thing whose job is setting up a fresh site.
// Nothing ships enabled at all, Onboarding included. A new site starts empty
// and the operator switches on what that client needs.
foreach ( $registry as $id => $spec ) {
	dpt_test_eq( $spec['default'], '0', "$id ships disabled" );
}
dpt_test_ok( isset( $registry['onboarding'] ), 'the onboarding module is registered' );

// The three that used to ship on are named explicitly, so this test fails
// loudly if one is ever quietly turned back on.
foreach ( array( 'cookie_banner', 'duplicate_post', 'update_emails' ) as $id ) {
	dpt_test_eq( $registry[ $id ]['default'], '0', "$id no longer ships enabled" );
}

/* ---- the part that protects existing sites ---- */

// A site that turned the cookie banner on before this change must keep it on:
// enabled_map() may only fill in ids that are not already saved.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_settings' => array( 'modules' => array( 'cookie_banner' => '1', 'hide_login' => '1' ) ),
);
$map = $plugin->enabled_map();
dpt_test_eq( $map['cookie_banner'], '1', 'a saved enabled module stays enabled' );
dpt_test_eq( $map['hide_login'], '1', 'a second saved enabled module stays enabled' );
dpt_test_eq( $map['duplicate_post'], '0', 'an unsaved module takes the new default' );
dpt_test_eq( $map['onboarding'], '0', 'an unsaved onboarding takes the disabled default' );

// A site that deliberately turned something off keeps it off.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_settings' => array( 'modules' => array( 'onboarding' => '0' ) ),
);
$GLOBALS['dpt_stub_options'] = array(
	'dpt_settings' => array( 'modules' => array( 'onboarding' => '1' ) ),
);
dpt_test_eq( $plugin->enabled_map()['onboarding'], '1', 'a module switched on stays on' );

// No saved option at all - a genuinely fresh install.
$GLOBALS['dpt_stub_options'] = array();
$fresh = $plugin->enabled_map();
dpt_test_eq( $fresh['onboarding'], '0', 'a fresh install does not even enable the wizard' );
dpt_test_eq( $fresh['cookie_banner'], '0', 'nor anything else' );
dpt_test_eq(
	array_filter(
		$fresh,
		function ( $v ) {
			return '1' === $v;
		}
	),
	array(),
	'a fresh install activates no module at all'
);

/* ---- the dead method is gone ---- */

dpt_test_ok( ! method_exists( 'DPT_Module', 'enabled_by_default' ), 'the unused enabled_by_default() is removed from the base class' );

$overrides = array();
foreach ( glob( dirname( __DIR__ ) . '/modules/*/class-dpt-*-module.php' ) as $file ) {
	if ( false !== strpos( file_get_contents( $file ), 'function enabled_by_default' ) ) {
		$overrides[] = basename( $file );
	}
}
dpt_test_eq( $overrides, array(), 'no module still overrides it' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
