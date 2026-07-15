<?php
/**
 * Disable Comments module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_DC_Settings {

	const OPTION = 'dpt_disable_comments';

	public static function defaults() {
		return array(
			// all      - disable comments everywhere (minus protected types)
			// selected - disable only for the post types listed below
			'mode'             => 'all',
			'post_types'       => array(),
			// WooCommerce product reviews ARE comments (comment_type
			// 'review' on the 'product' post type) - protect them by
			// default so shop sites keep their reviews.
			'keep_woo_reviews' => '1',
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
		if ( ! in_array( $all['mode'], array( 'all', 'selected' ), true ) ) {
			$all['mode'] = 'all';
		}
		if ( ! is_array( $all['post_types'] ) ) {
			$all['post_types'] = array();
		}
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Post types offered in the settings UI: everything registered with
	 * comments support.
	 */
	public static function comment_post_types() {
		$types = array();
		foreach ( get_post_types( array(), 'names' ) as $type ) {
			if ( post_type_supports( $type, 'comments' ) ) {
				$types[] = $type;
			}
		}
		return array_values( apply_filters( 'dpt_dc_post_types', $types ) );
	}

	/**
	 * Should comments be disabled for this post type?
	 */
	public static function disabled_for( $post_type ) {
		$o = self::all();
		if ( 'product' === $post_type && '1' === $o['keep_woo_reviews'] ) {
			return false;
		}
		if ( 'all' === $o['mode'] ) {
			return true;
		}
		return in_array( $post_type, $o['post_types'], true );
	}

	/**
	 * True when no comment-supporting post type is left with comments on
	 * (the WooCommerce 'product' type does not count: since WC 6.7 reviews
	 * are managed on WooCommerce's own Products > Reviews screen, not on
	 * edit-comments.php). Controls whether the admin comments UI is removed.
	 */
	public static function fully_disabled() {
		foreach ( self::comment_post_types() as $type ) {
			if ( 'product' === $type ) {
				continue;
			}
			if ( ! self::disabled_for( $type ) ) {
				return false;
			}
		}
		return true;
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean = self::all();

		if ( isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'all', 'selected' ), true ) ) {
			$clean['mode'] = $raw['mode'];
		}

		$post_types = array();
		if ( isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $type ) {
				$type = sanitize_key( is_array( $type ) ? '' : wp_unslash( $type ) );
				if ( $type && in_array( $type, self::comment_post_types(), true ) ) {
					$post_types[] = $type;
				}
			}
		}
		$clean['post_types'] = array_values( array_unique( $post_types ) );

		if ( isset( $raw['keep_woo_reviews'] ) ) {
			$clean['keep_woo_reviews'] = '1' === $raw['keep_woo_reviews'] ? '1' : '0';
		}

		update_option( self::OPTION, $clean );
		return true;
	}
}
