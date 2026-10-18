<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
if ( ! function_exists( 'do_action' ) ) { function do_action( $t ) {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $c ) { $GLOBALS['shortcode_tags'][ $t ] = $c; } }
require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-module.php';

$GLOBALS['dpt_stub_is_admin'] = false;

/* ---- standing down for the standalone plugin ---- */

// The module was extracted into Digitizer Cookie Consent for WordPress.org. A
// site running both would print two banners and two head prechecks from two
// sets of settings - so when the standalone is present this module registers
// nothing and says why on the Modules screen.
$module = new DPT_Cookie_Banner_Module();

dpt_test_ok( ! DPT_Cookie_Banner_Module::standalone_active(), 'without the standalone plugin the module is in charge' );
dpt_test_ok( $module->user_can_toggle(), 'its switch is the Modules screen\'s own business' );
dpt_test_eq( $module->toggle_denied_reason(), '', 'and it has nothing to explain' );

$GLOBALS['dpt_stub_filters'] = array();
$module->init();
dpt_test_ok( dpt_stub_has_filter( 'wp_footer' ), 'so it registers the banner' );
dpt_test_ok( dpt_stub_has_filter( 'wp_head' ), 'and the head precheck' );
dpt_test_ok( dpt_stub_has_filter( 'admin_post_dpt_cb_save' ), 'and its settings screen' );

// The standalone announces itself by its own class. Declared here rather than
// at the top of the file because a class declaration at the top level is
// hoisted, which would make the assertions above pass for the wrong reason.
if ( ! class_exists( 'Digitizer_Cookie_Consent_Core' ) ) {
	class Digitizer_Cookie_Consent_Core {}
}

dpt_test_ok( DPT_Cookie_Banner_Module::standalone_active(), 'the standalone plugin is seen by its core class' );
// Locked, not merely silent: a module switched off while the standalone is in
// charge would stay off after the standalone is removed, and the site would
// lose its banner - and its script blocking - without anything saying so.
dpt_test_ok( ! $module->user_can_toggle(), 'the switch is locked while the standalone is in charge' );
dpt_test_ok( false !== strpos( $module->toggle_denied_reason(), 'Digitizer Cookie Consent' ), 'the reason names the plugin as the Plugins screen does' );
dpt_test_ok( false !== strpos( $module->toggle_denied_reason(), 'Settings' ), 'and says where its settings are' );

$standing_down = new DPT_Cookie_Banner_Module();
$GLOBALS['dpt_stub_filters'] = array();
$GLOBALS['shortcode_tags'] = array();
$standing_down->init();
dpt_test_ok( ! dpt_stub_has_filter( 'wp_footer' ) && ! dpt_stub_has_filter( 'wp_head' ) && ! dpt_stub_has_filter( 'admin_post_dpt_cb_save' ), 'and the module registers no banner, precheck or settings screen' );

// The standalone, for wp.org, uses only its own full prefix. Site code written
// against the module's names keeps working: the module bridges them.
add_filter( 'dpt_cb_open_selector', function ( $sel ) { return $sel . ', .legacy-open'; } );
dpt_test_ok( '.legacy-open' === substr( (string) apply_filters( 'digitizer_cookie_consent_open_selector', '.dpt-cb-open' ), -12 ), 'a callback on dpt_cb_open_selector still changes what reopens the banner' );
add_filter( 'dpt_cb_rtl_langs', function ( $langs ) { $langs[] = 'ckb'; return $langs; } );
dpt_test_ok( in_array( 'ckb', (array) apply_filters( 'digitizer_cookie_consent_rtl_langs', array( 'he' ) ), true ), 'and one on dpt_cb_rtl_langs still adds an RTL language' );
dpt_test_ok( dpt_stub_has_filter( 'digitizer_cookie_consent_purge_caches' ), 'the standalone\'s cache purge fires dpt_cb_purge_caches too' );
$GLOBALS['shortcode_tags']['digitizer_cookie_consent_settings'] = function ( $atts ) { return 'standalone link: ' . ( is_array( $atts ) && isset( $atts['text'] ) ? $atts['text'] : '' ); };
dpt_test_ok( isset( $GLOBALS['shortcode_tags']['dpt_cookie_settings'] ), '[dpt_cookie_settings] is still registered' );
dpt_test_eq( call_user_func( $GLOBALS['shortcode_tags']['dpt_cookie_settings'], array( 'text' => 'Cookies' ) ), 'standalone link: Cookies', 'and prints the standalone\'s link' );
unset( $GLOBALS['shortcode_tags']['digitizer_cookie_consent_settings'] );
dpt_test_eq( call_user_func( $GLOBALS['shortcode_tags']['dpt_cookie_settings'], array() ), '', 'without the standalone\'s shortcode, it prints nothing rather than failing' );
$GLOBALS['dpt_stub_filters'] = array();

// No settings screen of its own either: one banner, one place to configure it.
$GLOBALS['dpt_stub_submenus'] = 0;
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page() { $GLOBALS['dpt_stub_submenus']++; }
}
$standing_down->register_admin_menu( 'digitizer-pro-tools' );
dpt_test_eq( $GLOBALS['dpt_stub_submenus'], 0, 'nor a settings page under the Digitizer menu' );
$module->register_admin_menu( 'digitizer-pro-tools' );
dpt_test_eq( $GLOBALS['dpt_stub_submenus'], 1, 'which the module initialised before the standalone appeared does add' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
