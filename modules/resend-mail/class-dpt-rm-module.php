<?php
/**
 * Resend Mail module - wp_mail() delivery through the Resend API, with a
 * send log, delivery-status webhooks and fallback to the default mailer.
 * Replaces SMTP plugins such as WP Mail SMTP / FluentSMTP on sites that
 * send through Resend.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-rm-settings.php';
require_once __DIR__ . '/class-dpt-rm-log.php';
require_once __DIR__ . '/class-dpt-rm-sender.php';
require_once __DIR__ . '/class-dpt-rm-webhook.php';
require_once __DIR__ . '/class-dpt-rm-admin.php';

class DPT_Resend_Mail_Module extends DPT_Module {

	/** @var DPT_RM_Admin */
	private $admin;

	public function id() {
		return 'resend_mail';
	}

	public function title() {
		return __( 'Resend Mail', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Delivers all site email (wp_mail) through the Resend API - verified-domain sending, a send log with delivery statuses, and automatic fallback to the default mailer on errors. Replaces WP Mail SMTP / FluentSMTP.', 'digitizer-pro-tools' );
	}

	public function init() {
		// The sender no-ops until an API key and sender address are saved.
		new DPT_RM_Sender();
		new DPT_RM_Webhook();

		if ( is_admin() ) {
			$this->admin = new DPT_RM_Admin();
		}
	}

	public function install_defaults() {
		DPT_RM_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
