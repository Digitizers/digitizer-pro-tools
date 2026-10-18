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

	/**
	 * Whether the standalone Digitizer Cookie Consent plugin is running here.
	 *
	 * The module was extracted into a plugin of its own for WordPress.org. A
	 * site with both would print two banners and two head prechecks, each
	 * from its own settings, and block and release scripts twice - so when
	 * the standalone is active this module does nothing and says so on the
	 * Modules screen. The standalone wins because it is the one the operator
	 * chose to install, and it copied this module's settings when it was
	 * first activated.
	 *
	 * The switch is locked meanwhile. Off would look like tidying up, and
	 * would mean that deactivating the standalone later leaves a site with
	 * no banner and no script blocking, with nothing saying so.
	 *
	 * @return bool
	 */
	public static function standalone_active() {
		return class_exists( 'Digitizer_Cookie_Consent_Core' );
	}

	public function init() {
		if ( self::standalone_active() ) {
			self::bridge_legacy_names();
			return;
		}
		new DPT_CB_Frontend();
		$this->admin = new DPT_CB_Admin();
	}

	/**
	 * The standalone uses only its own full prefix, as wordpress.org
	 * requires. Site code written against this module's names - its
	 * shortcode, filters and cache-purge action - keeps working: they are
	 * carried over to the standalone's here.
	 */
	public static function bridge_legacy_names() {
		add_filter(
			'digitizer_cookie_consent_open_selector',
			function ( $selector ) {
				return apply_filters( 'dpt_cb_open_selector', $selector );
			}
		);
		add_filter(
			'digitizer_cookie_consent_rtl_langs',
			function ( $langs ) {
				return apply_filters( 'dpt_cb_rtl_langs', $langs );
			}
		);
		add_action(
			'digitizer_cookie_consent_purge_caches',
			function () {
				do_action( 'dpt_cb_purge_caches' );
			}
		);
		add_shortcode(
			'dpt_cookie_settings',
			function ( $atts, $content = '' ) {
				global $shortcode_tags;
				if ( empty( $shortcode_tags['digitizer_cookie_consent_settings'] ) || ! is_callable( $shortcode_tags['digitizer_cookie_consent_settings'] ) ) {
					return '';
				}
				return call_user_func( $shortcode_tags['digitizer_cookie_consent_settings'], $atts, $content, 'digitizer_cookie_consent_settings' );
			}
		);
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
		return __( 'The standalone Digitizer Cookie Consent plugin is active and does this module\'s work, so the switch is locked - its settings are under Settings → Cookie Consent.', 'digitizer-pro-tools' );
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
