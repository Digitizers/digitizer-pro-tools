<?php
/**
 * Enlighter module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_EN_Settings {

	const OPTION = 'dpt_enlighter';

	public static function defaults() {
		return array(
			// light | dark | auto (follows the visitor's colour scheme)
			'theme'          => 'auto',
			'line_numbers'   => '1',
			'copy_button'    => '1',
			// Highlight every <pre><code> block automatically.
			'auto_highlight' => '0',
			'default_lang'   => 'php',
		);
	}

	/**
	 * Languages the highlighter understands (key => label).
	 */
	public static function languages() {
		$langs = array(
			'php'        => 'PHP',
			'javascript' => 'JavaScript',
			'css'        => 'CSS',
			'html'       => 'HTML / XML',
			'sql'        => 'SQL',
			'bash'       => 'Shell / Bash',
			'python'     => 'Python',
			'json'       => 'JSON',
			'plain'      => 'Plain text',
		);
		return apply_filters( 'dpt_en_languages', $langs );
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

		if ( ! in_array( $all['theme'], array( 'light', 'dark', 'auto' ), true ) ) {
			$all['theme'] = 'auto';
		}
		$all['line_numbers']   = '1' === (string) $all['line_numbers'] ? '1' : '0';
		$all['copy_button']    = '1' === (string) $all['copy_button'] ? '1' : '0';
		$all['auto_highlight'] = '1' === (string) $all['auto_highlight'] ? '1' : '0';
		$all['default_lang']   = self::sanitize_lang( $all['default_lang'] );
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Normalise a language key to one we support (aliases included).
	 */
	public static function sanitize_lang( $lang ) {
		$lang = strtolower( is_string( $lang ) ? trim( $lang ) : '' );
		$lang = preg_replace( '/[^a-z0-9#+]/', '', $lang );
		$aliases = array(
			'js'         => 'javascript',
			'shell'      => 'bash',
			'sh'         => 'bash',
			'py'         => 'python',
			'xml'        => 'html',
			'htm'        => 'html',
			'text'       => 'plain',
			'txt'        => 'plain',
			'mysql'      => 'sql',
		);
		if ( isset( $aliases[ $lang ] ) ) {
			$lang = $aliases[ $lang ];
		}
		return array_key_exists( $lang, self::languages() ) ? $lang : 'plain';
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$before = self::all();
		$clean  = $before;

		if ( isset( $raw['theme'] ) && in_array( $raw['theme'], array( 'light', 'dark', 'auto' ), true ) ) {
			$clean['theme'] = $raw['theme'];
		}
		$clean['line_numbers']   = ( isset( $raw['line_numbers'] ) && '1' === (string) $raw['line_numbers'] ) ? '1' : '0';
		$clean['copy_button']    = ( isset( $raw['copy_button'] ) && '1' === (string) $raw['copy_button'] ) ? '1' : '0';
		$clean['auto_highlight'] = ( isset( $raw['auto_highlight'] ) && '1' === (string) $raw['auto_highlight'] ) ? '1' : '0';
		if ( isset( $raw['default_lang'] ) ) {
			$clean['default_lang'] = self::sanitize_lang( is_array( $raw['default_lang'] ) ? '' : wp_unslash( $raw['default_lang'] ) );
		}

		update_option( self::OPTION, $clean );

		// Highlighted markup is baked into cached pages.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
