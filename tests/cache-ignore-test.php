<?php
/**
 * Query strings WP Rocket should cache as one page: what a module contributes
 * reaches WP Rocket's filter, and its config file is rebuilt when - and only
 * when - that contribution changes, because WP Rocket reads the list from that
 * file, not from the filter, when it serves a cached page.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-rocket-params.php';
require_once dirname( __DIR__ ) . '/modules/cache-ignore/class-dpt-ci-module.php';

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) { unset( $GLOBALS['dpt_stub_options'][ $key ] ); return true; }
}

$GLOBALS['dpt_stub_filters'] = array();
$GLOBALS['dpt_stub_options'] = array();
$GLOBALS['dpt_rocket_rebuilds'] = 0;

DPT_Rocket_Params::register();
dpt_test_ok( dpt_stub_has_filter( 'rocket_cache_ignored_parameters' ), 'the list is offered to WP Rocket' );
dpt_test_ok( dpt_stub_has_filter( 'admin_init' ), 'and checked in the admin' );
dpt_test_eq( apply_filters( 'rocket_cache_ignored_parameters', array( 'utm_source' => 1 ) ), array( 'utm_source' => 1 ), 'nothing contributed: WP Rocket\'s own list is untouched' );

/* Without WP Rocket nothing happens, and nothing is recorded. */
add_filter( 'dpt_rocket_ignored_params', function ( $p ) { $p[] = 'before_rocket'; return $p; } );
DPT_Rocket_Params::sync();
dpt_test_eq( get_option( DPT_Rocket_Params::OPTION, 'unset' ), 'unset', 'WP Rocket absent: no fatal, nothing recorded, so it syncs once it arrives' );
$GLOBALS['dpt_stub_filters']['dpt_rocket_ignored_params'] = array();

eval( 'function rocket_generate_config_file() { $GLOBALS["dpt_rocket_rebuilds"]++; }' );

/* A site that contributes nothing never touches WP Rocket's file. */
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 0, 'nothing contributed, nothing rebuilt' );

/* The module. */
$module = new DPT_Cache_Ignore_Module();
dpt_test_eq( $module->id(), 'cache_ignore', 'the module id' );
$module->init();
$out = apply_filters( 'rocket_cache_ignored_parameters', array( 'utm_source' => 1 ) );
dpt_test_ok( isset( $out['utm_source'] ), 'WP Rocket\'s own list is kept' );
foreach ( array( 'hss_channel', 'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsa_src', 'hsa_tgt', 'hsa_kw', 'hsa_mtsa_ne', 'hsa_ver', 'hsa_mt', 'hsa_net', '__hstc', '__hssc', '__hsfp', '_hsmi', '_hsenc', 'hsutk', 'hsCtaTracking', 'submissionGuid', 'hsa_la', 'hsa_ol' ) as $name ) {
	dpt_test_ok( isset( $out[ $name ] ), "HubSpot: $name" );
}
dpt_test_eq( count( $out ), 23, 'exactly the helper plugin\'s 22, beside WP Rocket\'s own' );

add_filter( 'dpt_cache_ignored_params', function ( $p ) { $p[] = 'my_param'; $p[] = ''; $p[] = array( 'nope' ); return $p; } );
$out = apply_filters( 'rocket_cache_ignored_parameters', array() );
dpt_test_ok( isset( $out['my_param'] ) && 23 === count( $out ), 'a site can add its own; junk is dropped' );

/* Rebuilt once per change. */
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 1, 'switching the module on rebuilds the file' );
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 1, 'and not again on the next admin request' );
add_filter( 'dpt_rocket_ignored_params', function ( $p ) { array_unshift( $p, 'another' ); return $p; } );
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 2, 'a changed list rebuilds' );
$GLOBALS['dpt_stub_filters']['dpt_rocket_ignored_params'] = array();
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 3, 'switching the module off rebuilds too: the file must drop the list' );
dpt_test_eq( get_option( DPT_Rocket_Params::OPTION, 'unset' ), '', 'and nothing is left marked as synced' );
DPT_Rocket_Params::sync();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], 3, 'once' );

/* Deactivating the plugin takes the list out of WP Rocket's file. */
$module->init();
DPT_Rocket_Params::sync();
$before = $GLOBALS['dpt_rocket_rebuilds'];
DPT_Rocket_Params::deactivate();
dpt_test_eq( $GLOBALS['dpt_rocket_rebuilds'], $before + 1, 'deactivation rebuilds the file' );
dpt_test_eq( apply_filters( 'rocket_cache_ignored_parameters', array() ), array(), 'with the list already withdrawn from the filter' );
dpt_test_eq( get_option( DPT_Rocket_Params::OPTION, 'unset' ), 'unset', 'and forgets the record' );

$registry = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-dpt-plugin.php' );
dpt_test_ok( 1 === preg_match( "/'cache_ignore'\s*=>\s*array\(\s*'file'\s*=>\s*DPT_PATH \. 'modules\/cache-ignore\/class-dpt-ci-module\.php',\s*'class'\s*=>\s*'DPT_Cache_Ignore_Module',\s*'default'\s*=>\s*'0'/", $registry ), 'registered, off by default' );
dpt_test_ok( false !== strpos( $registry, 'DPT_Rocket_Params::register()' ), 'the sync is wired at boot, whichever modules are on' );
$main = (string) file_get_contents( dirname( __DIR__ ) . '/digitizer-pro-tools.php' );
dpt_test_ok( false !== strpos( $main, 'register_deactivation_hook' ) && false !== strpos( $main, 'DPT_Rocket_Params::deactivate()' ), 'and on deactivation' );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
dpt_test_ok( false !== strpos( $uninstall, "delete_option( 'dpt_rocket_ignored_hash' )" ) && false !== strpos( $uninstall, "delete_option( 'dpt_utm_passthrough' )" ), 'uninstall removes the new options' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
