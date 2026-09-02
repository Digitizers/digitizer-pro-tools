<?php
/**
 * Copy URL module - a copy-this-page-address widget as one shortcode.
 *
 * Replaces the functions.php snippet that registered [digitizer_geturl] and
 * the three-widget Elementor construction (form + absolutely positioned
 * heading + raw HTML script) that turned it into a copyable field.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-cu-shortcodes.php';

class DPT_Copy_URL_Module extends DPT_Module {

	public function id() {
		return 'copy_url';
	}

	public function title() {
		return __( 'Copy URL', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Two shortcodes: [digitizer_copy_url] renders a copy-this-page-address field with a one-click button, and [digitizer_geturl] prints the current page address for use as a dynamic value. Replaces the functions.php snippet and the three-widget Elementor construction around it.', 'digitizer-pro-tools' );
	}

	public function init() {
		DPT_CU_Shortcodes::register();
	}
}
