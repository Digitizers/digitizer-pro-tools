<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-installer.php';

/* ---- the decision table ---- */

dpt_test_eq( DPT_ONB_Installer::action_for( 'missing' ), 'install', 'a missing item is installed' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'inactive' ), 'activate', 'an installed item is only activated' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'active' ), 'skip', 'an active item is left alone' );
dpt_test_eq( DPT_ONB_Installer::action_for( 'nonsense' ), 'skip', 'an unknown state is skipped, never installed' );

/* ---- theme activation gate ---- */

dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentytwentyfour' ), 'a default theme may be replaced' );
dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentytwentysix' ), 'the theme WordPress 7.0 bundles may be replaced' );
// The hardcoded list always lags the next release, and lagging breaks the main
// flow: a fresh site would be read as one with a chosen design. Core's own
// WP_DEFAULT_THEME closes that gap for whatever comes next.
dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentythirty', 'twentythirty' ), 'a future bundled default is recognised through WP_DEFAULT_THEME' );
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'astra', 'twentythirty' ), 'a custom theme is still refused when a bundled default is known' );
dpt_test_eq( DPT_ONB_Installer::bundled_default_theme(), '', 'the bundled default is empty when WP_DEFAULT_THEME is undefined' );
dpt_test_ok( DPT_ONB_Installer::may_activate_theme( 'twentyten' ), 'an old default theme may be replaced' );
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'astra' ), 'a live custom theme is never replaced' );
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'hello-digitizer' ), 'an already-correct theme is not re-activated here' );
// Guard against a theme that merely starts with the same letters.
dpt_test_ok( ! DPT_ONB_Installer::may_activate_theme( 'twenty-something-custom' ), 'only real default theme slugs count' );

/* ---- extracted-directory rename ---- */

dpt_test_eq(
	DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/elementor-mcp-a1b2c3d/', 'elementor-mcp' ),
	'/tmp/wp/upgrade/elementor-mcp/',
	'a zipball directory is renamed to the manifest slug'
);
dpt_test_eq(
	DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/elementor-mcp/', 'elementor-mcp' ),
	'/tmp/wp/upgrade/elementor-mcp/',
	'an already-correct directory is unchanged'
);
dpt_test_eq(
	DPT_ONB_Installer::desired_source_path( '/tmp/wp/upgrade/WordPress-mcp-adapter-9f8e7d6/', 'mcp-adapter' ),
	'/tmp/wp/upgrade/mcp-adapter/',
	'the owner prefix GitHub adds is stripped too'
);

/* ---- apply(): unknown ids never reach the upgrader ---- */

$res = DPT_ONB_Installer::apply( 'no-such-item' );
dpt_test_eq( $res['outcome'], 'failed', 'an unknown id fails' );
dpt_test_eq( $res['id'], 'no-such-item', 'the result echoes the id it was given' );

$res = DPT_ONB_Installer::apply( '../../../wp-config' );
dpt_test_eq( $res['outcome'], 'failed', 'a traversal attempt fails' );

/* ---- apply(): an active item short-circuits before any network work ---- */

$GLOBALS['dpt_stub_plugins']        = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
$GLOBALS['dpt_stub_active_plugins'] = array( 'elementor/elementor.php' );
$GLOBALS['dpt_stub_http']           = array(); // any HTTP call would be a stub miss
$res = DPT_ONB_Installer::apply( 'elementor' );
dpt_test_eq( $res['outcome'], 'skipped', 'an already-active plugin is skipped' );
dpt_test_ok( '' !== $res['message'], 'a skip still carries a message for the summary' );

/* ---- regression: a child theme is never activated without its parent ---- */

// The parent can be absent for ordinary reasons - the operator unticked it, or
// its own install failed earlier in a run that deliberately does not stop on
// failure. Switching anyway leaves the public site with no template.
$GLOBALS['dpt_stub_themes']     = array( 'hello-digitizer' ); // child present, parent not
$GLOBALS['dpt_stub_stylesheet'] = 'twentytwentyfour';         // switching would be allowed
$res = DPT_ONB_Installer::apply( 'hello_digitizer' );
dpt_test_eq( $res['outcome'], 'failed', 'the child theme is not activated when its parent is missing' );
dpt_test_eq( $GLOBALS['dpt_stub_stylesheet'], 'twentytwentyfour', 'the live theme is left alone' );
dpt_test_ok( false !== strpos( $res['message'], 'hello-elementor' ), 'the message names the missing parent' );

// With the parent present it switches as designed.
$GLOBALS['dpt_stub_themes']     = array( 'hello-elementor', 'hello-digitizer' );
$GLOBALS['dpt_stub_stylesheet'] = 'twentytwentyfour';
$res = DPT_ONB_Installer::apply( 'hello_digitizer' );
dpt_test_eq( $res['outcome'], 'activated', 'the child theme activates once its parent is present' );
dpt_test_eq( $GLOBALS['dpt_stub_stylesheet'], 'hello-digitizer', 'the child theme became the active theme' );

// A live custom theme still wins over both checks.
$GLOBALS['dpt_stub_themes']     = array( 'hello-digitizer' );
$GLOBALS['dpt_stub_stylesheet'] = 'astra';
$res = DPT_ONB_Installer::apply( 'hello_digitizer' );
dpt_test_eq( $res['outcome'], 'installed', 'a live custom theme defers activation before the parent check' );
dpt_test_eq( $GLOBALS['dpt_stub_stylesheet'], 'astra', 'the custom theme is untouched' );

/* ---- regression: the parent theme is never reported as activated ---- */

$GLOBALS['dpt_stub_themes']     = array( 'hello-elementor' );
$GLOBALS['dpt_stub_stylesheet'] = 'twentytwentyfour';
$parent = DPT_ONB_Manifest::get( 'hello_elementor' );
// Its own state, not ACTIVE: the status column prints the state, so calling it
// active would put the false claim back on screen instead of in the message.
dpt_test_eq( DPT_ONB_State::of( $parent ), DPT_ONB_State::PRESENT, 'an install-only item is satisfied once present' );
dpt_test_ok( DPT_ONB_State::PRESENT !== DPT_ONB_State::ACTIVE, 'present and active are distinct states' );
dpt_test_eq( DPT_ONB_Installer::action_for( DPT_ONB_State::PRESENT ), 'skip', 'a present item needs no action' );
dpt_test_eq( DPT_ONB_Installer::capability_for( $parent, DPT_ONB_State::PRESENT ), '', 'and needs no capability' );

$res = DPT_ONB_Installer::apply( 'hello_elementor' );
dpt_test_eq( $res['outcome'], 'skipped', 'a present parent theme is skipped, not re-activated' );
dpt_test_eq( $GLOBALS['dpt_stub_stylesheet'], 'twentytwentyfour', 'the parent theme is never made the active theme' );

// Running again must reach the same answer - the row has to converge.
$res = DPT_ONB_Installer::apply( 'hello_elementor' );
dpt_test_eq( $res['outcome'], 'skipped', 'and it stays skipped on a second run' );

/* ---- regression: capability matches the action, not the worst case ---- */

$plugin_item = DPT_ONB_Manifest::get( 'elementor' );
$theme_item  = DPT_ONB_Manifest::get( 'hello_digitizer' );

dpt_test_eq( DPT_ONB_Installer::capability_for( $plugin_item, 'missing' ), 'install_plugins', 'installing a plugin needs install_plugins' );
dpt_test_eq( DPT_ONB_Installer::capability_for( $plugin_item, 'inactive' ), 'activate_plugins', 'activating an installed plugin needs only activate_plugins' );
dpt_test_eq( DPT_ONB_Installer::capability_for( $plugin_item, 'active' ), '', 'skipping needs no extra capability' );
dpt_test_eq( DPT_ONB_Installer::capability_for( $theme_item, 'missing' ), 'install_themes', 'installing a theme needs install_themes' );
dpt_test_eq( DPT_ONB_Installer::capability_for( $theme_item, 'inactive' ), 'switch_themes', 'activating an installed theme needs only switch_themes' );

/* ---- regression: installing and activating are two permissions ---- */

// activate_plugin() performs no capability check of its own, so a role granted
// install_plugins but deliberately not activate_plugins must not end up
// activating everything it installed.
$GLOBALS['dpt_stub_plugins']        = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
$GLOBALS['dpt_stub_active_plugins'] = array();
$GLOBALS['dpt_stub_denied_caps']    = array( 'activate_plugins' );
$res = DPT_ONB_Installer::apply( 'elementor' );
dpt_test_eq( $res['outcome'], 'failed', 'a user who cannot activate plugins does not activate one' );
dpt_test_eq( $GLOBALS['dpt_stub_active_plugins'], array(), 'and nothing was activated' );

$GLOBALS['dpt_stub_denied_caps'] = array();
$res = DPT_ONB_Installer::apply( 'elementor' );
dpt_test_eq( $res['outcome'], 'activated', 'with the capability it activates normally' );
dpt_test_eq( $GLOBALS['dpt_stub_active_plugins'], array( 'elementor/elementor.php' ), 'and the plugin is active' );

// Same rule on the theme side.
$GLOBALS['dpt_stub_themes']      = array( 'hello-elementor', 'hello-digitizer' );
$GLOBALS['dpt_stub_stylesheet']  = 'twentytwentyfour';
$GLOBALS['dpt_stub_denied_caps'] = array( 'switch_themes' );
$res = DPT_ONB_Installer::apply( 'hello_digitizer' );
dpt_test_eq( $res['outcome'], 'failed', 'a user who cannot switch themes does not switch one' );
dpt_test_eq( $GLOBALS['dpt_stub_stylesheet'], 'twentytwentyfour', 'and the live theme is unchanged' );
$GLOBALS['dpt_stub_denied_caps'] = array();

exit( dpt_test_summary() > 0 ? 1 : 0 );
