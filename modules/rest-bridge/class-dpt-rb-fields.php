<?php
/**
 * REST Bridge - putting the discovered fields on the REST API.
 *
 * Discovery says what exists; this says it out loud to the API, under the
 * names JetEngine actually uses. A small compatibility layer keeps the names
 * the plugin this module replaces had invented, because automations were
 * written against those and an upgrade is not the moment to break them.
 *
 * Capabilities are not checked here on purpose: these are fields on core's
 * own post and term controllers, which have already established that the
 * request may edit the object before any update callback runs.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers REST fields for discovered and legacy meta.
 */
class DPT_RB_Fields {

	/**
	 * object/target => list of field names, for the info endpoint.
	 *
	 * @var array
	 */
	private static $registered = array();

	/**
	 * Names the compatibility layer added.
	 *
	 * @var array
	 */
	private static $compat = array();

	/**
	 * The legacy fields the replaced plugin promised, kept because
	 * automations use them and they may not be JetEngine fields at all.
	 *
	 * @return array
	 */
	private static function legacy() {
		return array(
			array(
				'meta_key' => 'reading_time',
				'title'    => 'Estimated reading time',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'post',
				'targets'  => array( 'post' ),
			),
			array(
				'meta_key' => 'author_description',
				'title'    => 'Author bio description',
				'type'     => 'wysiwyg',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			array(
				'meta_key' => 'author_image',
				'title'    => 'Author avatar image URL',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			array(
				'meta_key' => 'linkedin',
				'title'    => 'Author LinkedIn URL',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
		);
	}

	/**
	 * Register everything. Called on rest_api_init.
	 */
	public static function register() {
		self::$registered = array();
		self::$compat     = array();

		$discovered = DPT_RB_Definitions::all();
		foreach ( $discovered as $descriptor ) {
			self::register_one( $descriptor, $descriptor['meta_key'] );
		}

		// The alias: one name the old plugin invented for a repeater whose
		// real name is qna. It writes the same meta key, so both names are
		// the same field seen twice rather than two fields to keep in step.
		foreach ( $discovered as $descriptor ) {
			if ( 'qna' === $descriptor['meta_key'] && 'repeater' === $descriptor['type'] ) {
				self::register_one( $descriptor, 'jet_qna' );
				self::$compat[] = 'jet_qna';
			}
		}

		// And the fields the old plugin hard-coded. Each is registered only
		// where discovery did not already produce that name: a real
		// definition knows more than this list does.
		foreach ( self::legacy() as $descriptor ) {
			if ( self::already( $descriptor ) ) {
				continue;
			}
			self::register_one( $descriptor, $descriptor['meta_key'] );
			self::$compat[] = $descriptor['meta_key'];
		}

		// If nothing was discovered there is no qna to alias, yet ContentEngine
		// still writes jet_qna. Give it the shape the old plugin gave it.
		if ( ! in_array( 'jet_qna', self::$compat, true ) && ! self::name_taken( 'post', 'post', 'jet_qna' ) ) {
			self::register_one( self::fallback_qna(), 'jet_qna' );
			self::$compat[] = 'jet_qna';
		}
	}

	/**
	 * The FAQ repeater as the replaced plugin defined it, for a site whose
	 * JetEngine definitions this module cannot see.
	 *
	 * @return array
	 */
	private static function fallback_qna() {
		return array(
			'meta_key' => 'qna',
			'title'    => 'FAQ (question and answer pairs)',
			'type'     => 'repeater',
			'fields'   => array(
				array( 'meta_key' => 'question', 'title' => 'Question', 'type' => 'text', 'fields' => array() ),
				array( 'meta_key' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg', 'fields' => array() ),
			),
			'object'   => 'post',
			'targets'  => array( 'post' ),
		);
	}

	/**
	 * Whether discovery already produced this name on any of its targets.
	 *
	 * @param array $descriptor Legacy descriptor.
	 * @return bool
	 */
	private static function already( $descriptor ) {
		foreach ( $descriptor['targets'] as $target ) {
			if ( self::name_taken( $descriptor['object'], $target, $descriptor['meta_key'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a name is already registered on one target.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy.
	 * @param string $name   Field name.
	 * @return bool
	 */
	private static function name_taken( $object, $target, $name ) {
		$key = $object . '/' . $target;
		return isset( self::$registered[ $key ] ) && in_array( $name, self::$registered[ $key ], true );
	}

	/**
	 * Register one descriptor under one name, on every target that the site
	 * actually exposes to the REST API.
	 *
	 * @param array  $descriptor Field descriptor.
	 * @param string $name       The name to expose it under.
	 */
	private static function register_one( $descriptor, $name ) {
		$schema = DPT_RB_Schema::for_descriptor( $descriptor );

		foreach ( $descriptor['targets'] as $target ) {
			if ( ! self::exposed( $descriptor['object'], $target ) ) {
				continue;
			}

			register_rest_field(
				$target,
				$name,
				array(
					'get_callback'    => function ( $object ) use ( $descriptor ) {
						return DPT_RB_Fields::read( $descriptor, $object );
					},
					'update_callback' => function ( $value, $object ) use ( $descriptor ) {
						return DPT_RB_Fields::write( $descriptor, $value, $object );
					},
					'schema'          => $schema,
				)
			);

			$key = $descriptor['object'] . '/' . $target;
			if ( ! isset( self::$registered[ $key ] ) ) {
				self::$registered[ $key ] = array();
			}
			self::$registered[ $key ][] = $name;
		}
	}

	/**
	 * Whether a post type or taxonomy is on the REST API at all. Registering
	 * a field on something invisible would only be a lie in the info report.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @return bool
	 */
	private static function exposed( $object, $target ) {
		if ( 'taxonomy' === $object ) {
			$tax = get_taxonomy( $target );
			return $tax && ! empty( $tax->show_in_rest );
		}
		$type = get_post_type_object( $target );
		return $type && ! empty( $type->show_in_rest );
	}

	/**
	 * What was registered where.
	 *
	 * @return array
	 */
	public static function registered() {
		return self::$registered;
	}

	/**
	 * Names owed to the compatibility layer rather than to a definition.
	 *
	 * @return array
	 */
	public static function compat() {
		return self::$compat;
	}

	/**
	 * The id of the object a REST callback was handed. Core passes an array
	 * for a read and an object for a write, and terms and posts name their
	 * id differently.
	 *
	 * @param mixed $object Post or term, as array or object.
	 * @return int
	 */
	private static function object_id( $object ) {
		if ( is_array( $object ) ) {
			if ( isset( $object['id'] ) ) {
				return (int) $object['id'];
			}
			return isset( $object['term_id'] ) ? (int) $object['term_id'] : 0;
		}
		if ( is_object( $object ) ) {
			if ( isset( $object->ID ) ) {
				return (int) $object->ID;
			}
			return isset( $object->term_id ) ? (int) $object->term_id : 0;
		}
		return 0;
	}

	/**
	 * Read a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $object     Post or term.
	 * @return mixed
	 */
	public static function read( $descriptor, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return DPT_RB_Schema::normalize_read( $descriptor, null );
		}
		$stored = 'taxonomy' === $descriptor['object']
			? get_term_meta( $id, $descriptor['meta_key'], true )
			: get_post_meta( $id, $descriptor['meta_key'], true );

		return DPT_RB_Schema::normalize_read( $descriptor, $stored );
	}

	/**
	 * Write a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Incoming value.
	 * @param mixed $object     Post or term.
	 * @return true|WP_Error
	 */
	public static function write( $descriptor, $value, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return new WP_Error(
				'dpt_rb_no_object',
				__( 'The object to update could not be identified.', 'digitizer-pro-tools' ),
				array( 'status' => 400 )
			);
		}

		$clean = DPT_RB_Schema::sanitize( $descriptor, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$is_tax = 'taxonomy' === $descriptor['object'];
		$key    = $descriptor['meta_key'];

		// An empty repeater means "clear this", which is a delete rather than
		// a write of nothing.
		if ( 'repeater' === $descriptor['type'] && array() === $clean ) {
			$deleted = $is_tax ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
			if ( $deleted ) {
				return true;
			}
			// A delete also reports false when there was nothing there, which
			// is the outcome that was asked for.
			$still = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( '' === $still || array() === $still ) {
				return true;
			}
			return new WP_Error(
				'dpt_rb_not_cleared',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s could not be cleared.', 'digitizer-pro-tools' ),
					$key
				),
				array( 'status' => 500 )
			);
		}

		$updated = $is_tax ? update_term_meta( $id, $key, $clean ) : update_post_meta( $id, $key, $clean );
		if ( false === $updated ) {
			// update_*_meta returns false for an unchanged value as well as
			// for a refusal; only a value that did not land is a failure.
			$stored = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( $stored !== $clean ) {
				return new WP_Error(
					'dpt_rb_not_saved',
					sprintf(
						/* translators: %s: field name */
						__( 'The field %s could not be saved.', 'digitizer-pro-tools' ),
						$key
					),
					array( 'status' => 500 )
				);
			}
		}

		return true;
	}
}
