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
		return __( 'Small site-wide tweaks: HTTP security headers, sanitised SVG uploads, hiding the WordPress version, and Elementor helpers. Each tweak is an independent toggle.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return false;
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

		if ( is_admin() ) {
			$this->admin = new DPT_ST_Admin();
		}
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
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
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
		if ( 'svg' === $ext || 'svgz' === $ext ) {
			$data['ext']  = $ext;
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

		$is_svg = ( 'image/svg+xml' === $type || 'svg' === $ext || 'svgz' === $ext );
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
