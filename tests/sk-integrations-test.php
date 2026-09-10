<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-zmanim.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-enforce.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-integrations.php';

if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v ) { return update_option( $k, $v ); } }
function wp_date( $format, $ts, $tz = null ) { $d = new DateTime( '@' . $ts ); $d->setTimezone( $tz ? $tz : new DateTimeZone( 'Asia/Jerusalem' ) ); return $d->format( $format ); }
function wp_doing_ajax() { return (bool) $GLOBALS['dpt_stub_doing_ajax']; }
$GLOBALS['dpt_stub_doing_ajax'] = false;
$GLOBALS['dpt_stub_is_admin'] = false; // A visitor-facing request by default; refusals_apply() also checks is_admin().
$GLOBALS['dpt_stub_notices'] = array();
function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['dpt_stub_notices'][] = array( $msg, $type ); }

/* Plugins present on this pretend site: WooCommerce, CF7, WPForms, Elementor Pro. Not Gravity. */
class WooCommerce {}
function wpcf7() {}
function wpforms() { return $GLOBALS['dpt_stub_wpforms']; }
$GLOBALS['dpt_stub_wpforms'] = (object) array( 'process' => (object) array( 'errors' => array() ) );
class ElementorPro_Plugin_Stub {}
class_alias( 'ElementorPro_Plugin_Stub', 'ElementorPro\Plugin' );

$tz = new DateTimeZone( 'Asia/Jerusalem' );
$at = function ( $local ) use ( $tz ) { return ( new DateTime( $local, $tz ) )->getTimestamp(); };
$clock = $at( '2026-09-05 12:00' ); // Shabbat noon.
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );
$GLOBALS['dpt_stub_no_user'] = true;

DPT_SK_Settings::install_defaults();
DPT_SK_Enforce::reset();
DPT_SK_Integrations::hook_plugins();

/* ---- what got hooked ---- */
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_is_purchasable' ), 'WooCommerce purchasable filter hooked' );
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_store_api_cart_errors' ), 'Store API errors hooked' );
dpt_test_ok( dpt_stub_has_filter( 'wpcf7_validate' ), 'CF7 hooked' );
dpt_test_ok( dpt_stub_has_filter( 'wpforms_process_before' ), 'WPForms hooked' );
dpt_test_ok( dpt_stub_has_filter( 'elementor_pro/forms/validation' ), 'Elementor forms hooked' );
dpt_test_ok( ! dpt_stub_has_filter( 'gform_validation' ), 'Gravity Forms absent, not hooked' );
dpt_test_ok( dpt_stub_has_filter( 'do_shortcode_tag' ), 'shortcode markup hooked' );

/* ---- closed, visitor ---- */
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), false, 'nothing purchasable on Shabbat' );
dpt_test_eq( apply_filters( 'woocommerce_add_to_cart_validation', true, 7 ), false, 'add to cart refused' );
dpt_test_eq( $GLOBALS['dpt_stub_notices'][0][1], 'error', 'refusal is an error notice' );
$errors = new WP_Error();
apply_filters( 'woocommerce_store_api_cart_errors', $errors, null );
dpt_test_ok( in_array( 'dpt_shabbat_keeper', $errors->get_error_codes(), true ), 'Store API gets an error' );
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_valid_order_statuses_for_payment' ), 'order-pay statuses filter hooked (Codex round-5 P1)' );
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_before_pay_action' ), 'order-pay handler hooked' );
dpt_test_eq( apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), null ), array(), 'no order may be paid while closed' );
$thrown = null;
try { do_action_stub( 'woocommerce_before_pay_action', null ); } catch ( Exception $e ) { $thrown = $e->getMessage(); }
dpt_test_ok( is_string( $thrown ) && '' !== $thrown, 'paying an existing order while closed throws the refusal' );
$link = apply_filters( 'woocommerce_loop_add_to_cart_link', '<a class="button">Add</a>', null );
dpt_test_ok( false !== strpos( $link, 'dpt-sk-closed' ), 'shop grid button replaced' );

$out = apply_filters( 'do_shortcode_tag', '<form>cf7</form>', 'contact-form-7', array(), array() );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'CF7 shortcode replaced' );
$out = apply_filters( 'do_shortcode_tag', '<form>wpf</form>', 'wpforms', array(), array() );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'WPForms shortcode replaced' );
$out = apply_filters( 'do_shortcode_tag', '<div>gallery</div>', 'gallery', array(), array() );
dpt_test_eq( $out, '<div>gallery</div>', 'other shortcodes untouched' );

$widget = new class() { public function get_name() { return 'form'; } };
$out = apply_filters( 'elementor/widget/render_content', '<form>el</form>', $widget );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'Elementor form widget replaced' );
$heading = new class() { public function get_name() { return 'heading'; } };
dpt_test_eq( apply_filters( 'elementor/widget/render_content', '<h2>x</h2>', $heading ), '<h2>x</h2>', 'other widgets untouched' );

$cf7 = new class() { public $invalid = array(); public function invalidate( $tag, $msg ) { $this->invalid[] = array( $tag, $msg ); } };
apply_filters( 'wpcf7_validate', $cf7, array( 'your-name' ) );
dpt_test_eq( count( $cf7->invalid ), 1, 'CF7 submission invalidated' );

do_action_stub( 'wpforms_process_before', array(), array( 'id' => 9 ) );
dpt_test_ok( isset( $GLOBALS['dpt_stub_wpforms']->process->errors[9]['header'] ), 'WPForms submission refused' );

$ajax = new class() { public $errors = array(); public function add_error_message( $m ) { $this->errors[] = $m; } };
do_action_stub( 'elementor_pro/forms/validation', null, $ajax );
dpt_test_eq( count( $ajax->errors ), 1, 'Elementor submission refused' );

/* ---- a literal % in banner_text does not fatal message() ---- */
DPT_SK_Settings::save( array( 'banner_text' => '10% off when we reopen at %s' ) );
DPT_SK_Enforce::reset();
dpt_test_ok( false !== strpos( DPT_SK_Integrations::message(), '10% off' ), 'message() keeps a literal % in banner_text' );
DPT_SK_Settings::save( array( 'banner_text' => '' ) );
DPT_SK_Enforce::reset();

/* ---- refuse_checkout / product_notice, closed and anonymous ---- */
$GLOBALS['dpt_stub_notices'] = array();
apply_filters( 'woocommerce_checkout_process', null );
dpt_test_eq( count( $GLOBALS['dpt_stub_notices'] ), 1, 'checkout on Shabbat produces one notice' );
dpt_test_eq( $GLOBALS['dpt_stub_notices'][0][1], 'error', 'checkout refusal is an error notice' );
ob_start();
apply_filters( 'woocommerce_single_product_summary', null );
$product_notice_out = ob_get_clean();
dpt_test_ok( false !== strpos( $product_notice_out, 'dpt-sk-closed-notice' ), 'product page shows the closed notice' );

/* ---- refusals_apply(): cron, WP-CLI and non-AJAX wp-admin are skipped ---- */
$GLOBALS['dpt_stub_doing_cron'] = true;
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'cron never refuses a purchase' );
$GLOBALS['dpt_stub_doing_cron'] = false;

$GLOBALS['dpt_stub_is_admin']   = true;
$GLOBALS['dpt_stub_doing_ajax'] = false;
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'a plain wp-admin request never refuses a purchase, so a manager can build an order by hand' );
$GLOBALS['dpt_stub_doing_ajax'] = true;
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), false, 'admin-ajax still refuses a purchase' );
$GLOBALS['dpt_stub_is_admin']   = false;
$GLOBALS['dpt_stub_doing_ajax'] = false;

/* ---- open, visitor: everything passes through ---- */
$clock = $at( '2026-09-02 12:00' );
DPT_SK_Enforce::reset();
$GLOBALS['dpt_stub_notices'] = array();
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'purchasable on Wednesday' );
dpt_test_eq( apply_filters( 'woocommerce_add_to_cart_validation', true, 7 ), true, 'add to cart allowed' );
dpt_test_eq( apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), null ), array( 'pending', 'failed' ), 'order-pay statuses untouched on Wednesday' );
$thrown = null;
try { do_action_stub( 'woocommerce_before_pay_action', null ); } catch ( Exception $e ) { $thrown = $e->getMessage(); }
dpt_test_eq( $thrown, null, 'paying an existing order on Wednesday is not refused' );
dpt_test_eq( apply_filters( 'do_shortcode_tag', '<form>cf7</form>', 'contact-form-7', array(), array() ), '<form>cf7</form>', 'form shown on Wednesday' );
apply_filters( 'woocommerce_checkout_process', null );
dpt_test_eq( count( $GLOBALS['dpt_stub_notices'] ), 0, 'no checkout notice on Wednesday' );
ob_start();
apply_filters( 'woocommerce_single_product_summary', null );
dpt_test_eq( ob_get_clean(), '', 'no product notice on Wednesday' );

/* ---- closed, administrator: exempt ---- */
$clock = $at( '2026-09-05 12:00' );
$GLOBALS['dpt_stub_no_user'] = false;
$GLOBALS['dpt_stub_denied_caps'] = array();
DPT_SK_Enforce::reset();
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'administrator can still buy' );

/* ---- switches off ---- */
$GLOBALS['dpt_stub_no_user'] = true;
$GLOBALS['dpt_stub_filters'] = array();
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );
DPT_SK_Settings::save( array( 'block_woo' => '0', 'block_forms' => '0' ) );
DPT_SK_Enforce::reset();
DPT_SK_Integrations::hook_plugins();
dpt_test_ok( ! dpt_stub_has_filter( 'woocommerce_is_purchasable' ), 'woo not hooked when switched off' );
dpt_test_ok( ! dpt_stub_has_filter( 'wpcf7_validate' ), 'forms not hooked when switched off' );

/* Actions in the harness share the filter registry; run one by hand. */
function do_action_stub( $tag ) {
	$args = func_get_args();
	call_user_func_array( 'apply_filters', array_merge( array( $tag, $args[1] ), array_slice( $args, 2 ) ) );
}

exit( dpt_test_summary() );
