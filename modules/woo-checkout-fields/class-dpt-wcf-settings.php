<?php
/**
 * Checkout Field Editor module - settings storage.
 *
 * A scoped replacement for "WooCommerce Checkout Field Editor": show/hide and
 * mark-required a handful of standard checkout fields (and reorder them), plus
 * add a few custom fields (text / select / checkbox) that are saved to the
 * order and shown in the admin and in order emails. This targets the classic
 * (shortcode) checkout; the block checkout uses a different field API.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_WCF_Settings {

	const OPTION = 'dpt_woo_checkout_fields';

	// Keep the option small and the UI focused.
	const MAX_CUSTOM = 10;

	/**
	 * The standard checkout fields this module manages. Deliberately a short,
	 * safe list - editing name/email/country/etc. risks breaking checkout.
	 *
	 * @return array<string,array> key => [ section, field, default_required ]
	 */
	public static function standard_defs() {
		return array(
			'billing_company'   => array( 'section' => 'billing', 'field' => 'billing_company',   'default_required' => '0' ),
			'billing_address_2' => array( 'section' => 'billing', 'field' => 'billing_address_2', 'default_required' => '0' ),
			'billing_phone'     => array( 'section' => 'billing', 'field' => 'billing_phone',     'default_required' => '1' ),
			'order_comments'    => array( 'section' => 'order',   'field' => 'order_comments',    'default_required' => '0' ),
		);
	}

	public static function allowed_sections() {
		return array( 'billing', 'shipping', 'order' );
	}

	public static function allowed_types() {
		return array( 'text', 'select', 'checkbox' );
	}

	public static function defaults() {
		$standard = array();
		foreach ( self::standard_defs() as $key => $def ) {
			$standard[ $key ] = array(
				'enabled'  => '1',
				'required' => $def['default_required'],
				'priority' => '', // empty = keep WooCommerce's own priority
			);
		}
		return array(
			'standard' => $standard,
			'custom'   => array(),
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		update_option( self::OPTION, self::normalize( $existing ) );
	}

	/**
	 * Merge stored data over defaults and normalise every value.
	 *
	 * @param array $raw Stored (or posted-and-parsed) data.
	 * @return array
	 */
	public static function normalize( $raw ) {
		$defaults = self::defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		// --- Standard fields -------------------------------------------------
		$standard    = array();
		$raw_std     = isset( $raw['standard'] ) && is_array( $raw['standard'] ) ? $raw['standard'] : array();
		foreach ( self::standard_defs() as $key => $def ) {
			$stored              = isset( $raw_std[ $key ] ) && is_array( $raw_std[ $key ] ) ? $raw_std[ $key ] : array();
			$standard[ $key ]    = array(
				'enabled'  => ( isset( $stored['enabled'] ) && '1' === (string) $stored['enabled'] ) ? '1' : '0',
				'required' => ( isset( $stored['required'] ) && '1' === (string) $stored['required'] ) ? '1' : '0',
				'priority' => self::sanitize_priority( isset( $stored['priority'] ) ? $stored['priority'] : '' ),
			);
		}

		// --- Custom fields ---------------------------------------------------
		$custom  = array();
		$seen    = array();
		$raw_cf  = isset( $raw['custom'] ) && is_array( $raw['custom'] ) ? $raw['custom'] : array();
		foreach ( $raw_cf as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$field = self::sanitize_custom_field( $entry, $seen );
			if ( null === $field ) {
				continue;
			}
			$seen[ $field['key'] ] = true;
			$custom[]              = $field;
			if ( count( $custom ) >= self::MAX_CUSTOM ) {
				break;
			}
		}

		return array(
			'standard' => array_merge( $defaults['standard'], $standard ),
			'custom'   => $custom,
		);
	}

	/**
	 * Sanitise one custom-field definition. Returns null when it should be
	 * dropped (e.g. an empty label - the signal for "remove this row").
	 *
	 * @param array $entry Raw row.
	 * @param array $seen  Keys already taken (for de-duplication).
	 * @return array|null
	 */
	public static function sanitize_custom_field( $entry, $seen = array() ) {
		$label = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';
		if ( '' === trim( $label ) ) {
			return null; // No label -> treat the row as empty/removed.
		}

		// Derive a key from the explicit key or, failing that, the label. Keys
		// are namespaced with dpt_ so they never collide with core field names.
		$key = isset( $entry['key'] ) ? sanitize_key( (string) $entry['key'] ) : '';
		if ( '' === $key ) {
			$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
		}
		if ( '' === $key ) {
			return null;
		}
		// Ensure uniqueness against already-parsed rows.
		$base = $key;
		$i    = 2;
		while ( isset( $seen[ $key ] ) ) {
			$key = $base . '_' . $i;
			$i++;
		}

		$type = isset( $entry['type'] ) ? (string) $entry['type'] : 'text';
		if ( ! in_array( $type, self::allowed_types(), true ) ) {
			$type = 'text';
		}

		$section = isset( $entry['section'] ) ? (string) $entry['section'] : 'billing';
		if ( ! in_array( $section, self::allowed_sections(), true ) ) {
			$section = 'billing';
		}

		$required = ( isset( $entry['required'] ) && '1' === (string) $entry['required'] ) ? '1' : '0';

		$options = array();
		if ( 'select' === $type ) {
			$raw_options = isset( $entry['options'] ) ? $entry['options'] : '';
			$options     = is_array( $raw_options ) ? $raw_options : preg_split( '/[\r\n]+/', (string) $raw_options );
			$options     = self::sanitize_options( $options );
			// A select with no options is meaningless - drop it.
			if ( empty( $options ) ) {
				return null;
			}
		}

		return array(
			'key'      => $key,
			'label'    => $label,
			'type'     => $type,
			'section'  => $section,
			'required' => $required,
			'options'  => $options,
			'priority' => self::sanitize_priority( isset( $entry['priority'] ) ? $entry['priority'] : '' ),
		);
	}

	/**
	 * Normalise select options: trim, drop blanks and duplicates.
	 *
	 * @param array $options Raw options.
	 * @return string[]
	 */
	public static function sanitize_options( $options ) {
		$clean = array();
		foreach ( (array) $options as $opt ) {
			if ( is_array( $opt ) ) {
				continue;
			}
			$opt = sanitize_text_field( (string) $opt );
			if ( '' === trim( $opt ) ) {
				continue;
			}
			$clean[ $opt ] = $opt; // key by value to de-duplicate
		}
		return array_values( $clean );
	}

	/**
	 * A non-negative integer priority, or '' when unset/blank.
	 *
	 * @param mixed $value Raw priority.
	 * @return string
	 */
	public static function sanitize_priority( $value ) {
		if ( is_array( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
			return '';
		}
		return (string) (int) $value;
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		return self::normalize( is_array( $opts ) ? $opts : array() );
	}

	public static function standard() {
		$all = self::all();
		return $all['standard'];
	}

	/**
	 * The parsed custom fields, filterable so code can extend them.
	 *
	 * @return array[]
	 */
	public static function custom_fields() {
		$all = self::all();
		$out = apply_filters( 'dpt_wcf_custom_fields', $all['custom'] );
		return is_array( $out ) ? array_values( $out ) : array();
	}

	/**
	 * The order meta key a custom field is stored under. Underscore-prefixed so
	 * it stays out of the generic "Custom Fields" box; we render it ourselves.
	 *
	 * @param string $key Field key (already namespaced with dpt_).
	 * @return string
	 */
	public static function meta_key( $key ) {
		return '_dpt_wcf_' . sanitize_key( $key );
	}

	/**
	 * The checkout input name for a custom field.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function input_name( $key ) {
		return 'dpt_' . sanitize_key( $key );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		update_option( self::OPTION, self::normalize( $raw ) );
		return true;
	}
}
