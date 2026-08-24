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
	 * The object/target/meta-key triples the replaced plugin already
	 * published to anonymous readers, and which are public display content
	 * by nature: a reading time on posts, an author's bio and links on the
	 * authors taxonomy, a published FAQ on posts.
	 *
	 * Matched by meta key rather than by which descriptor produced them,
	 * because it is the meta key that was public before. A site whose own
	 * JetEngine definition owns one of these names takes the legacy field's
	 * place in the API, and it would be a regression for everything already
	 * reading it if that swap made it private.
	 *
	 * The target is part of the match, not just the object kind. The old
	 * plugin published reading_time on `post` and the author fields on
	 * `authors`, and nowhere else; a site that happens to define its own
	 * reading_time on a private custom post type, or author_description on
	 * an unrelated taxonomy, never had that field on an anonymous GET and
	 * must not gain one from a name collision.
	 *
	 * @var array
	 */
	private static $public_legacy = array(
		'post/post/reading_time',
		'post/post/qna',
		'taxonomy/authors/author_description',
		'taxonomy/authors/author_image',
		'taxonomy/authors/linkedin',
	);

	/**
	 * The schema for one descriptor, as it lands on one target.
	 *
	 * The target is asked for because the read context depends on it: the
	 * same meta key is public on the one target the replaced plugin
	 * published it on and private everywhere else. A caller with no target
	 * in hand gets the private answer, which is the safe one.
	 *
	 * @param array  $descriptor Field descriptor from DPT_RB_Definitions.
	 * @param string $target     Post type or taxonomy it is being registered on.
	 * @return array
	 */
	public static function for_descriptor( $descriptor, $target = '' ) {
		$schema = array(
			'description' => $descriptor['title'],
			'context'     => self::context( $descriptor, $target ),
		);

		if ( 'repeater' === $descriptor['type'] ) {
			$properties = array();
			foreach ( $descriptor['fields'] as $sub ) {
				// The sub-field's whole descriptor, not its type alone: a
				// media column inside a repeater carries the same value
				// format setting as one outside it, and the sub-schema has
				// to say the same thing about it that the top level would.
				$properties[ $sub['meta_key'] ] = array_merge(
					array( 'description' => $sub['title'] ),
					self::type_schema( $sub )
				);
			}
			$schema['type']  = 'array';
			$schema['items'] = array(
				'type'       => 'object',
				'properties' => $properties,
			);
			return $schema;
		}

		return array_merge( $schema, self::type_schema( $descriptor ) );
	}

	/**
	 * Which REST contexts a field may be read in.
	 *
	 * Discovery finds every JetEngine field on the site, and this module
	 * cannot know which of them a site meant for the public - internal
	 * notes, pricing, a client's details all look the same from here. So a
	 * discovered field is readable with context=edit, which any
	 * authenticated consumer asks for, and is not handed to anonymous
	 * callers of /wp/v2/posts. The legacy names above are the exception:
	 * they were already public before this module existed - and only on the
	 * targets they were public on, which is why the target is part of the
	 * question rather than the object kind on its own.
	 *
	 * @param array  $descriptor Field descriptor.
	 * @param string $target     Post type or taxonomy it is being registered on.
	 * @return array
	 */
	private static function context( $descriptor, $target ) {
		$object = isset( $descriptor['object'] ) ? $descriptor['object'] : '';
		$triple = $object . '/' . $target . '/' . $descriptor['meta_key'];

		return in_array( $triple, self::$public_legacy, true )
			? array( 'view', 'edit' )
			: array( 'edit' );
	}

	/**
	 * The schema fragment one field presents as: its type, and whatever else
	 * that type needs said about it.
	 *
	 * A fragment rather than a bare type name because a field's honest type
	 * is not always one word. The whole descriptor rather than its type
	 * alone for the same reason: JetEngine settles some formats with a
	 * per-field setting sitting next to the type, and a schema written from
	 * the type on its own describes a site that happens to use the default.
	 *
	 * @param array $descriptor Field descriptor.
	 * @return array
	 */
	private static function type_schema( $descriptor ) {
		$type = isset( $descriptor['type'] ) ? $descriptor['type'] : '';

		switch ( $type ) {
			case 'number':
				return array( 'type' => 'number' );
			case 'media':
				// JetEngine stores a media field as one of three things,
				// chosen per field: an attachment id, the attachment's URL,
				// or an array carrying both. All three are named, whatever
				// value_format says on this particular field, because the
				// setting is JetEngine's and this module only reads it: an
				// older field predates it, an editor can change it between
				// two requests, and a future version can spell it
				// differently. Advertising the id alone made a URL site's own
				// data a 400 on write and a 0 on read - a schema that refuses
				// what a site really holds is the worse of the two mistakes,
				// so the type is the union of the three and sanitize() and
				// normalize_read() answer the shape in hand rather than the
				// shape the setting promised.
				//
				// The order is the order core resolves them in:
				// rest_get_best_type_for_value() tries integer, then object,
				// and falls back to string, so an id stays an integer, an
				// array stays an array and a URL stays the string it is.
				return array( 'type' => array( 'integer', 'string', 'object' ) );
			case 'checkbox':
				return array( 'type' => 'object' );
			case 'repeater':
				return array( 'type' => 'array' );
			case 'switcher':
				// A switch is a yes or a no, so it is advertised as the boolean
				// it means rather than the string JetEngine happens to store it
				// as. That storage detail stays behind sanitize(), which writes
				// 'true'/'false' for JetEngine's own admin to keep reading, and
				// behind normalize_read(), which hands a real boolean back out.
				//
				// It was advertised as a string until this, which read honestly
				// but made the API refuse the natural payload: core validates a
				// registered field against the advertised type *before* the
				// field's own sanitizer runs, so { "featured": true } was a 400
				// and the sanitizer's boolean branch could never be reached.
				// Boolean is the type that takes both - core's own
				// rest_is_boolean() accepts true, false, 'true', 'false', '1',
				// '0', 1 and 0 - and it is the type the read really has.
				return array( 'type' => 'boolean' );
			default:
				// text, textarea, wysiwyg, select, radio, dates, url.
				return array( 'type' => 'string' );
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
			return self::sanitize_scalar( $descriptor, $value );
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

		// Keyed by name and holding the whole sub-descriptor: a sub-field's
		// type is not the only thing that decides how its value is shaped,
		// and handing the shaping code less than the descriptor is how the
		// settings beside the type got lost in the first place.
		$known = array();
		foreach ( $descriptor['fields'] as $sub ) {
			$known[ $sub['meta_key'] ] = $sub;
		}

		$clean    = array();
		$position = 0;
		foreach ( $value as $item ) {
			// Counted rather than taken from the array key: a client may send
			// the list as a JSON object, whose keys are strings, and an item
			// number a person can find in their payload is the point.
			$position++;
			if ( ! is_array( $item ) ) {
				return new WP_Error(
					'dpt_rb_invalid_repeater_item',
					sprintf(
						/* translators: 1: field name, 2: item number */
						__( 'Item %2$d of the field %1$s must be an object.', 'digitizer-pro-tools' ),
						$descriptor['meta_key'],
						$position
					),
					array( 'status' => 400 )
				);
			}
			// The item is kept whole and its known keys are then cleaned in
			// place. A sub-field this bridge could not map - an icon picker,
			// a gallery, a nested repeater - is still real data JetEngine and
			// the site's templates use, and read() hands it out; filtering it
			// away here would delete a column of every row on the documented
			// GET, modify, PUT round trip.
			$row = $item;
			foreach ( $known as $key => $sub ) {
				if ( array_key_exists( $key, $item ) ) {
					$row[ $key ] = self::sanitize_scalar( $sub, $item[ $key ] );
				}
			}
			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Clean one non-repeater value.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Raw value.
	 * @return mixed
	 */
	private static function sanitize_scalar( $descriptor, $value ) {
		switch ( $descriptor['type'] ) {
			case 'wysiwyg':
				// wp_kses_post() does not guard its input the way the other
				// sanitizers here do; unlike sanitize_text_field(), an array
				// or object reaches string-only internals and fatals instead
				// of degrading, so the guard has to happen here.
				return is_scalar( $value ) ? wp_kses_post( $value ) : '';
			case 'url':
				// JetEngine has no url type, so discovery never produces one;
				// this is here for the legacy descriptors, whose two URL
				// fields a theme prints straight into an href or a src. The
				// plain-text sanitizer would let javascript: and data: through
				// untouched, and the plugin this module replaces did use
				// esc_url_raw() on exactly these two.
				return is_scalar( $value ) ? esc_url_raw( $value ) : '';
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'number':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'media':
				return self::sanitize_media( $value );
			case 'switcher':
				// The field is advertised as a boolean and core hands one over
				// after validating against that, but the string forms JetEngine
				// stores still arrive here from a caller composing the two in
				// process, so both are answered. What is written is always the
				// string JetEngine's own admin reads back.
				return self::truthy( $value ) ? 'true' : 'false';
			case 'checkbox':
				// normalize_read() hands a checkbox back as an object, so this
				// has to accept one too - otherwise composing the two in
				// process, with no JSON round trip in between to flatten it
				// back to an array, silently wipes the field.
				if ( is_object( $value ) ) {
					$value = get_object_vars( $value );
				}
				$out = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $on ) {
						$key = sanitize_key( $key );
						if ( '' !== $key ) {
							$out[ $key ] = self::truthy( $on ) ? 'true' : 'false';
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
	 * Clean a media value by the shape it arrived in.
	 *
	 * A media field holds an attachment id, an attachment URL or the array
	 * of both, and which one is a per-field JetEngine setting this module
	 * only reads. So the value in hand decides how it is cleaned, not the
	 * setting: absint() was applied to every media write, and on a site
	 * storing URLs that turned the URL a client had just read back out into
	 * the number 0 - a field this bridge could not shape being destroyed
	 * rather than left alone.
	 *
	 * An array is cleaned member by member, each by its own shape, so the
	 * id half of an id-and-URL pair stays an id and the URL half is still
	 * kept out of javascript: and data:. A member that is neither - a
	 * nested array some future format leaves there - is kept exactly as it
	 * arrived, for the reason a repeater keeps its unknown columns.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_media( $value ) {
		if ( is_object( $value ) ) {
			// normalize_read() hands the array format back as an object, so
			// composing the two in process - with no JSON round trip in
			// between to flatten it - has to mean the same thing here.
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $member ) {
				$out[ $key ] = is_scalar( $member ) ? self::sanitize_media_scalar( $member ) : $member;
			}
			return $out;
		}

		return self::sanitize_media_scalar( $value );
	}

	/**
	 * Clean one media value that is not a container: an id or a URL.
	 *
	 * @param mixed $value Raw value.
	 * @return int|string
	 */
	private static function sanitize_media_scalar( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			// A boolean is neither an id nor a URL, and esc_url_raw() would
			// turn one into the address http://1 rather than saying so.
			return '';
		}
		if ( self::is_attachment_id( $value ) ) {
			return absint( $value );
		}
		// Anything else is treated as the URL it looks like. esc_url_raw()
		// rather than the plain-text sanitizer because a media URL is
		// printed straight into a src by every template that reads one.
		return esc_url_raw( $value );
	}

	/**
	 * Whether a media value is an attachment id rather than a URL.
	 *
	 * Digits are read as an id even when they arrive as a string, because
	 * that is what storage hands back: meta keeps a scalar in a text column,
	 * so the id 12 comes out of the database as "12" and a client that read
	 * it as JSON may well send it back either way. Reading those as a URL
	 * instead would let esc_url_raw() turn the id 12 into http://12.
	 *
	 * @param mixed $value Scalar value.
	 * @return bool
	 */
	private static function is_attachment_id( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return true;
		}
		return is_string( $value ) && '' !== $value && (string) (int) $value === $value;
	}

	/**
	 * Whether one switch-shaped value means on.
	 *
	 * A switch reaches this from three directions - a JSON boolean off the
	 * wire, the 'true'/'false' strings JetEngine's admin writes, and the
	 * '1'/'0' an older definition or a hand-edited row can leave behind - and
	 * the write side and the read side have to agree about every one of them
	 * or the field says one thing on the way in and another on the way out.
	 * One function is how they are kept from drifting.
	 *
	 * A value that is not a scalar at all - an array left by corrupt storage -
	 * is off: PHP would call a non-empty array true, which would turn junk
	 * into an affirmative answer.
	 *
	 * @param mixed $value Boolean, string or number.
	 * @return bool
	 */
	private static function truthy( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return (bool) $value && 'false' !== $value && '0' !== $value;
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
			return self::normalize_scalar_read( $descriptor, $stored );
		}

		if ( is_array( $stored ) ) {
			return self::normalize_items( $descriptor, array_values( $stored ) );
		}
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$decoded = json_decode( $stored, true );
		if ( is_array( $decoded ) ) {
			return self::normalize_items( $descriptor, array_values( $decoded ) );
		}

		// JetEngine has stored repeaters as PHP serialization. Unserializing
		// without allowing classes keeps a crafted string from building one.
		$unserialized = @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $unserialized ) ? self::normalize_items( $descriptor, array_values( $unserialized ) ) : array();
	}

	/**
	 * Present a repeater's items the way their own sub-schemas promise.
	 *
	 * A repeater advertises a type per sub-field, and until this ran the
	 * items went out exactly as storage held them - so a sub-field
	 * advertised as a number read back as the string it was stored as, the
	 * same advertised-versus-produced gap normalize_scalar_read() exists to
	 * close one level up.
	 *
	 * @param array $descriptor Repeater descriptor.
	 * @param array $items      Items as storage held them.
	 * @return array
	 */
	private static function normalize_items( $descriptor, $items ) {
		$known = array();
		foreach ( $descriptor['fields'] as $sub ) {
			$known[ $sub['meta_key'] ] = $sub;
		}

		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				// Corrupt storage can hold anything here. There is no sub-field
				// to shape it by, so it is handed back as found.
				$out[] = $item;
				continue;
			}
			foreach ( $known as $key => $sub ) {
				if ( array_key_exists( $key, $item ) ) {
					$item[ $key ] = self::normalize_scalar_read( $sub, $item[ $key ] );
				}
			}
			// Keys with no sub-field behind them are left exactly as they are,
			// for the reason sanitize() keeps them: this bridge does not
			// understand them, which is not a licence to reshape them.
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Present one non-repeater stored value the way its own schema promises.
	 *
	 * Mirrors type_schema() type for type, on purpose: type_schema() and this
	 * are the two places that get to say what a field's stored value looks
	 * like from outside, and letting either one drift from the other is the
	 * exact failure this class exists to prevent.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $stored     Whatever the database held.
	 * @return mixed
	 */
	private static function normalize_scalar_read( $descriptor, $stored ) {
		switch ( $descriptor['type'] ) {
			case 'checkbox':
				// The schema promises an object. A PHP array with no items,
				// or with keys that happen to look sequential once
				// sanitize() has run them through sanitize_key(), still
				// encodes as a JSON array - casting to stdClass is what
				// forces {} and {"0":...} instead of [] and [...].
				return is_array( $stored ) ? (object) $stored : (object) array();
			case 'number':
				// A field never saved reads back as 0, the schema-honest "no
				// value" for a number, not the empty string a text field uses.
				return is_numeric( $stored ) ? $stored + 0 : 0;
			case 'media':
				return self::normalize_media_read( $descriptor, $stored );
			case 'switcher':
				// The schema promises a boolean, so one comes back whatever
				// storage holds - JetEngine's 'true'/'false' strings, an older
				// '1'/'0', or the empty string a switch nobody ever touched
				// leaves behind, which is off. This is the half of the fix the
				// type change needs: advertising boolean and then handing back
				// the raw string would only move the disagreement from the
				// write side to the read side.
				return self::truthy( $stored );
			default:
				// text, textarea, wysiwyg, select, radio, dates,
				// url: all are advertised as a string by json_type(), so a
				// scalar cast is honest for all of them; anything that is
				// not a scalar - an array or object left behind by corrupt
				// data - has no honest string form and reads back as empty.
				return ( null === $stored || ! is_scalar( $stored ) ) ? '' : (string) $stored;
		}
	}

	/**
	 * Present a stored media value as the shape storage actually holds.
	 *
	 * The schema names all three formats, so all three can be handed back as
	 * themselves: an id reads back as an integer, a URL as the URL it is, and
	 * the id-and-URL pair as the object it is. Nothing is converted into
	 * anything else on the way out - absint() on a stored URL was 0, which
	 * both broke the promise the schema makes and told a client its own
	 * picture had been lost.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $stored     Whatever the database held.
	 * @return int|string|object
	 */
	private static function normalize_media_read( $descriptor, $stored ) {
		if ( is_object( $stored ) ) {
			$stored = get_object_vars( $stored );
		}
		if ( is_array( $stored ) ) {
			// Cast for the reason a checkbox is cast: a PHP array whose keys
			// happen to run 0, 1, 2 encodes as a JSON array, and the schema
			// promised an object. Every key and value survives the cast.
			return (object) $stored;
		}
		if ( self::is_attachment_id( $stored ) ) {
			return absint( $stored );
		}
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		// Nothing is stored. What "nothing" looks like is the one place the
		// site's own value_format is allowed to decide, because there is no
		// value in hand to read it off: a field that holds ids has always
		// answered 0 here and consumers depend on it, while 0 would be a lie
		// about a field that has never held a number at all.
		$format = isset( $descriptor['value_format'] ) ? $descriptor['value_format'] : 'id';
		return 'id' === $format ? 0 : '';
	}
}
