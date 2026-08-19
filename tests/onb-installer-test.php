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

exit( dpt_test_summary() > 0 ? 1 : 0 );
