<?php
/**
 * Hide Login module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_HL_Settings {

	const OPTION = 'dpt_hide_login';

	public static function defaults() {
		return array(
			'slug' => 'login',
		);
	}

	/**
	 * Slugs that would break WordPress itself if the login page moved there.
	 */
	public static function reserved_slugs() {
		$reserved = array(
			'wp-admin',
			'wp-login',
			'wp-login.php',
			'wp-content',
			'wp-includes',
			'wp-json',
			'admin',
			'dashboard',
			'wp-signup',
			'wp-activate',
			'wp-register',
			'404',
			'feed',
			'embed',
			'page',
			'comments',
			'search',
			'attachment',
		);
		return apply_filters( 'dpt_hl_reserved_slugs', $reserved );
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
		$slug = self::sanitize_slug( $all['slug'] );
		if ( '' === $slug ) {
			$slug = self::defaults()['slug'];
		}
		$all['slug'] = $slug;
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function slug() {
		return self::get( 'slug' );
	}

	/**
	 * One URL path segment: lowercase letters, digits, dashes, underscores.
	 */
	public static function sanitize_slug( $slug ) {
		$slug = is_string( $slug ) ? strtolower( trim( $slug, " /\t\n\r" ) ) : '';
		$slug = preg_replace( '/[^a-z0-9_-]/', '', $slug );
		if ( in_array( $slug, self::reserved_slugs(), true ) ) {
			return '';
		}
		return $slug;
	}

	/**
	 * The current login URL under the custom slug.
	 */
	public static function new_login_url( $scheme = null ) {
		$slug = self::slug();
		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( '/', $scheme ) . $slug;
			if ( '/' === substr( get_option( 'permalink_structure' ), -1 ) ) {
				$url = trailingslashit( $url );
			}
			return $url;
		}
		return home_url( '/', $scheme ) . '?' . $slug;
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$before = self::all();
		$clean  = $before;

		if ( isset( $raw['slug'] ) ) {
			$slug = self::sanitize_slug( is_array( $raw['slug'] ) ? '' : wp_unslash( $raw['slug'] ) );
			if ( '' !== $slug ) {
				$clean['slug'] = $slug;
			}
		}

		update_option( self::OPTION, $clean );

		// Cached pages can embed wp-login.php URLs (post-password forms,
		// login/register links) that this module rewrites to the slug.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
