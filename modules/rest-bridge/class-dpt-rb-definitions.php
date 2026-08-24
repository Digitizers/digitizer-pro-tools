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

			$object = self::object_type( $args );
			if ( 'post' === $object ) {
				$targets = isset( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array();
			} elseif ( 'taxonomy' === $object ) {
				$targets = isset( $args['allowed_tax'] ) ? (array) $args['allowed_tax'] : array();
			} elseif ( null === $object ) {
				self::$skipped[] = sprintf( 'meta box %s: an object type that is not a string', $id );
				continue;
			} else {
				self::$skipped[] = sprintf( 'meta box %1$s: object type %2$s is not exposed', $id, '' === $object ? '(none)' : $object );
				continue;
			}

			// A post type or taxonomy name, not a meta key: register_post_type()
			// sanitize_keys its own name, so this agrees with the registry
			// rather than deriving something new the way the field names
			// below must not. A member of the list that is not a string would
			// be a TypeError inside sanitize_key(), which hands its argument
			// straight to strtolower(), so the list is narrowed to what core
			// can be handed before it is handed any of it. A row left with
			// nothing usable falls into the sentence below.
			$targets = array_values( array_filter( array_map( 'sanitize_key', array_filter( $targets, 'is_scalar' ) ) ) );
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
	 * What kind of object a meta box is attached to, read the way JetEngine
	 * reads it.
	 *
	 * A missing object_type is not an unknown one. JetEngine's own reader -
	 * Jet_Engine_Meta_Boxes_Manager::register_instances() - opens its switch
	 * with `isset( $args['object_type'] ) ? esc_attr( $args['object_type'] )
	 * : 'post'`, so a row saved before that key existed, or written by an
	 * import or a migration rather than by the admin screen, really is
	 * registered on post types and really is read by the site's templates.
	 * Passing the whole meta box over cost it every field it holds, and said
	 * the object type was unknown when JetEngine's answer for it is `post` -
	 * silent absence in exactly the case this module exists for.
	 *
	 * `tax` is JetEngine's older spelling of `taxonomy` and the same switch
	 * still answers to both (`case 'tax': case 'taxonomy':`), so a meta box
	 * saved under it is a taxonomy meta box and is normalized to the one
	 * spelling the rest of this module uses.
	 *
	 * A value that is present but is not a string is the one thing still
	 * refused. JetEngine hands it to esc_attr(), which raises a notice on an
	 * array - and this parse runs on rest_api_init, where a notice corrupts
	 * the JSON of every REST response the site is building. Recorded on the
	 * same footing as every other malformed row.
	 *
	 * @param array $args The meta box's args.
	 * @return string|null 'post', 'taxonomy', another kind JetEngine knows,
	 *                     or null for a value that is not a string at all.
	 */
	private static function object_type( $args ) {
		if ( ! isset( $args['object_type'] ) ) {
			return 'post';
		}
		if ( ! is_scalar( $args['object_type'] ) ) {
			return null;
		}

		$object = (string) $args['object_type'];

		return 'tax' === $object ? 'taxonomy' : $object;
	}

	/**
	 * The per-field settings that change what a field's value *is*, carried
	 * onto the descriptor beside the type.
	 *
	 * A JetEngine type is not always the whole story: the admin writes a
	 * handful of per-field settings into the same option row, and some of
	 * them decide the format of the stored value rather than how it is
	 * edited. A media field's `value_format` is one - 'id', 'url' or 'both'
	 * for the array of an attachment id and its URL - a select field's
	 * `is_multiple` is another, a select with it on storing and submitting an
	 * array where a plain one stores a string, and a checkbox's `is_array` is
	 * the third: with it on JetEngine keeps a plain list of the checked option
	 * keys instead of a map of every option to 'true' or 'false'. Reading only
	 * the type left the schema, the sanitizer and the read path all assuming
	 * the default, which is how a site storing URLs had its own data read back
	 * as 0, a multi-select had its list read back as an empty string, and a
	 * checkbox lost every selection it had to a read, modify and write.
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

		if ( 'checkbox' === $type ) {
			// JetEngine's checkbox has a toggle of its own that changes what
			// is stored, not how it is edited: with is_array on,
			// cherry-x-post-meta's sanitize_meta() keeps a plain list of the
			// checked option keys where a plain checkbox keeps the
			// key => 'true'|'false' map. Read the way JetEngine reads it, for
			// the reason is_multiple is read that way - the admin's switcher
			// writes a real boolean and the same key has held the strings
			// over the plugin's life.
			$raw = isset( $field['is_array'] ) && is_scalar( $field['is_array'] ) ? $field['is_array'] : false;

			$settings['is_array'] = (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		}

		if ( 'select' === $type ) {
			// Read the way JetEngine reads it: the admin's switcher writes a
			// real boolean, but the same key has held the strings 'true' and
			// 'false' over the plugin's life, and JetEngine itself settles
			// all of them with FILTER_VALIDATE_BOOLEAN.
			$raw = isset( $field['is_multiple'] ) && is_scalar( $field['is_multiple'] ) ? $field['is_multiple'] : false;

			$settings['multiple'] = (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
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

		// The shape is checked before the cast, not after: this runs on
		// rest_api_init, over an option that belongs to another vendor's
		// plugin across every version it has ever had, so a row holding an
		// array where a name belongs must cost that one row rather than
		// raise a notice - which would corrupt the JSON of every REST
		// response the site was building - or worse. Recorded the way a
		// field with no name at all already is.
		if ( isset( $field['name'] ) && ! is_scalar( $field['name'] ) ) {
			self::$skipped[] = sprintf( 'meta box %s: a field whose name is not a string', $box );
			return null;
		}

		// Verbatim, deliberately. JetEngine uses the stored name as the meta
		// key with nothing in between - cherry-x-post-meta's save loop is
		// update_post_meta( $post_id, $key, $value ) with $key taken straight
		// off this definition - so a key derived here is a key nothing else on
		// the site uses. sanitize_key(), which is what this did, keeps only
		// [a-z0-9_-]: on a Hebrew site it reduced a field's whole name to ''
		// and then reported it as "a field with no name", which was never
		// true of it; and it turned an accented name into a shorter one that
		// is still a valid key, so reads came back empty, writes created a row
		// no template reads, and the API answered 200 over both. What this
		// module may expose out of what JetEngine defines is a separate
		// question, asked in DPT_RB_Fields where the object type is in hand.
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
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
				// The same malformed shape one level down, and the same answer.
				if ( isset( $sub['name'] ) && ! is_scalar( $sub['name'] ) ) {
					self::$skipped[] = sprintf( 'field %s: a sub-field whose name is not a string', $name );
					continue;
				}
				// Verbatim for the reason the field's own name is, and with
				// even less room for doubt: JetEngine's server side never
				// touches a repeater column's name at all - the only
				// normalisation there has ever been is in the admin's
				// JavaScript, which lowercases, collapses whitespace and
				// transliterates Cyrillic and nothing else. A column named in
				// Hebrew is stored under that name and read out of the item
				// array under that name, so a column this module renamed was
				// a column it advertised, sanitized and read at an address
				// nobody else uses.
				$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
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
