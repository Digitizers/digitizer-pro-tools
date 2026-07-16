<?php
/**
 * Content Control module - global settings storage (whole-site protection
 * and the default restriction message).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Settings {

	const OPTION = 'dpt_content_control';

	public static function defaults() {
		return array(
			// off | logged_in | roles
			'site_mode'       => 'off',
			'site_roles'      => array(),
			// login | page | message
			'site_action'     => 'login',
			'site_redirect'   => 0,
			'site_message'    => '',
			// Page IDs always reachable regardless of whole-site protection.
			'exempt_ids'      => array(),
			'default_message' => '',
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

		if ( ! in_array( $all['site_mode'], array( 'off', 'logged_in', 'roles' ), true ) ) {
			$all['site_mode'] = 'off';
		}
		if ( ! in_array( $all['site_action'], array( 'login', 'page', 'message' ), true ) ) {
			$all['site_action'] = 'login';
		}
		$all['site_roles'] = is_array( $all['site_roles'] ) ? array_values( array_filter( array_map( 'sanitize_key', $all['site_roles'] ) ) ) : array();
		$all['exempt_ids'] = is_array( $all['exempt_ids'] ) ? array_values( array_filter( array_map( 'absint', $all['exempt_ids'] ) ) ) : array();
		$all['site_redirect'] = absint( $all['site_redirect'] );
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function site_protection_active() {
		return 'off' !== self::get( 'site_mode' );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$before = self::all();
		$clean  = $before;

		if ( isset( $raw['site_mode'] ) && in_array( $raw['site_mode'], array( 'off', 'logged_in', 'roles' ), true ) ) {
			$clean['site_mode'] = $raw['site_mode'];
		}
		if ( isset( $raw['site_action'] ) && in_array( $raw['site_action'], array( 'login', 'page', 'message' ), true ) ) {
			$clean['site_action'] = $raw['site_action'];
		}
		$clean['site_roles'] = array();
		if ( isset( $raw['site_roles'] ) && is_array( $raw['site_roles'] ) ) {
			foreach ( $raw['site_roles'] as $r ) {
				$r = sanitize_key( is_array( $r ) ? '' : wp_unslash( $r ) );
				if ( '' !== $r ) {
					$clean['site_roles'][] = $r;
				}
			}
			$clean['site_roles'] = array_values( array_unique( $clean['site_roles'] ) );
		}
		$clean['site_redirect'] = isset( $raw['site_redirect'] ) ? absint( $raw['site_redirect'] ) : 0;
		$clean['site_message']  = isset( $raw['site_message'] ) ? wp_kses_post( wp_unslash( $raw['site_message'] ) ) : '';
		$clean['default_message'] = isset( $raw['default_message'] ) ? wp_kses_post( wp_unslash( $raw['default_message'] ) ) : '';

		$clean['exempt_ids'] = array();
		if ( isset( $raw['exempt_ids'] ) ) {
			$ids = is_array( $raw['exempt_ids'] ) ? $raw['exempt_ids'] : preg_split( '/[\s,]+/', (string) wp_unslash( $raw['exempt_ids'] ) );
			foreach ( (array) $ids as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$clean['exempt_ids'][] = $id;
				}
			}
			$clean['exempt_ids'] = array_values( array_unique( $clean['exempt_ids'] ) );
		}

		update_option( self::OPTION, $clean );

		// Whole-site protection changes which pages a cache may serve.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
