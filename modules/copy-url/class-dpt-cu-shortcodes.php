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
	}

	/**
	 * The current page's address, readable.
	 *
	 * Built from WordPress's own canonical home, never from $_SERVER's
	 * host or port: behind a reverse proxy (Cloudways among them)
	 * SERVER_PORT is the backend's, and a URL assembled from it names a
	 * server no visitor can reach. Decoded so a Hebrew slug reads as itself
	 * rather than percent-noise.
	 *
	 * @return string
	 */
	public static function current_url() {
		// Not passed through a text sanitizer: this is the one string whose
		// encoded characters are the content, and sanitizing would corrupt a
		// legitimately encoded path. The dangerous residue is handled below.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; markup-breaking characters are stripped after the decode below.
		$url = urldecode( home_url( $uri ) );
		// REQUEST_URI is the visitor's to shape, and urldecode() brings back
		// characters an encoded URL could not carry into markup. The four
		// that can break out of an attribute or open a tag are removed - they
		// are never part of an address anyone means to copy, so the display
		// is unchanged and the shortcode stays safe wherever a page builder
		// interpolates it.
		return str_replace( array( '<', '>', '"', "'" ), '', $url );
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
	 * Enqueue the widget's stylesheet and script, once, from the first
	 * render. Enqueued here rather than on every page so a site that enables
	 * the module pays nothing on pages without the widget; WordPress prints
	 * late-enqueued styles in the footer, which is fine for a widget that
	 * also arrives mid-page.
	 */
	private static function enqueue_assets() {
		$base = DPT_URL . 'modules/copy-url/assets/';
		wp_enqueue_style( 'dpt-copy-url', $base . 'css/copy-url.css', array(), DPT_VERSION );
		wp_enqueue_script( 'dpt-copy-url', $base . 'js/copy-url.js', array(), DPT_VERSION, true );
	}
}
