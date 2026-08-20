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
	 * Pure, so the rules can be tested without a filesystem. Three of them,
	 * and each exists because of a way the site breaks without it:
	 *
	 * - Never the active theme. Deleting what the site is running takes it
	 *   down immediately.
	 * - Never a theme another installed theme names as its parent. That
	 *   includes child themes we know nothing about: deleting the parent
	 *   leaves the child unusable.
	 * - Always keep the newest default that is present. It is the fallback
	 *   WordPress switches to if the active theme ever becomes unusable.
	 *   Without one the site does not degrade, it stops.
	 *
	 * @param array  $installed Slugs of installed themes.
	 * @param string $active    Active stylesheet.
	 * @param array  $parents   Template (parent) slugs installed themes declare.
	 * @return array Slugs to delete, oldest first.
	 */
	public static function removable( $installed, $active, $parents = array() ) {
		$defaults = array();
		foreach ( DPT_ONB_Installer::DEFAULT_THEMES as $slug ) {
			if ( in_array( $slug, $installed, true ) ) {
				$defaults[] = $slug;
			}
		}
		if ( count( $defaults ) < 2 ) {
			// One or none: whatever is there is the fallback.
			return array();
		}

		$keep = end( $defaults );

		$out = array();
		foreach ( $defaults as $slug ) {
			if ( $slug === $keep || $slug === $active || in_array( $slug, $parents, true ) ) {
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
		if ( ! current_user_can( 'delete_themes' ) ) {
			return array(
				array(
					'slug'    => '',
					'outcome' => 'failed',
					'message' => __( 'This site does not allow deleting themes from the dashboard.', 'digitizer-pro-tools' ),
				),
			);
		}

		$installed = array_keys( wp_get_themes() );
		$removable = self::removable( $installed, get_stylesheet(), self::parents_in_use() );

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
