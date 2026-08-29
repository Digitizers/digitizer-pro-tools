<?php
/**
 * Agent Log module - which channel this request arrived on.
 *
 * Pure, and the gate for everything else: a request this file cannot name is
 * a request nothing is recorded for. That is the module's whole boundary -
 * a person working in wp-admin leaves no trace here, by construction rather
 * than by a filter someone could forget to apply.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Channel {

	/**
	 * The channel this request arrived on, or '' for anything else.
	 *
	 * Order is precedence, not preference. Contexts nest - WP-CLI can run
	 * code that sets REST_REQUEST, and a cron run can call into the REST
	 * stack - and the outermost context is the one that describes where the
	 * change really came from.
	 *
	 * @return string
	 */
	public static function current() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		// wp_is_serving_rest_request() (wp-includes/functions.php) arrived in
		// WordPress 6.5 and is itself a read of the REST_REQUEST constant. On
		// anything older the constant is what there is. Note that core defines
		// REST_REQUEST inside rest_api_loaded(), on 'parse_request' - so this
		// answers '' for a REST request until then, which is why the caller
		// gates at shutdown rather than on plugins_loaded.
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			if ( wp_is_serving_rest_request() ) {
				return 'rest';
			}
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return '';
	}

	/**
	 * Whether this request only reads.
	 *
	 * ContentEngine polls. Recording reads fills the table in a day and
	 * drowns the writes that were the reason to look.
	 *
	 * A request with no method at all is CLI or cron, which are writes worth
	 * recording - so the absent case must answer false, not true.
	 *
	 * @return bool
	 */
	public static function is_read_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_scalar( $_SERVER['REQUEST_METHOD'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the HTTP verb, not request data.
		$method = strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * The name of the application password that authenticated this request.
	 *
	 * Implemented from the finding in
	 * docs/superpowers/specs/2026-08-25-agent-log-app-name-finding.md, and
	 * from nothing else: core's own global, set unconditionally by
	 * rest_application_password_collect_status() during authentication and
	 * exposed via rest_get_authenticated_app_password(), still readable at
	 * shutdown because nothing in core clears it.
	 *
	 * When the name cannot be determined this returns ''. It never falls back
	 * to a User-Agent, an IP or any other string that merely looks like an
	 * identity: a fabricated attribution in a log is worse than an absent one,
	 * because someone will believe it.
	 *
	 * @return string
	 */
	public static function app_name() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return '';
		}

		$uuid = rest_get_authenticated_app_password();
		if ( empty( $uuid ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return '';
		}

		$record = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );

		return ( is_array( $record ) && ! empty( $record['name'] ) ) ? (string) $record['name'] : '';
	}
}
