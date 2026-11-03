<?php
/**
 * Switching a module on or off purges the page caches only when that module
 * changes what visitors are served (#85). A purge for a back-end module is
 * pure cost, and on WP Rocket with Remove Unused CSS a site-wide clean while
 * used-CSS rows are still building let preload re-cache pages without them.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-plugin.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) { apply_filters( $tag, null ); }
}
$GLOBALS['dpt_purges'] = 0;
class DPT_CB_Settings { public static function purge_page_caches() { $GLOBALS['dpt_purges']++; } }

class DPT_Test_Front_Module extends DPT_Module {
	public function id() { return 'front'; }
	public function title() { return 'Front'; }
	public function description() { return ''; }
	public function init() {}
}
class DPT_Test_Back_Module extends DPT_Module {
	public function id() { return 'back'; }
	public function title() { return 'Back'; }
	public function description() { return ''; }
	public function init() {}
	public function affects_frontend() { return false; }
}

$GLOBALS['dpt_stub_filters'] = array();
add_filter( 'dpt_modules', function () {
	return array(
		'front'   => array( 'file' => __FILE__, 'class' => 'DPT_Test_Front_Module', 'default' => '0' ),
		'back'    => array( 'file' => __FILE__, 'class' => 'DPT_Test_Back_Module', 'default' => '0' ),
		'missing' => array( 'file' => __FILE__ . '.nope', 'class' => 'DPT_Nope', 'default' => '0' ),
	);
} );
$GLOBALS['dpt_cdn_purges'] = 0;
add_action( 'dpt_shabbat_keeper_purge', function () { $GLOBALS['dpt_cdn_purges']++; } );

$plugin = DPT_Plugin::instance();
$GLOBALS['dpt_stub_options'] = array( 'dpt_settings' => array( 'modules' => array( 'front' => '0', 'back' => '0', 'missing' => '0' ) ) );
$save = function ( $map ) use ( $plugin ) {
	$GLOBALS['dpt_purges']     = 0;
	$GLOBALS['dpt_cdn_purges'] = 0;
	$plugin->save_enabled_map( $map );
	return array( $GLOBALS['dpt_purges'], $GLOBALS['dpt_cdn_purges'] );
};

dpt_test_eq( $save( array( 'front' => '0', 'back' => '1', 'missing' => '0' ) ), array( 0, 0 ), 'switching on a back-end module purges nothing' );
dpt_test_eq( $plugin->enabled_map()['back'], '1', 'and it is saved' );
dpt_test_eq( $save( array( 'front' => '0', 'back' => '0', 'missing' => '0' ) ), array( 0, 0 ), 'nor does switching it off' );
dpt_test_eq( $save( array( 'front' => '1', 'back' => '0', 'missing' => '0' ) ), array( 1, 1 ), 'a module that changes the page purges, CDN hook included' );
dpt_test_eq( $save( array( 'front' => '1', 'back' => '1', 'missing' => '0' ) ), array( 0, 0 ), 'saving with only a back-end change purges nothing' );
dpt_test_eq( $save( array( 'front' => '0', 'back' => '0', 'missing' => '0' ) ), array( 1, 1 ), 'a mixed change purges once' );
dpt_test_eq( $save( array( 'front' => '0', 'back' => '0', 'missing' => '0' ) ), array( 0, 0 ), 'saving with nothing changed purges nothing' );
dpt_test_eq( $save( array( 'front' => '0', 'back' => '0', 'missing' => '1' ) ), array( 1, 1 ), 'a module that cannot be loaded is assumed to change the page' );

/* The real modules: back-end ones say so, the rest keep the safe default. */
$back = array(
	'agent-log/class-dpt-al-module.php',
	'update-emails/class-dpt-ue-module.php',
	'update-policy/class-dpt-up-module.php',
	'user-role-editor/class-dpt-ure-module.php',
	'resend-mail/class-dpt-rm-module.php',
	'onboarding/class-dpt-onb-module.php',
	'duplicate-post/class-dpt-dp-module.php',
	'rest-bridge/class-dpt-rb-module.php',
	'cache-ignore/class-dpt-ci-module.php',
	'related-fill/class-dpt-rf-module.php',
);
$rx = '/public function affects_frontend\(\)\s*\{\s*return false;\s*\}/';
foreach ( glob( dirname( __DIR__ ) . '/modules/*/class-dpt-*-module.php' ) as $file ) {
	$rel = basename( dirname( $file ) ) . '/' . basename( $file );
	$src = (string) file_get_contents( $file );
	if ( in_array( $rel, $back, true ) ) {
		dpt_test_ok( 1 === preg_match( $rx, $src ), "$rel: back-end only, no purge" );
	} else {
		dpt_test_ok( false === strpos( $src, 'affects_frontend' ), "$rel: changes the page, keeps the purge" );
	}
}
dpt_test_eq( count( array_filter( $back, function ( $rel ) { return file_exists( dirname( __DIR__ ) . '/modules/' . $rel ); } ) ), count( $back ), 'every back-end module named here exists' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
