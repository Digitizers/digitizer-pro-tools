<?php
/** Explicit alternate palettes; the site's original colors remain the base. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-dpt-ts-settings.php';
require_once __DIR__ . '/class-dpt-ts-admin.php';

class DPT_Theme_Switch_Module extends DPT_Module {

	private $admin;
	public function id() { return 'theme_switch'; }
	public function title() { return __( 'Theme Switch', 'digitizer-pro-tools' ); }
	public function description() {
		return __( 'Switch between your original palette and alternate colors, with light, dark and system controls. Supports Elementor global colors and remembers each visitor\'s choice.', 'digitizer-pro-tools' );
	}
	public function install_defaults() { DPT_TS_Settings::install_defaults(); }

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'dpt_theme_switch', array( $this, 'shortcode' ) );
		foreach ( array( 'rocket_exclude_defer_js', 'rocket_minify_excluded_external_js', 'rocket_rucss_external_exclusions' ) as $hook ) {
			add_filter( $hook, array( $this, 'exclude_assets' ) );
		}
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_inline' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'exclude_inline' ) );
		add_filter( 'rocket_rucss_inline_atts_exclusions', array( $this, 'exclude_style' ) );
		if ( is_admin() ) { $this->admin = new DPT_TS_Admin(); }
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) { $this->admin->register_menu( $parent_slug ); }
	}

	public function enqueue() {
		$o = DPT_TS_Settings::all();
		wp_enqueue_style( 'dpt-theme-switch', DPT_URL . 'modules/theme-switch/assets/theme-switch.css', array(), DPT_VERSION );
		wp_add_inline_style( 'dpt-theme-switch', self::palette_css( $o ) );
		// Synchronous head script: styles print at wp_head:8, scripts at :9.
		// https://developer.wordpress.org/reference/functions/wp_add_inline_script/
		wp_enqueue_script( 'dpt-theme-switch', DPT_URL . 'modules/theme-switch/assets/theme-switch.js', array(), DPT_VERSION, false );
		wp_add_inline_script( 'dpt-theme-switch', 'window.DPT_TS_CONFIG=' . wp_json_encode( array(
			'base' => $o['base'], 'default' => $o['default'],
			// WordPress installs in different paths on one origin keep their own choice.
			'key' => 'dpt-theme-switch:' . home_url( '/' ),
		), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	public static function palette_css( $options ) {
		$o = DPT_TS_Settings::clean( $options );
		if ( empty( $o['colors'] ) ) { return ''; }
		$mode = 'light' === $o['base'] ? 'dark' : 'light';
		$css  = 'html[data-dpt-theme="' . $mode . '"],html[data-dpt-theme="' . $mode . '"] body{';
		foreach ( $o['colors'] as $name => $value ) { $css .= $name . ':' . $value . ';'; }
		return $css . '}';
	}

	public function shortcode() {
		$labels = array(
			'light' => __( 'Light', 'digitizer-pro-tools' ),
			'dark' => __( 'Dark', 'digitizer-pro-tools' ),
			'system' => __( 'System', 'digitizer-pro-tools' ),
		);
		$out = '<div class="dpt-theme-switch" role="group" aria-label="' . esc_attr__( 'Color mode', 'digitizer-pro-tools' ) . '" hidden>';
		foreach ( $labels as $mode => $label ) {
			$out .= '<button type="button" data-dpt-theme-choice="' . esc_attr( $mode ) . '" aria-pressed="false">' . esc_html( $label ) . '</button>';
		}
		return $out . '</div>';
	}

	public function exclude_assets( $items ) {
		$items[] = '/modules/theme-switch/assets/';
		return $items;
	}
	public function exclude_inline( $items ) {
		$items[] = 'DPT_TS_CONFIG';
		$items[] = '/modules/theme-switch/assets/theme-switch.js';
		return $items;
	}
	public function exclude_style( $items ) {
		$items[] = 'id="dpt-theme-switch-inline-css"';
		return $items;
	}
}
