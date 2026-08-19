<?php
/**
 * Onboarding module - applying one baseline item.
 *
 * The decision logic is separated from the WordPress calls so it can be tested
 * without a filesystem: action_for(), may_activate_theme() and
 * desired_source_path() are pure.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Installer {

	/**
	 * Default theme slugs shipped by WordPress. A site still running one of
	 * these has not had its design chosen yet, which is the only situation in
	 * which switching the theme is safe.
	 */
	const DEFAULT_THEMES = array(
		'twentyten',
		'twentyeleven',
		'twentytwelve',
		'twentythirteen',
		'twentyfourteen',
		'twentyfifteen',
		'twentysixteen',
		'twentyseventeen',
		'twentynineteen',
		'twentytwenty',
		'twentytwentyone',
		'twentytwentytwo',
		'twentytwentythree',
		'twentytwentyfour',
		'twentytwentyfive',
		'twentytwentysix',
	);

	/**
	 * What to do about an item in a given state.
	 *
	 * An unrecognised state skips rather than installs: the fail-safe here is
	 * to do nothing to a site we cannot describe.
	 *
	 * @param string $state One of the DPT_ONB_State constants.
	 * @return string 'install', 'activate' or 'skip'.
	 */
	public static function action_for( $state ) {
		if ( DPT_ONB_State::MISSING === $state ) {
			return 'install';
		}
		if ( DPT_ONB_State::INACTIVE === $state ) {
			return 'activate';
		}
		// ACTIVE and PRESENT are both goal states, and anything unrecognised
		// falls here too.
		return 'skip';
	}

	/**
	 * Whether the child theme may be activated automatically.
	 *
	 * Activating a theme changes what every visitor sees. A site already
	 * running a chosen theme must never have it swapped out by a tool the
	 * operator ran to install plugins, so this is true only while the site is
	 * still on a WordPress default.
	 *
	 * A hardcoded list of default themes always lags the next WordPress
	 * release, and lagging fails in the direction that breaks the main flow:
	 * a genuinely fresh site would be mistaken for one with a chosen design,
	 * and the wizard would refuse to activate the child theme. So the caller
	 * also passes WP_DEFAULT_THEME, which core defines as the theme that
	 * version bundles.
	 *
	 * That constant is only a hint, never proof. A site may redefine it in
	 * wp-config.php to any theme at all, and hosts do. So the fallback demands
	 * two independent signals before it will call an unlisted theme a core
	 * default: core's naming convention, and core's authorship in the theme's
	 * own style.css. A name alone is not enough - "twentyagency" is a
	 * perfectly ordinary name for an agency's theme, and treating it as a
	 * default would replace a design someone chose.
	 *
	 * The two failure directions are not symmetric. Refusing a genuine default
	 * costs the operator one manual switch under Appearance > Themes, which the
	 * summary already tells them to do. Accepting a custom theme replaces a
	 * live site's design. When in doubt this returns false.
	 *
	 * The authorship check applies to the allowlisted slugs as well, not only
	 * to the WP_DEFAULT_THEME fallback: a host can ship its own design in a
	 * directory named twentytwentyfour, and the directory name alone would then
	 * hand it over as a replaceable default.
	 *
	 * Its limits are worth stating. It catches a bundled theme that was
	 * replaced, because a different theme carries a different author. It does
	 * not catch one that was edited in place, since an edit rarely touches the
	 * Author header. For that case the operator's remedy is the checklist -
	 * untick the theme rows and the wizard will not touch the design at all.
	 *
	 * @param string $current_stylesheet Active theme slug.
	 * @param string $current_author     Author header of the active theme.
	 * @param string $bundled_default    WP_DEFAULT_THEME, or '' when undefined.
	 * @return bool
	 */
	public static function may_activate_theme( $current_stylesheet, $current_author = '', $bundled_default = '' ) {
		if ( ! self::authored_by_core( $current_author ) ) {
			return false;
		}
		if ( in_array( $current_stylesheet, self::DEFAULT_THEMES, true ) ) {
			return true;
		}
		return '' !== $bundled_default
			&& $current_stylesheet === $bundled_default
			&& self::looks_like_core_theme( $bundled_default );
	}

	/**
	 * Whether a slug carries core's naming convention for a bundled theme.
	 *
	 * Every default theme WordPress has shipped since 2010 is named "twenty"
	 * followed by the year in words, with no separators or digits. Necessary
	 * but not sufficient - see authored_by_core().
	 *
	 * @param string $slug Theme slug.
	 * @return bool
	 */
	public static function looks_like_core_theme( $slug ) {
		return 1 === preg_match( '/^twenty[a-z]+$/', (string) $slug );
	}

	/**
	 * Whether a theme's Author header is the one core's own themes carry.
	 *
	 * Every bundled theme declares "the WordPress team". A third-party theme
	 * would have to claim WordPress's authorship in its own style.css to pass,
	 * which is a considerably higher bar than choosing a name.
	 *
	 * @param string $author Theme Author header.
	 * @return bool
	 */
	public static function authored_by_core( $author ) {
		return 'the wordpress team' === strtolower( trim( (string) $author ) );
	}

	/**
	 * Author header of a theme, or '' when it is not installed.
	 *
	 * @param string $slug Theme slug.
	 * @return string
	 */
	public static function theme_author( $slug ) {
		if ( '' === (string) $slug ) {
			return '';
		}
		$theme = wp_get_theme( $slug );
		return $theme->exists() ? (string) $theme->get( 'Author' ) : '';
	}

	/**
	 * Whether a theme is installed and free of the errors that would make it
	 * unusable - a missing template, an unreadable stylesheet, an absent
	 * parent.
	 *
	 * exists() only reports that a directory with a stylesheet is there. A
	 * theme can satisfy that and still be broken, and switching to a broken
	 * theme takes the public site down.
	 *
	 * @param string $slug Theme slug.
	 * @return bool
	 */
	public static function theme_is_usable( $slug ) {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return false;
		}
		$errors = $theme->errors();
		return ! is_wp_error( $errors );
	}

	/**
	 * The theme slug this WordPress ships as its own default.
	 *
	 * @return string
	 */
	public static function bundled_default_theme() {
		return defined( 'WP_DEFAULT_THEME' ) ? (string) WP_DEFAULT_THEME : '';
	}

	/**
	 * The capability an item needs for the action its state implies.
	 *
	 * Requiring install_plugins for everything would refuse to activate a
	 * plugin that is already on disk - which is exactly the case on a site
	 * running DISALLOW_FILE_MODS, and on a role granted activate_plugins
	 * without the right to install new code.
	 *
	 * @param array  $item  Manifest item.
	 * @param string $state One of the DPT_ONB_State constants.
	 * @return string Capability name, or '' when the action needs none.
	 */
	public static function capability_for( $item, $state ) {
		$action  = self::action_for( $state );
		$is_theme = ( 'theme' === $item['type'] );
		if ( 'install' === $action ) {
			return $is_theme ? 'install_themes' : 'install_plugins';
		}
		if ( 'activate' === $action ) {
			return $is_theme ? 'switch_themes' : 'activate_plugins';
		}
		return '';
	}

	/**
	 * Where an extracted archive must be moved before it is installed.
	 *
	 * A GitHub zipball extracts to <owner>-<repo>-<sha>, which changes with
	 * every commit. Installed as-is, the plugin lands in a different directory
	 * each time: WordPress sees a new plugin, the previous copy is orphaned and
	 * still loaded, and the site ends up running two versions at once.
	 *
	 * @param string $source Directory the archive extracted to (trailing slash).
	 * @param string $slug   Directory it must become.
	 * @return string
	 */
	public static function desired_source_path( $source, $slug ) {
		return trailingslashit( dirname( untrailingslashit( $source ) ) ) . $slug . '/';
	}

	/**
	 * Apply one item by id.
	 *
	 * @param string $item_id Manifest item id.
	 * @return array id, outcome ('installed'|'activated'|'skipped'|'failed'), message.
	 */
	public static function apply( $item_id ) {
		$item = DPT_ONB_Manifest::get( $item_id );
		if ( null === $item ) {
			return self::result( $item_id, 'failed', __( 'That item is not part of the baseline.', 'digitizer-pro-tools' ) );
		}

		$state  = DPT_ONB_State::of( $item );
		$action = self::action_for( $state );

		if ( 'skip' === $action ) {
			// The message replaces the row's status text, so it has to be as
			// true as the status it overwrites. An install-only item that is
			// already present was never activated, and saying "already active"
			// here would put that claim back on screen - which is the whole
			// reason PRESENT exists as a state of its own.
			return self::result(
				$item_id,
				'skipped',
				DPT_ONB_State::PRESENT === $state
					? __( 'Already installed.', 'digitizer-pro-tools' )
					: __( 'Already active.', 'digitizer-pro-tools' )
			);
		}

		if ( 'install' === $action ) {
			$installed = self::install( $item );
			if ( is_wp_error( $installed ) ) {
				return self::result( $item_id, 'failed', $installed->get_error_message() );
			}
		}

		$activated = self::activate( $item );
		if ( is_wp_error( $activated ) ) {
			// Reported as a failure on purpose. Calling a half-applied item a
			// success leaves the summary claiming a plugin is in place that is
			// not running.
			return self::result(
				$item_id,
				'failed',
				'install' === $action
					? sprintf(
						/* translators: %s: the underlying error */
						__( 'Installed, but could not be activated: %s', 'digitizer-pro-tools' ),
						$activated->get_error_message()
					)
					: $activated->get_error_message()
			);
		}

		// Only reachable after an install: an install-only item that was
		// already present is skipped above.
		if ( 'present' === $activated ) {
			return self::result( $item_id, 'installed', __( 'Installed.', 'digitizer-pro-tools' ) );
		}

		if ( 'deferred' === $activated ) {
			// A deferral after an install did install something. A deferral on
			// an item that was already on disk did nothing at all, and calling
			// that an installation would record a fresh success on every run.
			return 'install' === $action
				? self::result(
					$item_id,
					'installed',
					__( 'Installed. Not activated, because this site already uses a custom theme - switch it under Appearance > Themes when you are ready.', 'digitizer-pro-tools' )
				)
				: self::result(
					$item_id,
					'skipped',
					__( 'Already installed. Not activated, because this site already uses a custom theme - switch it under Appearance > Themes when you are ready.', 'digitizer-pro-tools' )
				);
		}

		return self::result(
			$item_id,
			'install' === $action ? 'installed' : 'activated',
			'install' === $action ? __( 'Installed and activated.', 'digitizer-pro-tools' ) : __( 'Activated.', 'digitizer-pro-tools' )
		);
	}

	/**
	 * Download and unpack one item.
	 *
	 * @param array $item Manifest item.
	 * @return true|WP_Error
	 */
	private static function install( $item ) {
		$url = DPT_ONB_Source::zip_url( $item );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin = new WP_Ajax_Upgrader_Skin();

		// GitHub archives extract to a directory named after the commit, so
		// the extracted folder has to be renamed before it is copied into
		// place. The filter is attached for this one install and detached
		// immediately, so it can never rename an archive belonging to some
		// other plugin's install running in the same request.
		// The upgrader fetches the archive itself, in a request of its own that
		// would otherwise carry WordPress's default agent - and that agent
		// embeds the site URL. Setting it on the release lookup alone leaves
		// the address disclosed on the download, which is the request that
		// actually happens for every install.
		$anonymize = null;
		if ( 'github' === $item['source'] ) {
			$anonymize = array( 'DPT_ONB_Source', 'anonymize_request' );
			add_filter( 'http_request_args', $anonymize, 10, 2 );
		}

		$rename = null;
		if ( 'github' === $item['source'] ) {
			$slug   = $item['slug'];
			$rename = function ( $source, $remote_source, $upgrader, $args = array() ) use ( $slug ) {
				global $wp_filesystem;
				$desired = DPT_ONB_Installer::desired_source_path( $source, $slug );
				if ( $source === $desired ) {
					return $source;
				}
				if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
					return new WP_Error(
						'dpt_onb_rename_failed',
						__( 'Could not normalise the downloaded folder name.', 'digitizer-pro-tools' )
					);
				}
				return $desired;
			};
			add_filter( 'upgrader_source_selection', $rename, 10, 4 );
		}

		if ( 'theme' === $item['type'] ) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			$upgrader = new Plugin_Upgrader( $skin );
		}

		$result = $upgrader->install( $url );

		if ( null !== $rename ) {
			remove_filter( 'upgrader_source_selection', $rename, 10 );
		}
		if ( null !== $anonymize ) {
			remove_filter( 'http_request_args', $anonymize, 10 );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->get_error_message() ) {
				return $errors;
			}
			return new WP_Error( 'dpt_onb_install_failed', __( 'The installation did not complete. The site may not be able to write to its own files - see FS_METHOD.', 'digitizer-pro-tools' ) );
		}
		return true;
	}

	/**
	 * Activate one installed item.
	 *
	 * @param array $item Manifest item.
	 * @return true|string|WP_Error True, the string 'deferred' when a theme was
	 *                              deliberately left inactive, or an error.
	 */
	private static function activate( $item ) {
		// An install-only item is finished once it is present. Saying it was
		// activated would be a lie the summary repeats on every run.
		if ( isset( $item['activate'] ) && false === $item['activate'] ) {
			return 'present';
		}

		if ( 'theme' === $item['type'] ) {
			$current = get_stylesheet();
			if ( ! self::may_activate_theme( $current, self::theme_author( $current ), self::bundled_default_theme() ) ) {
				return 'deferred';
			}
			// Switching to a child theme whose parent is absent leaves the
			// public site with no template at all. The parent can be missing
			// for ordinary reasons - the operator unticked it, or its own
			// install failed earlier in a run that deliberately does not stop
			// on failure - so this is checked here rather than assumed from
			// the manifest order.
			if ( ! empty( $item['parent'] ) && ! self::theme_is_usable( $item['parent'] ) ) {
				return new WP_Error(
					'dpt_onb_parent_missing',
					sprintf(
						/* translators: %s: parent theme slug */
						__( 'Installed, but not activated: its parent theme (%s) is missing or unusable, and switching to it would leave the site without a template.', 'digitizer-pro-tools' ),
						$item['parent']
					)
				);
			}
			// The theme we are about to make public has to be usable itself,
			// not merely present on disk.
			if ( ! self::theme_is_usable( $item['slug'] ) ) {
				return new WP_Error( 'dpt_onb_theme_broken', __( 'Installed, but not activated: WordPress reports the theme as broken, and activating it would take the site down.', 'digitizer-pro-tools' ) );
			}
			if ( ! current_user_can( 'switch_themes' ) ) {
				return new WP_Error( 'dpt_onb_cannot_switch', __( 'You are not allowed to switch themes on this site.', 'digitizer-pro-tools' ) );
			}
			switch_theme( $item['slug'] );
			// Confirm it took. switch_theme() returns nothing, and reporting a
			// switch that silently did not happen is worse than reporting a
			// failure.
			if ( get_stylesheet() !== $item['slug'] ) {
				return new WP_Error( 'dpt_onb_switch_failed', __( 'The theme switch did not take effect.', 'digitizer-pro-tools' ) );
			}
			return true;
		}

		// Checked here rather than only at the endpoint, because installing and
		// activating are two permissions and a missing plugin needs both:
		// activate_plugin() performs no capability check of its own, so a role
		// granted install_plugins but deliberately not activate_plugins would
		// otherwise end up activating everything it installed. The theme branch
		// above checks switch_themes for the same reason.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'dpt_onb_cannot_activate', __( 'You are not allowed to activate plugins on this site.', 'digitizer-pro-tools' ) );
		}

		$file = DPT_ONB_State::plugin_file( $item['slug'] );
		if ( null === $file ) {
			return new WP_Error( 'dpt_onb_not_found_after_install', __( 'The plugin is not where it was expected after installation.', 'digitizer-pro-tools' ) );
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$activated = activate_plugin( $file );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		// Confirm it stayed active, the same way the theme switch is confirmed
		// above. A plugin that finds an unmet prerequisite in its activation
		// hook deactivates itself from inside that hook, and activate_plugin()
		// still reports success - so the row would claim an activation the
		// next run contradicts. Directly relevant to this baseline: several of
		// its items depend on another item in the same list, which the operator
		// is free to untick.
		if ( ! is_plugin_active( $file ) ) {
			return new WP_Error(
				'dpt_onb_did_not_stay_active',
				__( 'It was activated but switched itself off again, which usually means it needs something this site does not have yet.', 'digitizer-pro-tools' )
			);
		}
		return true;
	}

	/**
	 * Shape one result.
	 *
	 * @param string $id      Item id.
	 * @param string $outcome installed|activated|skipped|failed.
	 * @param string $message Human-readable detail.
	 * @return array
	 */
	private static function result( $id, $outcome, $message ) {
		return array(
			'id'      => $id,
			'outcome' => $outcome,
			'message' => $message,
		);
	}
}
