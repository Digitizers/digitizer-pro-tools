<?php
/**
 * Agent Log module - what the automations did to this site.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-al-channel.php';
require_once __DIR__ . '/class-dpt-al-store.php';
require_once __DIR__ . '/class-dpt-al-buffer.php';
require_once __DIR__ . '/class-dpt-al-hooks.php';
require_once __DIR__ . '/class-dpt-al-rest.php';
require_once __DIR__ . '/class-dpt-al-admin.php';

class DPT_Agent_Log_Module extends DPT_Module {

	/** @var DPT_AL_Admin */
	private $admin;

	public function id() {
		return 'agent_log';
	}

	public function title() {
		return __( 'Agent Log', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Records what automations changed on this site - anything that arrived over the REST API, WP-Cron, WP-CLI or XML-RPC. A change made by a person in the admin is not recorded at all. Each entry names who, what and when, and which fields were touched; it never stores the values. Readable at /digitizer/v1/activity and on its own screen.', 'digitizer-pro-tools' );
	}

	/**
	 * Whether the standalone Digitizer AI Agent Log plugin is running here.
	 *
	 * The module was extracted into a plugin of its own for WordPress.org. A
	 * site with both would keep two logs of one thing, in two tables, from two
	 * sets of listeners on the same hooks - so when the standalone is active
	 * this module does nothing and says so on the Modules screen. The
	 * standalone wins because it is the one the operator chose to install.
	 *
	 * @return bool
	 */
	public static function standalone_active() {
		// Two class names because the standalone was renamed for the
		// WordPress.org directory, which judged "AI Agent Activity Log" too
		// generic a name to list. It never reached the directory under the
		// old name, but it did reach the machines it was tested on, and a
		// site still running that build must go on standing this module down
		// - otherwise it quietly gets two sets of listeners on one set of
		// hooks, which is the exact thing this method exists to prevent.
		return class_exists( 'Digitizer_AI_Agent_Log_Core' ) || class_exists( 'AI_Agent_Activity_Log_Core' );
	}

	public function init() {
		if ( self::standalone_active() ) {
			return;
		}

		DPT_AL_Store::install_table();
		DPT_AL_Hooks::init();
		add_action( 'rest_api_init', array( 'DPT_AL_Rest', 'init' ) );

		if ( is_admin() ) {
			$this->admin = new DPT_AL_Admin();
		}
	}

	/**
	 * @return string
	 */
	public function standing_down_reason() {
		if ( ! self::standalone_active() ) {
			return '';
		}
		return __( 'The standalone Digitizer AI Agent Log plugin is active, so this module is standing down - its log lives under Agent Activity in the admin menu.', 'digitizer-pro-tools' );
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
