<?php
/**
 * Onboarding module - brings a fresh site to the Digitizer baseline.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-onb-manifest.php';
require_once __DIR__ . '/class-dpt-onb-state.php';
require_once __DIR__ . '/class-dpt-onb-source.php';
require_once __DIR__ . '/class-dpt-onb-installer.php';
require_once __DIR__ . '/class-dpt-onb-cleanup.php';
require_once __DIR__ . '/class-dpt-onb-updates.php';
require_once __DIR__ . '/class-dpt-onb-admin.php';

class DPT_Onboarding_Module extends DPT_Module {

	/** @var DPT_ONB_Admin */
	private $admin;

	public function id() {
		return 'onboarding';
	}

	public function title() {
		return __( 'Onboarding', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Installs the Digitizer baseline - Hello Elementor with the Digitizer child theme, and the standard plugin set - on a new site. The wizard never reconfigures or overwrites anything already installed, and a run can be repeated safely. While this module is on, the baseline items that come from GitHub also report their updates to WordPress, which they cannot do for themselves.', 'digitizer-pro-tools' );
	}

	public function init() {
		// Not admin-only: the update transients are read on the front end and
		// refreshed from cron, and an item that reports an update in one place
		// and not the other is worse than one that reports none.
		DPT_ONB_Updates::init();

		if ( is_admin() ) {
			$this->admin = new DPT_ONB_Admin();
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
