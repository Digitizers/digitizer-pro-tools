<?php
/**
 * UTM Passthrough module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UTM_Settings {

	const OPTION = 'dpt_utm_passthrough';

	public static function defaults() {
		return array(
			'hubspot_ads' => '0',
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
		return array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean = self::all();
		foreach ( array_keys( self::defaults() ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$clean[ $flag ] = '1' === $raw[ $flag ] ? '1' : '0';
			}
		}
		update_option( self::OPTION, $clean );
		return true;
	}
}
