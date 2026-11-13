<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/theme-switch/class-dpt-ts-settings.php';
require_once dirname( __DIR__ ) . '/modules/theme-switch/class-dpt-ts-admin.php';
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action ) { throw new RuntimeException( 'nonce rejected: ' . $action ); }
function selected( $a, $b ) { if ( $a === $b ) { echo 'selected'; } }
function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture-only" />'; }
$admin = new DPT_TS_Admin();
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' );
try {
	$admin->handle_save();
	dpt_test_ok( false, 'unauthorized submission must stop' );
} catch ( RuntimeException $e ) {
	dpt_test_eq( $e->getMessage(), 'You are not allowed to do that.', 'save enforces administrator capability before processing input' );
}
ob_start(); $admin->render_page(); $denied = ob_get_clean();
dpt_test_eq( $denied, '', 'unauthorized visitor sees no settings' );
$GLOBALS['dpt_stub_denied_caps'] = array();
$_POST['dpt_ts'] = array( 'base' => 'dark', 'colors' => array( '--text' => '#fff' ) );
try {
	$admin->handle_save();
	dpt_test_ok( false, 'invalid nonce must stop' );
} catch ( RuntimeException $e ) {
	dpt_test_eq( $e->getMessage(), 'nonce rejected: dpt_ts_settings', 'save validates the settings nonce' );
}
dpt_test_eq( DPT_TS_Settings::all(), DPT_TS_Settings::defaults(), 'failed authorization does not change options' );
$_GET = array();
$GLOBALS['dpt_stub_options'][ DPT_TS_Settings::OPTION ] = array( 'colors' => array( '--surface' => '#111' ) );
ob_start(); $admin->render_page(); $html = ob_get_clean();
dpt_test_ok( false !== strpos( $html, '--surface=#111' ), 'manual mappings remain editable without Elementor' );
dpt_test_ok( false !== strpos( $html, '[dpt_theme_switch]' ), 'screen explains where the visitor switch goes' );
dpt_test_ok( false !== strpos( $html, 'name="dpt_ts[base]"' ) && false !== strpos( $html, 'name="dpt_ts[default]"' ), 'base palette and first-visit preference have independent controls' );
exit( dpt_test_summary() ? 1 : 0 );
