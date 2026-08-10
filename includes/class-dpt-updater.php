<?php
/**
 * GitHub-based self-updater.
 *
 * Wires the bundled Plugin Update Checker library to this plugin's public GitHub
 * repository so updates appear on the normal WordPress Plugins / Dashboard >
 * Updates screens. Updates are pulled from tagged GitHub Releases: publish a
 * Release whose tag matches the new version (e.g. v1.15.0) and sites will be
 * offered the update on the next check.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Updater {

	/** Public repository the updates are pulled from. */
	const REPO = 'https://github.com/Digitizers/digitizer-pro-tools/';

	/** @var object|null The built update-checker instance. */
	private static $checker = null;

	/**
	 * Build the update checker for the given main plugin file.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return object|null The update checker, or null when unavailable.
	 */
	public static function init( $plugin_file ) {
		if ( null !== self::$checker ) {
			return self::$checker;
		}

		$loader = DPT_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return null;
		}
		require_once $loader;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return null;
		}

		$checker = call_user_func(
			array( $factory, 'buildUpdateChecker' ),
			self::REPO,
			$plugin_file,
			'digitizer-pro-tools'
		);

		// Public repo: no authentication needed. We deliberately do NOT call
		// setBranch(): that would track a branch head instead of releases.
		$api = ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) ? $checker->getVcsApi() : null;
		if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
			// If a Release attaches a built plugin .zip named like the plugin,
			// prefer it; otherwise the checker falls back to the tag's source
			// archive. The pattern matches "digitizer-pro-tools*.zip".
			$api->enableReleaseAssets( '/digitizer-pro-tools.*\.zip/i' );
		}

		// Enforce release-ONLY detection. By default the GitHub API strategy
		// falls back latest-release -> latest-tag -> branch, so merely pushing a
		// version tag (before its Release is published/approved) would already
		// offer the update. Keep only the latest-release strategy so an update is
		// offered strictly when a GitHub Release exists. 'latest_release' is the
		// stable strategy key (Vcs\Api::STRATEGY_LATEST_RELEASE) across PUC 5.x.
		if ( is_object( $checker ) && method_exists( $checker, 'getUniqueName' ) ) {
			add_filter(
				$checker->getUniqueName( 'vcs_update_detection_strategies' ),
				array( __CLASS__, 'release_only_strategies' )
			);
			// Send a neutral user agent. WordPress's default is
			// "WordPress/6.x; https://example.com", which would hand the site URL
			// to GitHub on every update check - the plugin's privacy disclosure
			// states that no site data leaves the site during the check, so make
			// that true rather than merely documented.
			add_filter(
				$checker->getUniqueName( 'request_info_options' ),
				array( __CLASS__, 'anonymous_request_options' )
			);
		}

		self::$checker = $checker;
		return $checker;
	}

	/**
	 * Reduce the VCS update-detection strategies to release-only, so a bare
	 * version tag without a published GitHub Release never triggers an update.
	 *
	 * @param array $strategies Strategy key => callable.
	 * @return array
	 */
	/**
	 * Replace the outgoing user agent for the update check.
	 *
	 * WordPress defaults to "WordPress/<version>; <site url>", so leaving it
	 * alone would disclose the site URL to GitHub on every check. GitHub's API
	 * still requires *some* user agent, so send the plugin's name and version.
	 *
	 * @param array $options wp_remote_get() options.
	 * @return array
	 */
	public static function anonymous_request_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$options['user-agent'] = 'digitizer-pro-tools/' . ( defined( 'DPT_VERSION' ) ? DPT_VERSION : '0' );
		return $options;
	}

	public static function release_only_strategies( $strategies ) {
		if ( is_array( $strategies ) && isset( $strategies['latest_release'] ) ) {
			return array( 'latest_release' => $strategies['latest_release'] );
		}
		return $strategies;
	}
}
