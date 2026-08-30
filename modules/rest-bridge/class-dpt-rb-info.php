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
	 * The same report, for a module that has not registered anything.
	 *
	 * Rehearses the registration and then reads the result through payload(),
	 * so the preview screen and the live endpoint cannot drift apart: there
	 * is one description of what this module does to a site, and both read
	 * it.
	 *
	 * @return array
	 */
	public static function preview() {
		DPT_RB_Fields::rehearse();
		return self::payload();
	}

	/**
	 * What the endpoint says. Every value here is read from the same
	 * methods that do the actual registering, never re-derived, so the
	 * report cannot claim a field that was not really registered.
	 *
	 * @return array
	 */
	public static function payload() {
		return array(
			'module'    => 'Digitizer Pro Tools - REST Bridge',
			'version'   => DPT_VERSION,
			// Names and their schemas, not names alone: an agent handed a
			// site it has never seen has to know a field's type and shape
			// before it can write to it, and this is where it is told.
			'fields'    => DPT_RB_Fields::registered(),
			'compat'    => array_values( DPT_RB_Fields::compat() ),
			// Discovery's diagnostics first, then registration's - a
			// registration skip (e.g. jet_qna) only makes sense once
			// discovery has already been read.
			'skipped'   => array_values(
				array_merge( DPT_RB_Definitions::skipped(), DPT_RB_Fields::skipped() )
			),
			'rank_math' => DPT_RB_Rankmath::active(),
			'routes'    => array(
				// With their methods: the Elementor route reads on GET and
				// writes on POST, and a list that says neither leaves an
				// agent to guess that the write side exists at all.
				'/digitizer/v1/elementor/{post_id} (GET, POST)',
				'/digitizer/v1/info',
			),
		);
	}
}
