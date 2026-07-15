<?php
/**
 * Duplicate Post module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_DP_Settings {

	const OPTION = 'dpt_duplicate_post';

	public static function defaults() {
		// Seed the title suffix by the site locale so Hebrew sites get a
		// Hebrew suffix out of the box. Stored once; admins can change it.
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$suffix = ( 0 === strpos( (string) $locale, 'he' ) ) ? '(עותק)' : '(Copy)';

		return array(
			'post_types'      => array( 'post', 'page' ),
			'title_suffix'    => $suffix,
			'copy_meta'       => '1',
			'copy_taxonomies' => '1',
			'copy_date'       => '0',
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
	 * Post types the module is active for (validated against existing,
	 * duplicable post types).
	 */
	public static function enabled_post_types() {
		$saved = self::get( 'post_types' );
		return array_values( array_intersect( (array) $saved, self::duplicable_post_types() ) );
	}

	/**
	 * Post types that can be offered in the settings UI: public, or
	 * otherwise editable with a UI (e.g. Elementor templates).
	 */
	public static function duplicable_post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( apply_filters( 'dpt_dp_post_types', $types ) );
	}

	/**
	 * Save with sanitization. Full form (single page, no tabs).
	 */
	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$defaults = self::defaults();
		$existing = get_option( self::OPTION, array() );
		$clean    = array_merge( $defaults, is_array( $existing ) ? $existing : array() );

		$post_types = array();
		if ( isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $type ) {
				$type = sanitize_key( is_array( $type ) ? '' : wp_unslash( $type ) );
				if ( $type && in_array( $type, self::duplicable_post_types(), true ) ) {
					$post_types[] = $type;
				}
			}
		}
		$clean['post_types'] = array_values( array_unique( $post_types ) );

		if ( isset( $raw['title_suffix'] ) && ! is_array( $raw['title_suffix'] ) ) {
			$clean['title_suffix'] = sanitize_text_field( wp_unslash( $raw['title_suffix'] ) );
		}

		foreach ( array( 'copy_meta', 'copy_taxonomies', 'copy_date' ) as $flag ) {
			$clean[ $flag ] = ( isset( $raw[ $flag ] ) && '1' === $raw[ $flag ] ) ? '1' : '0';
		}

		update_option( self::OPTION, $clean );
		return true;
	}
}
