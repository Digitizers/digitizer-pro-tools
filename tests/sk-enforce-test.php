<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-zmanim.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-enforce.php';

/* The slice of WordPress this class touches and the harness does not stub. */
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v ) { return update_option( $k, $v ); } }
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) { $args = func_get_args(); $GLOBALS['dpt_stub_actions_fired'][] = $args; call_user_func_array( 'apply_filters', array_merge( array( $tag, null ), array_slice( $args, 1 ) ) ); }
}
$GLOBALS['dpt_stub_actions_fired'] = array();
function wp_doing_ajax() { return (bool) $GLOBALS['dpt_stub_doing_ajax']; }
$GLOBALS['dpt_stub_doing_ajax'] = false;
function status_header( $code ) { $GLOBALS['dpt_stub_status'] = (int) $code; }
function nocache_headers() { $GLOBALS['dpt_stub_nocache'] = true; }
function wp_date( $format, $ts, $tz = null ) { $d = new DateTime( '@' . $ts ); $d->setTimezone( $tz ? $tz : new DateTimeZone( 'Asia/Jerusalem' ) ); return $d->format( $format ); }
function is_rtl() { return true; }
function get_site_icon_url( $size = 512 ) { return ''; }
function get_bloginfo( $show = '' ) { return 'Example Site'; }
function language_attributes() { echo 'lang="he-IL" dir="rtl"'; }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['dpt_stub_cron'][ $hook ] ) ? $GLOBALS['dpt_stub_cron'][ $hook ] : false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['dpt_stub_cron'][ $hook ] = $ts; return true; }
$GLOBALS['dpt_stub_cron'] = array();
$GLOBALS['dpt_stub_is_admin'] = false;
if ( ! function_exists( "__return_false" ) ) { function __return_false() { return false; } }

$tz = new DateTimeZone( 'Asia/Jerusalem' );
$at = function ( $local ) use ( $tz ) { return ( new DateTime( $local, $tz ) )->getTimestamp(); };
$clock = $at( '2026-09-02 12:00' ); // Wednesday.
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );

function sk_set( $pairs ) {
	DPT_SK_Settings::save( $pairs );
	DPT_SK_Enforce::reset();
}
function sk_anon() { $GLOBALS['dpt_stub_no_user'] = true; $GLOBALS['dpt_stub_denied_caps'] = array(); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }
function sk_admin() { $GLOBALS['dpt_stub_no_user'] = false; $GLOBALS['dpt_stub_denied_caps'] = array(); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }
function sk_editor() { $GLOBALS['dpt_stub_no_user'] = false; $GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' ); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }

DPT_SK_Settings::install_defaults();
$z = DPT_SK_Enforce::zmanim();
$shabbat = $z->windows( $at( '2026-09-04 00:00' ), $at( '2026-09-06 00:00' ) )[0];

/* ---- Wednesday, anonymous: open ---- */
sk_anon();
dpt_test_ok( ! DPT_SK_Enforce::is_closed(), 'Wednesday is open' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'nothing to block on Wednesday' );
dpt_test_eq( DPT_SK_Enforce::banner_html(), '', 'no banner when open' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), $shabbat['start'] - $clock, 'cache lives until candle lighting' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array() ), 'Cache-Control: public, max-age=' . ( $shabbat['start'] - $clock ), 'cache header when nothing else has spoken' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: no-store, no-cache, must-revalidate' ) ), null, 'no-store is never overridden' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: private' ) ), null, 'private is never overridden' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'cache-control: public, max-age=30' ) ), null, 'a shorter existing max-age wins' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=99999999' ) ), 'Cache-Control: public, max-age=' . ( $shabbat['start'] - $clock ), 'a longer existing max-age is replaced' );

/* ---- Shabbat noon, business mode, anonymous ---- */
$clock = $at( '2026-09-05 12:00' );
sk_anon();
dpt_test_ok( DPT_SK_Enforce::is_closed(), 'Shabbat noon is closed' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'applies to a visitor' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'business mode does not block the page' );
dpt_test_eq( DPT_SK_Enforce::reopens_at(), $shabbat['end'], 'reopens at Havdalah' );
$banner = DPT_SK_Enforce::banner_html();
dpt_test_ok( false !== strpos( $banner, 'dpt-sk-banner' ), 'banner markup' );
dpt_test_ok( false !== strpos( $banner, wp_date( 'H:i', $shabbat['end'] ) ), 'banner names the reopening time' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), $shabbat['end'] - $clock, 'cache lives until Havdalah' );
ob_start(); DPT_SK_Enforce::print_banner(); DPT_SK_Enforce::print_banner_fallback(); $out = ob_get_clean();
dpt_test_eq( substr_count( $out, 'dpt-sk-banner' ), 1, 'banner printed once even with the fallback' );

/* ---- banner off ---- */
sk_set( array( 'banner_on' => '0' ) );
sk_anon();
dpt_test_eq( DPT_SK_Enforce::banner_html(), '', 'banner switched off' );
sk_set( array( 'banner_on' => '1' ) );

/* ---- contact CSS ---- */
sk_anon();
ob_start(); DPT_SK_Enforce::print_head_css(); $head = ob_get_clean();
dpt_test_ok( false === strpos( $head, 'tel:' ), 'contact links untouched by default' );
sk_set( array( 'hide_contact' => '1' ) );
sk_anon();
ob_start(); DPT_SK_Enforce::print_head_css(); $head = ob_get_clean();
dpt_test_ok( false !== strpos( $head, 'a[href^="tel:"]' ), 'contact links hidden when asked' );
sk_set( array( 'hide_contact' => '0' ) );

/* ---- Shabbat, closed mode, anonymous: 503 ---- */
sk_set( array( 'mode' => 'closed' ) );
sk_anon();
dpt_test_ok( DPT_SK_Enforce::should_block(), 'closed mode blocks the page' );
dpt_test_eq( DPT_SK_Enforce::closed_headers(), array( 'Retry-After: ' . ( $shabbat['end'] - $clock ) ), 'Retry-After until Havdalah' );
$html = DPT_SK_Enforce::render_closed_screen();
dpt_test_ok( false !== strpos( $html, 'The site is closed for Shabbat' ), 'closed screen carries the title' );
dpt_test_ok( false !== strpos( $html, 'dir="rtl"' ), 'closed screen is RTL on an RTL site' );
dpt_test_ok( false !== strpos( $html, 'Jerusalem' ), 'closed screen names the city' );
dpt_test_ok( false !== strpos( $html, '<style' ), 'styles inlined' );

/* ---- exemptions ---- */
sk_admin();
dpt_test_ok( ! DPT_SK_Enforce::applies(), 'administrator is exempt' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'administrator not blocked' );
sk_editor();
dpt_test_ok( DPT_SK_Enforce::applies(), 'editor is not exempt' );
sk_admin();
add_filter( 'dpt_shabbat_keeper_exempt', '__return_false' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'filter can revoke the exemption' );
remove_filter( 'dpt_shabbat_keeper_exempt', '__return_false' );

/* ---- requests never touched ---- */
sk_anon();
$GLOBALS['dpt_stub_is_admin'] = true;
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'wp-admin never blocked' );
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_doing_ajax'] = true;
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'ajax never blocked' );
$GLOBALS['dpt_stub_doing_ajax'] = false;
$GLOBALS['pagenow'] = 'wp-login.php';
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'login never blocked' );
unset( $GLOBALS['pagenow'] );
dpt_test_ok( DPT_SK_Enforce::should_block(), 'plain front request blocked again' );

/* ---- preview and overrides ---- */
$clock = $at( '2026-09-02 12:00' );
sk_admin();
$_GET['dpt_shabbat'] = 'preview';
DPT_SK_Enforce::reset();
dpt_test_ok( DPT_SK_Enforce::is_preview(), 'administrator preview recognised' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'preview overrides the exemption' );
dpt_test_ok( DPT_SK_Enforce::should_block(), 'preview shows the closed site on a Wednesday' );
dpt_test_eq( DPT_SK_Enforce::reopens_at(), null, 'no reopening time outside a real window' );
dpt_test_ok( false !== strpos( DPT_SK_Enforce::render_closed_screen(), 'after Havdalah' ), 'closed screen says after Havdalah when there is no window' );
sk_editor();
$_GET['dpt_shabbat'] = 'preview';
DPT_SK_Enforce::reset();
dpt_test_ok( ! DPT_SK_Enforce::is_preview(), 'preview is for administrators only' );
sk_anon();
sk_set( array( 'override' => 'force_closed' ) );
dpt_test_ok( DPT_SK_Enforce::is_closed(), 'force_closed closes a Wednesday' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), null, 'no cache bound under a manual override' );
$clock = $at( '2026-09-05 12:00' );
sk_set( array( 'override' => 'force_open' ) );
dpt_test_ok( ! DPT_SK_Enforce::is_closed(), 'force_open opens Shabbat' );
sk_set( array( 'override' => 'auto', 'mode' => 'business' ) );

/* ---- transition cron ---- */
$clock = $at( '2026-09-02 12:00' );
sk_anon();
DPT_SK_Enforce::ensure_transition_event();
dpt_test_eq( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ], $shabbat['start'] + 30, 'purge scheduled just after candle lighting' );
DPT_SK_Enforce::ensure_transition_event();
dpt_test_eq( count( $GLOBALS['dpt_stub_cron'] ), 1, 'not scheduled twice' );
$clock = $shabbat['start'] + 30;
$GLOBALS['dpt_stub_cron'] = array();
$GLOBALS['dpt_stub_actions_fired'] = array();
DPT_SK_Enforce::on_transition();
$fired = array_filter( $GLOBALS['dpt_stub_actions_fired'], function ( $a ) { return 'dpt_shabbat_keeper_transition' === $a[0]; } );
dpt_test_eq( count( $fired ), 1, 'transition action fired' );
dpt_test_eq( array_values( $fired )[0][1], true, 'transition reports now closed' );
dpt_test_eq( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ], $shabbat['end'] + 30, 'next purge scheduled for Havdalah' );

exit( dpt_test_summary() );
