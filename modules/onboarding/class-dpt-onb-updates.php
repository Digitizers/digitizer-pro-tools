<?php
/**
 * Onboarding module - update information for the baseline's GitHub items.
 *
 * WordPress learns about updates for a plugin from the directory, or from a
 * plugin that checks for itself. The baseline holds items that are neither:
 * they come from GitHub, and the wizard installs most of them inactive, so
 * their own code never runs and nothing on the site would ever look for a new
 * version. This fills that gap from a plugin that is running.
 *
 * It reports; it does not install. WordPress installs an update when the
 * operator presses the button, or unattended when the item is on the
 * auto_update_plugins list the wizard's checkbox writes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Updates {

	/**
	 * Register the transient filters.
	 */
	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'plugins' ) );
		add_filter( 'site_transient_update_themes', array( __CLASS__, 'themes' ) );
	}

	/**
	 * Whether this request may talk to GitHub.
	 *
	 * The update transients are read on the front end too, and a lookup there
	 * would put a network round trip in front of a visitor's page load and
	 * spend a rate limit nobody asked to spend. Front-end requests are served
	 * from whatever is already cached, which is what WordPress's own update
	 * checks do.
	 *
	 * @return bool
	 */
	public static function may_fetch() {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The manifest items this can answer for: installed, from GitHub, of the
	 * given type.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @return array
	 */
	public static function items_of_type( $type ) {
		$out = array();
		foreach ( DPT_ONB_Manifest::items() as $item ) {
			if ( ! isset( $item['type'], $item['source'] ) ) {
				continue;
			}
			if ( $item['type'] === $type && 'github' === $item['source'] ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Whether a release is worth offering over what is installed.
	 *
	 * Pure. Guards the two ways this could damage a site: an empty or
	 * unreadable version on either side is not a comparison, and a release
	 * older than what is installed is a downgrade wearing an update's clothes.
	 *
	 * @param string $installed Version currently on disk.
	 * @param mixed  $release   Release array from DPT_ONB_Source::github_release().
	 * @return bool
	 */
	public static function is_newer( $installed, $release ) {
		if ( ! is_array( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return false;
		}
		if ( '' === (string) $installed ) {
			return false;
		}
		return version_compare( (string) $release['version'], (string) $installed, '>' );
	}

	/**
	 * The update record for one item, or null when there is nothing to offer.
	 *
	 * Pure, so every shape can be tested without WordPress: an item with no
	 * release, a release that matches what is installed, a release behind it.
	 *
	 * @param array  $item      Manifest item.
	 * @param string $file      Plugin file or theme stylesheet.
	 * @param string $installed Installed version.
	 * @param mixed  $release   Release array, or a WP_Error.
	 * @return array|null
	 */
	public static function entry_for( $item, $file, $installed, $release ) {
		if ( ! self::is_newer( $installed, $release ) ) {
			return null;
		}
		return array(
			'slug'        => $item['slug'],
			'file'        => $file,
			'new_version' => (string) $release['version'],
			'package'     => (string) $release['package'],
			'url'         => 'https://github.com/' . $item['repo'],
		);
	}

	/**
	 * Release information for an item, cached lookups only unless this request
	 * may fetch.
	 *
	 * @param array $item Manifest item.
	 * @return array|WP_Error
	 */
	private static function release( $item ) {
		if ( ! self::may_fetch() ) {
			// A cached answer still comes back through the transient; an
			// uncached one would become a request, so it is refused here.
			$cached = get_transient( DPT_ONB_Source::RELEASE_PREFIX . md5( (string) $item['repo'] ) );
			if ( is_array( $cached ) && ! isset( $cached['error'] ) ) {
				return $cached;
			}
			return new WP_Error( 'dpt_onb_no_fetch', 'Not fetching outside the admin.' );
		}
		return DPT_ONB_Source::github_release( $item['repo'] );
	}

	/**
	 * Add update information for the baseline's GitHub plugins.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function plugins( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();

		foreach ( self::items_of_type( 'plugin' ) as $item ) {
			$file = DPT_ONB_State::plugin_file( $item['slug'] );
			if ( null === $file || empty( $installed[ $file ]['Version'] ) ) {
				continue;
			}
			// Never argue with an answer that is already there. The plugin's
			// own checker fills this in when it is active, and WordPress.org
			// would fill it in if the plugin were ever listed there; both know
			// more about it than the manifest does.
			if ( isset( $transient->response[ $file ] ) || isset( $transient->no_update[ $file ] ) ) {
				continue;
			}

			$release = self::release( $item );
			$entry   = is_wp_error( $release )
				? null
				: self::entry_for( $item, $file, $installed[ $file ]['Version'], $release );

			if ( null === $entry ) {
				// Recorded as "up to date" rather than left out. WordPress
				// decides from no_update whether a plugin can be put on
				// automatic updates at all, so an item missing from both lists
				// is shown as one updates are unavailable for - which is
				// exactly what the wizard's checkbox promised to change.
				$transient->no_update[ $file ] = self::record( $item, $file, $installed[ $file ]['Version'] );
				continue;
			}

			$transient->response[ $file ] = self::record( $item, $file, $entry['new_version'], $entry['package'] );
		}

		return $transient;
	}

	/**
	 * Add update information for the baseline's GitHub themes.
	 *
	 * Themes carry the same two lists, as arrays rather than objects.
	 *
	 * @param mixed $transient The update_themes site transient.
	 * @return mixed
	 */
	public static function themes( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		foreach ( self::items_of_type( 'theme' ) as $item ) {
			$theme = wp_get_theme( $item['slug'] );
			if ( ! $theme->exists() ) {
				continue;
			}
			$version = (string) $theme->get( 'Version' );
			if ( '' === $version ) {
				continue;
			}
			if ( isset( $transient->response[ $item['slug'] ] ) || isset( $transient->no_update[ $item['slug'] ] ) ) {
				continue;
			}

			$release = self::release( $item );
			$entry   = is_wp_error( $release )
				? null
				: self::entry_for( $item, $item['slug'], $version, $release );

			if ( null === $entry ) {
				$transient->no_update[ $item['slug'] ] = self::theme_record( $item, $version );
				continue;
			}

			$transient->response[ $item['slug'] ] = self::theme_record( $item, $entry['new_version'], $entry['package'] );
		}

		return $transient;
	}

	/**
	 * One plugin record in the shape WordPress reads.
	 *
	 * @param array  $item    Manifest item.
	 * @param string $file    Plugin file.
	 * @param string $version Version this record reports.
	 * @param string $package Archive to install, empty for a no_update record.
	 * @return object
	 */
	private static function record( $item, $file, $version, $package = '' ) {
		return (object) array(
			'id'          => 'github.com/' . $item['repo'],
			'slug'        => $item['slug'],
			'plugin'      => $file,
			'new_version' => (string) $version,
			'url'         => 'https://github.com/' . $item['repo'],
			'package'     => (string) $package,
			'icons'       => array(),
			'banners'     => array(),
			'banners_rtl' => array(),
		);
	}

	/**
	 * One theme record in the shape WordPress reads.
	 *
	 * @param array  $item    Manifest item.
	 * @param string $version Version this record reports.
	 * @param string $package Archive to install, empty for a no_update record.
	 * @return array
	 */
	private static function theme_record( $item, $version, $package = '' ) {
		return array(
			'theme'       => $item['slug'],
			'new_version' => (string) $version,
			'url'         => 'https://github.com/' . $item['repo'],
			'package'     => (string) $package,
		);
	}
}
