<?php
/**
 * User Role Editor module - edit role capabilities, add/clone/delete roles
 * and register custom capabilities. Replaces the standalone
 * "User Role Editor" plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-ure-manager.php';
require_once __DIR__ . '/class-dpt-ure-admin.php';

class DPT_User_Role_Editor_Module extends DPT_Module {

	/** @var DPT_URE_Admin */
	private $admin;

	public function id() {
		return 'user_role_editor';
	}

	public function title() {
		return __( 'User Role Editor', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Edit the capabilities of every role, add, clone or delete roles, and register custom capabilities. Replaces the User Role Editor plugin.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_URE_Manager::install_defaults();
	}

	public function init() {
		$this->admin = new DPT_URE_Admin();
	}

	public function register_admin_menu( $parent_slug ) {
		$this->admin->register_menu( $parent_slug );
	}
}
