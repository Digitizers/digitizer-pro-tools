<?php
/**
 * REST Bridge - what this site exposes, in one request.
 *
 * The agents that use this API are handed a site they have never seen. This
 * endpoint is the map: the fields that were discovered and their schemas, the
 * legacy names still honoured, what was passed over and why, and the routes
 * that exist. It replaces the old plugin's faq/info, which was public and
 * described a fixed feature list rather than the site in front of it.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The /digitizer/v1/info endpoint.
 */
class DPT_RB_Info {

	/**
	 * Register the route.
	 */
	public static function register() {
		register_rest_route(
			DPT_RB_Elementor::NAMESPACE_V1,
			'/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'respond' ),
				'permission_callback' => function () {
					// This describes the site's editing surface - what
					// fields exist, where, and under which names - so it is
					// for people who edit the site, not for anonymous
					// visitors. The plugin this replaces got this wrong.
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * The response.
	 *
	 * @return array
	 */
	public static function respond() {
		return rest_ensure_response( self::payload() );
	}

	/**
	 * What the endpoint says. Every value here is read from the same
	 * methods that do the actual registering, never re-derived, so the
	 * report cannot claim a field that was not really registered.
	 *
	 * @return array
	 */
	public static function payload() {
		$fields = array();
		foreach ( DPT_RB_Fields::registered() as $where => $names ) {
			$fields[ $where ] = array_values( $names );
		}

		return array(
			'module'    => 'Digitizer Pro Tools - REST Bridge',
			'version'   => DPT_VERSION,
			'fields'    => $fields,
			'compat'    => array_values( DPT_RB_Fields::compat() ),
			'skipped'   => array_values( DPT_RB_Definitions::skipped() ),
			'rank_math' => DPT_RB_Rankmath::active(),
			'routes'    => array(
				'/digitizer/v1/elementor/{post_id}',
				'/digitizer/v1/info',
			),
		);
	}
}
