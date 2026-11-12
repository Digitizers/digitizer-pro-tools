<?php
/**
 * UTM Passthrough module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UTM_Settings {

	const OPTION = 'dpt_utm_passthrough';

	public static function defaults() {
		return array(
			'hubspot_ads'  => '0',
			// Sister domains whose links get the parameters too, stored as bare
			// hosts (no scheme, path, port or www). Empty: own links only.
			'linked_hosts' => array(),
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
		$all['linked_hosts'] = is_array( $all['linked_hosts'] ) ? array_values( $all['linked_hosts'] ) : array();
		return $all;
	}

	/**
	 * One host per line, as typed - a pasted URL, a www., a port - reduced to
	 * bare lowercase hosts. Anything that is not a host is dropped.
	 *
	 * @param string|array $raw Textarea value or list.
	 * @return string[]
	 */
	public static function clean_hosts( $raw ) {
		$lines = is_array( $raw ) ? $raw : preg_split( '/[\r\n,]+/', (string) $raw );
		$hosts = array();
		foreach ( $lines as $line ) {
			$line = strtolower( trim( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( false === strpos( $line, '://' ) ) {
				$line = 'https://' . $line;
			}
			$host = (string) wp_parse_url( $line, PHP_URL_HOST );
			$host = (string) preg_replace( '/^www\./', '', $host );
			if ( 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host ) && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		return array_slice( $hosts, 0, 20 );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$before = self::all();
		$clean  = $before;
		if ( isset( $raw['hubspot_ads'] ) ) {
			$clean['hubspot_ads'] = '1' === $raw['hubspot_ads'] ? '1' : '0';
		}
		if ( isset( $raw['linked_hosts'] ) ) {
			$clean['linked_hosts'] = self::clean_hosts( $raw['linked_hosts'] );
		}
		update_option( self::OPTION, $clean );

		// The parameter list is printed into each page, so cached pages would
		// keep the old one. (PR #78 Codex P2)
		if ( $clean !== $before && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
