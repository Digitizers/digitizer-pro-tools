<?php
/**
 * Cookie Banner module - registration and wiring.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-cb-settings.php';
require_once __DIR__ . '/class-dpt-cb-frontend.php';
require_once __DIR__ . '/class-dpt-cb-admin.php';

class DPT_Cookie_Banner_Module extends DPT_Module {

	/** @var DPT_CB_Admin */
	private $admin;

	public function id() {
		return 'cookie_banner';
	}

	public function title() {
		return __( 'Cookie Banner', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Multilingual cookie-consent banner with cookie categories, script blocking until consent, and a floating preferences button.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return true;
	}

	public function init() {
		new DPT_CB_Frontend();
		$this->admin = new DPT_CB_Admin();
	}

	public function install_defaults() {
		DPT_CB_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
