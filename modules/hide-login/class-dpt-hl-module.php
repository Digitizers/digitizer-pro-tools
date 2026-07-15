<?php
/**
 * Hide Login module - moves the login page to a custom slug and serves a
 * 404 for the default wp-login.php / logged-out wp-admin requests.
 *
 * Replaces the standalone "WPS Hide Login" plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-hl-settings.php';
require_once __DIR__ . '/class-dpt-hl-admin.php';

class DPT_Hide_Login_Module extends DPT_Module {

	/** @var DPT_HL_Admin */
	private $admin;

	/** True when the request targets the default wp-login.php. */
	private $is_old_login = false;

	/** True when the request targets the custom login slug. */
	private $is_new_login = false;

	public function id() {
		return 'hide_login';
	}

	public function title() {
		return __( 'Hide Login', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Moves the login page to a custom URL and returns a 404 for wp-login.php and logged-out wp-admin requests. Replaces the WPS Hide Login plugin.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_HL_Settings::install_defaults();
	}

	public function init() {
		$this->admin = new DPT_HL_Admin();

		// DPT boots on plugins_loaded (priority 10); a later priority on the
		// same hook still fires within this request.
		add_action( 'plugins_loaded', array( $this, 'classify_request' ), 9999 );
		add_action( 'wp_loaded', array( $this, 'route_request' ) );

		add_filter( 'site_url', array( $this, 'filter_login_url' ), 10, 2 );
		add_filter( 'network_site_url', array( $this, 'filter_login_url' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'filter_login_url' ), 10, 1 );
		add_filter( 'site_option_welcome_email', array( $this, 'filter_welcome_email' ) );
	}

	public function register_admin_menu( $parent_slug ) {
		$this->admin->register_menu( $parent_slug );
	}

	/**
	 * Decide early whether this request hits the old wp-login.php or the
	 * custom slug, before anything acts on $pagenow.
	 */
	public function classify_request() {
		global $pagenow;

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! is_admin() && false !== stripos( $path, 'wp-login.php' ) ) {
			$this->is_old_login = true;
			$pagenow            = 'index.php';
			return;
		}

		$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( get_option( 'permalink_structure' ) ) {
			// Pretty permalinks: match the slug path (carrying the
			// index.php prefix on PATHINFO installs).
			$slug_path = $home_path . '/' . DPT_HL_Settings::permalink_prefix() . DPT_HL_Settings::slug();
			$match     = untrailingslashit( $path ) === untrailingslashit( $slug_path );
		} else {
			// Plain permalinks: the slug is a query key, but only the home
			// URL form (/?slug) is the login endpoint - a normal page like
			// /some-page/?login=1 must not be hijacked.
			$match = isset( $_GET[ DPT_HL_Settings::slug() ] ) && untrailingslashit( $path ) === $home_path;
		}

		if ( $match ) {
			$this->is_new_login = true;
			$pagenow            = 'wp-login.php';
		}
	}

	/**
	 * Serve the login page on the custom slug, block the old entry points.
	 */
	public function route_request() {
		global $pagenow;

		// Logged-out wp-admin never gets the usual redirect to the (now
		// hidden) login page. admin-ajax/admin-post/cron keep working.
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! wp_doing_cron() && 'admin-post.php' !== $pagenow ) {
			wp_safe_redirect( $this->blocked_url() );
			exit;
		}

		if ( $this->is_new_login && 'wp-login.php' === $pagenow ) {
			// wp-login.php expects these globals in scope.
			global $error, $interim_login, $action, $user_login;
			require_once ABSPATH . 'wp-login.php';
			exit;
		}

		if ( $this->is_old_login ) {
			$this->render_404_and_exit();
		}
	}

	/**
	 * Rewrite any generated wp-login.php URL to the custom slug, keeping
	 * the query string (action, redirect_to, resetpass keys, ...).
	 *
	 * The existing query substring is carried over verbatim rather than
	 * parsed and rebuilt: WordPress already encoded it, and decoding then
	 * re-encoding through add_query_arg() (which does not encode values)
	 * would corrupt nested query strings such as redirect_to targets.
	 */
	public function filter_login_url( $url, $path = '' ) {
		if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		// Preserve the scheme WordPress chose for this URL - it may have
		// forced https (FORCE_SSL_ADMIN) even on a non-SSL request; deriving
		// it from is_ssl() would downgrade the rewritten login URL to http.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$base   = DPT_HL_Settings::new_login_url( $scheme ? $scheme : null );
		$parts  = explode( '?', $url, 2 );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$sep = ( false !== strpos( $base, '?' ) ) ? '&' : '?';
			return $base . $sep . $parts[1];
		}
		return $base;
	}

	/**
	 * Multisite welcome email contains a hardcoded BLOG_URLwp-login.php
	 * link; swap in the slug in the same relative form new_login_url()
	 * would produce (pretty path or plain ?slug), so the link routes on
	 * plain-permalink sites too.
	 */
	public function filter_welcome_email( $value ) {
		return is_string( $value )
			? str_replace( 'wp-login.php', DPT_HL_Settings::login_relative(), $value )
			: $value;
	}

	/**
	 * Where blocked wp-admin requests land: a front-end URL that resolves
	 * to the theme's 404 page.
	 */
	private function blocked_url() {
		return home_url( user_trailingslashit( '404' ) );
	}

	/**
	 * Render the theme's real 404 template with a 404 status by running the
	 * main query against a path that cannot exist.
	 */
	private function render_404_and_exit() {
		global $pagenow;
		$pagenow                = 'index.php';
		$_SERVER['REQUEST_URI'] = user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}
		wp();
		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}
}
