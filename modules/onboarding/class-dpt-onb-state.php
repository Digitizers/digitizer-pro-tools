<?php
/**
 * Onboarding module - what the site already has.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_State {

	const MISSING  = 'missing';
	const INACTIVE = 'inactive';
	const ACTIVE   = 'active';

	/**
	 * Installed, and that is all this item needs - the parent theme. Distinct
	 * from ACTIVE so the wizard does not tell the operator a theme is running
	 * the site when it is not.
	 */
	const PRESENT = 'present';

	/**
	 * The installed plugin whose directory is $slug.
	 *
	 * Matching is on the directory, not on the file name: a plugin's main file
	 * is not reliably <slug>/<slug>.php - Rank Math installs as
	 * seo-by-rank-math/rank-math.php - and guessing the file name reports a
	 * present plugin as missing, which would make the wizard install it a
	 * second time.
	 *
	 * @param string $slug Plugin directory.
	 * @return string|null The dir/file.php key, or null when not installed.
	 */
	public static function plugin_file( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug || '.' === $slug ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			// A single-file plugin lives at the plugins root, where dirname()
			// is '.', and belongs to no slug.
			$dir = dirname( $file );
			if ( '.' !== $dir && $dir === $slug ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * State of one manifest item.
	 *
	 * @param array $item Manifest item.
	 * @return string One of the class constants.
	 */
	public static function of( $item ) {
		// For an install-only item, being present IS the goal state. Reporting
		// it as inactive would make the wizard "activate" it on every run - it
		// would never converge, and the row would claim an activation that
		// never happened. An item the operator has since switched on by hand
		// still reports as active, because it is.
		$install_only = ( isset( $item['activate'] ) && false === $item['activate'] );

		if ( 'theme' === $item['type'] ) {
			$theme = wp_get_theme( $item['slug'] );
			if ( ! $theme->exists() ) {
				return self::MISSING;
			}
			if ( get_stylesheet() === $item['slug'] ) {
				return self::ACTIVE;
			}
			return $install_only ? self::PRESENT : self::INACTIVE;
		}

		$file = self::plugin_file( $item['slug'] );
		if ( null === $file ) {
			return self::MISSING;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( $file ) ) {
			return self::ACTIVE;
		}
		return $install_only ? self::PRESENT : self::INACTIVE;
	}

	/**
	 * State of every manifest item, in manifest order.
	 *
	 * @return array item id => state
	 */
	public static function all() {
		$out = array();
		foreach ( DPT_ONB_Manifest::items() as $item ) {
			$out[ $item['id'] ] = self::of( $item );
		}
		return $out;
	}
}
