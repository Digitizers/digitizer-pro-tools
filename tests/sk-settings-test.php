<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value ) { return update_option( $key, $value ); }
}

/* ---- defaults ---- */
$d = DPT_SK_Settings::defaults();
dpt_test_eq( $d['mode'], 'business', 'default mode is business' );
dpt_test_eq( $d['city'], 'jerusalem', 'default city' );
dpt_test_eq( $d['havdalah'], '42', 'default havdalah' );
dpt_test_eq( $d['override'], 'auto', 'default override' );
dpt_test_eq( $d['hide_contact'], '0', 'contact links shown by default' );
dpt_test_eq( $d['block_woo'], '1', 'woo blocked by default' );
dpt_test_eq( count( $d ), 15, 'fifteen keys' );

/* ---- install_defaults seeds, then only fills gaps ---- */
DPT_SK_Settings::install_defaults();
dpt_test_eq( get_option( DPT_SK_Settings::OPTION ), $d, 'seeded with defaults' );
update_option( DPT_SK_Settings::OPTION, array( 'mode' => 'closed', 'stale' => 'x' ) );
DPT_SK_Settings::install_defaults();
$o = get_option( DPT_SK_Settings::OPTION );
dpt_test_eq( $o['mode'], 'closed', 'existing value kept' );
dpt_test_eq( $o['city'], 'jerusalem', 'missing key filled' );
dpt_test_ok( ! isset( $o['stale'] ), 'unknown key dropped' );

/* ---- sanitize ---- */
$c = DPT_SK_Settings::sanitize( array(
	'mode'          => 'banana',
	'city'          => 'atlantis',
	'havdalah'      => '99',
	'override'      => 'maybe',
	'custom_lat'    => '123.4',
	'custom_lon'    => '-200',
	'custom_candle' => '500',
	'block_woo'     => 'yes',
	'banner_on'     => '0',
	'closed_title'  => "  <b>Closed</b>\n",
	'closed_message' => '<p>Hello</p><script>alert(1)</script>',
	'banner_text'   => 'Back at %s',
	'evil'          => '1',
) );
dpt_test_eq( $c['mode'], 'business', 'unknown mode falls back' );
dpt_test_eq( $c['city'], 'jerusalem', 'unknown city falls back' );
dpt_test_eq( $c['havdalah'], '42', 'unknown havdalah falls back' );
dpt_test_eq( $c['override'], 'auto', 'unknown override falls back' );
dpt_test_eq( $c['custom_lat'], '33.4', 'latitude clamped to Israel (Codex round-9 P2)' );
dpt_test_eq( $c['custom_lon'], '34.2', 'longitude clamped to Israel' );
dpt_test_eq( $c['custom_candle'], '60', 'candle minutes clamped' );
dpt_test_eq( $c['block_woo'], '1', 'truthy bool is 1' );
dpt_test_eq( $c['banner_on'], '0', 'zero bool is 0' );
dpt_test_eq( $c['closed_title'], 'Closed', 'title is plain text, trimmed' );
dpt_test_ok( false === strpos( $c['closed_message'], '<script' ), 'script stripped from message' );
dpt_test_ok( false !== strpos( $c['closed_message'], '<p>' ), 'paragraph kept in message' );
dpt_test_eq( $c['banner_text'], 'Back at %s', 'banner keeps its placeholder' );
dpt_test_ok( ! isset( $c['evil'] ), 'unknown key dropped' );

$c = DPT_SK_Settings::sanitize( array( 'mode' => 'closed', 'city' => 'custom', 'havdalah' => '72', 'override' => 'force_closed', 'custom_lat' => '31.5', 'custom_lon' => '34.9', 'custom_candle' => '22' ) );
dpt_test_eq( $c['mode'], 'closed', 'valid mode kept' );
dpt_test_eq( $c['city'], 'custom', 'custom city kept' );
dpt_test_eq( $c['havdalah'], '72', 'Rabbeinu Tam kept' );
dpt_test_eq( $c['override'], 'force_closed', 'force kept' );
dpt_test_eq( $c['custom_lat'], '31.5', 'latitude kept' );

/* ---- text fallbacks ---- */
DPT_SK_Settings::save( array( 'closed_title' => '' ) );
dpt_test_eq( DPT_SK_Settings::text( 'closed_title' ), 'The site is closed for Shabbat', 'empty title falls back to the translated default' );
dpt_test_ok( false !== strpos( DPT_SK_Settings::text( 'banner_text' ), '%s' ), 'default banner text carries the placeholder' );
DPT_SK_Settings::save( array( 'closed_title' => 'Shhh' ) );
dpt_test_eq( DPT_SK_Settings::text( 'closed_title' ), 'Shhh', 'saved title wins' );

/* ---- location ---- */
DPT_SK_Settings::save( array( 'city' => 'haifa' ) );
dpt_test_eq( DPT_SK_Settings::location()['candle'], 30, 'city location comes from the table' );
DPT_SK_Settings::save( array( 'city' => 'custom', 'custom_lat' => '31.5', 'custom_lon' => '34.9', 'custom_candle' => '25' ) );
$loc = DPT_SK_Settings::location();
dpt_test_eq( array( $loc['lat'], $loc['lon'], $loc['candle'] ), array( 31.5, 34.9, 25 ), 'custom location comes from settings' );

exit( dpt_test_summary() );
