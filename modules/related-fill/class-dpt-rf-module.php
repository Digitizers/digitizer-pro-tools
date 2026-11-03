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
		// The whole ordered list goes to post__in, not one row of it, so
		// WordPress still applies the widget's offset and page and counts
		// the pages its pagination needs. (PR #87 Codex P2 x2)
		$want    = max( $per, (int) apply_filters( 'dpt_related_fill_max', 100 ) );
		$current = is_singular() ? (int) get_queried_object_id() : 0;
		$type    = $query->get( 'post_type' );
		$type    = ( $type && 'any' !== $type ) ? $type : 'post';
		$skip    = array_map( 'intval', (array) $query->get( 'post__not_in' ) );
		// Filters that stay on the outer query apply to the lookups too, or
		// the cap fills with posts it then drops. (PR #87 Codex P2)
		$keep = array();
		foreach ( array( 'author', 'author__in', 'author__not_in', 'date_query', 'meta_query' ) as $var ) {
			$value = $query->get( $var );
			if ( '' !== $value && array() !== $value && null !== $value ) {
				$keep[ $var ] = $value;
			}
		}
		if ( $current ) {
			$skip[] = $current;
		}

		$this->running = true;
		// Elementor keeps its offset callback (priority 1) on pre_get_posts
		// while the widget's query is built, and it sets the widget's offset
		// on every query - these lookups too. (PR #87 Codex P2)
		add_action( 'pre_get_posts', array( $this, 'no_offset' ), PHP_INT_MAX );
		$ids  = array();
		$cats = $current ? wp_get_post_categories( $current ) : array();
		if ( $cats ) {
			$ids = $this->newest( $type, $want, $skip, $cats, $keep );
		}
		if ( count( $ids ) < $want ) {
			$ids = array_merge( $ids, $this->newest( $type, $want - count( $ids ), array_merge( $skip, $ids ), array(), $keep ) );
		}
		remove_action( 'pre_get_posts', array( $this, 'no_offset' ), PHP_INT_MAX );
		$this->running = false;

		if ( ! $ids ) {
			return; // Nothing to show: Elementor's own fallback may still act.
		}
		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'tax_query', array() );
		$query->set( 'category__in', array() );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'posts_per_page', $per );
	}

	/**
	 * @param WP_Query $query A query running while the lookups do.
	 */
	public function no_offset( $query ) {
		if ( $query->get( 'dpt_related_fill_lookup' ) ) {
			$query->set( 'offset', 0 );
		}
	}

	/**
	 * @return int[] Published post ids, newest first.
	 */
	private function newest( $type, $limit, $skip, $cats, $keep ) {
		$args = $keep + array(
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
			'dpt_related_fill_lookup' => true,
		);
		if ( $cats ) {
			$args['category__in'] = array_map( 'intval', $cats );
		}
		return array_map( 'intval', get_posts( $args ) );
	}
}
