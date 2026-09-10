<?php
/**
 * Shabbat Keeper settings: one option, whitelisted on the way in.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Settings {

	const OPTION = 'dpt_shabbat_keeper';

	const MODES     = array( 'closed', 'business' );
	const HAVDALAH  = array( '42', '72' );
	const OVERRIDES = array( 'auto', 'force_open', 'force_closed' );
	const BOOLS     = array( 'closed_show_times', 'block_woo', 'block_forms', 'hide_contact', 'banner_on' );

	public static function defaults() {
		return array(
			'mode'              => 'business',
			'city'              => 'jerusalem',
			'custom_lat'        => '0',
			'custom_lon'        => '0',
			'custom_candle'     => '18',
			'havdalah'          => '42',
			'override'          => 'auto',
			'closed_title'      => '',
			'closed_message'    => '',
			'closed_show_times' => '1',
			'block_woo'         => '1',
			'block_forms'       => '1',
			'hide_contact'      => '0',
			'banner_on'         => '1',
			'banner_text'       => '',
		);
	}

	/** Seed on activation; on upgrade fill missing keys and drop unknown ones. */
	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		$merged = array_merge( self::defaults(), array_intersect_key( $existing, self::defaults() ) );
		if ( $merged !== $existing ) {
			update_option( self::OPTION, $merged );
		}
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $opts, self::defaults() ) );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** A saved text, or its translated default when the site left it empty. */
	public static function text( $key ) {
		$saved = trim( (string) self::get( $key ) );
		if ( '' !== $saved ) {
			return $saved;
		}
		switch ( $key ) {
			case 'closed_title':
				return __( 'The site is closed for Shabbat', 'digitizer-pro-tools' );
			case 'closed_message':
				return __( 'We observe Shabbat and Jewish holidays. The site reopens automatically after Havdalah.', 'digitizer-pro-tools' );
			case 'banner_text':
				/* translators: %s: "on Saturday 19:32", "after Havdalah" or "when the closure is lifted". */
				return __( 'The site is closed for Shabbat. Orders and forms reopen %s.', 'digitizer-pro-tools' );
		}
		return '';
	}

	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$d     = self::defaults();
		$clean = array();

		$clean['mode']     = isset( $raw['mode'] ) && in_array( $raw['mode'], self::MODES, true ) ? $raw['mode'] : $d['mode'];
		$clean['havdalah'] = isset( $raw['havdalah'] ) && in_array( (string) $raw['havdalah'], self::HAVDALAH, true ) ? (string) $raw['havdalah'] : $d['havdalah'];
		$clean['override'] = isset( $raw['override'] ) && in_array( $raw['override'], self::OVERRIDES, true ) ? $raw['override'] : $d['override'];

		$city          = isset( $raw['city'] ) ? sanitize_key( $raw['city'] ) : '';
		$clean['city'] = ( 'custom' === $city || null !== DPT_SK_Cities::get( $city ) ) ? $city : $d['city'];

		// Kept below the polar circles: past them the sun may not set, a window
		// would have no start or end, and the site would fail open for weeks.
		$clean['custom_lat']    = (string) self::clamp_float( isset( $raw['custom_lat'] ) ? $raw['custom_lat'] : 0, -65, 65 );
		$clean['custom_lon']    = (string) self::clamp_float( isset( $raw['custom_lon'] ) ? $raw['custom_lon'] : 0, -180, 180 );
		$clean['custom_candle'] = (string) max( 0, min( 60, absint( isset( $raw['custom_candle'] ) ? $raw['custom_candle'] : 18 ) ) );

		foreach ( self::BOOLS as $key ) {
			$clean[ $key ] = ! empty( $raw[ $key ] ) ? '1' : '0';
		}

		$clean['closed_title']   = isset( $raw['closed_title'] ) ? sanitize_text_field( wp_unslash( $raw['closed_title'] ) ) : '';
		$clean['closed_message'] = isset( $raw['closed_message'] ) ? wp_kses_post( wp_unslash( $raw['closed_message'] ) ) : '';
		$clean['banner_text']    = isset( $raw['banner_text'] ) ? sanitize_text_field( wp_unslash( $raw['banner_text'] ) ) : '';

		return $clean;
	}

	/** Replace the option with a sanitized copy of the submitted fields, defaults filling the rest. */
	public static function save( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $raw ) ) );
	}

	/** @return array{name:string, lat:float, lon:float, candle:int} */
	public static function location() {
		$o = self::all();
		if ( 'custom' === $o['city'] ) {
			return array(
				'name'   => __( 'Custom location', 'digitizer-pro-tools' ),
				'lat'    => (float) $o['custom_lat'],
				'lon'    => (float) $o['custom_lon'],
				'candle' => (int) $o['custom_candle'],
			);
		}
		$city = DPT_SK_Cities::get( $o['city'] );
		return $city ? $city : DPT_SK_Cities::get( 'jerusalem' );
	}

	private static function clamp_float( $v, $min, $max ) {
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		return max( $min, min( $max, $v ) );
	}
}
