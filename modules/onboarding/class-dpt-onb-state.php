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
		if ( 'theme' === $item['type'] ) {
			$theme = wp_get_theme( $item['slug'] );
			if ( ! $theme->exists() ) {
				return self::MISSING;
			}
			return ( get_stylesheet() === $item['slug'] ) ? self::ACTIVE : self::INACTIVE;
		}

		$file = self::plugin_file( $item['slug'] );
		if ( null === $file ) {
			return self::MISSING;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $file ) ? self::ACTIVE : self::INACTIVE;
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
