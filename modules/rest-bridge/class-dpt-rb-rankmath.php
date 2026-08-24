<?php
/**
 * REST Bridge - Rank Math's SEO fields on the REST API.
 *
 * Rank Math stores these as ordinary post meta and does not expose them to
 * REST itself, so anything writing SEO metadata over the API needs them
 * registered. Pages get them as well as posts: the plugin this replaces
 * registered posts only, which left every landing page unreachable.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the Rank Math meta keys for REST.
 */
class DPT_RB_Rankmath {

	/**
	 * Whether Rank Math is running here. A separate method, rather than a
	 * check folded into register(), so the info endpoint can report the
	 * same fact without registering anything.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'RankMath' );
	}

	/**
	 * The keys, and what each one is.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'rank_math_title'               => __( 'SEO title override', 'digitizer-pro-tools' ),
			'rank_math_description'         => __( 'SEO meta description', 'digitizer-pro-tools' ),
			'rank_math_focus_keyword'       => __( 'Focus keyword(s)', 'digitizer-pro-tools' ),
			'rank_math_robots'              => __( 'Robot meta directives', 'digitizer-pro-tools' ),
			'rank_math_canonical_url'       => __( 'Canonical URL override', 'digitizer-pro-tools' ),
			'rank_math_primary_category'    => __( 'Primary category ID', 'digitizer-pro-tools' ),
			'rank_math_seo_score'           => __( 'SEO score (0-100)', 'digitizer-pro-tools' ),
			'rank_math_og_title'            => __( 'Open Graph title', 'digitizer-pro-tools' ),
			'rank_math_og_description'      => __( 'Open Graph description', 'digitizer-pro-tools' ),
			'rank_math_og_image'            => __( 'Open Graph image URL', 'digitizer-pro-tools' ),
			'rank_math_twitter_title'       => __( 'Twitter card title', 'digitizer-pro-tools' ),
			'rank_math_twitter_description' => __( 'Twitter card description', 'digitizer-pro-tools' ),
		);
	}

	/**
	 * Register them, when there is something to register them for. Rank
	 * Math itself never calls show_in_rest on these keys, so without this
	 * they are invisible to the REST API no matter how the rest of the
	 * bridge is configured.
	 */
	public static function register() {
		if ( ! self::active() ) {
			return;
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			foreach ( self::fields() as $key => $description ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'string',
						'description'   => $description,
						'auth_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}
}
