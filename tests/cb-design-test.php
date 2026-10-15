<?php
/**
 * Cookie Banner: the settings that let a site match an existing banner's
 * design (issue #55) - title visibility, the settings button's border colour,
 * corner offsets, a full-width mobile bar, an inline policy link, and button
 * size. Every default must leave the banner exactly as it was.
 *
 * Then the follow-ups: room for the close button when the title is hidden
 * (#57), buttons that fill the row, a rounded inner edge on the full-width
 * mobile bar and a policy link colour (#58), and an ordinary link that
 * reopens the banner (#59). And the policy link kept whole (#61), the close
 * button's opacity and an icon in place of the × character (#62).
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
$frontend = new DPT_CB_Frontend();

/** The inline <style> block for these settings. */
function dpt_test_cb_css( $frontend, $o ) {
	$m = new ReflectionMethod( 'DPT_CB_Frontend', 'render_inline_css' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true ); // A no-op, and deprecated, from PHP 8.1 on.
	}
	ob_start();
	$m->invoke( $frontend, $o );
	return (string) ob_get_clean();
}

/** The banner markup for these settings, in a language and direction. */
function dpt_test_cb_markup( $frontend, $o, $lang, $dir ) {
	$m = new ReflectionMethod( 'DPT_CB_Frontend', 'render_banner_markup' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true ); // A no-op, and deprecated, from PHP 8.1 on.
	}
	ob_start();
	$m->invoke( $frontend, $o, DPT_CB_Settings::get_texts( $lang ), $lang, $dir );
	return (string) ob_get_clean();
}

/** Settings as all() returns them, with these saved over the defaults. */
function dpt_test_cb_opts( $saved ) {
	$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ] = array_merge(
		array( 'policy_url' => 'https://example.test/privacy/' ),
		$saved
	);
	return DPT_CB_Settings::all();
}

/** The rule body for a selector inside a CSS string, whitespace collapsed. */
function dpt_test_cb_rule( $css, $selector ) {
	if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $m ) ) {
		return null;
	}
	return trim( preg_replace( '/\s+/', ' ', $m[1] ) );
}

/* ---- defaults change nothing ---- */

$d = DPT_CB_Settings::defaults();
dpt_test_eq(
	array( $d['title_show'], $d['btn_settings_border_color'], $d['corner_offset_x'], $d['corner_offset_y'], $d['mobile_full_width'], $d['policy_inline'], $d['btn_height'], $d['btn_font_size'], $d['btn_font_weight'] ),
	array( '1', '', '20', '20', '0', '0', '0', '14', '600' ),
	'the new defaults are what the banner already looked like: title on, text-coloured border, 20px corners, floating card on mobile, link on its own line, 14px/600 buttons'
);

$o    = dpt_test_cb_opts( array( 'position' => 'bottom-left' ) );
$css  = dpt_test_cb_css( $frontend, $o );
$html = dpt_test_cb_markup( $frontend, $o, 'he', 'rtl' );
dpt_test_eq( dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-btn' ), 'font-size: 14px; font-weight: 600;', 'buttons keep 14px, weight 600, and no fixed height' );
dpt_test_ok( false !== strpos( $css, 'border:1px solid currentColor;' ), 'the settings button keeps its text-coloured border' );
dpt_test_ok( false !== strpos( preg_replace( '/\s+/', ' ', $css ), '#dpt-cb-banner.dpt-cb-pos-bottom-left { left: 20px; bottom: 20px; }' ), 'a corner banner stays 20px from the side and the bottom' );
dpt_test_ok( false === strpos( $css, 'border-radius: 0 !important' ), 'no full-width mobile bar unless asked for' );
dpt_test_ok( false !== strpos( $html, '<h2 id="dpt-cb-title" class="dpt-cb-title">' ), 'the title is shown' );
dpt_test_ok( false !== strpos( $html, '<p class="dpt-cb-policy"><a href="https://example.test/privacy/"' ), 'and the policy link sits on its own line' );

/* ---- title ---- */

$o    = dpt_test_cb_opts( array( 'title_show' => '0' ) );
$html = dpt_test_cb_markup( $frontend, $o, 'he', 'rtl' );
dpt_test_ok( false !== strpos( $html, '<h2 id="dpt-cb-title" class="dpt-cb-title dpt-cb-sr-only">' ), 'switched off, the title is hidden from sight, not removed' );
dpt_test_ok( false !== strpos( $html, 'aria-labelledby="dpt-cb-title"' ), 'so the dialog keeps the name screen readers announce' );
$stylesheet = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/css/frontend.css' );
dpt_test_ok( false !== strpos( $stylesheet, '#dpt-cb-banner .dpt-cb-title.dpt-cb-sr-only' ) && false !== strpos( $stylesheet, 'clip: rect(0, 0, 0, 0) !important;' ), 'the stylesheet hides it the screen-reader-text way' );

/* ---- settings button border colour ---- */

$o   = dpt_test_cb_opts( array( 'btn_settings_border_color' => '#f2f2f2' ) );
$css = dpt_test_cb_css( $frontend, $o );
dpt_test_ok( false !== strpos( $css, 'border:1px solid #f2f2f2;' ), 'the settings button border takes its own colour' );
$o   = dpt_test_cb_opts( array( 'btn_settings_border_color' => '#f2f2f2', 'btn_settings_border' => '0' ) );
dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, $o ), '#f2f2f2' ), 'and none at all when the border is switched off' );

/* ---- corner offsets ---- */

$o   = dpt_test_cb_opts( array( 'position' => 'bottom-left', 'corner_offset_x' => '70', 'corner_offset_y' => '30' ) );
$css = preg_replace( '/\s+/', ' ', dpt_test_cb_css( $frontend, $o ) );
dpt_test_ok( false !== strpos( $css, '@media (min-width: 641px) { #dpt-cb-banner.dpt-cb-pos-bottom-left { left: 70px; bottom: 30px; } }' ), 'bottom-left: 70px from the left and 30px from the bottom, on desktop only' );
$o   = dpt_test_cb_opts( array( 'position' => 'bottom-right', 'corner_offset_x' => '70', 'corner_offset_y' => '30' ) );
$css = preg_replace( '/\s+/', ' ', dpt_test_cb_css( $frontend, $o ) );
dpt_test_ok( false !== strpos( $css, '#dpt-cb-banner.dpt-cb-pos-bottom-right { right: 70px; bottom: 30px; }' ), 'bottom-right measures from the right - physical, not flipped by RTL' );
$o   = dpt_test_cb_opts( array( 'position' => 'bottom', 'corner_offset_x' => '70' ) );
dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, $o ), 'min-width: 641px' ), 'a full-width bar has no corner offset' );
$o   = dpt_test_cb_opts( array( 'position' => 'bottom-left', 'corner_offset_x' => '70px;}body{display:none' ) );
$css = dpt_test_cb_css( $frontend, $o );
dpt_test_ok( false !== strpos( $css, 'left: 70px;' ) && false === strpos( $css, 'display:none' ), 'an offset is a number of pixels and nothing else' );

/* ---- full-width bar on mobile ---- */

$o      = dpt_test_cb_opts( array( 'position' => 'bottom-right', 'mobile_full_width' => '1' ) );
$css    = dpt_test_cb_css( $frontend, $o );
$mobile = substr( $css, (int) strpos( $css, '@media (max-width: 640px)' ) );
dpt_test_eq( dpt_test_cb_rule( $mobile, '#dpt-cb-banner.dpt-cb-pos-bottom-right' ), 'left: 0 !important; right: 0 !important; bottom: 0 !important;', 'on mobile the banner is flush with the bottom edge, side to side' );
dpt_test_eq(
	dpt_test_cb_rule( substr( $mobile, (int) strpos( $mobile, 'border-radius: 0' ) - 200 ), '#dpt-cb-banner.dpt-cb-pos-bottom-right .dpt-cb-box' ),
	'width: 100% !important; max-width: 100% !important; margin-left: 0 !important; margin-right: 0 !important; border-radius: 0 !important;',
	'with a full-width box, no margin and square corners'
);
dpt_test_ok( strpos( $mobile, 'border-radius: 0 !important' ) > strpos( $mobile, 'margin-left: auto !important' ), 'printed after the mobile sizing rule, so it wins' );
$o = dpt_test_cb_opts( array( 'position' => 'top', 'mobile_full_width' => '1' ) );
dpt_test_ok( false !== strpos( dpt_test_cb_css( $frontend, $o ), 'top: 0 !important;' ), 'a top bar is flush with the top' );
$o = dpt_test_cb_opts( array( 'position' => 'center', 'mobile_full_width' => '1' ) );
dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, $o ), 'border-radius: 0 !important' ), 'the centered dialog is left as it is' );

/* ---- the policy link inline ---- */

foreach ( array( 'he' => 'rtl', 'en' => 'ltr' ) as $lang => $dir ) {
	$o    = dpt_test_cb_opts( array( 'policy_inline' => '1' ) );
	$html = dpt_test_cb_markup( $frontend, $o, $lang, $dir );
	dpt_test_ok( false === strpos( $html, '<p class="dpt-cb-policy">' ), "$lang: no separate policy line" );
	dpt_test_ok( 1 === preg_match( '#<div class="dpt-cb-message">.*<span class="dpt-cb-policy-inline"><a href="https://example.test/privacy/" target="_blank" rel="noopener">[^<]+</a></span></p></div>#s', $html ), "$lang: the link closes the message's last paragraph" );
}
$inline = DPT_CB_Frontend::message_html( "First paragraph.\n\nSecond paragraph.", 'https://example.test/privacy/', 'Privacy policy', true );
dpt_test_eq(
	$inline,
	'<div class="dpt-cb-message"><p>First paragraph.</p>' . "\n" . '<p>Second paragraph. <span class="dpt-cb-policy-inline"><a href="https://example.test/privacy/" target="_blank" rel="noopener">Privacy policy</a></span></p></div>',
	'in the last paragraph, after a space, not in the first'
);
dpt_test_eq(
	DPT_CB_Frontend::message_html( '', 'https://example.test/privacy/', 'Privacy policy', true ),
	'<div class="dpt-cb-message"><p><span class="dpt-cb-policy-inline"><a href="https://example.test/privacy/" target="_blank" rel="noopener">Privacy policy</a></span></p></div>',
	'an empty message still gets the link, as a paragraph of its own'
);
dpt_test_eq(
	DPT_CB_Frontend::message_html( 'Text.', '', 'Privacy policy', true ),
	'<div class="dpt-cb-message"><p>Text.</p>' . "\n" . '</div>',
	'no link at all when there is no policy page'
);
dpt_test_ok( false !== strpos( DPT_CB_Frontend::message_html( 'Text.', 'https://example.test/"><script>', 'A <b>link</b>', true ), 'A &lt;b&gt;link&lt;/b&gt;' ), 'the link text is escaped' );

/* ---- button size ---- */

$o   = dpt_test_cb_opts( array( 'btn_height' => '45', 'btn_font_size' => '15', 'btn_font_weight' => '500' ) );
$css = dpt_test_cb_css( $frontend, $o );
dpt_test_eq( dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-btn' ), 'font-size: 15px; font-weight: 500; min-height: 45px;', 'buttons 45px high with 15px text at weight 500' );
$o = dpt_test_cb_opts( array( 'btn_font_weight' => '500;}body{display:none' ) );
dpt_test_eq( dpt_test_cb_rule( dpt_test_cb_css( $frontend, $o ), '#dpt-cb-banner .dpt-cb-btn' ), 'font-size: 14px; font-weight: 600;', 'a weight that is not one of the offered values falls back to the default' );

/* ---- #57: the close button and a hidden title ---- */

$d = DPT_CB_Settings::defaults();
dpt_test_eq(
	array( $d['btn_stretch'], $d['mobile_full_width_radius'], $d['policy_link_color'] ),
	array( '0', '0', '' ),
	'the follow-up defaults change nothing either: buttons sized to their text, a square full-width bar, a link in the text colour'
);

$float_sel = '#dpt-cb-banner .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-title.dpt-cb-sr-only + .dpt-cb-message::before';
$o   = dpt_test_cb_opts( array( 'title_show' => '0', 'padding' => '18', 'close_size' => '22' ) );
$css = dpt_test_cb_css( $frontend, $o );
dpt_test_ok( false !== strpos( $css, $float_sel ), 'with the title hidden, the message keeps room for the close button' );
dpt_test_ok( false !== strpos( $css, '#dpt-cb-banner .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-message:first-child::before' ), 'and so does a language with no title at all' );
// The button is 22 + 12 = 34px, 8px in from the corner: it reaches 42px into
// the box, 24px past an 18px padding - the overlap measured in the issue.
dpt_test_eq(
	dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-message:first-child::before' ),
	'content: ""; float: right; width: 28px; height: 24px;',
	'a float as deep as the button reaches past the padding, 4px wider so the text does not touch it'
);
dpt_test_ok( false !== strpos( $css, '#dpt-cb-banner[dir="rtl"] .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-message:first-child::before { float: left; }' ) || 1 === preg_match( '/\[dir="rtl"\][^{]*::before\s*\{\s*float: left;\s*\}/', $css ), 'on the left in Hebrew, where the button is' );
$html = dpt_test_cb_markup( $frontend, $o, 'he', 'rtl' );
dpt_test_ok( 1 === preg_match( '#<button type="button" class="dpt-cb-close"[^>]*>&times;</button>\s*<div class="dpt-cb-main-view">\s*<h2 id="dpt-cb-title" class="dpt-cb-title dpt-cb-sr-only">[^<]*</h2>\s*<div class="dpt-cb-message">#', $html ), 'the markup is the shape the rule matches: button, then the content, then the hidden title, then the message' );
$html = dpt_test_cb_markup( $frontend, dpt_test_cb_opts( array() ), 'he', 'rtl' );
dpt_test_ok( 1 === preg_match( '#<h2 id="dpt-cb-title" class="dpt-cb-title">#', $html ), 'with the title shown it is not .dpt-cb-sr-only, so the rule leaves the message alone' );
$o = dpt_test_cb_opts( array( 'title_show' => '0', 'padding' => '50', 'close_size' => '22' ) );
dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, $o ), '::before' ), 'with a padding deeper than the button, nothing is added' );

/* ---- #58: buttons that fill the row ---- */

$css = dpt_test_cb_css( $frontend, dpt_test_cb_opts( array() ) );
dpt_test_ok( false === strpos( $css, 'flex: 1 1 0' ), 'off: buttons keep the width of their text' );
$css = preg_replace( '/\s+/', ' ', dpt_test_cb_css( $frontend, dpt_test_cb_opts( array( 'btn_stretch' => '1' ) ) ) );
dpt_test_ok( false !== strpos( $css, '@media (min-width: 641px) { #dpt-cb-banner .dpt-cb-main-view .dpt-cb-buttons .dpt-cb-btn { flex: 1 1 0; } }' ), 'on: on desktop the main view\'s buttons share the row equally' );

/* ---- #58: the full-width mobile bar's inner edge ---- */

dpt_test_eq( DPT_CB_Frontend::full_width_radius( 'bottom', 24, false ), '0', 'off: square corners, as in 1.42.0' );
dpt_test_eq( DPT_CB_Frontend::full_width_radius( 'bottom', 24, true ), '24px 24px 0 0', 'a bottom bar keeps its top corners' );
dpt_test_eq( DPT_CB_Frontend::full_width_radius( 'top', 24, true ), '0 0 24px 24px', 'a top bar keeps its bottom corners' );
dpt_test_eq( DPT_CB_Frontend::full_width_radius( 'bottom', 0, true ), '0', 'no radius, nothing to keep' );
$o      = dpt_test_cb_opts( array( 'position' => 'bottom', 'mobile_full_width' => '1', 'mobile_full_width_radius' => '1', 'border_radius' => '24' ) );
$css    = dpt_test_cb_css( $frontend, $o );
dpt_test_ok( false !== strpos( $css, 'border-radius: 24px 24px 0 0 !important;' ), 'and the stylesheet says so' );
dpt_test_ok( false !== strpos( $css, 'box-shadow:0 10px 40px rgba(0,0,0,0.2);' ), 'the shadow stays as the shadow setting says' );
$o = dpt_test_cb_opts( array( 'position' => 'center', 'mobile_full_width' => '1', 'mobile_full_width_radius' => '1' ) );
dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, $o ), '0 0 !important' ), 'the centered dialog is still left as it is' );

/* ---- #58: the policy link colour ---- */

dpt_test_ok( false === strpos( dpt_test_cb_css( $frontend, dpt_test_cb_opts( array() ) ), '.dpt-cb-policy-inline a' ), 'empty: the link keeps the text colour' );
$css = preg_replace( '/\s+/', ' ', dpt_test_cb_css( $frontend, dpt_test_cb_opts( array( 'policy_link_color' => '#af8fe2' ) ) ) );
dpt_test_eq( dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-policy a, #dpt-cb-banner .dpt-cb-policy-inline a' ), 'color: #af8fe2; opacity: 1;', 'set: the link on its own line and the link inside the message take it, at full strength' );

/* ---- #59: a link that reopens the banner ---- */

dpt_test_eq( DPT_CB_Frontend::open_selector(), 'a[href="#dpt-cookie-settings"], a[href$="#dpt-cookie-settings"], .dpt-cb-open', 'a link to the hash, alone or at the end of a full address, or the class' );
add_filter( 'dpt_cb_open_selector', function ( $s ) { return $s . ', .footer-cookies'; } );
dpt_test_ok( '.footer-cookies' === substr( DPT_CB_Frontend::open_selector(), -15 ), 'a site can add its own' );
$GLOBALS['dpt_stub_filters'] = array();

dpt_test_ok( isset( $GLOBALS['dpt_stub_shortcodes']['dpt_cookie_settings'] ), 'the shortcode is registered' );
$GLOBALS['dpt_stub_is_admin'] = false; // A page on the site.
dpt_test_cb_opts( array( 'enabled' => '1' ) );
dpt_test_eq( $frontend->settings_link_shortcode( array( 'text' => 'Cookie <b>settings</b>' ) ), '<a href="#dpt-cookie-settings" class="dpt-cb-settings-link">Cookie &lt;b&gt;settings&lt;/b&gt;</a>', 'it prints a link to the hash, the text escaped' );
dpt_test_eq( $frontend->settings_link_shortcode( '' ), '<a href="#dpt-cookie-settings" class="dpt-cb-settings-link">ניהול עוגיות</a>', 'without text, the floating button\'s label in the page\'s language' );
$GLOBALS['dpt_stub_locale'] = 'en_US';
dpt_test_eq( $frontend->settings_link_shortcode( array() ), '<a href="#dpt-cookie-settings" class="dpt-cb-settings-link">Manage cookies</a>', 'in English on an English page' );
$GLOBALS['dpt_stub_locale'] = 'he_IL';
dpt_test_cb_opts( array( 'enabled' => '0' ) );
dpt_test_eq( $frontend->settings_link_shortcode( array( 'text' => 'x' ) ), '', 'with the banner off there is nothing to open, so no link' );
$GLOBALS['dpt_stub_is_admin'] = true;

$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/js/frontend.js' );
dpt_test_ok( false !== strpos( $js, "document.addEventListener('click'" ) && false !== strpos( $js, 'closest(openSelector)' ), 'frontend.js listens on the document, so a link added anywhere works' );
dpt_test_ok( false !== strpos( $js, 'e.preventDefault();' ) && false !== strpos( $js, 'openFrom(' ), 'it opens the banner instead of following the link' );
dpt_test_ok( false !== strpos( $js, 'returnFocus();' ) && false !== strpos( $js, "querySelector('.dpt-cb-main-view .dpt-cb-btn')" ), 'focus goes into the dialog and back to the link after the choice' );
dpt_test_ok( false !== strpos( $js, "window.location.hash === '#' + config.openHash" ), 'a page loaded at #dpt-cookie-settings opens it too' );

/* ---- #61: the inline policy link stays whole ---- */

$stylesheet = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/css/frontend.css' );
dpt_test_eq( dpt_test_cb_rule( $stylesheet, '#dpt-cb-banner .dpt-cb-policy-inline a' ), 'white-space: nowrap;', 'the link at the end of the message does not break between "Privacy" and "policy"' );

/* ---- #62: the close button ---- */

$d = DPT_CB_Settings::defaults();
dpt_test_eq( array( $d['close_opacity'], $d['close_icon'] ), array( '0.7', 'text' ), 'defaults: faded to 0.7 as frontend.css always did, the × character' );
$o    = dpt_test_cb_opts( array() );
$css  = dpt_test_cb_css( $frontend, $o );
$html = dpt_test_cb_markup( $frontend, $o, 'he', 'rtl' );
dpt_test_ok( false !== strpos( dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-close' ), 'opacity: 0.7;' ), 'the default opacity is printed as it was' );
dpt_test_ok( 1 === preg_match( '#<button type="button" class="dpt-cb-close"[^>]*>&times;</button>#', $html ), 'and the button still holds the character' );
dpt_test_ok( false !== strpos( $stylesheet, '#dpt-cb-banner .dpt-cb-close:hover {' ) && false !== strpos( $stylesheet, "opacity: 1;\n    transform: scale(1.08);" ), 'on hover it is shown in full - a pseudo-class, so it outranks the setting' );

$o    = dpt_test_cb_opts( array( 'close_opacity' => '1', 'close_icon' => 'svg', 'close_size' => '16', 'close_color' => '#1b1a0f' ) );
$css  = dpt_test_cb_css( $frontend, $o );
$html = dpt_test_cb_markup( $frontend, $o, 'en', 'ltr' );
dpt_test_eq( dpt_test_cb_rule( $css, '#dpt-cb-banner .dpt-cb-close' ), 'color: #1b1a0f; font-size: 16px; width: 28px; height: 28px; opacity: 1; background:transparent;', 'the Complianz look: full strength, 16px, the text colour' );
dpt_test_ok( 1 === preg_match( '#<button type="button" class="dpt-cb-close"[^>]*><svg class="dpt-cb-close-icon"[^>]* width="1em" height="1em"[^>]*stroke="currentColor"[^>]*aria-hidden="true"[^>]*><path d="M4 4L20 20M20 4L4 20"></path></svg></button>#', $html ), 'the icon is a 1em × in currentColor, hidden from screen readers' );
dpt_test_ok( false !== strpos( $html, 'aria-label="Close"' ), 'the button keeps its name' );
dpt_test_ok( false !== strpos( $stylesheet, '#dpt-cb-banner .dpt-cb-close .dpt-cb-close-icon {' ), 'the stylesheet sets the icon as a block, so no baseline gap moves it off centre' );

dpt_test_eq( DPT_CB_Frontend::close_opacity( '0' ), '0.1', 'an opacity of 0 would hide a button that still closes the banner: 0.1 at least' );
dpt_test_eq( DPT_CB_Frontend::close_opacity( '3' ), '1', 'and no more than 1' );
dpt_test_eq( DPT_CB_Frontend::close_opacity( '0.85' ), '0.85', 'a value between is kept' );
dpt_test_eq( DPT_CB_Frontend::close_opacity( '1;}body{display:none' ), '0.7', 'something that is not a number falls back to 0.7' );
$o = dpt_test_cb_opts( array( 'close_icon' => '<script>' ) );
dpt_test_ok( 1 === preg_match( '#class="dpt-cb-close"[^>]*>&times;</button>#', dpt_test_cb_markup( $frontend, $o, 'he', 'rtl' ) ), 'a shape that is not one of the two falls back to the character' );

/* ---- saving ---- */

$GLOBALS['dpt_stub_options'] = array();
DPT_CB_Settings::save(
	array(
		'title_show'                => '0',
		'mobile_full_width'         => '1',
		'policy_inline'             => 'yes',
		'btn_settings_border_color' => 'red;}',
		'corner_offset_x'           => '70',
	)
);
$saved = get_option( DPT_CB_Settings::OPTION );
dpt_test_eq( array( $saved['title_show'], $saved['mobile_full_width'], $saved['policy_inline'] ), array( '0', '1', '0' ), 'the three switches save as switches: only "1" is on' );
dpt_test_eq( $saved['btn_settings_border_color'], '', 'a border colour that is not a colour is dropped, back to the text colour' );
dpt_test_eq( $saved['corner_offset_x'], '70', 'and an offset is kept' );
DPT_CB_Settings::save( array( 'btn_settings_border_color' => '#F2F2F2' ) );
dpt_test_eq( get_option( DPT_CB_Settings::OPTION )['btn_settings_border_color'], '#F2F2F2', 'a real colour is kept' );
$GLOBALS['dpt_stub_options'] = array();
DPT_CB_Settings::save( array( 'btn_stretch' => '1', 'mobile_full_width_radius' => 'on', 'policy_link_color' => 'purple;}' ) );
$saved = get_option( DPT_CB_Settings::OPTION );
dpt_test_eq( array( $saved['btn_stretch'], $saved['mobile_full_width_radius'], $saved['policy_link_color'] ), array( '1', '0', '' ), 'the new switches save as switches, and a link colour that is not a colour is dropped' );
DPT_CB_Settings::save( array( 'policy_link_color' => '#af8fe2' ) );
dpt_test_eq( get_option( DPT_CB_Settings::OPTION )['policy_link_color'], '#af8fe2', 'a real link colour is kept' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
