<?php
/**
 * Related Posts Fill module - an Elementor Posts widget whose Query ID is
 * dpt_related_fill always shows a full row: posts from the current post's
 * categories, newest first, then the newest posts site-wide.
 *
 * Elementor's own related fallback fires only when the related query finds
 * nothing, so a small category leaves a row of one or two cards. This decides
 * which posts the widget shows and nothing else - the card markup, and with
 * it any used CSS a cache built for it, stays as it is.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Related_Fill_Module extends DPT_Module {

	const QUERY_ID = 'dpt_related_fill';

	/** @var bool A lookup below runs pre_get_posts, and so this hook, again. */
	private $running = false;

	public function id() {
		return 'related_fill';
	}

	public function title() {
		return __( 'Related Posts Fill', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'An Elementor Posts widget with the Query ID dpt_related_fill always shows a full row: posts from the current post\'s categories first, newest first, then the newest posts on the site. Other widgets are not affected.', 'digitizer-pro-tools' );
	}

	public function init() {
		// Elementor Pro fires this inside the widget query's pre_get_posts,
		// in related mode too, before its zero-result fallback is considered.
		add_action( 'elementor/query/' . self::QUERY_ID, array( $this, 'fill' ) );
	}

	/**
	 * @param WP_Query $query The widget's query, about to run.
	 */
	public function fill( $query ) {
		if ( $this->running ) {
			return;
		}
		$per     = (int) $query->get( 'posts_per_page' );
		$per     = $per > 0 ? $per : 3;
		// An offset or a later page skips that many of the row's own posts,
		// which are only known here; the outer query then skips none.
		// (PR #87 Codex P2)
		$from    = max( 0, (int) $query->get( 'offset' ) ) + ( max( 1, (int) $query->get( 'paged' ) ) - 1 ) * $per;
		$want    = $from + $per;
		$current = is_singular() ? (int) get_queried_object_id() : 0;
		$type    = $query->get( 'post_type' );
		$type    = ( $type && 'any' !== $type ) ? $type : 'post';
		$skip    = array_map( 'intval', (array) $query->get( 'post__not_in' ) );
		if ( $current ) {
			$skip[] = $current;
		}

		$this->running = true;
		$ids  = array();
		$cats = $current ? wp_get_post_categories( $current ) : array();
		if ( $cats ) {
			$ids = $this->newest( $type, $want, $skip, $cats );
		}
		if ( count( $ids ) < $want ) {
			$ids = array_merge( $ids, $this->newest( $type, $want - count( $ids ), array_merge( $skip, $ids ), array() ) );
		}
		$this->running = false;
		$ids = array_slice( $ids, $from, $per );

		if ( ! $ids ) {
			return; // Nothing to show: Elementor's own fallback may still act.
		}
		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'tax_query', array() );
		$query->set( 'category__in', array() );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'posts_per_page', $per );
		$query->set( 'offset', 0 );
		$query->set( 'paged', 1 );
	}

	/**
	 * @return int[] Published post ids, newest first.
	 */
	private function newest( $type, $limit, $skip, $cats ) {
		$args = array(
			'post_type'           => $type,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => array_values( array_unique( $skip ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false, // Language plugins filter it.
		);
		if ( $cats ) {
			$args['category__in'] = array_map( 'intval', $cats );
		}
		return array_map( 'intval', get_posts( $args ) );
	}
}
