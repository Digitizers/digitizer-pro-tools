<?php
/**
 * Name Your Price module - settings storage and per-product meta access.
 *
 * Replaces the WPC "Name Your Price" plugin: let customers set their own price
 * on chosen WooCommerce products (donations / pay-what-you-want), with optional
 * minimum, maximum, suggested and default prices enforced on the server.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_NYP_Settings {

	const OPTION = 'dpt_name_your_price';

	// Per-product meta keys.
	const META_ENABLED   = '_dpt_nyp_enabled';
	const META_MIN       = '_dpt_nyp_min';
	const META_MAX       = '_dpt_nyp_max';
	const META_SUGGESTED = '_dpt_nyp_suggested';
	const META_DEFAULT   = '_dpt_nyp_default';

	public static function defaults() {
		return array(
			'label'          => 'Name your price',
			'show_range_hint' => '1', // show the allowed min/max under the field
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
		$all['label']           = is_string( $all['label'] ) && '' !== trim( $all['label'] ) ? $all['label'] : 'Name your price';
		$all['show_range_hint'] = ( '1' === (string) $all['show_range_hint'] ) ? '1' : '0';
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function is_on( $key ) {
		return '1' === (string) self::get( $key );
	}

	public static function label() {
		return (string) apply_filters( 'dpt_nyp_label', self::get( 'label' ) );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean = self::all();
		$clean['label']           = isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : 'Name your price';
		if ( '' === trim( $clean['label'] ) ) {
			$clean['label'] = 'Name your price';
		}
		$clean['show_range_hint'] = ( isset( $raw['show_range_hint'] ) && '1' === (string) $raw['show_range_hint'] ) ? '1' : '0';
		update_option( self::OPTION, $clean );
		return true;
	}

	// --- Per-product meta --------------------------------------------------

	public static function is_nyp( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, self::META_ENABLED, true );
	}

	/**
	 * A numeric meta value, or null when unset/blank. Never returns a negative.
	 */
	public static function meta_price( $product_id, $meta_key ) {
		$raw = get_post_meta( (int) $product_id, $meta_key, true );
		// Meta is stored canonically (dot decimal, via wc_format_decimal), so it
		// must be read as a plain float - NOT through the locale-aware input
		// parser, which would misread the dot as a thousands separator in stores
		// configured with a comma decimal separator.
		if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$val = (float) $raw;
		return ( ! is_finite( $val ) || $val < 0 ) ? null : $val;
	}

	public static function min_price( $product_id ) {
		return self::meta_price( $product_id, self::META_MIN );
	}

	public static function max_price( $product_id ) {
		return self::meta_price( $product_id, self::META_MAX );
	}

	public static function suggested_price( $product_id ) {
		return self::meta_price( $product_id, self::META_SUGGESTED );
	}

	public static function default_price( $product_id ) {
		$default = self::meta_price( $product_id, self::META_DEFAULT );
		if ( null !== $default ) {
			return $default;
		}
		// Fall back to the suggested price as the pre-filled default.
		return self::suggested_price( $product_id );
	}

	/**
	 * The base price given to an otherwise-unpriced NYP product so WooCommerce
	 * treats it as purchasable: default (or suggested), else minimum, else 0.
	 */
	public static function base_price( $product_id ) {
		$default = self::default_price( $product_id );
		if ( null !== $default ) {
			return $default;
		}
		$min = self::min_price( $product_id );
		return ( null !== $min ) ? $min : 0.0;
	}

	/**
	 * Parse a user/meta price into a non-negative float, or null if invalid.
	 * Accepts comma or dot decimals; rejects anything non-numeric.
	 */
	public static function sanitize_price( $value ) {
		if ( is_array( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( function_exists( 'wc_get_price_decimal_separator' ) && function_exists( 'wc_get_price_thousand_separator' ) ) {
			// Use the store's configured separators, so "1,000" is 1000 in a
			// comma-grouping store and 1.0 in a comma-decimal store.
			$decimal  = (string) wc_get_price_decimal_separator();
			$thousand = (string) wc_get_price_thousand_separator();
			if ( '' !== $thousand ) {
				$value = str_replace( $thousand, '', $value );
			}
			if ( '' !== $decimal ) {
				$value = str_replace( $decimal, '.', $value );
			}
			// Do NOT strip unexpected characters - reject them instead, so
			// "10oops20" or "1e309" fail validation rather than becoming 1020/1309.
			if ( preg_match( '/[^0-9.\-]/', $value ) ) {
				return null;
			}
		} else {
			// No WooCommerce (e.g. tests): the last , or . is the decimal point.
			$value = str_replace( ' ', '', $value );
			if ( preg_match( '/[.,](\d+)$/', $value, $m ) ) {
				$dec     = $m[1];
				$intpart = preg_replace( '/[.,]/', '', substr( $value, 0, strlen( $value ) - strlen( $dec ) - 1 ) );
				$value   = $intpart . '.' . $dec;
			} else {
				$value = preg_replace( '/[.,]/', '', $value );
			}
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$num = (float) $value;
		// Reject non-finite values (e.g. an overflowing "1e309" -> INF).
		if ( ! is_finite( $num ) ) {
			return null;
		}
		return $num < 0 ? null : $num;
	}

	/**
	 * Validate a submitted price for a product. Returns an error message, or
	 * null when the price is acceptable. This is the authoritative, server-side
	 * check - never trust the posted price without it.
	 *
	 * @param int    $product_id Product id.
	 * @param mixed  $raw_price  Raw submitted price.
	 * @return string|null
	 */
	public static function validate_price( $product_id, $raw_price ) {
		$price = self::sanitize_price( $raw_price );
		if ( null === $price ) {
			return __( 'Please enter a valid price.', 'digitizer-pro-tools' );
		}

		$min = self::min_price( $product_id );
		$max = self::max_price( $product_id );

		// A price of 0 is only allowed when no positive minimum is set.
		$floor = ( null !== $min ) ? $min : 0.0;
		if ( $price < $floor ) {
			return sprintf(
				/* translators: %s: formatted minimum price */
				__( 'The price must be at least %s.', 'digitizer-pro-tools' ),
				self::format_price( $floor )
			);
		}
		if ( null !== $max && $price > $max ) {
			return sprintf(
				/* translators: %s: formatted maximum price */
				__( 'The price must be no more than %s.', 'digitizer-pro-tools' ),
				self::format_price( $max )
			);
		}
		if ( null === $min && $price <= 0 ) {
			return __( 'Please enter a price greater than zero.', 'digitizer-pro-tools' );
		}
		return null;
	}

	/**
	 * Format a price for a message - uses WooCommerce when available.
	 */
	public static function format_price( $price ) {
		if ( function_exists( 'wc_price' ) ) {
			// wc_price() returns HTML and some currency symbols are HTML entities
			// (e.g. &euro;). Strip the tags AND decode the entities, so callers
			// that esc_html() the result do not double-encode the symbol.
			return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format( (float) $price, 2 );
	}
}
