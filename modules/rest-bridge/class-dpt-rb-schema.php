<?php
/**
 * REST Bridge - what a field's type means to the REST API.
 *
 * One place decides three things per JetEngine type: the schema the API
 * advertises, how a written value is cleaned, and how a stored value is
 * presented. Keeping them together is what stops a field being advertised as
 * one thing and sanitized as another.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Maps JetEngine field types onto REST schemas and sanitizers.
 */
class DPT_RB_Schema {

	/**
	 * The schema for one descriptor.
	 *
	 * @param array $descriptor Field descriptor from DPT_RB_Definitions.
	 * @return array
	 */
	public static function for_descriptor( $descriptor ) {
		$schema = array(
			'description' => $descriptor['title'],
			'context'     => array( 'view', 'edit' ),
		);

		if ( 'repeater' === $descriptor['type'] ) {
			$properties = array();
			foreach ( $descriptor['fields'] as $sub ) {
				$properties[ $sub['meta_key'] ] = array(
					'description' => $sub['title'],
					'type'        => self::json_type( $sub['type'] ),
				);
			}
			$schema['type']  = 'array';
			$schema['items'] = array(
				'type'       => 'object',
				'properties' => $properties,
			);
			return $schema;
		}

		$schema['type'] = self::json_type( $descriptor['type'] );
		return $schema;
	}

	/**
	 * The JSON Schema type one JetEngine type presents as.
	 *
	 * @param string $type JetEngine type.
	 * @return string
	 */
	private static function json_type( $type ) {
		switch ( $type ) {
			case 'number':
				return 'number';
			case 'media':
				return 'integer';
			case 'checkbox':
				return 'object';
			case 'repeater':
				return 'array';
			default:
				// text, textarea, wysiwyg, select, radio, switcher, dates.
				// A switcher is stored as the string 'true' or 'false' by
				// JetEngine, so it is advertised as what it is.
				return 'string';
		}
	}

	/**
	 * Clean a written value, or refuse it.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Whatever the request sent.
	 * @return mixed|WP_Error
	 */
	public static function sanitize( $descriptor, $value ) {
		if ( 'repeater' !== $descriptor['type'] ) {
			return self::sanitize_scalar( $descriptor['type'], $value );
		}

		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'dpt_rb_invalid_repeater',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s must be an array of items.', 'digitizer-pro-tools' ),
					$descriptor['meta_key']
				),
				array( 'status' => 400 )
			);
		}

		$known = array();
		foreach ( $descriptor['fields'] as $sub ) {
			$known[ $sub['meta_key'] ] = $sub['type'];
		}

		$clean = array();
		foreach ( $value as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error(
					'dpt_rb_invalid_repeater_item',
					sprintf(
						/* translators: 1: field name, 2: item number */
						__( 'Item %2$d of the field %1$s must be an object.', 'digitizer-pro-tools' ),
						$descriptor['meta_key'],
						(int) $index
					),
					array( 'status' => 400 )
				);
			}
			$row = array();
			foreach ( $known as $key => $type ) {
				if ( array_key_exists( $key, $item ) ) {
					$row[ $key ] = self::sanitize_scalar( $type, $item[ $key ] );
				}
			}
			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Clean one non-repeater value.
	 *
	 * @param string $type  JetEngine type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_scalar( $type, $value ) {
		switch ( $type ) {
			case 'wysiwyg':
				return wp_kses_post( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'number':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'media':
				return absint( $value );
			case 'switcher':
				// JetEngine stores a switcher as a string, and a REST client
				// may send a real boolean; both end up saying the same thing.
				return ( $value && 'false' !== $value && '0' !== $value ) ? 'true' : 'false';
			case 'checkbox':
				$out = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $on ) {
						$key = sanitize_key( $key );
						if ( '' !== $key ) {
							$out[ $key ] = ( $on && 'false' !== $on && '0' !== $on ) ? 'true' : 'false';
						}
					}
				}
				return $out;
			default:
				// text, select, radio, date, time, datetime-local: none of
				// these carry markup, so the plain-text sanitizer is right
				// for all of them.
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Present a stored value the way the API promises it.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $stored     Whatever the database held.
	 * @return mixed
	 */
	public static function normalize_read( $descriptor, $stored ) {
		if ( 'repeater' !== $descriptor['type'] ) {
			if ( 'checkbox' === $descriptor['type'] ) {
				return is_array( $stored ) ? $stored : array();
			}
			return ( null === $stored || is_array( $stored ) ) ? '' : (string) $stored;
		}

		if ( is_array( $stored ) ) {
			return array_values( $stored );
		}
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );
		if ( is_array( $decoded ) ) {
			return array_values( $decoded );
		}

		// JetEngine has stored repeaters as PHP serialization. Unserializing
		// without allowing classes keeps a crafted string from building one.
		$unserialized = @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $unserialized ) ? array_values( $unserialized ) : array();
	}
}
