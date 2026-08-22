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
		return __( 'Holds a major WordPress release back for a set number of days after this site is first offered it, so the plugins and themes here have time to catch up. Security and maintenance releases are never held. The hold is shown on the Updates screen and can be lifted from there. On a multisite network the policy belongs to the main site, because the WordPress it governs belongs to the network.', 'digitizer-pro-tools' );
	}

	/**
	 * On a network this module's switch decides for every site on it, so it
	 * asks for the authority that decision needs rather than the authority
	 * the Modules screen happens to require. A main-site administrator who is
	 * not a network administrator can reach that screen with manage_options
	 * alone, and would otherwise be able to switch off a hold they are not
	 * allowed to release - or to let a configured unattended major update
	 * through - from a screen that never mentions the network.
	 *
	 * @return bool
	 */
	public function user_can_toggle() {
		return ! is_multisite() || DPT_UP_Settings::may_decide();
	}

	/**
	 * @return string
	 */
	public function toggle_denied_reason() {
		return __( 'On a multisite network this policy applies to every site, so only a network administrator can switch it on or off.', 'digitizer-pro-tools' );
	}

	/**
	 * Whether the standalone Update Policy plugin is running on this site.
	 *
	 * The module was extracted into a plugin of its own for WordPress.org. A
	 * site with both would have two filters on one transient, two settings
	 * screens and two notices saying the same thing - so when the standalone
	 * is active this module does nothing and says so on the Modules screen.
	 * The standalone wins because it is the one the operator chose to install.
	 *
	 * @return bool
	 */
	public static function standalone_active() {
		return class_exists( 'Update_Policy_Core' );
	}

	public function init() {
		if ( self::standalone_active() ) {
			return;
		}

		// Not admin-only: the update transient is read on the front end too,
		// and a hold that applies in one place and not the other is worse than
		// no hold at all.
		DPT_UP_Policy::init();

		if ( is_admin() ) {
			$this->admin = new DPT_UP_Admin();
		}
	}

	/**
	 * @return string
	 */
	public function standing_down_reason() {
		return self::standalone_active()
			? __( 'The standalone Update Policy plugin is active, so this module is standing down - its settings live under Settings > Update Policy.', 'digitizer-pro-tools' )
			: '';
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
