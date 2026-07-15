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

		// Legacy hand-pasted snippets register blanket filters on these same
		// hooks and would override this module's failure-preserving logic.
		// Neutralize the well-known ones late (init:1), after the theme's
		// functions.php and snippet plugins have registered them.
		add_action( 'init', array( $this, 'neutralize_legacy_snippets' ), 1 );

		if ( is_admin() ) {
			$this->admin = new DPT_UE_Admin();
		}
	}

	/**
	 * Remove the widespread functions.php snippet callbacks on the hooks
	 * this module manages (only for toggles that are ON):
	 * - __return_false on the plugin/theme email hooks silences FAILED
	 *   update emails too, defeating this module's promise;
	 * - the WPBeginner-style core callbacks (both spellings - the snippet
	 *   circulating with a typo registers a function that is never defined
	 *   and fatals the cron request when a core update completes).
	 */
	public function neutralize_legacy_snippets() {
		$o = DPT_UE_Settings::all();
		if ( '1' === $o['disable_plugin_emails'] ) {
			remove_filter( 'auto_plugin_update_send_email', '__return_false' );
		}
		if ( '1' === $o['disable_theme_emails'] ) {
			remove_filter( 'auto_theme_update_send_email', '__return_false' );
		}
		if ( '1' === $o['disable_core_success_emails'] ) {
			remove_filter( 'auto_core_update_send_email', 'wpb_stop_auto_update_emails' );
			remove_filter( 'auto_core_update_send_email', 'wpb_stop_update_emails' );
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
