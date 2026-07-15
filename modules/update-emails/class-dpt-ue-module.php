<?php
/**
 * Update Emails module - silences WordPress auto-update email notifications.
 *
 * Safe to run alongside a hand-pasted functions.php snippet doing the same:
 * duplicate __return_false registrations on these hooks are harmless, and
 * all callbacks here are class methods, so no function-name collisions.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-ue-settings.php';
require_once __DIR__ . '/class-dpt-ue-admin.php';

class DPT_Update_Emails_Module extends DPT_Module {

	/** @var DPT_UE_Admin */
	private $admin;

	public function id() {
		return 'update_emails';
	}

	public function title() {
		return __( 'Update Emails', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Silences the "site updated" email notifications WordPress sends after automatic plugin, theme and core updates. Failure emails are always kept.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return true;
	}

	public function init() {
		$o = DPT_UE_Settings::all();

		if ( '1' === $o['disable_plugin_emails'] ) {
			add_filter( 'auto_plugin_update_send_email', array( $this, 'filter_plugin_theme_update_email' ), 10, 2 );
		}
		if ( '1' === $o['disable_theme_emails'] ) {
			add_filter( 'auto_theme_update_send_email', array( $this, 'filter_plugin_theme_update_email' ), 10, 2 );
		}
		if ( '1' === $o['disable_core_success_emails'] ) {
			add_filter( 'auto_core_update_send_email', array( $this, 'filter_core_update_email' ), 10, 4 );
		}

		if ( is_admin() ) {
			$this->admin = new DPT_UE_Admin();
		}
	}

	/**
	 * Plugin/theme auto-update notifications use ONE combined email for both
	 * completed and failed updates, so a plain __return_false would hide
	 * failures too. WordPress passes ($enabled, $update_results): a flat
	 * array of result objects whose ->result is true only on success (see
	 * WP_Automatic_Updater::after_plugin_theme_update()). Silence the email
	 * only when EVERY update in the batch succeeded; anything else - a
	 * failure, or a shape we don't recognize - keeps the notification.
	 *
	 * @param bool  $send           Whether WordPress would send the email.
	 * @param array $update_results Update result objects for this batch.
	 */
	public function filter_plugin_theme_update_email( $send, $update_results = array() ) {
		foreach ( (array) $update_results as $update ) {
			$result = ( is_object( $update ) && property_exists( $update, 'result' ) ) ? $update->result : null;
			if ( true !== $result ) {
				return $send;
			}
		}
		return false;
	}

	/**
	 * Drop only the SUCCESS notification for core auto-updates; failure and
	 * critical emails still go out. Respects earlier filters' decisions
	 * instead of force-enabling everything else.
	 */
	public function filter_core_update_email( $send, $type, $core_update, $result ) {
		if ( 'success' === $type ) {
			return false;
		}
		return $send;
	}

	public function install_defaults() {
		DPT_UE_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
