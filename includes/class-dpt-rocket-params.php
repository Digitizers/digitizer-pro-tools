<?php
/**
 * Query strings WP Rocket should serve from one cached page. Modules add to
 * the list through dpt_rocket_ignored_params.
 *
 * Offering the list on WP Rocket's filter is not enough: its cache handler
 * reads the list from a generated config file, so the file must be rebuilt
 * whenever the list changes. Modules cannot do that themselves - a module
 * that has just been switched off no longer runs - so this lives in the core
 * and compares against what it last wrote, on the next admin request.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Rocket_Params {

	const OPTION = 'dpt_rocket_ignored_hash';

	public static function register() {
		add_filter( 'rocket_cache_ignored_parameters', array( __CLASS__, 'add_to_rocket' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'sync' ) );
	}

	/**
	 * @return string[]
	 */
	public static function params() {
		$params = self::clean( apply_filters( 'dpt_rocket_ignored_params', array() ) );
		sort( $params );
		return $params;
	}

	/**
	 * Parameter names from a filter: trimmed, non-empty strings, once each.
	 *
	 * @param mixed $names What the filter returned.
	 * @return string[]
	 */
	public static function clean( $names ) {
		$clean = array();
		foreach ( (array) $names as $name ) {
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$clean[] = trim( $name );
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param array $params WP Rocket's list, parameter names as keys.
	 * @return array
	 */
	public static function add_to_rocket( $params ) {
		foreach ( self::params() as $name ) {
			$params[ $name ] = 1;
		}
		return $params;
	}

	public static function sync() {
		if ( ! function_exists( 'rocket_generate_config_file' ) ) {
			return;
		}
		$params = self::params();
		$hash   = $params ? md5( implode( "\n", $params ) ) : '';
		if ( get_option( self::OPTION, '' ) === $hash ) {
			return;
		}
		rocket_generate_config_file();
		update_option( self::OPTION, $hash );
	}

	/**
	 * The plugin is going away: take the list out of WP Rocket's file.
	 */
	public static function deactivate() {
		remove_filter( 'rocket_cache_ignored_parameters', array( __CLASS__, 'add_to_rocket' ), 999 );
		if ( '' !== get_option( self::OPTION, '' ) && function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		delete_option( self::OPTION );
	}
}
