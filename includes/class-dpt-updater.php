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
		// setBranch(): that would track a branch head instead of releases. Left
		// at the default, the checker offers the latest tagged GitHub Release,
		// so only a published Release (not every push to main) ships an update.
		$api = ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) ? $checker->getVcsApi() : null;
		if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
			// If a Release attaches a built plugin .zip named like the plugin,
			// prefer it; otherwise the checker falls back to the tag's source
			// archive. The pattern matches "digitizer-pro-tools*.zip".
			$api->enableReleaseAssets( '/digitizer-pro-tools.*\.zip/i' );
		}

		self::$checker = $checker;
		return $checker;
	}
}
