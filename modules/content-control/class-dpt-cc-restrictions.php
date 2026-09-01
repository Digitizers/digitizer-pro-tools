<?php
/**
 * Content Control module - global restriction rows: storage, sanitization
 * and first-match resolution. The rows live in one option and their order
 * IS their priority - the first enabled row whose conditions match wins.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Restrictions {

	const OPTION = 'dpt_cc_restrictions';

	/** @var array<string, array|null> Per-request first-match memo. */
	private static $match_cache = array();

	public static function defaults_row() {
		return array(
			'id'      => '',
			'title'   => '',
			'enabled' => false,
			'who'     => array(
				'status'     => 'logged_in',  // logged_in | logged_out
				'role_match' => 'any',        // any | match | exclude
				'roles'      => array(),
			),
			'protection' => array(
				'method'           => 'redirect', // redirect | replace
				'redirect_type'    => 'login',    // login | home | custom
				'redirect_url'     => '',
				'replacement_page' => 0,
				'override_message' => false,
				'custom_message'   => '',
				'show_excerpts'    => false,
			),
			'archive_handling'      => 'filter',  // filter | hide | replace_page | redirect
			'archive_page'          => 0,
			'archive_redirect_type' => 'login',   // login | home | custom
			'archive_redirect_url'  => '',
			'query_handling'        => 'filter',  // filter | hide
			'show_in_search'        => false,
			'conditions'            => array( 'operator' => 'and', 'items' => array() ),
		);
	}

	private static function pick( $raw, $key, $allowed, $fallback ) {
		return ( isset( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ) ? $raw[ $key ] : $fallback;
	}

	/**
	 * Whatever came in, a complete row of only known keys and values goes
	 * out. Unknown enum values fall to their default rather than erroring:
	 * a half-imported row must degrade to something inert, not fatal.
	 */
	public static function sanitize_row( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$row = self::defaults_row();

		$row['id'] = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		if ( '' === $row['id'] ) {
			$row['id'] = 'r_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		$row['title']   = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		$row['enabled'] = ! empty( $raw['enabled'] );

		$who                      = isset( $raw['who'] ) && is_array( $raw['who'] ) ? $raw['who'] : array();
		$row['who']['status']     = self::pick( $who, 'status', array( 'logged_in', 'logged_out' ), 'logged_in' );
		$row['who']['role_match'] = self::pick( $who, 'role_match', array( 'any', 'match', 'exclude' ), 'any' );
		if ( isset( $who['roles'] ) && is_array( $who['roles'] ) ) {
			$row['who']['roles'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $who['roles'] ) ) ) );
		}

		$p                                     = isset( $raw['protection'] ) && is_array( $raw['protection'] ) ? $raw['protection'] : array();
		$row['protection']['method']           = self::pick( $p, 'method', array( 'redirect', 'replace' ), 'redirect' );
		$row['protection']['redirect_type']    = self::pick( $p, 'redirect_type', array( 'login', 'home', 'custom' ), 'login' );
		$row['protection']['redirect_url']     = isset( $p['redirect_url'] ) ? esc_url_raw( (string) $p['redirect_url'] ) : '';
		$row['protection']['replacement_page'] = isset( $p['replacement_page'] ) ? absint( $p['replacement_page'] ) : 0;
		$row['protection']['override_message'] = ! empty( $p['override_message'] );
		$row['protection']['custom_message']   = isset( $p['custom_message'] ) ? wp_kses_post( (string) $p['custom_message'] ) : '';
		$row['protection']['show_excerpts']    = ! empty( $p['show_excerpts'] );

		$row['archive_handling']      = self::pick( $raw, 'archive_handling', array( 'filter', 'hide', 'replace_page', 'redirect' ), 'filter' );
		$row['archive_page']          = isset( $raw['archive_page'] ) ? absint( $raw['archive_page'] ) : 0;
		$row['archive_redirect_type'] = self::pick( $raw, 'archive_redirect_type', array( 'login', 'home', 'custom' ), 'login' );
		$row['archive_redirect_url']  = isset( $raw['archive_redirect_url'] ) ? esc_url_raw( (string) $raw['archive_redirect_url'] ) : '';
		$row['query_handling']        = self::pick( $raw, 'query_handling', array( 'filter', 'hide' ), 'filter' );
		$row['show_in_search']        = ! empty( $raw['show_in_search'] );

		$row['conditions'] = self::sanitize_conditions( isset( $raw['conditions'] ) ? $raw['conditions'] : array() );
		return $row;
	}

	/**
	 * Conditions are {operator, items[]} where an item is a rule or one
	 * level of group (a group holds only rules). Anything else is dropped.
	 */
	public static function sanitize_conditions( $raw ) {
		$out = array( 'operator' => 'and', 'items' => array() );
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		if ( isset( $raw['operator'] ) && in_array( $raw['operator'], array( 'and', 'or' ), true ) ) {
			$out['operator'] = $raw['operator'];
		}
		$items = isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['type'] ) && 'group' === $item['type'] ) {
				$group = array( 'type' => 'group', 'operator' => 'or', 'items' => array() );
				if ( isset( $item['operator'] ) && in_array( $item['operator'], array( 'and', 'or' ), true ) ) {
					$group['operator'] = $item['operator'];
				}
				$inner = isset( $item['items'] ) && is_array( $item['items'] ) ? $item['items'] : array();
				foreach ( $inner as $rule ) {
					$rule = self::sanitize_rule( $rule );
					if ( $rule ) {
						$group['items'][] = $rule;
					}
				}
				if ( $group['items'] ) {
					$out['items'][] = $group;
				}
				continue;
			}
			$rule = self::sanitize_rule( $item );
			if ( $rule ) {
				$out['items'][] = $rule;
			}
		}
		return $out;
	}

	private static function sanitize_rule( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['name'] ) || ! is_scalar( $raw['name'] ) ) {
			return null;
		}
		$options = array();
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			foreach ( $raw['options'] as $k => $v ) {
				$k = sanitize_key( $k );
				if ( '' !== $k ) {
					$options[ $k ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
				}
			}
		}
		return array(
			'type'    => 'rule',
			'name'    => sanitize_key( $raw['name'] ),
			'not'     => ! empty( $raw['not'] ),
			'options' => $options,
		);
	}

	/** All rows, sanitized, in stored (priority) order. */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::sanitize_row( $row );
		}
		return $out;
	}

	public static function enabled() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $r ) {
					return ! empty( $r['enabled'] );
				}
			)
		);
	}

	public static function get( $id ) {
		foreach ( self::all() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Sanitize and store the full set. Duplicate ids keep their first
	 * occurrence - the higher-priority row - and drop the rest.
	 */
	public static function save_all( $rows ) {
		$clean = array();
		$seen  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = self::sanitize_row( $row );
			if ( isset( $seen[ $row['id'] ] ) ) {
				continue;
			}
			$seen[ $row['id'] ] = true;
			$clean[]            = $row;
		}
		update_option( self::OPTION, $clean );
		self::flush_cache();
		return $clean;
	}

	public static function cache_key( $context ) {
		if ( isset( $context['type'] ) && 'post' === $context['type'] && ! empty( $context['post_id'] ) ) {
			return 'post-' . (int) $context['post_id'];
		}
		if ( isset( $context['type'] ) && 'term' === $context['type'] && ! empty( $context['term_id'] ) ) {
			return 'term-' . (int) $context['term_id'];
		}
		// Cache-key material only, never unserialized.
		return 'ctx-' . md5( serialize( array_diff_key( $context, array( 'post' => 1, 'term' => 1 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * First enabled restriction whose conditions match this context, or
	 * null. A row with no conditions never matches - parity with the
	 * standalone plugin, where "no rules" means "applies to nothing".
	 *
	 * @param array $context See DPT_CC_Rules::check().
	 * @return array|null
	 */
	public static function match( $context ) {
		$key = self::cache_key( $context );
		if ( array_key_exists( $key, self::$match_cache ) ) {
			return self::$match_cache[ $key ];
		}
		$found = null;
		foreach ( self::enabled() as $row ) {
			if ( empty( $row['conditions']['items'] ) ) {
				continue;
			}
			if ( DPT_CC_Rules::check( $row['conditions'], $context ) ) {
				$found = $row;
				break;
			}
		}
		self::$match_cache[ $key ] = $found;
		return $found;
	}

	public static function flush_cache() {
		self::$match_cache = array();
	}
}
