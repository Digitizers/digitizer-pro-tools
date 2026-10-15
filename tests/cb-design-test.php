<?php
/**
 * Cookie Banner: the settings that let a site match an existing banner's
 * design (issue #55) - title visibility, the settings button's border colour,
 * corner offsets, a full-width mobile bar, an inline policy link, and button
 * size. Every default must leave the banner exactly as it was.
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

exit( dpt_test_summary() > 0 ? 1 : 0 );
