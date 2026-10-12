<?php
/**
 * Shabbat Keeper module - closes the site, or only its shop and forms,
 * from candle lighting to Havdalah, with the times computed here.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-sk-hebrew-calendar.php';
require_once __DIR__ . '/class-dpt-sk-sun.php';
require_once __DIR__ . '/class-dpt-sk-cities.php';
require_once __DIR__ . '/class-dpt-sk-zmanim.php';
require_once __DIR__ . '/class-dpt-sk-settings.php';
require_once __DIR__ . '/class-dpt-sk-enforce.php';
require_once __DIR__ . '/class-dpt-sk-integrations.php';
require_once __DIR__ . '/class-dpt-sk-admin.php';

class DPT_Shabbat_Keeper_Module extends DPT_Module {

	/** @var DPT_SK_Admin */
	private $admin;

	public function id() {
		return 'shabbat_keeper';
	}

	public function title() {
		return __( 'Shabbat Keeper', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for the city you pick; nothing is sent anywhere.', 'digitizer-pro-tools' );
	}

	/**
	 * Whether the standalone Digitizer Shabbat Keeper plugin is running here.
	 *
	 * The module was extracted into a plugin of its own for WordPress.org. A
	 * site with both would close twice - two closed pages racing on
	 * template_redirect, two banners, two refusals on every checkout, each
	 * from its own settings - so when the standalone is active this module
	 * does nothing and says so on the Modules screen. The standalone wins
	 * because it is the one the operator chose to install, and it copied
	 * this module's settings when it was first activated.
	 *
	 * The switch is locked meanwhile. Off would look like tidying up, and
	 * would mean that deactivating the standalone later leaves a site that
	 * used to close for Shabbat open, with nothing saying so.
	 *
	 * @return bool
	 */
	public static function standalone_active() {
		return class_exists( 'Digitizer_Shabbat_Keeper_Core' );
	}

	public function init() {
		if ( self::standalone_active() ) {
			return;
		}

		DPT_SK_Enforce::register();
		DPT_SK_Integrations::register();
		$this->admin = new DPT_SK_Admin();
	}

	/**
	 * @return bool
	 */
	public function user_can_toggle() {
		return ! self::standalone_active();
	}

	/**
	 * One sentence for both states of a locked switch, on or off; the
	 * Modules screen shows it under the switch either way.
	 *
	 * @return string
	 */
	public function toggle_denied_reason() {
		if ( ! self::standalone_active() ) {
			return '';
		}
		return __( 'The standalone Digitizer Shabbat Keeper plugin is active and does this module\'s work, so the switch is locked - its settings are on the Shabbat Keeper screen under Settings.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_SK_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
