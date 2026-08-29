<?php
/**
 * Agent Log module - reading the log from outside.
 *
 * The API is the product and the screen is the bonus: the question "did the
 * agent do what I think it did" is usually asked by something that is not a
 * person sitting at wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Rest {

	const NAMESPACE_V1 = 'digitizer/v1';

	/**
	 * Registered independently of the REST Bridge module: the namespace is
	 * shared, the registration is not, so this endpoint exists whether or not
	 * that module is switched on.
	 *
	 * @return void
	 */
	public static function init() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/activity',
			array(
				// GET only. There is deliberately no route that deletes: a
				// log that can be erased through the API is a log an attacker
				// erases on the way out.
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'may_read' ),
				'args'                => self::args(),
			)
		);
	}

	/**
	 * @return array
	 */
	public static function args() {
		return array(
			'after'       => array( 'type' => 'string' ),
			'before'      => array( 'type' => 'string' ),
			'channel'     => array(
				'type' => 'string',
				'enum' => array( 'rest', 'cron', 'cli', 'xmlrpc' ),
			),
			'object_type' => array(
				'type' => 'string',
				'enum' => array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' ),
			),
			'object_id'   => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'app'         => array( 'type' => 'string' ),
			'page'        => array(
				'default' => 1,
				'type'    => 'integer',
				'minimum' => 1,
			),
			'per_page'    => array(
				'default' => 20,
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
		);
	}

	/**
	 * The log names who changed what, which is more than edit_posts should
	 * reveal.
	 *
	 * @return bool
	 */
	public static function may_read() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$args = array();
		foreach ( array_keys( self::args() ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$args[ $key ] = $value;
			}
		}

		$rows  = DPT_AL_Store::query( $args );
		$total = DPT_AL_Store::count( $args );
		$parts = DPT_AL_Store::query_args( $args );

		$items = array();
		foreach ( $rows as $row ) {
			$fields  = json_decode( isset( $row->fields ) ? (string) $row->fields : '[]', true );
			$items[] = array(
				'id'             => isset( $row->id ) ? (int) $row->id : 0,
				'logged_at'      => isset( $row->logged_at ) ? (string) $row->logged_at : '',
				'channel'        => isset( $row->channel ) ? (string) $row->channel : '',
				'app'            => isset( $row->app ) ? (string) $row->app : '',
				'user_id'        => isset( $row->user_id ) ? (int) $row->user_id : 0,
				'action'         => isset( $row->action ) ? (string) $row->action : '',
				'object_type'    => isset( $row->object_type ) ? (string) $row->object_type : '',
				'object_subtype' => isset( $row->object_subtype ) ? (string) $row->object_subtype : '',
				'object_id'      => isset( $row->object_id ) ? (int) $row->object_id : 0,
				'object_name'    => isset( $row->object_name ) ? (string) $row->object_name : '',
				'fields'         => is_array( $fields ) ? $fields : array(),
			);
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $parts['limit'] > 0 ? (int) ceil( $total / $parts['limit'] ) : 0 ) );
		return $response;
	}
}
