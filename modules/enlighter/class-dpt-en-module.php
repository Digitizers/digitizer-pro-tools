<?php
/**
 * Enlighter module - dependency-free code syntax highlighting via a block,
 * the [dpt_code] shortcode, automatic <pre><code> highlighting and an
 * Elementor widget. Replaces the standalone "Enlighter" plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-en-settings.php';
require_once __DIR__ . '/class-dpt-en-admin.php';

class DPT_Enlighter_Module extends DPT_Module {

	/** @var DPT_EN_Admin */
	private $admin;

	public function id() {
		return 'enlighter';
	}

	public function title() {
		return __( 'Enlighter', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Syntax-highlight code with a block, the [dpt_code] shortcode, automatic pre/code highlighting and an Elementor widget. Replaces the Enlighter plugin.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_EN_Settings::install_defaults();
	}

	public function init() {
		$this->admin = new DPT_EN_Admin();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'dpt_code', array( $this, 'shortcode_code' ) );
		add_filter( 'no_texturize_shortcodes', array( $this, 'no_texturize' ) );

		add_action( 'init', array( $this, 'register_block' ) );

		// Elementor widget (only when Elementor is active).
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
	}

	public function register_admin_menu( $parent_slug ) {
		$this->admin->register_menu( $parent_slug );
	}

	public function no_texturize( $shortcodes ) {
		$shortcodes[] = 'dpt_code';
		return $shortcodes;
	}

	/* --------------------------------------------------------------------- */
	/* Assets                                                                */
	/* --------------------------------------------------------------------- */

	public function enqueue_assets() {
		$base = DPT_URL . 'modules/enlighter/assets/';
		wp_register_style( 'dpt-enlighter', $base . 'css/highlight.css', array(), DPT_VERSION );
		wp_register_script( 'dpt-enlighter', $base . 'js/highlight.js', array(), DPT_VERSION, true );

		$o = DPT_EN_Settings::all();
		wp_localize_script(
			'dpt-enlighter',
			'DPTEnlighter',
			array(
				'auto'         => '1' === $o['auto_highlight'],
				'lines'        => '1' === $o['line_numbers'],
				'copy'         => '1' === $o['copy_button'],
				'copyLabel'    => __( 'Copy', 'digitizer-pro-tools' ),
				'copiedLabel'  => __( 'Copied', 'digitizer-pro-tools' ),
			)
		);

		wp_enqueue_style( 'dpt-enlighter' );
		wp_enqueue_script( 'dpt-enlighter' );
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                             */
	/* --------------------------------------------------------------------- */

	private static function truthy( $v ) {
		return in_array( strtolower( (string) $v ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * The safe highlighted-block markup. The code is always esc_html'd; the
	 * JS highlighter rebuilds tokens from the escaped text, so no code can
	 * inject markup or script.
	 */
	public function build_markup( $code, $lang, $lines, $copy ) {
		$lang  = DPT_EN_Settings::sanitize_lang( $lang );
		$theme = DPT_EN_Settings::get( 'theme' );
		$class = 'dpt-en-block dpt-en-theme-' . $theme;

		$attrs = ' data-lang="' . esc_attr( $lang ) . '"';
		if ( $lines ) {
			$attrs .= ' data-dpt-en-lines="1"';
		}
		if ( $copy ) {
			$attrs .= ' data-dpt-en-copy="1"';
		}

		return '<pre class="' . esc_attr( $class ) . '"' . $attrs . '><code class="language-' . esc_attr( $lang ) . '">'
			. esc_html( $code ) . '</code></pre>';
	}

	/**
	 * Undo the wpautop / kses wrappers that classic-editor and Elementor
	 * text fields can add around shortcode content, recovering the raw code
	 * so it can be re-escaped safely on output.
	 */
	private function clean_shortcode_code( $content ) {
		$content = preg_replace( '#<br\s*/?>#i', "\n", (string) $content );
		$content = str_replace( array( '<p>', '</p>' ), array( '', "\n" ), $content );
		$content = preg_replace( '#</?span[^>]*>#i', '', $content );
		$content = trim( $content, "\r\n" );
		// Recover entities WP may have introduced; build_markup re-escapes.
		return html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
	}

	public function shortcode_code( $atts, $content = '' ) {
		$defaults = DPT_EN_Settings::all();
		$atts = shortcode_atts(
			array(
				'lang'  => $defaults['default_lang'],
				'lines' => null,
				'copy'  => null,
			),
			$atts,
			'dpt_code'
		);

		$lines = is_null( $atts['lines'] ) ? ( '1' === $defaults['line_numbers'] ) : self::truthy( $atts['lines'] );
		$copy  = is_null( $atts['copy'] ) ? ( '1' === $defaults['copy_button'] ) : self::truthy( $atts['copy'] );
		$code  = $this->clean_shortcode_code( $content );

		return $this->build_markup( $code, $atts['lang'], $lines, $copy );
	}

	/* --------------------------------------------------------------------- */
	/* Block                                                                 */
	/* --------------------------------------------------------------------- */

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'dpt-enlighter-block',
			DPT_URL . 'modules/enlighter/assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			DPT_VERSION,
			true
		);
		wp_localize_script( 'dpt-enlighter-block', 'DPTEnlighterBlock', array( 'languages' => DPT_EN_Settings::languages() ) );

		register_block_type(
			'digitizer/enlighter',
			array(
				'api_version'     => 2,
				'editor_script'   => 'dpt-enlighter-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'code'     => array( 'type' => 'string', 'default' => '' ),
					'language' => array( 'type' => 'string', 'default' => DPT_EN_Settings::get( 'default_lang' ) ),
					'lines'    => array( 'type' => 'boolean', 'default' => '1' === DPT_EN_Settings::get( 'line_numbers' ) ),
					'copy'     => array( 'type' => 'boolean', 'default' => '1' === DPT_EN_Settings::get( 'copy_button' ) ),
				),
			)
		);
	}

	public function render_block( $attributes ) {
		$code = isset( $attributes['code'] ) ? (string) $attributes['code'] : '';
		if ( '' === trim( $code ) ) {
			return '';
		}
		return $this->build_markup(
			$code,
			isset( $attributes['language'] ) ? $attributes['language'] : 'plain',
			! empty( $attributes['lines'] ),
			! empty( $attributes['copy'] )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Elementor                                                             */
	/* --------------------------------------------------------------------- */

	public function register_elementor_widget( $widgets_manager ) {
		require_once __DIR__ . '/class-dpt-en-elementor.php';
		if ( class_exists( 'DPT_EN_Elementor_Widget' ) ) {
			$widgets_manager->register( new DPT_EN_Elementor_Widget( array(), array( 'dpt_module' => $this ) ) );
		}
	}
}
