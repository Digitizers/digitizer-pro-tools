<?php
/**
 * WooCommerce Checkout module - settings storage.
 *
 * Replaces two hand-pasted checkout snippets: an email-typo suggester on the
 * billing email field, and Israeli phone-number validation on the billing
 * phone field (client-side hint + authoritative server-side check).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_WCC_Settings {

	const OPTION = 'dpt_woo_checkout';

	/**
	 * Domains suggested when a typo is detected. Israeli providers included.
	 */
	public static function default_domains() {
		return array(
			'gmail.com',
			'outlook.com',
			'hotmail.com',
			'yahoo.com',
			'icloud.com',
			'walla.com',
			'walla.co.il',
			'013net.net',
			'netvision.net.il',
		);
	}

	public static function defaults() {
		return array(
			'email_suggestion' => '1',
			'email_domains'    => self::default_domains(),
			'phone_validation' => '1',
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

		$all['email_suggestion'] = ( '1' === (string) $all['email_suggestion'] ) ? '1' : '0';
		$all['phone_validation'] = ( '1' === (string) $all['phone_validation'] ) ? '1' : '0';

		$domains = is_array( $all['email_domains'] ) ? $all['email_domains'] : array();
		$all['email_domains'] = self::sanitize_domains( $domains );
		if ( empty( $all['email_domains'] ) ) {
			$all['email_domains'] = self::default_domains();
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

	/**
	 * The suggestion domain list, filterable so code can extend it.
	 *
	 * @return string[]
	 */
	public static function email_domains() {
		$domains = self::get( 'email_domains' );
		return array_values( (array) apply_filters( 'dpt_wcc_email_domains', $domains ) );
	}

	/**
	 * Normalise a list of domains: lowercase, keep only valid domain
	 * characters, drop blanks and duplicates.
	 *
	 * @param array $domains Raw domain list.
	 * @return string[]
	 */
	public static function sanitize_domains( $domains ) {
		$clean = array();
		foreach ( (array) $domains as $domain ) {
			if ( is_array( $domain ) ) {
				continue;
			}
			$domain = strtolower( trim( (string) $domain ) );
			// Keep only a plausible domain: letters, digits, dots and hyphens,
			// with at least one dot.
			if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain ) ) {
				$clean[] = $domain;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Parse a textarea (one domain per line or comma-separated) into a list.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	public static function parse_domain_textarea( $raw ) {
		$parts = preg_split( '/[\r\n,]+/', (string) $raw );
		return self::sanitize_domains( $parts );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean  = self::all();
		$before = self::all();

		$clean['email_suggestion'] = ( isset( $raw['email_suggestion'] ) && '1' === (string) $raw['email_suggestion'] ) ? '1' : '0';
		$clean['phone_validation'] = ( isset( $raw['phone_validation'] ) && '1' === (string) $raw['phone_validation'] ) ? '1' : '0';

		if ( isset( $raw['email_domains'] ) ) {
			$domains = self::parse_domain_textarea( $raw['email_domains'] );
			$clean['email_domains'] = ! empty( $domains ) ? $domains : self::default_domains();
		}

		update_option( self::OPTION, $clean );

		// Checkout scripts/behaviour are part of the (cacheable) checkout page.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
