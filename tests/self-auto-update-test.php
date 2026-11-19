<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-updater.php';

// Policy must also be installed when a checker was already initialized.
$checker = new stdClass();
$property = new ReflectionProperty( 'DPT_Updater', 'checker' );
if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
$property->setValue( null, $checker );
dpt_test_eq( DPT_Updater::init( __FILE__ ), $checker, 'existing checker is reused' );
DPT_Updater::init( __FILE__ );
dpt_test_eq( count( $GLOBALS['dpt_stub_filters']['auto_update_plugin'] ?? array() ), 1, 'policy is registered exactly once' );

$own = (object) array( 'plugin' => DPT_BASENAME );
$other = (object) array( 'plugin' => 'wordfence/wordfence.php' );
foreach ( array( true, false, null ) as $incoming ) {
	dpt_test_eq( apply_filters( 'auto_update_plugin', $incoming, $own ), false, 'self-update is denied regardless of WordPress preference' );
	dpt_test_eq( apply_filters( 'auto_update_plugin', $incoming, $other ), $incoming, 'other plugin preference is untouched' );
	dpt_test_eq( apply_filters( 'auto_update_plugin', $incoming, (object) array() ), $incoming, 'missing basename is untouched' );
}
add_filter( 'auto_update_plugin', '__return_true', 100 );
dpt_test_eq( apply_filters( 'auto_update_plugin', true, $own ), false, 'a broad update enabler cannot override the default policy' );
remove_filter( 'auto_update_plugin', '__return_true' );

$html = '<a href="fixture-only">Enable auto-updates</a>';
$managed = apply_filters( 'plugin_auto_update_setting_html', $html, DPT_BASENAME, array() );
dpt_test_ok( false !== strpos( $managed, 'Automatic updates are managed by DPT' ), 'own Plugins row explains the policy' );
dpt_test_ok( false === strpos( $managed, '<a ' ), 'blocked row does not offer a misleading toggle' );
dpt_test_eq( apply_filters( 'plugin_auto_update_setting_html', $html, $other->plugin, array() ), $html, 'other Plugins rows are unchanged' );

$allow = function () { return true; };
add_filter( 'dpt_allow_self_auto_update', $allow );
foreach ( array( true, false, null ) as $incoming ) {
	dpt_test_eq( apply_filters( 'auto_update_plugin', $incoming, $own ), $incoming, 'explicit opt-in restores the WordPress decision without forcing enrollment' );
}
dpt_test_eq( apply_filters( 'plugin_auto_update_setting_html', $html, DPT_BASENAME, array() ), $html, 'opted-in row restores the WordPress control' );
remove_filter( 'dpt_allow_self_auto_update', $allow );
add_filter( 'dpt_allow_self_auto_update', function () { return 'yes'; } );
dpt_test_eq( apply_filters( 'auto_update_plugin', true, $own ), false, 'only boolean true opts in' );
dpt_test_eq( get_option( 'auto_update_plugins', array() ), array(), 'policy never rewrites enrollment preferences' );
exit( dpt_test_summary() ? 1 : 0 );
