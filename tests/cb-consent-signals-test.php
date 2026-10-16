<?php
/**
 * Cookie Banner: the visitor's choice signalled outward - Google Consent
 * Mode v2 and the WP Consent API. Both are off by default, and off must
 * leave the page exactly as it was.
 *
 * The head precheck is run for real in Node where Node is available, so the
 * assertions are about what lands in the data layer, not about how the
 * script is spelled.
 */
require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}

require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-settings.php';
require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php';

$GLOBALS['dpt_stub_no_user']  = true;
$GLOBALS['dpt_stub_is_admin'] = false; // A page on the site.

/** Save these settings over the defaults. */
function dpt_test_cs_opts( $saved ) {
	$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ] = $saved;
}

/** The head precheck as printed for the current settings. */
function dpt_test_cs_precheck() {
	$frontend = new DPT_CB_Frontend();
	ob_start();
	$frontend->inject_inline_consent_check();
	return (string) ob_get_clean();
}

/**
 * Run the precheck's script in Node with a cookie, and return what it left
 * in window.dataLayer, as [command, type, params] triples. Null without Node.
 */
function dpt_test_cs_run( $html, $cookie ) {
	static $node = null;
	if ( null === $node ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
	}
	if ( '' === $node || ! preg_match( '#<script id="dpt-cb-precheck"[^>]*>(.*?)</script>#s', $html, $m ) ) {
		return null;
	}
	$harness = 'var window = {}; var document = { cookie: ' . json_encode( $cookie ) . ', documentElement: { setAttribute: function(){} } };'
		. 'var localStorage = { getItem: function(){ return null; } };'
		. $m[1]
		. ';process.stdout.write(JSON.stringify((window.dataLayer || []).map(function(a){ return Array.prototype.slice.call(a); })));';
	$file = tempnam( sys_get_temp_dir(), 'dptcs' ) . '.js';
	file_put_contents( $file, $harness );
	$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	$decoded = json_decode( (string) $out, true );
	return is_array( $decoded ) ? $decoded : array( 'node error' => $out );
}

/** A stored choice, as the banner writes it. */
function dpt_test_cs_cookie( $cats, $version = '1' ) {
	$c = array_merge( array( 'v' => $version, 'essential' => true, 'ts' => (int) ( microtime( true ) * 1000 ) ), $cats );
	return 'dpt_consent=' . rawurlencode( json_encode( $c ) );
}

/* ---- off: nothing changes ---- */

$d = DPT_CB_Settings::defaults();
dpt_test_eq( array( $d['consent_mode'], $d['wp_consent_api'] ), array( '0', '0' ), 'both are off by default' );

dpt_test_cs_opts( array() );
$html = dpt_test_cs_precheck();
dpt_test_ok( false === strpos( $html, "gtag('consent', 'default'" ) && false !== strpos( $html, 'var CM = null;' ), 'off: the precheck sets no consent state' );
$layer = dpt_test_cs_run( $html, dpt_test_cs_cookie( array( 'analytics' => true ) ) );
if ( null !== $layer ) {
	dpt_test_eq( $layer, array(), 'and leaves no data layer behind' );
}

/* ---- on: the defaults ---- */

dpt_test_eq(
	DPT_CB_Frontend::consent_mode_defaults(),
	array(
		'functionality_storage'   => 'denied',
		'personalization_storage' => 'denied',
		'analytics_storage'       => 'denied',
		'ad_storage'              => 'denied',
		'ad_user_data'            => 'denied',
		'ad_personalization'      => 'denied',
		'security_storage'        => 'granted',
		'wait_for_update'         => 500,
	),
	'every signal a category grants starts denied; security storage is granted; tags wait half a second for a stored choice'
);

dpt_test_cs_opts( array( 'consent_mode' => '1' ) );
$html = dpt_test_cs_precheck();
dpt_test_ok( strpos( $html, "gtag('consent', 'default'" ) < strpos( $html, 'try {' ), 'the default is set first, before anything that could fail' );

$layer = dpt_test_cs_run( $html, '' );
if ( null !== $layer ) {
	dpt_test_eq( $layer, array( array( 'consent', 'default', DPT_CB_Frontend::consent_mode_defaults() ) ), 'a first visit: the default and nothing else' );

	/* ---- a stored choice updates it in the head ---- */

	$layer = dpt_test_cs_run( $html, dpt_test_cs_cookie( array( 'functional' => false, 'analytics' => true, 'marketing' => false ) ) );
	dpt_test_eq( count( $layer ), 2, 'a returning visitor: the default, then an update' );
	dpt_test_eq(
		isset( $layer[1] ) ? $layer[1] : null,
		array(
			'consent',
			'update',
			array(
				'functionality_storage'   => 'denied',
				'personalization_storage' => 'denied',
				'analytics_storage'       => 'granted',
				'ad_storage'              => 'denied',
				'ad_user_data'            => 'denied',
				'ad_personalization'      => 'denied',
			),
		),
		'analytics allowed grants analytics storage alone'
	);
	$layer = dpt_test_cs_run( $html, dpt_test_cs_cookie( array( 'functional' => true, 'analytics' => true, 'marketing' => true ) ) );
	dpt_test_eq( isset( $layer[1][2] ) ? array_values( array_unique( array_values( $layer[1][2] ) ) ) : null, array( 'granted' ), 'accept all grants all six' );
	$layer = dpt_test_cs_run( $html, dpt_test_cs_cookie( array( 'analytics' => true ), 'old' ) );
	dpt_test_eq( count( $layer ), 1, 'a choice made under an older consent version is not used: the visitor is asked again, from denied' );
} else {
	echo "SKIP: node not found, the precheck was not executed\n";
}

/* ---- a category switched off since ---- */

dpt_test_cs_opts( array( 'consent_mode' => '1', 'cat_marketing_enabled' => '0' ) );
$layer = dpt_test_cs_run( dpt_test_cs_precheck(), dpt_test_cs_cookie( array( 'functional' => true, 'analytics' => true, 'marketing' => true ) ) );
if ( null !== $layer ) {
	dpt_test_eq(
		isset( $layer[1][2] ) ? array( $layer[1][2]['analytics_storage'], $layer[1][2]['ad_storage'], $layer[1][2]['ad_user_data'], $layer[1][2]['ad_personalization'] ) : null,
		array( 'granted', 'denied', 'denied', 'denied' ),
		'marketing accepted once, switched off since: its signals stay denied'
	);
}

/* ---- hidden on mobile ---- */

dpt_test_cs_opts( array( 'consent_mode' => '1', 'show_on_mobile' => '0' ) );
dpt_test_ok( false === strpos( dpt_test_cs_precheck(), "gtag('consent', 'default'" ), 'with the banner hidden on phones, phone visitors could never grant: nothing is sent' );
dpt_test_eq( DPT_CB_Frontend::signals_possible( DPT_CB_Settings::all() ), false, 'one rule for both integrations' );

/* ---- on, but no banner ---- */

dpt_test_cs_opts( array( 'consent_mode' => '1', 'enabled' => '0' ) );
dpt_test_ok( false === strpos( dpt_test_cs_precheck(), "gtag('consent', 'default'" ), 'with the banner off nobody could ever grant, so nothing is denied either' );

/* ---- the WP Consent API ---- */

dpt_test_eq(
	DPT_CB_Frontend::wp_consent_api_map(),
	array(
		'functional' => array( 'preferences' ),
		'analytics'  => array( 'statistics', 'statistics-anonymous' ),
		'marketing'  => array( 'marketing' ),
	),
	'banner categories in the API\'s terms; the API\'s own "functional" is the essential kind'
);

$GLOBALS['dpt_stub_filters'] = array();
dpt_test_cs_opts( array() );
new DPT_CB_Frontend();
dpt_test_ok( ! dpt_stub_has_filter( 'wp_get_consent_type' ), 'off: the banner does not register with the API' );

$GLOBALS['dpt_stub_filters'] = array();
dpt_test_cs_opts( array( 'wp_consent_api' => '1' ) );
new DPT_CB_Frontend();
dpt_test_ok( dpt_stub_has_filter( 'wp_consent_api_registered_digitizer-pro-tools/digitizer-pro-tools.php' ), 'on: it registers itself, by the plugin\'s basename' );
dpt_test_eq( apply_filters( 'wp_get_consent_type', false ), 'optin', 'and collects opt-in consent' );
$GLOBALS['dpt_stub_filters'] = array();

// With the banner off, an opt-in manager would leave every category denied
// for good: nobody could ever say yes.
dpt_test_cs_opts( array( 'wp_consent_api' => '1', 'enabled' => '0' ) );
new DPT_CB_Frontend();
dpt_test_ok( ! dpt_stub_has_filter( 'wp_get_consent_type' ) && ! dpt_stub_has_filter( 'wp_has_consent' ), 'with the banner off it does not register at all' );
$GLOBALS['dpt_stub_filters'] = array();

dpt_test_cs_opts( array( 'wp_consent_api' => '1', 'show_on_mobile' => '0' ) );
new DPT_CB_Frontend();
dpt_test_ok( ! dpt_stub_has_filter( 'wp_get_consent_type' ), 'nor while the banner is hidden on mobile' );
$GLOBALS['dpt_stub_filters'] = array();

/* ---- wp_has_consent(): the banner's cookie is the truth ---- */

dpt_test_cs_opts( array( 'wp_consent_api' => '1' ) );
$frontend = new DPT_CB_Frontend();
dpt_test_ok( dpt_stub_has_filter( 'wp_has_consent' ), 'on: wp_has_consent() is answered from the banner\'s cookie' );
$GLOBALS['dpt_stub_filters'] = array();

$now = (int) ( microtime( true ) * 1000 );
unset( $_COOKIE['dpt_consent'] );
dpt_test_eq( $frontend->filter_wp_has_consent( true, 'statistics' ), false, 'no choice: an "allow" left in the API\'s own cookies does not count' );
dpt_test_eq( $frontend->filter_wp_has_consent( false, 'functional' ), true, 'the API\'s functional, the essential kind, is always allowed - also before frontend.js has written its cookie' );
dpt_test_eq( $frontend->filter_wp_has_consent( false, 'some-other' ), false, 'and so is a category that is not ours' );

$_COOKIE['dpt_consent'] = json_encode( array( 'v' => '1', 'ts' => $now, 'functional' => false, 'analytics' => true, 'marketing' => false ) );
dpt_test_eq(
	array( $frontend->filter_wp_has_consent( false, 'statistics' ), $frontend->filter_wp_has_consent( false, 'statistics-anonymous' ), $frontend->filter_wp_has_consent( true, 'marketing' ), $frontend->filter_wp_has_consent( true, 'preferences' ) ),
	array( true, true, false, false ),
	'a valid analytics-only choice: statistics yes, marketing and preferences no'
);
$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ]['cat_analytics_enabled'] = '0';
dpt_test_eq( $frontend->filter_wp_has_consent( false, 'statistics' ), false, 'analytics switched off since the choice: statistics no' );
$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ]['cat_analytics_enabled'] = '1';
$_COOKIE['dpt_consent'] = json_encode( array( 'v' => 'old', 'ts' => $now, 'analytics' => true ) );
dpt_test_eq( $frontend->filter_wp_has_consent( true, 'statistics' ), false, 'a choice under an older consent version no longer counts' );
$_COOKIE['dpt_consent'] = json_encode( array( 'v' => '1', 'ts' => $now - 400 * 86400000, 'analytics' => true ) );
dpt_test_eq( $frontend->filter_wp_has_consent( true, 'statistics' ), false, 'nor does an expired one' );
unset( $_COOKIE['dpt_consent'] );

/* ---- frontend.js ---- */

$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/js/frontend.js' );
$finish = substr( $js, (int) strpos( $js, 'function finishConsent' ) );
dpt_test_ok( strpos( $finish, 'signalConsentMode(consent);' ) < strpos( $finish, 'injectConsentedScripts(consent);' ), 'a choice on the page is signalled before the consented scripts are injected' );
dpt_test_ok( false !== strpos( $finish, 'signalWpConsentApi(consent);' ), 'and shared with the WP Consent API' );
dpt_test_ok( false !== strpos( $js, "window.wp_set_consent(apiCat, granted(consent, cat) ? 'allow' : 'deny');" ), 'each API category is allowed or denied, never left out' );
dpt_test_ok( false !== strpos( $js, "window.addEventListener('load'" ), 'if the API script comes later, the signal waits for it' );
dpt_test_ok( false !== strpos( $js, 'return !!(consent && consent[cat] && cats[cat]);' ), 'on the page too, a category grants only while it is offered' );
dpt_test_ok( 1 === preg_match( '/\} else \{[^}]*signalWpConsentApi\(null\);/s', $js ), 'with no valid choice on load, the API\'s categories are set back to deny' );

/* ---- saving ---- */

$GLOBALS['dpt_stub_options'] = array();
DPT_CB_Settings::save( array( 'consent_mode' => '1', 'wp_consent_api' => 'yes' ) );
$saved = get_option( DPT_CB_Settings::OPTION );
dpt_test_eq( array( $saved['consent_mode'], $saved['wp_consent_api'] ), array( '1', '0' ), 'both save as switches: only "1" is on' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
