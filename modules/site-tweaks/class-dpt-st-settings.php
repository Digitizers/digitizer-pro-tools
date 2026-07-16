<?php
/**
 * Site Tweaks module - settings storage.
 *
 * Consolidates the loose functions.php hardening/utility snippets Digitizer
 * pastes on client sites: HTTP security headers, SVG uploads (sanitised),
 * removal of the WordPress generator version, and a couple of Elementor
 * conveniences. Each tweak is an independent toggle.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ST_Settings {

	const OPTION = 'dpt_site_tweaks';

	/**
	 * Boolean-style keys stored as '1'/'0'.
	 */
	public static function defaults() {
		return array(
			// HTTP security headers (sent on frontend responses).
			'x_frame_options'        => '1', // X-Frame-Options: SAMEORIGIN
			'x_content_type_options' => '1', // X-Content-Type-Options: nosniff
			// X-XSS-Protection is deprecated; modern browsers ignore it and the
			// legacy "1; mode=block" filter has itself caused issues. Off by
			// default, exposed only for parity with the old snippet.
			'x_xss_protection'       => '0',
			// HSTS is powerful but hard to undo (browsers cache it). Opt-in,
			// and only ever emitted over HTTPS.
			'hsts'                   => '0',
			// Adds "; includeSubDomains; preload" to the HSTS header. Extra
			// dangerous (affects every subdomain, and preload is near-permanent).
			'hsts_preload'           => '0',

			// Allow (sanitised) SVG uploads for administrators.
			'svg_upload'             => '0',

			// Strip the WordPress version meta tag / generator strings.
			'remove_generator'       => '1',

			// Elementor conveniences (only wired when Elementor is active).
			'elementor_google_fonts' => '0', // '1' = stop Elementor loading Google Fonts
			'elementor_tel_validate' => '0', // '1' = enforce a phone-number format on tel fields
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		update_option( self::OPTION, array_merge( self::defaults(), $existing ) );
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		$all  = array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
		// Normalise every known key to a strict '1'/'0'.
		foreach ( array_keys( self::defaults() ) as $key ) {
			$all[ $key ] = ( isset( $all[ $key ] ) && '1' === (string) $all[ $key ] ) ? '1' : '0';
		}
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function is_on( $key ) {
		return '1' === self::get( $key );
	}

	/**
	 * Capability required to upload SVGs. Filterable so sites can widen it
	 * (e.g. to 'upload_files') deliberately.
	 */
	public static function svg_capability() {
		return apply_filters( 'dpt_st_svg_capability', 'manage_options' );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean  = self::all();
		$before = self::all();

		foreach ( array_keys( self::defaults() ) as $key ) {
			$clean[ $key ] = ( isset( $raw[ $key ] ) && '1' === (string) $raw[ $key ] ) ? '1' : '0';
		}

		update_option( self::OPTION, $clean );

		// Headers / generator output are part of the cacheable response -
		// invalidate page caches when behaviour actually changed.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
