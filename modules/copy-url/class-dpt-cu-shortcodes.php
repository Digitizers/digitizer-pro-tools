<?php
/**
 * Copy URL module - the two shortcodes.
 *
 * [digitizer_geturl] prints the current page address, for use as a dynamic
 * value (an Elementor form field's default among them). [digitizer_copy_url]
 * renders the whole copy-this-address widget - field, button, script - that
 * used to take three Elementor widgets stacked on top of that shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CU_Shortcodes {

	public static function register() {
		add_shortcode( 'digitizer_geturl', array( __CLASS__, 'current_url' ) );
		add_shortcode( 'digitizer_copy_url', array( __CLASS__, 'copy_widget' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * The current page's address, readable.
	 *
	 * Scheme and host come from WordPress's own canonical home, never from
	 * $_SERVER: behind a reverse proxy (Cloudways among them) SERVER_PORT is
	 * the backend's, and a URL assembled from it names a server no visitor
	 * can reach. Only scheme+host, though - home_url( $request_uri ) would
	 * double a subdirectory install's path (/site/site/...), which is why
	 * DPT_CC_Enforce::current_request_url() assembles it the same way.
	 *
	 * Decoded for display so a Hebrew slug reads as itself rather than
	 * percent-noise - but only the non-ASCII bytes. A decoded %2F or %3D is
	 * a routing or query delimiter the address did not have, so the copied
	 * URL would resolve somewhere else; every ASCII escape (and every
	 * literal +) is kept exactly as the request carried it.
	 *
	 * @return string
	 */
	public static function current_url() {
		// Not passed through a text sanitizer: this is the one string whose
		// encoded characters are the content, and sanitizing would corrupt a
		// legitimately encoded path. The dangerous residue is handled below.
		$req    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; markup-breaking characters are stripped below.
		$home   = wp_parse_url( home_url() );
		$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';
		$host   = ! empty( $home['host'] ) ? $home['host'] : '';
		if ( ! empty( $home['port'] ) ) {
			$host .= ':' . $home['port'];
		}
		$url = $scheme . '://' . $host . self::decode_for_display( $req );
		// A literal <, >, " or ' can still arrive in REQUEST_URI from a
		// client that is not a browser. They are never part of an address
		// anyone means to copy, and removing them is what keeps the
		// shortcode safe wherever a page builder interpolates it into
		// markup - an Elementor form field's value attribute, say.
		return str_replace( array( '<', '>', '"', "'" ), '', $url );
	}

	/**
	 * Decode only the percent-escapes that stand for non-ASCII bytes - the
	 * ones that make a Hebrew slug readable. Escaped ASCII stays escaped:
	 * decoding %2F, %3F, %3D or %26 would turn data into delimiters and
	 * change where the copied address resolves, decoding %20 would put a
	 * space in the middle of a URL, and decoding %3C/%22 would hand markup
	 * back to whatever prints the result. urldecode()'s +-to-space rule is
	 * deliberately not applied either - a literal + in a path is a +.
	 *
	 * @param string $value Raw request URI.
	 * @return string
	 */
	private static function decode_for_display( $value ) {
		return preg_replace_callback(
			'/%[0-9A-Fa-f]{2}/',
			static function ( $m ) {
				$byte = hexdec( substr( $m[0], 1 ) );
				return $byte >= 0x80 ? chr( $byte ) : $m[0];
			},
			$value
		);
	}

	/**
	 * The copy widget: a read-only field carrying the address and a button
	 * that copies it, in one flex row - no absolute positioning, no per-page
	 * script, any number of instances per page.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function copy_widget( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label_copy'   => __( 'Copy', 'digitizer-pro-tools' ),
				'label_copied' => __( 'Copied', 'digitizer-pro-tools' ),
			),
			$atts,
			'digitizer_copy_url'
		);

		self::enqueue_assets();

		return '<div class="dpt-copy-url">'
			. '<input class="dpt-copy-url-field" type="text" readonly dir="ltr" value="' . esc_attr( self::current_url() ) . '" aria-label="URL" />'
			. '<button type="button" class="dpt-copy-url-btn" data-copied="' . esc_attr( $atts['label_copied'] ) . '">' . esc_html( $atts['label_copy'] ) . '</button>'
			. '</div>';
	}

	/**
	 * Register the assets and enqueue the stylesheet, on wp_enqueue_scripts.
	 *
	 * The stylesheet cannot wait for the shortcode: the_content renders
	 * after wp_head() has printed styles, so a render-time enqueue reaches
	 * the page only from the footer and the widget paints unstyled first -
	 * the same lifecycle constraint the Embed module documents. The
	 * stylesheet is tiny and the module is opt-in, so carrying it on the
	 * front end is fine. The script has no such constraint and rides only
	 * with pages that render the widget.
	 */
	public static function register_assets() {
		$base = DPT_URL . 'modules/copy-url/assets/';
		wp_register_style( 'dpt-copy-url', $base . 'css/copy-url.css', array(), DPT_VERSION );
		wp_register_script( 'dpt-copy-url', $base . 'js/copy-url.js', array(), DPT_VERSION, true );
		wp_enqueue_style( 'dpt-copy-url' );
	}

	/**
	 * From a render: the footer script, plus the stylesheet as a fallback
	 * for the odd request where wp_enqueue_scripts never ran (the shortcode
	 * done outside a normal front-end page).
	 */
	private static function enqueue_assets() {
		$base = DPT_URL . 'modules/copy-url/assets/';
		if ( ! wp_style_is( 'dpt-copy-url', 'enqueued' ) ) {
			wp_enqueue_style( 'dpt-copy-url', $base . 'css/copy-url.css', array(), DPT_VERSION );
		}
		wp_enqueue_script( 'dpt-copy-url', $base . 'js/copy-url.js', array(), DPT_VERSION, true );
	}
}
