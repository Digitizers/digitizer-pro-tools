<?php
/**
 * UTM Passthrough module - a visitor who arrives with utm_* parameters keeps
 * them on the site's own links, so the campaign is still on the address when
 * they reach the form or the checkout.
 *
 * Replaces the snippet pasted into each site's header. It all happens in the
 * browser, so page caches are unaffected, and it stores nothing: no cookie,
 * no consent question.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UTM_Passthrough_Module extends DPT_Module {

	public function id() {
		return 'utm_passthrough';
	}

	public function title() {
		return __( 'UTM Passthrough', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'A visitor who arrives with utm_* parameters keeps them on the site\'s internal links, so the campaign reaches the form or the checkout. Runs in the browser, stores nothing, and leaves external links, downloads, the admin and add-to-cart links alone. Replaces the snippet pasted into the site header.', 'digitizer-pro-tools' );
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		wp_enqueue_script(
			'dpt-utm-passthrough',
			DPT_URL . 'modules/utm-passthrough/assets/js/utm-passthrough.js',
			array(),
			DPT_VERSION,
			true
		);
		wp_localize_script( 'dpt-utm-passthrough', 'DPT_UTM_CONFIG', array( 'exclude' => self::excluded_paths() ) );
	}

	/**
	 * Paths whose links never get the parameters: the admin and the login
	 * page, as this site's own URLs spell them (a subdirectory install has a
	 * longer path). Filter dpt_utm_excluded_paths adds more.
	 *
	 * @return string[]
	 */
	private static function excluded_paths() {
		$paths = array(
			(string) wp_parse_url( admin_url(), PHP_URL_PATH ),
			(string) wp_parse_url( wp_login_url(), PHP_URL_PATH ),
		);
		return array_values( array_filter( (array) apply_filters( 'dpt_utm_excluded_paths', $paths ) ) );
	}
}
