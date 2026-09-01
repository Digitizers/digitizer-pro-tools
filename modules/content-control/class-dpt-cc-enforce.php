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
		$row = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => (int) $post->ID, 'post' => $post, 'term' => null ) );
		if ( ! $row || DPT_CC_Access::who_allows( $row['who'] ) ) {
			return null;
		}
		return $row;
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
			// replace + message: the content filter shows the denial message.
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

	private function do_redirect( $type, $url ) {
		if ( 'home' === $type ) {
			$to = home_url( '/' );
		} elseif ( 'custom' === $type && '' !== $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				add_filter(
					'allowed_redirect_hosts',
					static function ( $hosts ) use ( $host ) {
						$hosts[] = $host;
						return $hosts;
					}
				);
			}
			$to = $url;
		} else {
			// Back-to-here login. REQUEST_URI is only re-assembled into the
			// redirect target, never printed.
			$req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$to  = wp_login_url( home_url( $req ) );
		}
		wp_safe_redirect( $to );
		exit;
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
		$changed   = false;
		foreach ( $posts as $i => $post ) {
			if ( ! is_object( $post ) || empty( $post->ID ) ) {
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
