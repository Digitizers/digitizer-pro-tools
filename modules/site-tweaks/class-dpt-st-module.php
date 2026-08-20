<?php
/**
 * Site Tweaks module - dynamic replacement for the assorted functions.php
 * hardening/utility snippets: HTTP security headers, sanitised SVG uploads,
 * WordPress version hiding and a couple of Elementor conveniences.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-st-settings.php';
require_once __DIR__ . '/class-dpt-st-svg.php';
require_once __DIR__ . '/class-dpt-st-admin.php';

class DPT_Site_Tweaks_Module extends DPT_Module {

	/** @var DPT_ST_Admin */
	private $admin;

	public function id() {
		return 'site_tweaks';
	}

	public function title() {
		return __( 'Site Tweaks', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Small site-wide tweaks: HTTP security headers, sanitised SVG uploads, hiding the WordPress version, Elementor helpers, and dropping front-end weight an Elementor site does not use. Each tweak is an independent toggle.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_ST_Settings::install_defaults();
	}

	public function init() {
		$o = DPT_ST_Settings::all();

		// --- HTTP security headers (frontend responses) ---------------------
		if ( '1' === $o['x_frame_options'] || '1' === $o['x_content_type_options']
			|| '1' === $o['x_xss_protection'] || '1' === $o['hsts'] ) {
			add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		}

		// --- Hide the WordPress version ------------------------------------
		if ( '1' === $o['remove_generator'] ) {
			add_filter( 'the_generator', '__return_empty_string' );
			remove_action( 'wp_head', 'wp_generator' );
			// Strip ?ver=<wp version> only from core-versioned assets, so the
			// exact WP version is not leaked - unlike blanket ?ver removal this
			// keeps real cache-busting for plugin/theme asset versions.
			add_filter( 'style_loader_src', array( $this, 'strip_core_version' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'strip_core_version' ), 9999 );
		}

		// --- SVG uploads (sanitised, capability-gated) ---------------------
		if ( '1' === $o['svg_upload'] ) {
			add_filter( 'upload_mimes', array( $this, 'allow_svg_mime' ) );
			add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_svg_filetype' ), 10, 4 );
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'sanitize_svg_upload' ) );
			add_action( 'admin_head', array( $this, 'svg_admin_thumb_css' ) );
		}

		// --- Elementor conveniences ----------------------------------------
		if ( '1' === $o['elementor_google_fonts'] ) {
			add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
		}
		if ( '1' === $o['elementor_tel_validate'] ) {
			add_action( 'elementor_pro/forms/validation/tel', array( $this, 'validate_tel_field' ), 10, 3 );
		}
		if ( '1' === $o['elementor_icon_fonts'] ) {
			add_action( 'elementor/frontend/after_register_styles', array( $this, 'drop_elementor_icon_fonts' ), 20 );
		}

		// --- Front-end weight ----------------------------------------------
		if ( '1' === $o['block_library_css'] ) {
			// Late, and on the front end only: the block editor and the widget
			// screen load the same stylesheets and need them.
			add_action( 'wp_enqueue_scripts', array( $this, 'drop_block_library_css' ), 100 );
		}
		if ( '1' === $o['disable_block_editor'] ) {
			add_filter( 'use_block_editor_for_post', array( $this, 'classic_editor_for_post' ), 10, 2 );
			add_filter( 'use_block_editor_for_post_type', array( $this, 'classic_editor_for_post_type' ), 10, 2 );
		}

		if ( is_admin() ) {
			$this->admin = new DPT_ST_Admin();
		}
	}

	/**
	 * The post types the classic editor is forced for.
	 *
	 * Posts and pages, which is what the setting says and all it should mean.
	 * Returning false for everything would also take the block editor from
	 * post types that require it - reusable blocks and template parts are
	 * edited with it and have no classic equivalent - and from any custom type
	 * a plugin registered expecting it.
	 *
	 * @return array
	 */
	public function classic_editor_post_types() {
		/**
		 * Filter which post types are edited in the classic editor.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'dpt_st_classic_editor_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Force the classic editor for one post, leaving other types alone.
	 *
	 * @param bool  $use  Whether the block editor would be used.
	 * @param mixed $post The post being edited.
	 * @return bool
	 */
	public function classic_editor_for_post( $use, $post = null ) {
		$type = ( is_object( $post ) && isset( $post->post_type ) ) ? (string) $post->post_type : '';
		return in_array( $type, $this->classic_editor_post_types(), true ) ? false : $use;
	}

	/**
	 * The same question asked about a post type rather than a post.
	 *
	 * @param bool   $use       Whether the block editor would be used.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function classic_editor_for_post_type( $use, $post_type = '' ) {
		return in_array( (string) $post_type, $this->classic_editor_post_types(), true ) ? false : $use;
	}

	/**
	 * Stop Elementor's icon fonts loading, without breaking what depends on
	 * them.
	 *
	 * Deregistering the handles outright would be wrong. WordPress skips a
	 * style whose dependency is missing, and Elementor registers
	 * `elementor-common` with `elementor-icons` as a dependency - so removing
	 * the icons would silently take the stylesheet above them as well. That is
	 * a much larger hole than the one this setting is for, and it would look
	 * like a broken page rather than a missing icon.
	 *
	 * So each handle is re-registered with no source: still there for anything
	 * that depends on it, resolving exactly as before, and printing nothing.
	 *
	 * @return void
	 */
	public function drop_elementor_icon_fonts() {
		$handles = array(
			'elementor-icons-fa-solid',
			'elementor-icons-fa-regular',
			'elementor-icons-fa-brands',
			'elementor-icons',
		);
		foreach ( $handles as $handle ) {
			wp_deregister_style( $handle );
			wp_register_style( $handle, false, array(), null );
		}
	}

	/**
	 * Dequeue the block library stylesheets on the front end.
	 *
	 * Dequeued rather than deregistered, so anything that asks for them later
	 * in the same request can still get them - and only on the front end,
	 * because the editor screens are built out of these styles.
	 *
	 * A page that does use a block will lose its styling. On a site built with
	 * Elementor that is usually no page at all, which is the case this exists
	 * for; it is off by default because "usually" is not "always".
	 *
	 * @return void
	 */
	public function drop_block_library_css() {
		if ( is_admin() ) {
			return;
		}
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
	}

	/**
	 * Emit the enabled security headers. Runs on send_headers, which fires for
	 * frontend responses only (never wp-admin).
	 */
	public function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}
		$o = DPT_ST_Settings::all();

		if ( '1' === $o['x_frame_options'] ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
		if ( '1' === $o['x_content_type_options'] ) {
			header( 'X-Content-Type-Options: nosniff' );
		}
		if ( '1' === $o['x_xss_protection'] ) {
			header( 'X-XSS-Protection: 1; mode=block' );
		}
		// HSTS only ever over HTTPS - sending it on plain HTTP is ignored by
		// browsers and would be meaningless, and enabling it site-wide before
		// HTTPS is solid can lock visitors out.
		if ( '1' === $o['hsts'] && is_ssl() ) {
			$value = 'max-age=31536000';
			if ( '1' === $o['hsts_preload'] ) {
				$value .= '; includeSubDomains; preload';
			}
			header( 'Strict-Transport-Security: ' . $value );
		}
	}

	/**
	 * Remove ?ver=<current WP version> from asset URLs so responses don't
	 * advertise the exact core version. Plugin/theme version query args are
	 * preserved (cache-busting intact).
	 *
	 * @param string $src Asset URL.
	 */
	public function strip_core_version( $src ) {
		global $wp_version;
		if ( ! is_string( $src ) || '' === (string) $wp_version ) {
			return $src;
		}
		$query = wp_parse_url( $src, PHP_URL_QUERY );
		if ( ! $query ) {
			return $src;
		}
		$args = array();
		parse_str( $query, $args );
		// Exact match only: a plugin asset versioned ?ver=6.8.10 on core 6.8
		// must keep its cache-busting query - substring matching would strip it.
		if ( isset( $args['ver'] ) && (string) $args['ver'] === (string) $wp_version ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	// --- SVG ---------------------------------------------------------------

	/**
	 * Add the SVG mime type - only for users allowed to upload SVGs. Other
	 * users keep the default whitelist, so SVG stays blocked for them.
	 *
	 * @param array $mimes Allowed mime types.
	 */
	public function allow_svg_mime( $mimes ) {
		if ( current_user_can( DPT_ST_Settings::svg_capability() ) ) {
			// Only plain .svg: the sanitiser cannot inspect gzip-compressed
			// .svgz, so it is not accepted.
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Teach WordPress that a .svg file really is image/svg+xml. Core's
	 * real-file sniffing otherwise reports SVG as text/plain and rejects the
	 * upload. Capability-gated so it never widens uploads for other users.
	 *
	 * @param array  $data     ext/type/proper_filename result.
	 * @param string $file     Full path to the file.
	 * @param string $filename Name of the file.
	 * @param array  $mimes    Allowed mime types.
	 */
	public function fix_svg_filetype( $data, $file, $filename, $mimes ) {
		if ( ! current_user_can( DPT_ST_Settings::svg_capability() ) ) {
			return $data;
		}
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Sanitise the SVG before WordPress moves it into the uploads directory.
	 * If sanitisation fails (unparseable file), the upload is rejected with an
	 * error rather than stored.
	 *
	 * @param array $file Entry from $_FILES being processed.
	 */
	public function sanitize_svg_upload( $file ) {
		$type = isset( $file['type'] ) ? (string) $file['type'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$is_svg = ( 'image/svg+xml' === $type || 'svg' === $ext );
		if ( ! $is_svg ) {
			return $file;
		}

		// Defence in depth: a non-privileged user should never reach here
		// (the mime type is not whitelisted for them), but reject outright if
		// they somehow do.
		if ( ! current_user_can( DPT_ST_Settings::svg_capability() ) ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'digitizer-pro-tools' );
			return $file;
		}

		if ( empty( $file['tmp_name'] ) || ! DPT_ST_SVG_Sanitizer::sanitize_file( $file['tmp_name'] ) ) {
			$file['error'] = __( 'This SVG could not be sanitised and was not uploaded.', 'digitizer-pro-tools' );
		}
		return $file;
	}

	/**
	 * Give SVGs sensible dimensions in the media library (they otherwise
	 * render at 0x0 in some admin thumbnails).
	 */
	public function svg_admin_thumb_css() {
		echo '<style id="dpt-st-svg">.media-icon img[src$=".svg"],img.attachment-post-thumbnail[src$=".svg"],.attachment-preview .thumbnail img[src$=".svg"]{width:100%;height:auto;}</style>' . "\n";
	}

	// --- Elementor ---------------------------------------------------------

	/**
	 * Validate an Elementor Pro "tel" form field against an international
	 * phone-number shape. Signature matches the Elementor Pro action.
	 *
	 * @param array  $field        The field record.
	 * @param object $record       Form record.
	 * @param object $ajax_handler Ajax handler (add_error()).
	 */
	public function validate_tel_field( $field, $record, $ajax_handler ) {
		$value = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';
		if ( '' === $value ) {
			return; // Empty handling is Elementor's "required" job, not ours.
		}
		if ( ! preg_match( '/^\+?[0-9]{9,14}$/', $value ) ) {
			$message = apply_filters(
				'dpt_st_tel_error_message',
				__( 'Please enter a valid phone number.', 'digitizer-pro-tools' )
			);
			$ajax_handler->add_error( $field['id'], $message );
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
