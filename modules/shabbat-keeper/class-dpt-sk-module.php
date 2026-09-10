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

class DPT_Shabbat_Keeper_Module extends DPT_Module {

	public function id() {
		return 'shabbat_keeper';
	}

	public function title() {
		return __( 'Shabbat Keeper', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for the city you pick; nothing is sent anywhere.', 'digitizer-pro-tools' );
	}

	public function init() {
		DPT_SK_Enforce::register();
		DPT_SK_Integrations::register();
	}

	public function install_defaults() {
		DPT_SK_Settings::install_defaults();
	}
}
