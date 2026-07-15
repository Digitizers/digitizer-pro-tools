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
			// WordPress paths and endpoints.
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
			'comments',
			'attachment',
			// Core public query vars: on plain permalinks the slug becomes a
			// query key (/?slug), so any of these would hijack normal
			// front-end routing such as /?p=123 or /?s=term.
			'p',
			'page',
			'page_id',
			'pagename',
			'name',
			's',
			'cat',
			'category_name',
			'tag',
			'tag_id',
			'author',
			'author_name',
			'post_type',
			'taxonomy',
			'term',
			'm',
			'w',
			'year',
			'monthnum',
			'day',
			'hour',
			'minute',
			'second',
			'paged',
			'order',
			'orderby',
			'cpage',
			'attachment_id',
			'preview',
			'robots',
			'sitemap',
			'rest_route',
			'error',
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
	 * Prefix WordPress inserts before pretty permalinks: "index.php/" on
	 * PATHINFO permalink structures (e.g. /index.php/%postname%/), empty
	 * otherwise. Front-end URLs on those installs only route through the
	 * index.php prefix, so the login URL must carry it too.
	 */
	public static function permalink_prefix() {
		$struct = get_option( 'permalink_structure' );
		if ( $struct && 0 === strpos( ltrim( $struct, '/' ), 'index.php' ) ) {
			return 'index.php/';
		}
		return '';
	}

	/**
	 * The current absolute login URL under the custom slug.
	 */
	public static function new_login_url( $scheme = null ) {
		$struct = get_option( 'permalink_structure' );
		if ( $struct ) {
			$url = home_url( '/', $scheme ) . self::permalink_prefix() . self::slug();
			if ( '/' === substr( $struct, -1 ) ) {
				$url = trailingslashit( $url );
			}
			return $url;
		}
		return home_url( '/', $scheme ) . '?' . self::slug();
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
