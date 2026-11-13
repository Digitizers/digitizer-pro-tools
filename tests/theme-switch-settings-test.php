<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/theme-switch/class-dpt-ts-settings.php';
function add_option( $key, $value ) { $GLOBALS['dpt_stub_options'][ $key ] = $value; }
class DPT_CB_Settings {
	public static $purges = 0;
	public static function purge_page_caches() { self::$purges++; }
}
DPT_TS_Settings::install_defaults();
dpt_test_eq( DPT_TS_Settings::all(), array( 'base' => 'light', 'default' => 'system', 'colors' => array() ), 'base palette stays unchanged until configured' );
$raw = array( 'base' => 'dark', 'default' => 'light', 'colors' => array(
	'--Brand' => '#AbCd', '--surface' => '#12345678', '--text' => '#FFF',
	'--evil;}body{color:red' => '#fff', '--payload' => '#fff;</style><script>alert(1)</script>',
	'--url' => 'url(https://example.test)', '--alias' => 'var(--text)', '--array' => array(),
	'--dpt-ts-ui' => '#123', 'no-prefix' => '#123', '--badhex' => '#12345',
) );
DPT_TS_Settings::save( $raw );
dpt_test_eq( DPT_TS_Settings::all(), array( 'base' => 'dark', 'default' => 'light', 'colors' => array(
	'--Brand' => '#abcd', '--surface' => '#12345678', '--text' => '#fff',
) ), 'rejects CSS/HTML injection, URLs, aliases, malformed hex and non-scalar values; keeps case-sensitive names' );
dpt_test_eq( DPT_CB_Settings::$purges, 1, 'changing rendered settings purges caches' );
DPT_TS_Settings::save( $raw );
dpt_test_eq( DPT_CB_Settings::$purges, 1, 'identical settings do not purge caches' );
DPT_TS_Settings::install_defaults();
dpt_test_eq( DPT_TS_Settings::all()['base'], 'dark', 'activation preserves saved settings' );
$GLOBALS['dpt_stub_options'][ DPT_TS_Settings::OPTION ] = array( 'base' => array(), 'default' => 'invalid', 'colors' => $raw['colors'], 'junk' => true );
dpt_test_eq( DPT_TS_Settings::all()['base'], 'light', 'malformed stored settings fall back safely' );
dpt_test_eq( count( DPT_TS_Settings::all()['colors'] ), 3, 'stored data is validated again at read time' );
dpt_test_eq( DPT_TS_Settings::elementor_colors(), array(), 'missing Kit API is harmless' );
$many = array();
for ( $i = 0; $i < 150; $i++ ) { $many[ '--color-' . $i ] = '#abc'; }
dpt_test_eq( count( DPT_TS_Settings::clean( array( 'colors' => $many ) )['colors'] ), 100, 'mapping size is bounded' );
dpt_test_eq( DPT_TS_Settings::save( null ), false, 'malformed whole submission is rejected' );
exit( dpt_test_summary() ? 1 : 0 );
