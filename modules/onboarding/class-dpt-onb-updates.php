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
	 * Whether the current call is allowed to ask GitHub anything.
	 *
	 * @var bool
	 */
	private static $fetching = false;

	/**
	 * Register the filters.
	 */
	public static function init() {
		// Reading is cache-only, always. These fire on every request that
		// touches the transients, the front end included.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'plugins' ) );
		add_filter( 'site_transient_update_themes', array( __CLASS__, 'themes' ) );

		// Fetching happens here and nowhere else: this is WordPress finishing
		// an update check of its own, which is already a network operation the
		// caller expects to wait for.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'refresh_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'refresh_themes' ) );

		// An update we reported is downloaded by core, in a request of its own
		// that would otherwise carry WordPress's default user agent - and that
		// agent embeds the site URL.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'before_package_download' ), 10, 2 );

		// A GitHub archive's top-level directory is not reliably the plugin's
		// own slug, and core installs an update into whatever the archive is
		// called. Left alone, an update can land beside the plugin instead of
		// replacing it.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_source' ), 10, 4 );
	}

	/**
	 * Whether this call may talk to GitHub.
	 *
	 * False on an ordinary transient read - the front end included, where a
	 * lookup would put a network round trip in front of a visitor's page and
	 * spend a rate limit nobody asked to spend. True only inside WordPress's
	 * own update check.
	 *
	 * @return bool
	 */
	public static function may_fetch() {
		return self::$fetching;
	}

	/**
	 * Refresh the plugin lookups while WordPress runs an update check.
	 *
	 * Deliberately returns the value untouched. What this module knows is
	 * added when the transient is read, not written into what WordPress
	 * stores, so nothing it reported outlives it: disable the module and the
	 * offers disappear with the same request. An entry persisted into the
	 * stored transient would still be installable after the module - and with
	 * it the archive-renaming and user-agent hooks - had gone.
	 *
	 * @param mixed $value The transient value being stored.
	 * @return mixed
	 */
	public static function refresh_plugins( $value ) {
		self::warm( 'plugin' );
		return $value;
	}

	/**
	 * The same for themes.
	 *
	 * @param mixed $value The transient value being stored.
	 * @return mixed
	 */
	public static function refresh_themes( $value ) {
		self::warm( 'theme' );
		return $value;
	}

	/**
	 * Put every item of one type into the release cache, so the reads that
	 * follow have something to answer with.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @return void
	 */
	private static function warm( $type ) {
		self::$fetching = true;
		try {
			foreach ( self::items_of_type( $type ) as $item ) {
				self::release( $item );
			}
		} finally {
			self::$fetching = false;
		}
	}

	/**
	 * Attach the anonymised user agent when core is about to download one of
	 * the packages this module reported.
	 *
	 * Matched against the manifest's repositories, so an unrelated GitHub
	 * download in the same request is left alone. Passes the reply through
	 * untouched - it hooks, it does not answer.
	 *
	 * @param mixed  $reply   Short-circuit reply.
	 * @param string $package Package URL about to be downloaded.
	 * @return mixed
	 */
	public static function before_package_download( $reply, $package = '' ) {
		if ( ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		foreach ( DPT_ONB_Manifest::items() as $item ) {
			if ( empty( $item['repo'] ) ) {
				continue;
			}
			if ( false !== strpos( $package, '/' . $item['repo'] . '/' ) ) {
				add_filter( 'http_request_args', array( 'DPT_ONB_Source', 'anonymize_request' ), 10, 2 );
				break;
			}
		}
		return $reply;
	}

	/**
	 * Rename an extracted archive to the directory the item already occupies.
	 *
	 * Core installs an update into a directory named after whatever the
	 * archive unpacked to. A GitHub release asset is built by whoever tagged
	 * it and its top-level directory is not guaranteed to be the plugin's
	 * slug, so without this an update can be installed beside the plugin
	 * rather than over it - leaving the old copy in place and still loaded.
	 *
	 * Only ever renames for an item on the manifest, identified from the
	 * upgrader's own arguments, so an unrelated install in the same request is
	 * untouched.
	 *
	 * @param string|WP_Error $source        Directory the archive unpacked to.
	 * @param string          $remote_source Working directory it sits in.
	 * @param mixed           $upgrader      The upgrader instance.
	 * @param array           $args          Upgrader hook_extra.
	 * @return string|WP_Error
	 */
	public static function normalize_source( $source, $remote_source = '', $upgrader = null, $args = array() ) {
		if ( is_wp_error( $source ) || ! is_string( $source ) || ! is_array( $args ) ) {
			return $source;
		}
		$slug = self::slug_for_upgrade( $args );
		if ( null === $slug ) {
			return $source;
		}

		global $wp_filesystem;
		$desired = DPT_ONB_Installer::desired_source_path( $source, $slug );
		if ( $source === $desired ) {
			return $source;
		}
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $source, $desired, true ) ) {
			return new WP_Error(
				'dpt_onb_rename_failed',
				__( 'Could not normalise the downloaded folder name.', 'digitizer-pro-tools' )
			);
		}
		return $desired;
	}

	/**
	 * The manifest slug an upgrade is for, or null when it is not ours.
	 *
	 * Pure: the upgrader's hook_extra names either a plugin file or a theme
	 * stylesheet, and both are matched against the manifest's GitHub items.
	 *
	 * @param array $args Upgrader hook_extra.
	 * @param array $files Plugin files by manifest slug, for testing.
	 * @return string|null
	 */
	public static function slug_for_upgrade( $args, $files = null ) {
		if ( ! empty( $args['theme'] ) ) {
			foreach ( self::items_of_type( 'theme' ) as $item ) {
				if ( $item['slug'] === $args['theme'] ) {
					return $item['slug'];
				}
			}
			return null;
		}
		if ( empty( $args['plugin'] ) ) {
			return null;
		}
		foreach ( self::items_of_type( 'plugin' ) as $item ) {
			$file = ( null === $files )
				? DPT_ONB_State::plugin_file( $item['slug'] )
				: ( isset( $files[ $item['slug'] ] ) ? $files[ $item['slug'] ] : null );
			if ( null !== $file && $file === $args['plugin'] ) {
				return $item['slug'];
			}
		}
		return null;
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
		// Forced: this is the update check, and the point of it is to find out
		// what is published now, not to confirm what was published last time.
		return DPT_ONB_Source::github_release( $item['repo'], 8, true );
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
