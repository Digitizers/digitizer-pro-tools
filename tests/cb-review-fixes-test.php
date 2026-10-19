<?php
/**
 * Cookie Banner: the fixes the standalone Digitizer Cookie Consent got in its
 * wordpress.org and Codex reviews, carried back into the module (#72).
 */
require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $c ) {
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? (string) $c : null;
	}
}
// Paragraphs on blank lines, as core's wpautop() makes them; enough for
// where the policy link lands.
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$out = array();
		foreach ( preg_split( '/\n\s*\n/', $text ) as $p ) {
			$out[] = '<p>' . $p . '</p>';
		}
		return implode( "\n", $out ) . "\n";
	}
}

$GLOBALS['dpt_stub_shortcodes'] = array();
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['dpt_stub_shortcodes'][ $tag ] = $callback;
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}
$GLOBALS['dpt_stub_locale'] = 'he_IL';
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return $GLOBALS['dpt_stub_locale'];
	}
}

require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-settings.php';
require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php';

$GLOBALS['dpt_stub_no_user'] = true;


function dpt_test_rf_markup( $o ) {
	$m = new ReflectionMethod( 'DPT_CB_Frontend', 'render_banner_markup' );
	if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	ob_start();
	$m->invoke( new DPT_CB_Frontend(), $o, DPT_CB_Settings::get_texts( 'en' ), 'en', 'ltr' );
	return (string) ob_get_clean();
}
function dpt_test_rf_css( $o ) {
	$m = new ReflectionMethod( 'DPT_CB_Frontend', 'render_inline_css' );
	if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	ob_start();
	$m->invoke( new DPT_CB_Frontend(), $o );
	return (string) ob_get_clean();
}
$base = array_merge( DPT_CB_Settings::defaults(), array( 'cat_functional_enabled' => '1', 'cat_analytics_enabled' => '1', 'cat_marketing_enabled' => '1' ) );

/* Accessibility. */
$html = dpt_test_rf_markup( $base );
foreach ( array( 'functional', 'analytics', 'marketing' ) as $cat ) {
	$ok = preg_match( '#data-dpt-cb-cat="' . $cat . '" aria-labelledby="([a-z0-9-]+)"#', $html, $hit ) && false !== strpos( $html, 'id="' . $hit[1] . '"' );
	dpt_test_ok( (bool) $ok, "the $cat switch is labelled by its category name" );
}
dpt_test_ok( false !== strpos( $html, 'role="dialog"' ) && false === strpos( $html, 'aria-modal' ), 'a non-modal dialog: the banner traps no focus' );
dpt_test_ok( false !== strpos( $html, 'dpt-cb-settings-title" tabindex="-1"' ), 'the preferences heading can take focus' );
$admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
dpt_test_ok( false !== strpos( $admin_js, '.dpt-switch input[type="checkbox"]' ) && false !== strpos( $admin_js, 'aria-labelledby' ), 'admin.js names every settings switch by its row heading' );

/* Numbers: blank or nonsense is the default, the rest held to the form's bounds. */
$GLOBALS['dpt_stub_options'] = array();
DPT_CB_Settings::save( array( 'width' => '', 'max_width_pct' => '500', 'width_mobile' => '50', 'overlay_opacity' => '', 'close_opacity' => '2', 'show_delay' => '-3', 'consent_version' => '', 'consent_days' => '9999' ) );
$o = DPT_CB_Settings::all();
dpt_test_eq(
	array( $o['width'], $o['max_width_pct'], $o['width_mobile'], $o['overlay_opacity'], $o['close_opacity'], $o['show_delay'], $o['consent_version'], $o['consent_days'] ),
	array( '700', '100', '200', '0.5', '1', '0', '1', '730' ),
	'blank -> default, out of range -> the nearest bound'
);

/* The consent cookie's domain: parent by default, host-only or explicit through dpt_cb_cookie_domain. */
dpt_test_ok( null === DPT_CB_Frontend::cookie_domain(), 'by default frontend.js picks the parent domain itself' );
add_filter( 'dpt_cb_cookie_domain', function () { return ''; } );
dpt_test_eq( DPT_CB_Frontend::cookie_domain(), '', '\'\' makes the cookie host-only' );
$GLOBALS['dpt_stub_filters'] = array();
add_filter( 'dpt_cb_cookie_domain', function () { return '.example.com"><x'; } );
dpt_test_eq( DPT_CB_Frontend::cookie_domain(), null, 'anything that is not a domain name falls back to the default' );
$GLOBALS['dpt_stub_filters'] = array();

/* frontend.js. */
$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/js/frontend.js' );
dpt_test_ok( false !== strpos( $js, "if (typeof config.cookieDomain === 'string') return config.cookieDomain;" ), 'frontend.js honours the cookie domain from the filter' );
dpt_test_ok( false !== strpos( $js, "var gone = name + '=; expires=Thu, 01 Jan 1970" ), 'and expires a same-named copy on another domain before writing' );
$reveal = substr( $js, (int) strpos( $js, 'var revealBanner = function' ), 300 );
dpt_test_ok( 1 === preg_match( '/^var revealBanner = function \(\) \{\s*if \(readConsent\(\)\) return;/', $reveal ), 'a delayed reveal does not bring the banner back after an early choice' );
$finish = substr( $js, (int) strpos( $js, 'function finishConsent' ), 2500 );
dpt_test_ok( false !== strpos( $finish, 'injectedCats[cat] && !consent[cat]' ) && false !== strpos( $finish, 'location.reload()' ), 'withdrawing a category whose snippet already runs reloads the page' );
dpt_test_ok( false !== strpos( substr( $js, (int) strpos( $js, 'function showSettingsView' ), 1200 ), '.focus(' ), 'opening the preferences view moves focus into it' );
dpt_test_ok( false === strpos( $js, 's.onload = s.onerror' ) && false !== strpos( $js, "s.addEventListener('load', resume);" ), 'an injected script keeps its own onload/onerror' );
$css = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/css/frontend.css' );
dpt_test_ok( 1 === preg_match( '/\.dpt-cb-toggle input:focus-visible \+ \.dpt-cb-toggle-slider\s*\{[^}]*outline:/', $css ), 'a focused category toggle shows a focus ring' );

/* Escaping late in the inline CSS. */
$src  = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php' );
$from = strpos( $src, '<style id="dpt-cb-inline-css">' );
$body = substr( $src, $from, strpos( $src, "</style>\n", $from ) - $from );
dpt_test_ok( substr_count( $body, '<?php echo' ) > 40, 'the CSS template was found' );
preg_match_all( '/<\?php echo (?!esc_attr\(|absint\(|esc_url\()[^?]*\?>/', $body, $bare );
dpt_test_eq( $bare[0], array(), 'every echo in the CSS template is escaped where it is printed' );
$GLOBALS['dpt_stub_options'] = array( DPT_CB_Settings::OPTION => array_merge( $base, array( 'font_family' => 'custom', 'font_family_custom' => '"Source Sans 3", sans-serif', 'bg_image_url' => 'https://example.test/u/banner(1).jpg' ) ) );
$out = dpt_test_rf_css( DPT_CB_Settings::all() );
dpt_test_ok( false !== strpos( $out, 'font-family: Source Sans \\33 , sans-serif !important;' ), 'family names are printed as escaped identifiers, valid unquoted' );
dpt_test_ok( false !== strpos( $out, 'url(https://example.test/u/banner%281%29.jpg)' ), 'the image URL is percent-encoded for an unquoted url()' );
dpt_test_ok( false === strpos( $out, '&quot;' ) && false === strpos( $out, '&#039;' ), 'escaping leaves no HTML entity in the CSS' );
$svg = DPT_CB_Frontend::svg_allowed_html();
dpt_test_ok( isset( $svg['svg']['viewbox'], $svg['path']['d'] ), 'icons are printed through wp_kses() with an SVG allowlist' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
