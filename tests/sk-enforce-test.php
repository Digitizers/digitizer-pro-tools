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
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ), 'cache lives until candle lighting, capped at an hour' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array() ), null, 'no Cache-Control sent: none introduced (Codex round-2 P1)' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public' ) ), null, 'a Cache-Control without a lifetime is left alone' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=86400, s-maxage=86400' ) ), 'Cache-Control: public, max-age=' . min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ) . ', s-maxage=' . min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ), 'both max-age and s-maxage are shortened (Codex round-3 P2)' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=30, s-maxage=86400' ) ), 'Cache-Control: public, max-age=30, s-maxage=' . min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ), 'a shorter max-age is kept while a longer s-maxage is shortened' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, s-maxage=86400, stale-while-revalidate=60' ) ), 'Cache-Control: public, s-maxage=' . min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ) . ', stale-while-revalidate=60', 's-maxage alone is shortened, other directives kept' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=30, s-maxage=30' ) ), null, 'two short lifetimes: nothing to do' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: no-store, no-cache, must-revalidate' ) ), null, 'no-store is never overridden' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: private' ) ), null, 'private is never overridden' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'cache-control: public, max-age=30' ) ), null, 'a shorter existing max-age wins' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=99999999' ) ), 'Cache-Control: public, max-age=' . min( HOUR_IN_SECONDS, $shabbat['start'] - $clock ), 'a longer existing max-age is replaced' );
$clock = $shabbat['start'] - 600;
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), 600, 'ten minutes before candle lighting, the bound is ten minutes' );
$clock = $shabbat['start'] - 10;
DPT_SK_Enforce::reset();
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), 10, 'ten seconds before candle lighting, the bound is ten seconds - no floor across the transition (Codex round-5 P2)' );
dpt_test_eq( DPT_SK_Enforce::cache_header_for( array( 'Cache-Control: public, max-age=30' ) ), 'Cache-Control: public, max-age=10', 'a 30-second lifetime is cut to the 10 that remain' );
$clock = $at( '2026-09-02 12:00' );

/* ---- send_cache_header ---- */
sk_anon();
dpt_test_eq( DPT_SK_Enforce::send_cache_header(), DPT_SK_Enforce::cache_header_for( array() ), 'send_cache_header matches cache_header_for on an anonymous GET' );
$GLOBALS['dpt_stub_no_user'] = false;
dpt_test_eq( DPT_SK_Enforce::send_cache_header(), null, 'no cache header for a logged-in visitor' );
$GLOBALS['dpt_stub_no_user'] = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
dpt_test_eq( DPT_SK_Enforce::send_cache_header(), null, 'no cache header on a POST' );
unset( $_SERVER['REQUEST_METHOD'] );
$GLOBALS['dpt_stub_is_admin'] = true;
dpt_test_eq( DPT_SK_Enforce::send_cache_header(), null, 'no cache header in wp-admin' );
$GLOBALS['dpt_stub_is_admin'] = false;

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
sk_set( array( 'banner_text' => '10% off when we reopen at %s' ) );
sk_anon();
$pct_banner = DPT_SK_Enforce::banner_html();
dpt_test_ok( false !== strpos( $pct_banner, '10% off when we reopen at ' ) && false !== strpos( $pct_banner, wp_date( 'H:i', $shabbat['end'] ) ), 'a literal % in banner_text does not fatal sprintf, and %s still gets the reopening time' );
sk_set( array( 'banner_text' => '' ) );
sk_anon();
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), min( HOUR_IN_SECONDS, $shabbat['end'] - $clock ), 'cache lives until Havdalah, capped at an hour' );
ob_start(); DPT_SK_Enforce::print_banner(); DPT_SK_Enforce::print_banner_fallback(); $out = ob_get_clean();
dpt_test_eq( substr_count( $out, 'dpt-sk-banner' ), 1, 'banner printed once even with the fallback' );

/* ---- banner off ---- */
sk_set( array( 'banner_on' => '0' ) );
sk_anon();
dpt_test_eq( DPT_SK_Enforce::banner_html(), '', 'banner switched off' );
ob_start(); DPT_SK_Enforce::print_head_css(); $head_no_banner = ob_get_clean();
dpt_test_ok( false !== strpos( $head_no_banner, 'dpt-sk-card' ), 'closed-screen styles still print in business mode with the banner off' );
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

$GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ] = 12345;
DPT_SK_Enforce::on_transition();
dpt_test_eq( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ], 12345, 'on_transition respects an existing schedule, nothing double-booked' );

/* ---- schedule_next_transition() only under override auto ---- */
sk_set( array( 'override' => 'force_closed' ) );
sk_anon();
$GLOBALS['dpt_stub_cron'] = array();
DPT_SK_Enforce::ensure_transition_event();
dpt_test_eq( $GLOBALS['dpt_stub_cron'], array(), 'nothing scheduled under a manual override' );
sk_set( array( 'override' => 'auto' ) );

/* ---- settings save: memo reset, event dropped, caches purged (Codex round-1 P2) ---- */
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['dpt_stub_cron'][ $hook ] ); } }
$GLOBALS['dpt_stub_purged'] = 0;
add_action( 'litespeed_purge_all', function () { $GLOBALS['dpt_stub_purged']++; } );
$GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ] = 777;
DPT_SK_Enforce::on_settings_saved();
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ] ), 'saving settings drops the pending transition event' );
dpt_test_eq( $GLOBALS['dpt_stub_purged'], 1, 'saving settings purges page caches' );

/* ---- the closed-site guard runs before Content Control's priority-1 exit (Codex round-1 P2) ---- */
DPT_SK_Enforce::register();
dpt_test_eq( dpt_stub_filter_priority( 'template_redirect', array( 'DPT_SK_Enforce', 'maybe_block' ) ), 0, 'closed-site guard registered at template_redirect priority 0' );

exit( dpt_test_summary() );
