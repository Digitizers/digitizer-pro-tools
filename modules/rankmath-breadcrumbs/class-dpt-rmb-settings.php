<?php
/**
 * Rank Math Breadcrumbs module - settings storage.
 *
 * Replaces two Rank Math breadcrumb snippets: inserting a "Blog" crumb on post
 * contexts and a "Shop" crumb on WooCommerce product pages. URLs and labels are
 * auto-detected from the site (posts page / WooCommerce shop page) and can be
 * overridden manually.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RMB_Settings {

	const OPTION = 'dpt_rankmath_breadcrumbs';

	public static function defaults() {
		return array(
			'blog_crumb' => '1',
			'blog_label' => '', // empty = auto (posts page title, else "Blog")
			'blog_url'   => '', // empty = auto (posts page permalink)
			'shop_crumb' => '1',
			'shop_label' => '', // empty = auto (shop page title, else "Shop")
			'shop_url'   => '', // empty = auto (wc_get_page_permalink('shop'))
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

		$all['blog_crumb'] = ( '1' === (string) $all['blog_crumb'] ) ? '1' : '0';
		$all['shop_crumb'] = ( '1' === (string) $all['shop_crumb'] ) ? '1' : '0';
		foreach ( array( 'blog_label', 'blog_url', 'shop_label', 'shop_url' ) as $k ) {
			$all[ $k ] = is_string( $all[ $k ] ) ? $all[ $k ] : '';
		}
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function is_on( $key ) {
		return '1' === (string) self::get( $key );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean  = self::all();
		$before = self::all();

		$clean['blog_crumb'] = ( isset( $raw['blog_crumb'] ) && '1' === (string) $raw['blog_crumb'] ) ? '1' : '0';
		$clean['shop_crumb'] = ( isset( $raw['shop_crumb'] ) && '1' === (string) $raw['shop_crumb'] ) ? '1' : '0';

		$clean['blog_label'] = isset( $raw['blog_label'] ) ? sanitize_text_field( $raw['blog_label'] ) : '';
		$clean['shop_label'] = isset( $raw['shop_label'] ) ? sanitize_text_field( $raw['shop_label'] ) : '';
		$clean['blog_url']   = isset( $raw['blog_url'] ) ? esc_url_raw( trim( (string) $raw['blog_url'] ) ) : '';
		$clean['shop_url']   = isset( $raw['shop_url'] ) ? esc_url_raw( trim( (string) $raw['shop_url'] ) ) : '';

		update_option( self::OPTION, $clean );

		// Breadcrumbs are part of the rendered (cacheable) page markup.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
