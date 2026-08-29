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
				return self::is_browser_rest() ? '' : 'rest';
			}
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::is_browser_rest() ? '' : 'rest';
		}
		return '';
	}

	/**
	 * Whether this REST request is a person in a browser rather than a client.
	 *
	 * The block editor saves posts over the REST API, cookie-authenticated,
	 * so REST_REQUEST alone is not "an automation did this" - it is also
	 * every Gutenberg save on every site that uses the block editor. Without
	 * this the module's central promise fails exactly where it is most
	 * load-bearing: the log fills with ordinary human editing.
	 *
	 * The signal is core's own $wp_rest_auth_cookie. Core sets it in
	 * rest_cookie_collect_status() (wp-includes/rest-api.php:1185), which
	 * default-filters.php hooks to auth_cookie_valid and to each of the four
	 * failure actions (lines 338-342):
	 *
	 *     if ( 'auth_cookie_valid' !== $status_type ) {
	 *         $wp_rest_auth_cookie = substr( $status_type, 12 );
	 *         return;
	 *     }
	 *     $wp_rest_auth_cookie = true;
	 *
	 * so `true` means, and only means, that wp_validate_auth_cookie() got all
	 * the way to its final do_action( 'auth_cookie_valid', ... )
	 * (wp-includes/pluggable.php:931) - past the malformed, expired, bad
	 * username, bad hash and bad session token returns above it. For a REST
	 * request that is reached through wp_validate_logged_in_cookie()
	 * (wp-includes/user.php:598, on 'determine_current_user',
	 * default-filters.php:521), which is exactly the path a browser session
	 * takes. rest_cookie_check_errors() reads the same global for the same
	 * meaning: "if we get an auth error, but we're still logged in, another
	 * authentication must have been used" (rest-api.php:1142).
	 *
	 * Neither direction is misclassified:
	 *
	 * - A REST write from ContentEngine with an application password never
	 *   validates a logged-in cookie, so the global is null and the answer is
	 *   'rest'. And in the one case where both could be true at once - a
	 *   browser that also sent an application password header - the password
	 *   wins, because rest_get_authenticated_app_password()
	 *   (rest-api.php:1231) answering with a UUID is unambiguously not a
	 *   browser session. It is read second on purpose: reading it first would
	 *   cost the lookup on every request, and the cookie global answers the
	 *   common case for free.
	 * - A Gutenberg save validates that cookie, so the global is true and the
	 *   answer is '' - the same as any other browser request, which is what
	 *   the module promises.
	 * - A REST request with no authentication at all leaves the global null -
	 *   nothing ever set it - and so counts as 'rest'. That is the right
	 *   side: no browser session was established, so it is not a person
	 *   editing; a permission callback would normally reject such a write,
	 *   and one that somehow lands is precisely the anomalous non-browser
	 *   change the log exists to surface. Erring towards recording an
	 *   unauthenticated write costs a rare row; erring towards recording a
	 *   cookie session costs the whole boundary.
	 *
	 * A string value ('expired', 'bad_hash', ...) means a cookie was sent and
	 * rejected, which is not a session either - only the identity `true`
	 * counts.
	 *
	 * @return bool
	 */
	private static function is_browser_rest() {
		if ( ! isset( $GLOBALS['wp_rest_auth_cookie'] ) || true !== $GLOBALS['wp_rest_auth_cookie'] ) {
			return false;
		}
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return true;
		}
		$uuid = rest_get_authenticated_app_password();
		return empty( $uuid );
	}

	/**
	 * Whether this request is on a channel whose name is knowable this early.
	 *
	 * "This early" means 'plugins_loaded', which is where the module wires
	 * itself up. Three of the four channels announce themselves before that,
	 * and each does so before WordPress is even loaded:
	 *
	 * - WP-CLI defines WP_CLI while bootstrapping, ahead of wp-load.php.
	 * - wp-cron.php defines DOING_CRON at line 42, and only then requires
	 *   wp-load.php - so wp_doing_cron() is already true at plugins_loaded.
	 * - xmlrpc.php defines XMLRPC_REQUEST at line 13, likewise before the
	 *   load.
	 *
	 * 'rest' is deliberately absent. Core defines REST_REQUEST inside
	 * rest_api_loaded() (wp-includes/rest-api.php line 478), which
	 * default-filters.php hooks to 'parse_request' - long after
	 * plugins_loaded. At plugins_loaded a REST request cannot be told from a
	 * browser one, which is the whole reason the channel gate itself lives in
	 * DPT_AL_Hooks::flush(), on 'shutdown'.
	 *
	 * @return bool
	 */
	public static function is_early_channel() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;
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
