<?php
/**
 * Content Control module - enforcement of global restrictions: main-query
 * redirect/replace, hiding in post and term lists, denial-message hand-off
 * to the module's content filters, and REST refusal.
 *
 * Per-post meta and whole-site protection are enforced elsewhere and take
 * precedence; administrators bypass everything.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Enforce {

	public function init() {
		// After whole-site protection (@1), before the theme renders.
		add_action( 'template_redirect', array( $this, 'enforce_main_query' ), 5 );
		// Late registration keeps setup-time queries out of the filters.
		add_action( 'init', array( $this, 'register_query_filters' ), 999 );
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_rest' ), 10, 3 );
		// Denied main-query pages whose protection is "replace with message"
		// but which match no individual post (search, blog index, archives)
		// get their message through these. (Codex round-2 P1)
		add_filter( 'the_content', array( $this, 'filter_main_denial_content' ), 999 );
		add_filter( 'the_excerpt', array( $this, 'filter_main_denial_content' ), 999 );
	}

	public function register_query_filters() {
		add_filter( 'the_posts', array( $this, 'filter_posts' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'filter_terms' ), 10, 4 );
	}

	/* --------------------------------------------------------------------- */
	/* Decision helpers                                                      */
	/* --------------------------------------------------------------------- */

	private function enforcement_off() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( DPT_CC_Access::user_can_bypass() ) {
			return true;
		}
		return (bool) apply_filters( 'dpt_cc_enforcement_off', false );
	}

	/**
	 * Pages that must stay reachable no matter what: every enabled row's
	 * replacement and archive pages. Restricting the page a refused visitor
	 * lands on would loop.
	 *
	 * @return int[]
	 */
	public function exempt_ids() {
		$ids = array();
		foreach ( DPT_CC_Restrictions::enabled() as $row ) {
			if ( $row['protection']['replacement_page'] ) {
				$ids[] = (int) $row['protection']['replacement_page'];
			}
			if ( $row['archive_page'] ) {
				$ids[] = (int) $row['archive_page'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The restriction that BARS the current user from this post, or null -
	 * null both when nothing matches and when the user is admitted.
	 *
	 * @param object $post Post-like object with ID and post_type.
	 * @return array|null
	 */
	public function restriction_for_post( $post ) {
		if ( ! $post || empty( $post->ID ) || $this->enforcement_off() ) {
			return null;
		}
		if ( in_array( (int) $post->ID, $this->exempt_ids(), true ) ) {
			return null;
		}
		if ( DPT_CC_Access::post_is_restricted( (int) $post->ID ) ) {
			return null; // Per-page settings always win over global rules. (Codex round-1 P2)
		}
		$context = array( 'type' => 'post', 'post_id' => (int) $post->ID, 'post' => $post, 'term' => null );
		$main    = $this->main_flags_for( $post );
		if ( null !== $main ) {
			$context['main'] = $main;
		}
		$row = DPT_CC_Restrictions::match( $context );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Main-query conditional flags for the post being viewed. Without them,
	 * the main query's own the_posts pass evaluates the queried page with a
	 * post-only context and caches that verdict in the shared post-{id}
	 * slot - so a "front page" rule reads false and the poisoned entry then
	 * skips the restriction at template_redirect too. (Codex round-1 P1)
	 *
	 * @param object $post Post-like object.
	 * @return array|null Null when $post is not the queried main object.
	 */
	private function main_flags_for( $post ) {
		if ( ! function_exists( 'get_queried_object_id' ) || (int) get_queried_object_id() !== (int) $post->ID ) {
			return null;
		}
		return array(
			'is_front_page'        => function_exists( 'is_front_page' ) ? is_front_page() : false,
			'is_home'              => function_exists( 'is_home' ) ? is_home() : false,
			'is_search'            => false,
			'is_404'               => false,
			'is_post_type_archive' => array(),
			'is_tax'               => array(),
		);
	}

	/**
	 * @param object $term Term-like object with term_id and taxonomy.
	 * @return array|null
	 */
	public function restriction_for_term( $term ) {
		if ( ! $term || empty( $term->term_id ) || $this->enforcement_off() ) {
			return null;
		}
		$row = DPT_CC_Restrictions::match( array( 'type' => 'term', 'term_id' => (int) $term->term_id, 'post' => null, 'term' => $term ) );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * filter|hide for a matched row inside a list of posts. Search results
	 * force hide unless the row opts into being seen there - a restricted
	 * title in search leaks that the content exists.
	 */
	public function handling_for( $row, $is_main, $is_search ) {
		if ( $is_search && empty( $row['show_in_search'] ) ) {
			return 'hide';
		}
		if ( $is_main ) {
			return 'hide' === $row['archive_handling'] ? 'hide' : 'filter';
		}
		return 'hide' === $row['query_handling'] ? 'hide' : 'filter';
	}

	/**
	 * The row's own message when it overrides, '' otherwise - the caller
	 * then falls back to the module's default chain.
	 */
	public function denial_message( $row ) {
		if ( ! empty( $row['protection']['override_message'] ) && '' !== trim( (string) $row['protection']['custom_message'] ) ) {
			return $row['protection']['custom_message'];
		}
		return '';
	}

	/** @var array|null Row denying the whole current main query, message-style. */
	private $main_denial = null;

	/**
	 * Mark the whole current main request as denied by $row. A "Search
	 * results" / "Blog index" / archive / 404 rule matches no individual
	 * post, so the ordinary per-post content filter would sail past it and
	 * leave the listing visible. (Codex round-2 P1)
	 */
	public function deny_main( $row ) {
		$this->main_denial = $row;
	}

	public function filter_main_denial_content( $content ) {
		if ( ! $this->main_denial ) {
			return $content;
		}
		$custom = $this->denial_message( $this->main_denial );
		if ( '' !== $custom ) {
			return '<div class="dpt-cc-restricted">' . wp_kses_post( wpautop( $custom ) ) . '</div>';
		}
		return DPT_CC_Access::restriction_message( 0 );
	}

	/* --------------------------------------------------------------------- */
	/* Main query                                                            */
	/* --------------------------------------------------------------------- */

	public function enforce_main_query() {
		if ( $this->enforcement_off() ) {
			return;
		}
		global $wp_query;

		$context = array(
			'type' => 'main',
			'post' => is_singular() ? get_queried_object() : null,
			'term' => ( is_category() || is_tag() || is_tax() ) ? get_queried_object() : null,
			'main' => array(
				'is_front_page'        => is_front_page(),
				'is_home'              => is_home(),
				'is_search'            => is_search(),
				'is_404'               => is_404(),
				'is_post_type_archive' => $this->queried_archive_types(),
				'is_tax'               => $this->queried_taxonomies(),
			),
		);
		// A singular view shares the post cache slot so the content filter
		// later agrees with the decision made here.
		if ( ! empty( $context['post'] ) && ! empty( $context['post']->ID ) ) {
			if ( in_array( (int) $context['post']->ID, $this->exempt_ids(), true ) ) {
				return;
			}
			if ( DPT_CC_Access::post_is_restricted( (int) $context['post']->ID ) ) {
				return; // Per-page settings always win over global rules. (Codex round-1 P2)
			}
			$context['type']    = 'post';
			$context['post_id'] = (int) $context['post']->ID;
		}

		$row = DPT_CC_Restrictions::match( $context );
		if ( $row && ! DPT_CC_Access::who_allows( $row['who'] ) ) {
			if ( 'redirect' === $row['protection']['method'] ) {
				$this->do_redirect( $row['protection']['redirect_type'], $row['protection']['redirect_url'] );
			}
			if ( 'replace' === $row['protection']['method'] && $row['protection']['replacement_page'] ) {
				$this->replace_with_page( (int) $row['protection']['replacement_page'] );
				return;
			}
			if ( 'replace' === $row['protection']['method'] && ! is_singular() ) {
				// Search / blog index / archive / 404 rules match no single
				// post, so the per-post content filter cannot show the
				// message - deny the whole page. Singular views re-match
				// through the queried post's own context. (Codex round-2 P1)
				$this->deny_main( $row );
			}
		}

		// Archive-level handling for restricted posts inside the list.
		if ( ! is_singular() ) {
			$this->enforce_archive_posts( $wp_query );
		}
	}

	private function queried_archive_types() {
		if ( ! is_post_type_archive() ) {
			return array();
		}
		global $wp_query;
		return array_values( array_filter( array_map( 'strval', (array) $wp_query->get( 'post_type' ) ) ) );
	}

	private function queried_taxonomies() {
		if ( is_category() ) {
			return array( 'category' );
		}
		if ( is_tag() ) {
			return array( 'post_tag' );
		}
		if ( is_tax() ) {
			$obj = get_queried_object();
			return ( $obj && ! empty( $obj->taxonomy ) ) ? array( $obj->taxonomy ) : array();
		}
		return array();
	}

	private function enforce_archive_posts( $query ) {
		if ( empty( $query->posts ) ) {
			return;
		}
		foreach ( $query->posts as $post ) {
			$row = $this->restriction_for_post( $post );
			if ( ! $row ) {
				continue;
			}
			if ( 'redirect' === $row['archive_handling'] ) {
				$this->do_redirect( $row['archive_redirect_type'], $row['archive_redirect_url'] );
			}
			if ( 'replace_page' === $row['archive_handling'] && $row['archive_page'] ) {
				$this->replace_with_page( (int) $row['archive_page'] );
				return;
			}
		}
	}

	/**
	 * Where a denied request should go. A home or custom destination equal
	 * to the page being denied would send the browser to itself forever -
	 * such a target falls back to the login form. (Codex round-2 P1)
	 */
	public static function redirect_target( $type, $url ) {
		$current = untrailingslashit( self::current_request_url() );
		$to      = '';
		if ( 'home' === $type ) {
			$to = home_url( '/' );
		} elseif ( 'custom' === $type && '' !== $url ) {
			$to = $url;
		}
		if ( '' === $to || untrailingslashit( $to ) === $current ) {
			$to = wp_login_url( self::current_request_url() );
		}
		return $to;
	}

	private function do_redirect( $type, $url ) {
		$to   = self::redirect_target( $type, $url );
		$host = wp_parse_url( $to, PHP_URL_HOST );
		if ( $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * The URL being requested: trusted scheme+host from home_url() plus
	 * REQUEST_URI. home_url( $request_uri ) would double a subdirectory
	 * install's path (/site/site/...). Only compared and redirected to,
	 * never printed. (Codex round-1 P2)
	 */
	public static function current_request_url() {
		$req    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- re-assembled into a URL, never output.
		$home   = wp_parse_url( home_url() );
		$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : ( function_exists( 'is_ssl' ) && is_ssl() ? 'https' : 'http' );
		$host   = ! empty( $home['host'] ) ? $home['host'] : '';
		if ( ! empty( $home['port'] ) ) {
			$host .= ':' . $home['port'];
		}
		return $scheme . '://' . $host . $req;
	}

	private function replace_with_page( $page_id ) {
		global $wp_query;
		$page = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return;
		}
		$wp_query->init();
		$wp_query->query( array( 'page_id' => $page_id, 'ignore_restrictions' => true ) );
		status_header( 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Post / term lists                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Post types whose rows are plumbing, not content: hiding a template,
	 * a reusable block or a nav item blanks layouts and menus instead of
	 * protecting anything. (Codex round-2 P1)
	 */
	private function post_type_ignored( $type ) {
		$ignored = (array) apply_filters(
			'dpt_cc_ignored_post_types',
			array( 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'oembed_cache', 'revision', 'custom_css', 'customize_changeset', 'elementor_library' )
		);
		if ( in_array( $type, $ignored, true ) ) {
			return true;
		}
		return function_exists( 'is_post_type_viewable' ) && ! is_post_type_viewable( $type );
	}

	private function taxonomy_ignored( $taxonomy ) {
		$ignored = (array) apply_filters(
			'dpt_cc_ignored_taxonomies',
			array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' )
		);
		if ( in_array( $taxonomy, $ignored, true ) ) {
			return true;
		}
		return function_exists( 'is_taxonomy_viewable' ) && ! is_taxonomy_viewable( $taxonomy );
	}

	private function query_ignored( $query ) {
		if ( $query && method_exists( $query, 'get' ) && $query->get( 'ignore_restrictions' ) ) {
			return true;
		}
		return (bool) apply_filters( 'dpt_cc_query_ignored', false, $query );
	}

	public function filter_posts( $posts, $query = null ) {
		if ( empty( $posts ) || ! is_array( $posts ) || $this->enforcement_off() || $this->query_ignored( $query ) ) {
			return $posts;
		}
		$is_main   = $query && method_exists( $query, 'is_main_query' ) && $query->is_main_query();
		$is_search = $query && method_exists( $query, 'is_search' ) && $query->is_search();
		// A singular main query is template_redirect's job: hiding its only
		// post here would 404 the request before the configured redirect or
		// replacement protection ever runs. (Codex round-1 P1)
		if ( $is_main && method_exists( $query, 'is_singular' ) && $query->is_singular() ) {
			return $posts;
		}
		$changed = false;
		foreach ( $posts as $i => $post ) {
			if ( ! is_object( $post ) || empty( $post->ID ) ) {
				continue;
			}
			if ( empty( $post->post_type ) || $this->post_type_ignored( $post->post_type ) ) {
				continue;
			}
			$row = $this->restriction_for_post( $post );
			if ( $row && 'hide' === $this->handling_for( $row, $is_main, $is_search ) ) {
				unset( $posts[ $i ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$posts = array_values( $posts );
			if ( $query && property_exists( $query, 'post_count' ) ) {
				$query->post_count = count( $posts );
			}
		}
		return $posts;
	}

	public function filter_terms( $terms, $taxonomies = array(), $args = array(), $query = null ) {
		if ( empty( $terms ) || ! is_array( $terms ) || $this->enforcement_off() ) {
			return $terms;
		}
		$changed = false;
		foreach ( $terms as $i => $term ) {
			// get_terms may return ids, slugs or counts - only objects are checked.
			if ( ! is_object( $term ) || empty( $term->term_id ) ) {
				continue;
			}
			if ( empty( $term->taxonomy ) || $this->taxonomy_ignored( $term->taxonomy ) ) {
				continue;
			}
			$row = $this->restriction_for_term( $term );
			if ( $row && 'hide' === $row['query_handling'] ) {
				unset( $terms[ $i ] );
				$changed = true;
			}
		}
		return $changed ? array_values( $terms ) : $terms;
	}

	/* --------------------------------------------------------------------- */
	/* REST                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Single-item core routes for a post a redirect-style restriction bars
	 * are refused outright; replace/message rows are blanked instead by the
	 * module's rest_prepare filter, and collections are filtered per item.
	 */
	public function enforce_rest( $result, $server, $request ) {
		if ( null !== $result || DPT_CC_Access::user_can_bypass() ) {
			return $result;
		}
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		if ( ! preg_match( '#^/wp/v2/[^/]+/(\d+)$#', (string) $route, $m ) ) {
			return $result;
		}
		$post = get_post( (int) $m[1] );
		if ( ! $post ) {
			return $result;
		}
		$row = $this->restriction_for_post( $post );
		if ( $row && 'redirect' === $row['protection']['method'] ) {
			return new WP_Error(
				'dpt_cc_forbidden',
				__( 'You do not have permission to view this content.', 'digitizer-pro-tools' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return $result;
	}
}
