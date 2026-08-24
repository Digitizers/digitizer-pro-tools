<?php
/**
 * REST Bridge - reading and editing Elementor content over REST.
 *
 * Ported from the Digitizer API Extensions plugin, with the response shapes
 * kept byte for byte: the automations that call these routes were written
 * against them. What changed is the permission check, which used to ask only
 * whether the caller could edit anything at all.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The /digitizer/v1/elementor/{id} endpoints.
 */
class DPT_RB_Elementor {

	const NAMESPACE_V1 = 'digitizer/v1';
	const ROUTE        = '/elementor/(?P<post_id>\d+)';

	/**
	 * Settings keys that hold text worth showing in a tree.
	 *
	 * @var array
	 */
	private static $content_keys = array(
		'title',
		'editor',
		'text',
		'title_text',
		'heading_title',
		'tab_title',
		'description_text',
	);

	/**
	 * Register both endpoints.
	 */
	public static function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_tree' ),
				'permission_callback' => array( __CLASS__, 'may_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => array( __CLASS__, 'may_edit' ),
				'args'                => array(
					'updates' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'A list of { widget_id, settings } objects.', 'digitizer-pro-tools' ),
					),
				),
			)
		);
	}

	/**
	 * Whether this request may edit the post it names.
	 *
	 * This is the fix the port exists for: the plugin being replaced checked
	 * current_user_can( 'edit_posts' ) - a blanket "can this user edit
	 * *something*" - which let any author-level account rewrite any page on
	 * the site. Passing the post id makes WordPress check that post's own
	 * edit capability instead.
	 *
	 * That capability alone is still a weaker door than the editor beside it.
	 * Elementor's own gate - Document::is_editable_by_current_user(), which
	 * defers to User::is_current_user_can_edit() - refuses four more things:
	 * a trashed post, a post type Elementor does not support, the posts page,
	 * and any user whose role a site has listed in elementor_exclude_user_roles.
	 * These endpoints write the same meta key that gate protects, so a site
	 * that has deliberately excluded a role from editing with Elementor was
	 * bypassed here, and a trashed page could be rewritten through it.
	 *
	 * Elementor's own check is called rather than its rules reimplemented.
	 * is_current_user_can_edit() is a public static, the four rules are
	 * Elementor's to change, and a copy of them here would go stale the first
	 * time it did.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public static function may_edit( $request ) {
		$post_id = (int) $request['post_id'];

		// Asked first, and kept even though Elementor's gate asks it too: it
		// is the check this module owes on its own account, and it is the
		// whole of the gate on a site with no Elementor at all - which this
		// module supports, and where these routes still answer for a page
		// whose layout is already in the database.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// A revision is refused by target_post(), with a 400 that names the
		// post to retry against. Elementor's gate would refuse it half a step
		// earlier and for an unrelated reason - `revision` is not a post type
		// Elementor supports - turning a diagnostic an automation can act on
		// into a bare 403. Nothing is read or written either way, because
		// both routes go through target_post() before they touch anything, so
		// letting it past here costs nothing and keeps the better answer.
		$post = get_post( $post_id );
		if ( $post && 'revision' === $post->post_type ) {
			return true;
		}

		return self::elementor_allows_editing( $post_id );
	}

	/**
	 * Elementor's own answer to "may this user edit this post with Elementor",
	 * or true where there is no Elementor to ask.
	 *
	 * is_callable() rather than class_exists(), and for the reason
	 * clear_cache() checks the same way: this runs against whatever Elementor
	 * version is installed, and a static this module leans on is not this
	 * plugin's to guarantee across all of them.
	 *
	 * Absent Elementor the answer is yes, which is not a hole: the caller has
	 * already passed edit_post on this exact post, and the four extra rules
	 * are all Elementor's own settings, which a site without Elementor has
	 * not got. Refusing instead would break the module's own promise that
	 * these routes work on a site whose Elementor was deactivated but whose
	 * layouts are still in the database.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function elementor_allows_editing( $post_id ) {
		if ( ! is_callable( array( '\Elementor\User', 'is_current_user_can_edit' ) ) ) {
			return true;
		}

		return (bool) \Elementor\User::is_current_user_can_edit( $post_id );
	}

	/**
	 * The post these routes may work on, or the reason they may not.
	 *
	 * Both routes come through here, which is the point: a revision is refused
	 * once, for reading and for writing alike, and the two can no longer end up
	 * disagreeing about which post they are touching.
	 *
	 * A revision has to be refused rather than quietly redirected, because
	 * update_post_meta() redirects it *for* us - core opens with "make sure
	 * meta is added to the post, not a revision" and swaps in the parent id -
	 * while get_post_meta() does no such thing. So POST /elementor/{revision}
	 * used to read the revision's own layout, merge the caller's changes into
	 * it and write the result over the live parent page, reporting the revision
	 * id as the thing it had updated: someone inspecting or amending an old
	 * revision republished it without being told. The permission check cannot
	 * catch it either - WordPress maps edit_post on a revision to the parent's
	 * capability, so anyone who may edit the page passes.
	 *
	 * Refusing is the honest answer of the two available. Resolving to the
	 * parent instead would mean a GET for a revision handing back the live
	 * page's layout - not the revision's, since a coherent pair cannot read one
	 * post and write another - which is a different page than the caller asked
	 * for, returned without complaint. A 400 naming the parent in its data lets
	 * the caller decide, and can never republish anything by accident.
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|WP_Error
	 */
	private static function target_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'digitizer-pro-tools' ), array( 'status' => 404 ) );
		}

		if ( 'revision' === $post->post_type ) {
			return new WP_Error(
				'revision_not_supported',
				__( 'That id is a revision. Elementor layouts are read and written on the post itself, so use the id of the post this revision belongs to.', 'digitizer-pro-tools' ),
				array(
					'status' => 400,
					// The parent id is handed over as data rather than built
					// into the sentence: it is what an automation needs in
					// order to retry against the right post, and it is not
					// always there to name - an orphaned revision left behind
					// by a half-finished delete has no parent at all.
					'parent' => isset( $post->post_parent ) ? (int) $post->post_parent : 0,
				)
			);
		}

		return $post;
	}

	/**
	 * The Elementor layout of one post, decoded.
	 *
	 * @param int $post_id Post id.
	 * @return array|WP_Error
	 */
	private static function layout( $post_id ) {
		$post = self::target_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) ) {
			return new WP_Error( 'no_elementor', __( 'This post has no Elementor data.', 'digitizer-pro-tools' ), array( 'status' => 404 ) );
		}

		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}

		// The stored value is JSON written by another plugin across many
		// versions, and possibly hand-edited - it is not guaranteed to still
		// decode to an array, so this is treated as a real failure rather
		// than let a scalar or null reach the recursive walks below.
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', __( 'The stored Elementor data is not valid JSON.', 'digitizer-pro-tools' ), array( 'status' => 500 ) );
		}

		return $data;
	}

	/**
	 * GET: the widget tree.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	public static function get_tree( $request ) {
		$post_id = (int) $request['post_id'];
		$data    = self::layout( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response(
			array(
				'post_id'      => $post_id,
				'widget_count' => self::count_widgets( $data ),
				'tree'         => self::tree( $data ),
			)
		);
	}

	/**
	 * POST: merge settings into named widgets.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_Error
	 */
	public static function update( $request ) {
		$post_id = (int) $request['post_id'];
		$updates = $request->get_param( 'updates' );

		if ( ! is_array( $updates ) || ! $updates ) {
			return new WP_Error( 'invalid_updates', __( 'Updates must be a non-empty array.', 'digitizer-pro-tools' ), array( 'status' => 400 ) );
		}

		$data = self::layout( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$map = array();
		foreach ( $updates as $update ) {
			// widget_id has to be a scalar before it can become a map key.
			// Casting an array straight to string would only raise a PHP
			// warning and then key the map under the literal string "Array",
			// silently corrupting the request instead of rejecting it - and
			// this is untrusted input off the wire, not just stored data.
			if ( ! is_array( $update ) || ! isset( $update['widget_id'] ) || ! is_scalar( $update['widget_id'] ) || ! isset( $update['settings'] ) || ! is_array( $update['settings'] ) ) {
				return new WP_Error( 'invalid_update', __( 'Each update must have a widget_id and a settings object.', 'digitizer-pro-tools' ), array( 'status' => 400 ) );
			}
			$map[ (string) $update['widget_id'] ] = $update['settings'];
		}

		$map = self::filter_settings( $map );

		$applied = 0;
		$data    = self::apply( $data, $map, $applied );

		// wp_json_encode() (json_encode() under the hood) returns false
		// rather than a string when the layout now contains bytes that are
		// not valid UTF-8 - a widget setting pasted from somewhere odd, say.
		// Writing that false (or an empty string, if a caller coerced it) to
		// _elementor_data would blank the page for every visitor, which is
		// strictly worse than refusing the write and leaving the page as it
		// was. So the encode is checked before anything is saved.
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return new WP_Error(
				'encode_failed',
				__( 'The updated layout could not be encoded back to JSON and was not saved.', 'digitizer-pro-tools' ),
				array( 'status' => 500 )
			);
		}

		// update_post_meta() answers false both for a write the site refused
		// - a database error, a metadata filter that said no - and for one
		// that asked for the value already stored. Discarding that answer is
		// how this endpoint used to clear the cache and report success:true
		// over a page whose layout it had not changed at all, telling a
		// caller it had edited something it had not. DPT_RB_Fields::write()
		// tells the two apart the only way there is, by reading storage back
		// and comparing it against what was asked for, and so does this.
		// The comparison is the encoded string rather than the slashed one:
		// wp_slash() is for the escaping update_post_meta() undoes on the
		// way in, so what lands in the row is the JSON itself.
		if ( false === update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) ) && get_post_meta( $post_id, '_elementor_data', true ) !== $encoded ) {
			return new WP_Error(
				'save_failed',
				__( 'The updated layout could not be saved.', 'digitizer-pro-tools' ),
				array( 'status' => 500 )
			);
		}

		// Only now, with the new layout known to be stored: the rendered CSS
		// is describing a page that no longer exists.
		delete_post_meta( $post_id, '_elementor_css' );
		self::clear_cache();

		return rest_ensure_response(
			array(
				'success'           => true,
				'post_id'           => $post_id,
				'updates_requested' => count( $updates ),
				'updates_applied'   => $applied,
				'not_found'         => array_values( array_keys( array_diff_key( $map, array_flip( self::collect_ids( $data ) ) ) ) ),
			)
		);
	}

	/**
	 * The caller's settings, under the rule Elementor applies to its own.
	 *
	 * Elementor prints widget settings raw - the HTML widget renders through
	 * print_unescaped_setting() and the text editor echoes its content with
	 * the escaping sniff switched off - so Document::save() runs
	 * map_deep( $data, 'wp_kses_post' ) over everything it is about to store
	 * whenever the current user lacks unfiltered_html. This endpoint writes
	 * the same meta key with the same caller-supplied values, so without the
	 * same rule it was a weaker door into the same data: a Contributor, an
	 * Author, or an Editor on multisite or under DISALLOW_UNFILTERED_HTML
	 * could POST a script tag that Elementor's own editor would have stripped
	 * and have it printed on the front end.
	 *
	 * Elementor's callback copied rather than paraphrased, booleans and nulls
	 * included: those are handed through because wp_kses_post() would make a
	 * boolean into '1' or '', and a widget reading a switch back as a string
	 * is a different bug. A numeric setting really does come out of this as a
	 * string, on this endpoint as in Elementor's own save, which is the point
	 * of matching it rather than inventing a filter of our own.
	 *
	 * Applied to the caller's map and not to the stored layout. Elementor
	 * kses's the whole document because the editor posts the whole document;
	 * here only the named settings came from the request, and running kses
	 * over the rest would rewrite content that has been on the page for years
	 * on the strength of one unrelated widget edit - the same "a value this
	 * module cannot shape is not a value it may destroy" rule the field side
	 * is held to.
	 *
	 * What is deliberately not copied is get_elements_raw_data(), which
	 * Elementor runs on the way to storage and which passes every setting
	 * through its widget's registered controls, dropping the keys that widget
	 * does not define. That is right for a document the editor composed and
	 * wrong for a settings merge: it needs Elementor loaded and every widget's
	 * plugin active at that moment, throws on data it cannot instantiate, and
	 * would silently delete settings another plugin put on a widget - which
	 * is exactly the deletion this module refuses to make elsewhere. The
	 * escaping is the half that has to match; the shape is not.
	 *
	 * @param array $map widget id => settings to merge.
	 * @return array
	 */
	private static function filter_settings( $map ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $map;
		}

		return map_deep(
			$map,
			function ( $value ) {
				return is_bool( $value ) || is_null( $value ) ? $value : wp_kses_post( $value );
			}
		);
	}

	/**
	 * Ask Elementor to forget its generated files, when Elementor is here.
	 *
	 * Deleting the post's own _elementor_css is not enough on its own: the
	 * global and per-post files are managed elsewhere, and a page can keep
	 * serving the old CSS until they are cleared. is_callable() is checked
	 * rather than assumed, because this runs against whatever Elementor
	 * version is installed, and files_manager's shape is not this plugin's
	 * to guarantee.
	 */
	private static function clear_cache() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		$elementor = \Elementor\Plugin::instance();
		if ( isset( $elementor->files_manager ) && is_callable( array( $elementor->files_manager, 'clear_cache' ) ) ) {
			$elementor->files_manager->clear_cache();
		}
	}

	/**
	 * A readable tree of what is on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private static function tree( $elements ) {
		$tree = array();

		foreach ( $elements as $element ) {
			// The stored JSON may have been hand-edited; an entry that is
			// not itself an array (a bare string, say) is skipped rather
			// than indexed into, which is what would raise a notice here.
			if ( ! is_array( $element ) ) {
				continue;
			}
			$node = array(
				'id'   => isset( $element['id'] ) ? $element['id'] : '',
				'type' => isset( $element['elType'] ) ? $element['elType'] : 'unknown',
			);

			if ( ! empty( $element['widgetType'] ) ) {
				$node['widget'] = $element['widgetType'];
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			foreach ( self::$content_keys as $key ) {
				if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || '' === $settings[ $key ] ) {
					continue;
				}
				$node[ $key ] = self::truncate( $settings[ $key ] );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$node['children'] = self::tree( $element['elements'] );
			}

			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * Shorten a text value for the tree, without cutting a multi-byte UTF-8
	 * character in half.
	 *
	 * substr() cuts on raw bytes, which only works for single-byte content.
	 * Hebrew - what this plugin's own sites run - is two bytes per character
	 * in UTF-8, so a byte-based cut lands mid-character routinely, and a
	 * malformed byte sequence reaching wp_json_encode() fails the *whole*
	 * response, not just the one truncated field.
	 *
	 * Cut by character count (mb_substr) rather than by byte count
	 * (mb_strcut): 200 characters reads as "about this much text" in any
	 * script, where a 200-*byte* cap would hand back roughly half as much
	 * visible Hebrew as Latin for the same limit - the wrong trade-off for
	 * text meant to be read, on a plugin built for Hebrew sites.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function truncate( $value ) {
		if ( ! function_exists( 'mb_strlen' ) ) {
			// No character-safe way to cut a multi-byte string without
			// mbstring; not truncating at all is the safe failure here; a
			// byte-based fallback risks handing back the exact invalid
			// sequence this method exists to avoid.
			return $value;
		}

		if ( mb_strlen( $value, 'UTF-8' ) <= 200 ) {
			return $value;
		}

		return mb_substr( $value, 0, 200, 'UTF-8' ) . '...';
	}

	/**
	 * How many widgets are on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return int
	 */
	private static function count_widgets( $elements ) {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$count++;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::count_widgets( $element['elements'] );
			}
		}
		return $count;
	}

	/**
	 * Merge the update map into the tree, counting what landed.
	 *
	 * If a hand-edited tree has more than one element sharing the same id,
	 * every one of them is merged and $applied counts each match - so it can
	 * end up larger than the number of ids that were requested. That is
	 * intentional (every widget an id could plausibly mean gets the update),
	 * not a bug to fix.
	 *
	 * @param array $elements Elementor elements.
	 * @param array $map      widget id => settings to merge.
	 * @param int   $applied  Running count, by reference.
	 * @return array
	 */
	private static function apply( $elements, $map, &$applied ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			// An id that survived hand-editing as an array or object would
			// raise "Array to string conversion" on a bare (string) cast;
			// treated as no id at all instead, the same as an element with
			// none, rather than risking that warning on untrusted stored data.
			$id = ( isset( $element['id'] ) && is_scalar( $element['id'] ) ) ? (string) $element['id'] : '';

			if ( '' !== $id && isset( $map[ $id ] ) ) {
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
					$element['settings'] = array();
				}
				foreach ( $map[ $id ] as $key => $value ) {
					$element['settings'][ $key ] = $value;
				}
				$applied++;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::apply( $element['elements'], $map, $applied );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Every element id on the page.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private static function collect_ids( $elements ) {
		$ids = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			// Same guard as apply(): a hand-edited id that is not a scalar
			// cannot become a string without a warning, so it is left out of
			// the id list rather than cast.
			if ( ! empty( $element['id'] ) && is_scalar( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, self::collect_ids( $element['elements'] ) );
			}
		}
		return $ids;
	}
}
