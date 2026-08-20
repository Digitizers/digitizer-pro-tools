<?php
/**
 * Onboarding module - removing the default themes a finished site no longer
 * needs.
 *
 * Kept apart from the installer because it is the one destructive thing the
 * module does, and it should be readable on its own.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Cleanup {

	/**
	 * Which of the installed default themes may be deleted.
	 *
	 * Pure, so the rules can be tested without a filesystem. Each exists
	 * because of a way the site breaks without it:
	 *
	 * - Only themes that are core's own. The directory name is not proof of
	 *   that: a host or an operator can put an entirely different theme in a
	 *   directory called twentytwentyfour, and deleting it on the strength of
	 *   its name would destroy someone's work. So a candidate must also carry
	 *   core's authorship in its own style.css, the same two-signal test
	 *   DPT_ONB_Installer uses before it will replace a theme. A theme whose
	 *   author is unknown to the caller is left alone.
	 * - Never the active theme. Deleting what the site is running takes it
	 *   down immediately.
	 * - Never a theme another installed theme names as its parent. That
	 *   includes child themes we know nothing about: deleting the parent
	 *   leaves the child unusable.
	 * - Never WP_DEFAULT_THEME. That constant, not the newest slug in the
	 *   list below, is the theme core actually switches to when the active
	 *   theme becomes invalid, and the two disagree on any site running an
	 *   older WordPress with a newer default installed by hand.
	 * - Always keep the newest default that is present, as well. It is the
	 *   fallback on a site where WP_DEFAULT_THEME is undefined or points at
	 *   something that is not installed. Keeping one theme too many costs a
	 *   few megabytes; keeping none can stop the site.
	 *
	 * @param array  $installed       Slugs of installed themes.
	 * @param string $active          Active stylesheet.
	 * @param array  $parents         Template (parent) slugs installed themes declare.
	 * @param array  $authors         Map of slug => Author header, for the installed themes.
	 * @param string $bundled_default WP_DEFAULT_THEME, or '' when undefined.
	 * @return array Slugs to delete, oldest first.
	 */
	public static function removable( $installed, $active, $parents = array(), $authors = array(), $bundled_default = '' ) {
		$defaults = array();
		foreach ( DPT_ONB_Installer::DEFAULT_THEMES as $slug ) {
			if ( ! in_array( $slug, $installed, true ) ) {
				continue;
			}
			$author = isset( $authors[ $slug ] ) ? $authors[ $slug ] : '';
			if ( ! DPT_ONB_Installer::authored_by_core( $author ) ) {
				// A third-party theme wearing a core directory name, or one we
				// were told nothing about. Not ours to delete either way.
				continue;
			}
			$defaults[] = $slug;
		}
		if ( count( $defaults ) < 2 ) {
			// One or none: whatever is there is the fallback.
			return array();
		}

		$keep = end( $defaults );

		$out = array();
		foreach ( $defaults as $slug ) {
			if ( $slug === $keep || $slug === $active || $slug === $bundled_default || in_array( $slug, $parents, true ) ) {
				continue;
			}
			$out[] = $slug;
		}
		return $out;
	}

	/**
	 * Parent themes declared by the themes installed on this site.
	 *
	 * @return array
	 */
	public static function parents_in_use() {
		$parents = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$template = $theme->get_template();
			if ( $template && $template !== $slug ) {
				$parents[] = $template;
			}
		}
		return array_values( array_unique( $parents ) );
	}

	/**
	 * Delete the removable default themes.
	 *
	 * @return array List of array( slug, outcome, message ).
	 */
	public static function run() {
		if ( is_multisite() ) {
			// One directory of themes is shared by every site on a network, so
			// what is unused here can be the active theme, or the parent of the
			// active theme, somewhere else. Answering that means walking every
			// blog, and this module exists for single client sites - so it
			// declines rather than deleting on a partial view.
			return array(
				array(
					'slug'    => '',
					'outcome' => 'skipped',
					'message' => __( 'Themes are shared across a multisite network, so they are not removed from here.', 'digitizer-pro-tools' ),
				),
			);
		}

		if ( ! current_user_can( 'delete_themes' ) ) {
			return array(
				array(
					'slug'    => '',
					'outcome' => 'failed',
					'message' => __( 'This site does not allow deleting themes from the dashboard.', 'digitizer-pro-tools' ),
				),
			);
		}

		$installed = array();
		$authors   = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$installed[]      = $slug;
			$authors[ $slug ] = (string) $theme->get( 'Author' );
		}
		$removable = self::removable(
			$installed,
			get_stylesheet(),
			self::parents_in_use(),
			$authors,
			DPT_ONB_Installer::bundled_default_theme()
		);

		if ( ! $removable ) {
			return array(
				array(
					'slug'    => '',
					'outcome' => 'skipped',
					'message' => __( 'No default themes to remove.', 'digitizer-pro-tools' ),
				),
			);
		}

		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$results = array();
		foreach ( $removable as $slug ) {
			$deleted = delete_theme( $slug );
			if ( is_wp_error( $deleted ) ) {
				$results[] = array( 'slug' => $slug, 'outcome' => 'failed', 'message' => $deleted->get_error_message() );
				continue;
			}
			if ( true !== $deleted ) {
				$results[] = array(
					'slug'    => $slug,
					'outcome' => 'failed',
					'message' => __( 'The theme could not be removed.', 'digitizer-pro-tools' ),
				);
				continue;
			}
			$results[] = array(
				'slug'    => $slug,
				'outcome' => 'deleted',
				'message' => __( 'Removed.', 'digitizer-pro-tools' ),
			);
		}
		return $results;
	}
}
