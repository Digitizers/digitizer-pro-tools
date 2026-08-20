<?php
/**
 * Update Policy module - holds a major WordPress release back for a while
 * after this site is first offered it.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-up-version.php';
require_once __DIR__ . '/class-dpt-up-offers.php';
require_once __DIR__ . '/class-dpt-up-settings.php';
require_once __DIR__ . '/class-dpt-up-policy.php';
require_once __DIR__ . '/class-dpt-up-admin.php';

class DPT_Update_Policy_Module extends DPT_Module {

	/** @var DPT_UP_Admin */
	private $admin;

	public function id() {
		return 'update_policy';
	}

	public function title() {
		return __( 'Update Policy', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Holds a major WordPress release back for a set number of days after this site is first offered it, so the plugins and themes here have time to catch up. Security and maintenance releases are never held. The hold is shown on the Updates screen and can be lifted from there.', 'digitizer-pro-tools' );
	}

	public function init() {
		// Not admin-only: the update transient is read on the front end too,
		// and a hold that applies in one place and not the other is worse than
		// no hold at all.
		DPT_UP_Policy::init();

		if ( is_admin() ) {
			$this->admin = new DPT_UP_Admin();
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
