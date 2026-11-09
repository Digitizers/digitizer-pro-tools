<?php
/**
 * What WordPress shows for the plugin: the Plugins list line and the
 * "View details" window (banner, icon, tested version).
 *
 * Run: php tests/plugin-details-test.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

/* Banner and icon: Plugin Update Checker reads them from assets/ in the installed plugin. */
$sizes = array(
	'banner-772x250.png'  => array( 772, 250 ),
	'banner-1544x500.png' => array( 1544, 500 ),
	'icon-128x128.png'    => array( 128, 128 ),
	'icon-256x256.png'    => array( 256, 256 ),
);
foreach ( $sizes as $file => $wh ) {
	$path = $root . '/assets/' . $file;
	$info = is_file( $path ) ? getimagesize( $path ) : false;
	dpt_test_eq( $info ? array( $info[0], $info[1] ) : null, $wh, "assets/$file exists at {$wh[0]}x{$wh[1]}" );
}

/* The zip ships them: build-zip.sh does not skip assets/. */
$build = (string) file_get_contents( $root . '/bin/build-zip.sh' );
dpt_test_ok( false === strpos( $build, 'assets/*' ), 'the zip keeps assets/' );
dpt_test_ok( false !== strpos( $build, 'design/*' ), 'the zip leaves out the design sources' );

/* Tested up to is current, so the window shows no "not tested" warning. */
preg_match( '/^Tested up to:\s*([0-9.]+)/m', (string) file_get_contents( $root . '/readme.txt' ), $m );
dpt_test_ok( isset( $m[1] ) && version_compare( $m[1], '7.1', '>=' ), 'readme Tested up to is 7.1 or later' );

/* The Plugins list line describes today's plugin, not the first three modules. */
preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', (string) file_get_contents( $root . '/digitizer-pro-tools.php' ), $d );
$desc = isset( $d[1] ) ? $d[1] : '';
dpt_test_ok( false === strpos( $desc, 'more modules to come' ), 'the header description is not the launch-era line' );
dpt_test_ok( false !== strpos( $desc, 'off until' ), 'the header description says modules are off until switched on' );

/* View details: WordPress reads banners low = 772x250, high = 1544x500. The bundled
   checker swaps them and drops the icons, so DPT_Updater sets both itself. */
require_once dirname( __DIR__ ) . '/includes/class-dpt-updater.php';
$base = DPT_URL . 'assets/';
$info = (object) array( 'slug' => 'digitizer-pro-tools', 'banners' => array( 'high' => $base . 'banner-772x250.png', 'low' => $base . 'banner-1544x500.png' ) );
$out  = DPT_Updater::details_assets( $info, 'plugin_information', (object) array( 'slug' => 'digitizer-pro-tools' ) );
dpt_test_eq( $out->banners, array( 'low' => $base . 'banner-772x250.png', 'high' => $base . 'banner-1544x500.png' ), 'banners: low is 772x250, high is 1544x500' );
dpt_test_eq( $out->icons, array( '1x' => $base . 'icon-128x128.png', '2x' => $base . 'icon-256x256.png', 'default' => $base . 'icon-256x256.png' ), 'icons are set' );
$other = (object) array( 'slug' => 'elementor', 'banners' => array( 'high' => 'x' ) );
dpt_test_eq( DPT_Updater::details_assets( $other, 'plugin_information', (object) array( 'slug' => 'elementor' ) ), $other, 'another plugin\'s details are untouched' );
dpt_test_eq( DPT_Updater::details_assets( false, 'plugin_information', (object) array( 'slug' => 'digitizer-pro-tools' ) ), false, 'a failed lookup stays failed' );
dpt_test_eq( DPT_Updater::details_assets( $info, 'query_plugins', (object) array( 'slug' => 'digitizer-pro-tools' ) ), $info, 'other plugins_api actions are untouched' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
