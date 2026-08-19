<?php
/**
 * Embed module - a [dpt_embed] shortcode for PDF and Google Docs sources, which
 * WordPress core oEmbed does not handle, rendered in a responsive frame.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-emb-settings.php';
require_once __DIR__ . '/class-dpt-emb-admin.php';

class DPT_Embed_Module extends DPT_Module {

	/** @var DPT_EMB_Admin */
	private $admin;

	public function id() {
		return 'embed';
	}

	public function title() {
		return __( 'Embed', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'A [dpt_embed] shortcode that embeds PDF files and Google Docs, Sheets, Slides, Forms and Drive previews in a responsive frame - the sources WordPress does not embed on its own.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_EMB_Settings::install_defaults();
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_EMB_Admin();
		}
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_shortcode() {
		add_shortcode( 'dpt_embed', array( $this, 'render_shortcode' ) );
	}

	public function register_assets() {
		wp_register_style(
			'dpt-embed',
			DPT_URL . 'modules/embed/assets/css/embed.css',
			array(),
			DPT_VERSION
		);
		// Enqueue here, on wp_enqueue_scripts, rather than at shortcode-render
		// time: shortcodes in the_content run after wp_head() has already printed
		// styles, so a late enqueue would leave the frame unstyled. The stylesheet
		// is tiny and the module is opt-in, so loading it on the front end is fine.
		wp_enqueue_style( 'dpt-embed' );
	}

	/**
	 * Render the [dpt_embed] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'url'    => '',
				'ratio'  => '',
				'height' => '',
				'title'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			'dpt_embed'
		);

		$resolved = DPT_EMB_Settings::resolve( $atts['url'] );
		if ( null === $resolved ) {
			// Only surface a hint to users who can fix it; visitors see nothing.
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="dpt-embed-error">' . esc_html__( 'dpt_embed: unsupported or missing URL. This shortcode handles PDF files and Google Docs/Sheets/Slides/Forms/Drive links.', 'digitizer-pro-tools' ) . '</p>';
			}
			return '';
		}

		$defaults = DPT_EMB_Settings::all();

		$explicit_ratio = is_scalar( $atts['ratio'] ) && '' !== trim( (string) $atts['ratio'] );

		// A per-shortcode height always wins. Otherwise fall back to the default
		// height ONLY when the shortcode also gave no explicit ratio - an explicit
		// ratio must stay responsive even on sites with a configured default
		// height, or the documented ratio override would be unusable.
		$height = DPT_EMB_Settings::sanitize_height( $atts['height'] );
		if ( '' === $height && ! $explicit_ratio ) {
			$height = $defaults['default_height'];
		}
		$ratio = DPT_EMB_Settings::sanitize_ratio( $explicit_ratio ? $atts['ratio'] : $defaults['default_ratio'], $defaults['default_ratio'] );

		$title = sanitize_text_field( $atts['title'] );
		if ( '' === $title ) {
			$title = ( 'pdf' === $resolved['type'] )
				? __( 'Embedded PDF document', 'digitizer-pro-tools' )
				: __( 'Embedded Google document', 'digitizer-pro-tools' );
		}

		// The stylesheet is enqueued on wp_enqueue_scripts (see register_assets),
		// which fires before the head is printed - a shortcode-time enqueue would
		// be too late. Fall back to a late enqueue only if it somehow was not run
		// (e.g. the shortcode is rendered outside a normal front-end request).
		if ( ! wp_style_is( 'dpt-embed', 'enqueued' ) ) {
			wp_enqueue_style( 'dpt-embed' );
		}

		$loading = DPT_EMB_Settings::is_on( 'lazy_load' ) ? 'lazy' : 'eager';
		$iframe  = sprintf(
			'<iframe src="%1$s" title="%2$s" loading="%3$s" class="dpt-embed-frame" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>',
			esc_url( $resolved['src'] ),
			esc_attr( $title ),
			esc_attr( $loading )
		);

		if ( '' !== $height ) {
			return sprintf(
				'<div class="dpt-embed dpt-embed--fixed dpt-embed--%1$s" style="height:%2$dpx">%3$s</div>',
				esc_attr( $resolved['type'] ),
				(int) $height,
				$iframe
			);
		}

		$pad = DPT_EMB_Settings::ratio_padding( $ratio );
		return sprintf(
			'<div class="dpt-embed dpt-embed--ratio dpt-embed--%1$s" style="padding-top:%2$s%%">%3$s</div>',
			esc_attr( $resolved['type'] ),
			esc_attr( (string) $pad ),
			$iframe
		);
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
