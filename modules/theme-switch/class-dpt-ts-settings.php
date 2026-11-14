<?php
/** Theme Switch settings. Only explicit variable names and hex colors reach CSS. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_TS_Settings {

	const OPTION = 'dpt_theme_switch';

	public static function defaults() {
		return array( 'base' => 'light', 'default' => 'system', 'colors' => array() );
	}

	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function clean( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();
		if ( isset( $raw['base'] ) && in_array( $raw['base'], array( 'light', 'dark' ), true ) ) {
			$out['base'] = $raw['base'];
		}
		if ( isset( $raw['default'] ) && in_array( $raw['default'], array( 'light', 'dark', 'system' ), true ) ) {
			$out['default'] = $raw['default'];
		}
		if ( isset( $raw['colors'] ) && is_array( $raw['colors'] ) ) {
			foreach ( $raw['colors'] as $name => $color ) {
				if ( ! is_string( $name ) || ! is_string( $color ) ) { continue; }
				$name  = trim( $name );
				$color = trim( $color );
				if ( preg_match( '/^--[a-zA-Z_][a-zA-Z0-9_-]{0,79}$/D', $name ) &&
					0 !== strpos( $name, '--dpt-ts-' ) &&
					preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $color ) ) {
					$out['colors'][ $name ] = strtolower( $color );
				}
				if ( count( $out['colors'] ) >= 100 ) { break; }
			}
		}
		ksort( $out['colors'] );
		return $out;
	}

	public static function all() {
		return self::clean( get_option( self::OPTION, array() ) );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) { return false; }
		$before = self::all();
		$clean  = self::clean( $raw );
		update_option( self::OPTION, $clean );
		if ( $before !== $clean && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}

	/** Read the Kit API; never write Elementor's page or Kit data. */
	public static function elementor_colors() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) { return array(); }
		$plugin = \Elementor\Plugin::instance();
		if ( ! isset( $plugin->kits_manager ) || ! is_callable( array( $plugin->kits_manager, 'get_active_kit' ) ) ) {
			return array();
		}
		$kit = $plugin->kits_manager->get_active_kit();
		if ( ! is_object( $kit ) || ! is_callable( array( $kit, 'get_settings' ) ) ) { return array(); }
		$colors = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $key ) {
			$rows = $kit->get_settings( $key );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['_id'], $row['title'], $row['color'] ) ||
					! is_string( $row['_id'] ) || ! is_string( $row['title'] ) || ! is_string( $row['color'] ) ||
					! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $row['_id'] ) ) { continue; }
				$colors[ '--e-global-color-' . $row['_id'] ] = array( 'label' => $row['title'], 'color' => $row['color'] );
			}
		}
		return $colors;
	}
}
