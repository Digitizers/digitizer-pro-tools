<?php
/**
 * REST Bridge - Rank Math's SEO fields on the REST API.
 *
 * Rank Math stores these as ordinary post meta and does not expose them to
 * REST itself, so anything writing SEO metadata over the API needs them
 * registered. Pages get them as well as posts: the plugin this replaces
 * registered posts only, which left every landing page unreachable.
 *
 * Names and types below were checked against Rank Math's own source rather
 * than assumed: the plugin this replaces shipped three Open Graph keys
 * (rank_math_og_*) that Rank Math has never read - it stores that data under
 * rank_math_facebook_* instead - and one array field (rank_math_robots)
 * declared as a string.
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
	 * The keys, what each one is, and the REST type it actually holds. The
	 * type is not decoration: a write is validated against it, so a wrong
	 * type either rejects a value Rank Math itself stores or accepts one it
	 * cannot read back.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'rank_math_title'                => array(
				'type'        => 'string',
				'description' => __( 'SEO title override', 'digitizer-pro-tools' ),
			),
			'rank_math_description'          => array(
				'type'        => 'string',
				'description' => __( 'SEO meta description', 'digitizer-pro-tools' ),
			),
			'rank_math_focus_keyword'        => array(
				'type'        => 'string',
				'description' => __( 'Focus keyword(s)', 'digitizer-pro-tools' ),
			),
			// Rank Math stores this as a list of directives (e.g. noindex),
			// not a single string - class-post-columns.php reads it with
			// FILTER_REQUIRE_ARRAY and every importer writes it as an array.
			'rank_math_robots'               => array(
				'type'        => 'array',
				'description' => __( 'Robot meta directives', 'digitizer-pro-tools' ),
			),
			'rank_math_canonical_url'        => array(
				'type'        => 'string',
				'description' => __( 'Canonical URL override', 'digitizer-pro-tools' ),
			),
			// A term id: Rank Math's editor sidebar parseInt()s it before
			// writing the hidden field that becomes this meta value.
			'rank_math_primary_category'     => array(
				'type'        => 'integer',
				'description' => __( 'Primary category term ID', 'digitizer-pro-tools' ),
			),
			// Rank Math's own REST route casts this to (int) before saving
			// it - class-admin.php's update_seo_score().
			'rank_math_seo_score'            => array(
				'type'        => 'integer',
				'description' => __( 'SEO score (0-100)', 'digitizer-pro-tools' ),
			),
			// Rank Math's Open Graph data lives under rank_math_facebook_*,
			// confirmed by wpml-config.xml and by every third-party importer
			// mapping other plugins' OG fields into these same keys. There is
			// no rank_math_og_* anywhere in Rank Math's source.
			'rank_math_facebook_title'       => array(
				'type'        => 'string',
				'description' => __( 'Open Graph title', 'digitizer-pro-tools' ),
			),
			'rank_math_facebook_description' => array(
				'type'        => 'string',
				'description' => __( 'Open Graph description', 'digitizer-pro-tools' ),
			),
			'rank_math_facebook_image'       => array(
				'type'        => 'string',
				'description' => __( 'Open Graph image URL', 'digitizer-pro-tools' ),
			),
			'rank_math_twitter_title'        => array(
				'type'        => 'string',
				'description' => __( 'Twitter card title', 'digitizer-pro-tools' ),
			),
			'rank_math_twitter_description'  => array(
				'type'        => 'string',
				'description' => __( 'Twitter card description', 'digitizer-pro-tools' ),
			),
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
			foreach ( self::fields() as $key => $field ) {
				$args = array(
					'show_in_rest'  => self::show_in_rest( $field['type'] ),
					'single'        => true,
					'type'          => $field['type'],
					'description'   => $field['description'],
					'auth_callback' => array( __CLASS__, 'may_edit_meta' ),
				);

				if ( 'array' === $field['type'] ) {
					$args['default'] = array();
				}

				register_post_meta( $post_type, $key, $args );
			}
		}
	}

	/**
	 * The show_in_rest argument for a type.
	 *
	 * Always a schema, and the schema always names the edit context. That is
	 * the only mechanism there is for keeping a registered meta key off an
	 * anonymous read, and without it these twelve were on one.
	 *
	 * The auth_callback below gates writes and nothing else:
	 * WP_REST_Meta_Fields::get_value() performs no capability check of any
	 * kind - unlike update_meta_value() and delete_meta_value(), which both
	 * do - so a registered key is read by whoever asks. The `meta` container's
	 * own schema is context view and edit, each key's schema carried no
	 * context at all, and rest_filter_response_by_context() leaves a property
	 * alone when its schema names none. So GET /wp/v2/posts returned the focus
	 * keyword, the SEO score and the canonical URL of every post and page on
	 * the site to callers with no authentication whatsoever. Rank Math has
	 * said in its own code what it thinks of that: Common::hide_rank_math_meta()
	 * filters is_protected_meta() to true for every rank_math_* key there is,
	 * unconditionally, on REST requests as much as anywhere else.
	 *
	 * Edit only, which is where core's own gate is: a request asking for that
	 * context has already had the post's update capability checked by the
	 * posts controller before a field is assembled. It is also the answer this
	 * module already gives for every field it discovers, on exactly the same
	 * reasoning - a module cannot tell a site's public content from its
	 * working notes, and SEO scoring is not rendered content by anyone's
	 * reckoning.
	 *
	 * A scalar would otherwise be describable with just `true`, core filling
	 * the schema in from `type`; an array never was, because without an
	 * explicit item schema WordPress refuses to expose it at all rather than
	 * expose it wrong. Both now carry a schema, and core merges it over the
	 * defaults it builds from `type`, so naming the context is all either one
	 * has to add.
	 *
	 * @param string $type REST schema type.
	 * @return array
	 */
	private static function show_in_rest( $type ) {
		$schema = array( 'context' => array( 'edit' ) );

		if ( 'array' === $type ) {
			$schema['type']  = 'array';
			$schema['items'] = array( 'type' => 'string' );
		}

		return array( 'schema' => $schema );
	}

	/**
	 * Whether this request may write to the post the meta key belongs to.
	 *
	 * register_post_meta()'s auth_callback is handed the object id
	 * specifically so a check can be scoped to it; discarding that id and
	 * checking only current_user_can( 'edit_posts' ) - a blanket "can this
	 * user edit *something*" - is the exact bug DPT_RB_Elementor::may_edit()
	 * exists to avoid on the Elementor endpoint. Doing it here instead would
	 * reopen the same hole for SEO metadata: any author-level account could
	 * rewrite another author's page.
	 *
	 * This runs as an auth_{$object_type}_meta_{$meta_key} filter rather than
	 * as a standalone check, and $allowed arrives carrying two different
	 * things that this has to tell apart.
	 *
	 * map_meta_cap() seeds it with ! is_protected_meta( $meta_key, 'post' )
	 * and then hands it through every callback hooked to that key. Rank Math
	 * marks **every** rank_math_* key protected - Common::hide_rank_math_meta()
	 * on the is_protected_meta filter, from a constructor that runs
	 * unconditionally - so on the only sites where these keys are registered
	 * at all, the seed is false before this callback is reached. Returning
	 * `$allowed && current_user_can( ... )` therefore refused every write to
	 * every one of these keys, to every user there is, administrators
	 * included: the Rank Math capability this module exists to provide was
	 * dead on arrival. (The comment that used to stand here said none of these
	 * keys is protected. It was wrong about the vendor, and being wrong in a
	 * docblock is how it survived a review.)
	 *
	 * That seed is precisely what an auth_callback on register_post_meta() is
	 * for. A key registered with one is a key whose protected default the
	 * registration means to replace - core has no other mechanism for saying
	 * so. But a refusal a *site* made, with an auth_post_meta_rank_math_*
	 * filter of its own at this priority or an earlier one, is a decision that
	 * must survive, and it arrives as the same false.
	 *
	 * They are told apart by recomputing what the seed would have been and
	 * comparing it with what arrived. Equal means nothing has intervened and
	 * the decision is this callback's, so it answers with its own capability
	 * check. Different means somebody decided, and what they decided stands -
	 * a denial refused, and a grant honoured. A grant honoured is not a grant
	 * of anything on its own: map_meta_cap() has already put edit_post's own
	 * mapping into $caps before any of this runs, and a true here only
	 * declines to add the blocking capability on top of it.
	 *
	 * One case this cannot see, stated plainly rather than glossed: when the
	 * seed is already false, a site filter that also denies is byte-identical
	 * to nothing having spoken, and no rule reading $allowed alone can
	 * distinguish them. A site that needs a denial to hold on a key Rank Math
	 * has protected must hook after this callback's priority 10, where its
	 * false is the last word.
	 *
	 * @param bool   $allowed  Whether the value may be seen or edited so far.
	 * @param string $meta_key The meta key being checked.
	 * @param int    $post_id  The post this meta value belongs to.
	 * @return bool
	 */
	public static function may_edit_meta( $allowed, $meta_key, $post_id ) {
		// 'post' is the metadata object type map_meta_cap() seeds with, not
		// the post type - these keys are registered on post and page alike and
		// the seed is the same for both.
		$seed = ! is_protected_meta( $meta_key, 'post' );

		if ( $seed !== (bool) $allowed ) {
			return (bool) $allowed;
		}

		return current_user_can( 'edit_post', (int) $post_id );
	}
}
