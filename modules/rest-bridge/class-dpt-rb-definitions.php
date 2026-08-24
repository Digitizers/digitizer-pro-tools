<?php
/**
 * REST Bridge - what JetEngine has been told to store.
 *
 * JetEngine's admin writes its meta box definitions to a single option. That
 * option is the source of truth here: read it, and the fields a site actually
 * has are known without a line of per-site code. Fields registered in PHP by
 * another plugin are not in it and are not seen - a deliberate trade for not
 * depending on JetEngine's undocumented internals.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reads and normalizes the JetEngine meta box definitions.
 */
class DPT_RB_Definitions {

	const OPTION = 'jet_engine_meta_boxes';

	/**
	 * Field descriptors, or null before the option has been read.
	 *
	 * @var array|null
	 */
	private static $defs = null;

	/**
	 * Reasons rows were passed over, for the info endpoint.
	 *
	 * @var array
	 */
	private static $skipped = array();

	/**
	 * Types this bridge knows how to expose. Anything else is skipped and
	 * said out loud rather than guessed at.
	 *
	 * @var array
	 */
	private static $known = array(
		'text',
		'textarea',
		'wysiwyg',
		'number',
		'switcher',
		'checkbox',
		'select',
		'radio',
		'date',
		'time',
		'datetime-local',
		'media',
		'repeater',
	);

	/**
	 * Forget what was read. Only tests need this; a request reads once.
	 */
	public static function reset() {
		self::$defs    = null;
		self::$skipped = array();
	}

	/**
	 * Every field this site has defined, flattened.
	 *
	 * @return array List of descriptors.
	 */
	public static function all() {
		if ( null === self::$defs ) {
			self::read();
		}
		return self::$defs;
	}

	/**
	 * Why anything was left out.
	 *
	 * @return array List of sentences.
	 */
	public static function skipped() {
		if ( null === self::$defs ) {
			self::read();
		}
		return self::$skipped;
	}

	/**
	 * Whether a type is one this bridge can expose.
	 *
	 * @param string $type JetEngine field type.
	 * @return bool
	 */
	public static function known_type( $type ) {
		return in_array( $type, self::$known, true );
	}

	/**
	 * Parse the option into descriptors.
	 */
	private static function read() {
		self::$defs    = array();
		self::$skipped = array();

		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : '(unnamed meta box)';
			$args   = isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array();
			$fields = isset( $row['meta_fields'] ) && is_array( $row['meta_fields'] ) ? $row['meta_fields'] : array();

			if ( ! $fields ) {
				self::$skipped[] = sprintf( 'meta box %s: no fields', $id );
				continue;
			}

			$object = isset( $args['object_type'] ) && is_scalar( $args['object_type'] ) ? (string) $args['object_type'] : '';
			if ( 'post' === $object ) {
				$targets = isset( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array();
			} elseif ( 'taxonomy' === $object ) {
				$targets = isset( $args['allowed_tax'] ) ? (array) $args['allowed_tax'] : array();
			} else {
				self::$skipped[] = sprintf( 'meta box %1$s: object type %2$s is not exposed', $id, '' === $object ? '(none)' : $object );
				continue;
			}

			$targets = array_values( array_filter( array_map( 'sanitize_key', $targets ) ) );
			if ( ! $targets ) {
				self::$skipped[] = sprintf( 'meta box %s: attached to nothing', $id );
				continue;
			}

			foreach ( $fields as $field ) {
				$descriptor = self::descriptor( $field, $id );
				if ( null === $descriptor ) {
					continue;
				}
				$descriptor['object']  = $object;
				$descriptor['targets'] = $targets;
				self::$defs[]          = $descriptor;
			}
		}
	}

	/**
	 * The per-field settings that change what a field's value *is*, carried
	 * onto the descriptor beside the type.
	 *
	 * A JetEngine type is not always the whole story: the admin writes a
	 * handful of per-field settings into the same option row, and some of
	 * them decide the format of the stored value rather than how it is
	 * edited. A media field's `value_format` is one - 'id', 'url' or 'both'
	 * for the array of an attachment id and its URL. Reading only the type
	 * left the schema, the sanitizer and the read path all assuming the
	 * default, which is how a site storing URLs had its own data read back
	 * as 0.
	 *
	 * Only settings this bridge acts on are carried, and only in the
	 * spellings JetEngine's own admin writes - an unrecognized value falls
	 * back to the format JetEngine itself defaults to rather than being
	 * passed through to code that would then have to guess at it.
	 *
	 * @param array  $field The raw field row.
	 * @param string $type  Its JetEngine type.
	 * @return array
	 */
	private static function settings( $field, $type ) {
		$settings = array();

		if ( 'media' === $type ) {
			$format = isset( $field['value_format'] ) && is_scalar( $field['value_format'] ) ? (string) $field['value_format'] : '';
			// JetEngine's media control defaults to 'id' and the admin only
			// writes the key once a format has been chosen, so a field that
			// predates the setting holds an attachment id.
			$settings['value_format'] = in_array( $format, array( 'id', 'url', 'both' ), true ) ? $format : 'id';
		}

		return $settings;
	}

	/**
	 * One field row to a descriptor, or null when it is not one.
	 *
	 * @param mixed  $field The raw row.
	 * @param string $box   Meta box id, for the skip message.
	 * @return array|null
	 */
	private static function descriptor( $field, $box ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		// Tabs, accordions and the like share the list with real fields and
		// carry no value of their own.
		$kind = isset( $field['object_type'] ) && is_scalar( $field['object_type'] ) ? (string) $field['object_type'] : 'field';
		if ( 'field' !== $kind ) {
			return null;
		}

		$name = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
		if ( '' === $name ) {
			self::$skipped[] = sprintf( 'meta box %s: a field with no name', $box );
			return null;
		}

		$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? (string) $field['type'] : '';
		if ( ! self::known_type( $type ) ) {
			self::$skipped[] = sprintf( 'field %1$s: type %2$s is not exposed', $name, '' === $type ? '(none)' : $type );
			return null;
		}

		$descriptor = array_merge(
			array(
				'meta_key' => $name,
				'title'    => isset( $field['title'] ) && is_scalar( $field['title'] ) ? (string) $field['title'] : $name,
				'type'     => $type,
				'fields'   => array(),
			),
			self::settings( $field, $type )
		);

		if ( 'repeater' === $type ) {
			$subs = array();
			if ( isset( $field['repeater-fields'] ) ) {
				if ( is_array( $field['repeater-fields'] ) ) {
					$subs = $field['repeater-fields'];
				} else {
					// Present but not a list: a real repeater, just one this
					// bridge cannot see the inside of - not the same as a
					// repeater that legitimately has no sub-fields.
					self::$skipped[] = sprintf( 'field %s: repeater sub-field list is not a list', $name );
				}
			}
			foreach ( $subs as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sub_name = isset( $sub['name'] ) ? sanitize_key( $sub['name'] ) : '';
				$sub_type = isset( $sub['type'] ) && is_scalar( $sub['type'] ) ? (string) $sub['type'] : '';
				// A repeater inside a repeater is more than this bridge
				// promises, and an unknown type is a guess it will not make.
				if ( '' === $sub_name || 'repeater' === $sub_type || ! self::known_type( $sub_type ) ) {
					self::$skipped[] = sprintf( 'field %1$s: sub-field %2$s is not exposed', $name, '' === $sub_name ? '(unnamed)' : $sub_name );
					continue;
				}
				// A sub-field carries the same settings as a field of its own
				// type: JetEngine's admin offers the value format on a media
				// column inside a repeater exactly as it does at the top
				// level, and a sub-field whose settings were left behind
				// would be shaped by its base type alone.
				$descriptor['fields'][] = array_merge(
					array(
						'meta_key' => $sub_name,
						'title'    => isset( $sub['title'] ) && is_scalar( $sub['title'] ) ? (string) $sub['title'] : $sub_name,
						'type'     => $sub_type,
						'fields'   => array(),
					),
					self::settings( $sub, $sub_type )
				);
			}

			if ( ! $descriptor['fields'] ) {
				// A repeater whose every column this bridge had to pass over
				// has no shape left to offer: the API would advertise a list
				// of empty objects and a write would have nothing to check.
				// Saying so is better than exposing a field that cannot mean
				// anything.
				self::$skipped[] = sprintf( 'field %s: a repeater with no sub-field this bridge can expose', $name );
				return null;
			}
		}

		return $descriptor;
	}
}
