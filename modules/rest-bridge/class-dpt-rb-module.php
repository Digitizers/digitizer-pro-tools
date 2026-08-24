<?php
/**
 * REST Bridge module - exposes this site's own custom fields to the REST API.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-rb-definitions.php';
require_once __DIR__ . '/class-dpt-rb-schema.php';
require_once __DIR__ . '/class-dpt-rb-fields.php';
require_once __DIR__ . '/class-dpt-rb-elementor.php';
require_once __DIR__ . '/class-dpt-rb-rankmath.php';
require_once __DIR__ . '/class-dpt-rb-info.php';

/**
 * Wires the REST bridge.
 */
class DPT_Rest_Bridge_Module extends DPT_Module {

	public function id() {
		return 'rest_bridge';
	}

	public function title() {
		return __( 'REST Bridge', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Puts this site\'s own custom fields on the REST API, so an automation can read and write them the way it reads and writes a post title. The fields are found by reading the definitions JetEngine already stores, which means a field added in JetEngine appears in the API without anyone writing code for it - repeaters included, with their sub-fields described. Also exposes Rank Math\'s SEO fields, and endpoints for reading and editing Elementor content without disturbing a page\'s design.', 'digitizer-pro-tools' );
	}

	/**
	 * Whether the plugin this module replaces is running here.
	 *
	 * Both would register the same field names on the same post types and
	 * the same routes in the same namespace; which one answered would be a
	 * matter of load order. The plugin wins, because it is the one somebody
	 * installed on purpose, and this module stands aside and says so.
	 *
	 * @return bool
	 */
	public static function legacy_plugin_active() {
		return function_exists( 'digitizer_elementor_build_tree' );
	}

	public function init() {
		if ( self::legacy_plugin_active() ) {
			return;
		}

		// Everything here answers REST requests and nothing else, so nothing
		// is registered until a REST request is being served.
		add_action( 'rest_api_init', array( __CLASS__, 'boot' ) );
	}

	/**
	 * Register the whole surface. Public so a test can call it without a
	 * REST request.
	 */
	public static function boot() {
		// init() decided this at plugins_loaded; rest_api_init fires later,
		// and often on a different request than the one that ran init() at
		// all (a REST call skips the admin-page code paths that reach some
		// plugins' own hooks). Whether the legacy plugin is active is worth
		// asking again here, at the moment the decision is actually acted
		// on, rather than trusted from further back.
		if ( self::legacy_plugin_active() ) {
			return;
		}

		DPT_RB_Fields::register();
		DPT_RB_Elementor::register();
		DPT_RB_Rankmath::register();
		DPT_RB_Info::register();
	}

	/**
	 * @return string
	 */
	public function standing_down_reason() {
		return self::legacy_plugin_active()
			? __( 'The Digitizer API Extensions plugin is active, so this module is standing down - the two would register the same fields and the same routes. Deactivate that plugin to let this module take over.', 'digitizer-pro-tools' )
			: '';
	}
}
