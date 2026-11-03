<?php
/**
 * Related Posts Fill - an Elementor Posts widget with Query ID
 * dpt_related_fill always shows a full row: the current post's categories
 * first, newest first, then the newest posts site-wide.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';

/* The slice of WordPress this touches. */
$GLOBALS['dpt_stub_queried'] = 100;
function get_queried_object_id() { return $GLOBALS['dpt_stub_queried']; }
function is_singular( $t = '' ) { return 0 !== $GLOBALS['dpt_stub_queried']; }
$GLOBALS['dpt_stub_cats'] = array( 100 => array( 7 ) );
function wp_get_post_categories( $id, $args = array() ) { return isset( $GLOBALS['dpt_stub_cats'][ $id ] ) ? $GLOBALS['dpt_stub_cats'][ $id ] : array(); }
function get_post_type( $id = null ) { return 'post'; }
// Scripted posts: id => category ids, newest first in this order.
$GLOBALS['dpt_stub_pool'] = array();
$GLOBALS['dpt_stub_get_posts'] = array();
function get_posts( $args ) {
	$GLOBALS['dpt_stub_get_posts'][] = $args;
	// Every query runs pre_get_posts - Elementor's offset callback among them.
	$pq = new DPT_Stub_Q( $args );
	do_action( 'pre_get_posts', $pq );
	$out = array();
	foreach ( $GLOBALS['dpt_stub_pool'] as $id => $cats ) {
		if ( in_array( $id, (array) $args['post__not_in'], true ) ) { continue; }
		if ( ! empty( $args['category__in'] ) && ! array_intersect( $cats, $args['category__in'] ) ) { continue; }
		$out[] = $id;
	}
	// A nested query fires the widget's hook again in Elementor: model that.
	do_action( 'elementor/query/dpt_related_fill', new DPT_Stub_Q( array() ), null );
	return array_slice( $out, (int) $pq->get( 'offset', 0 ), $args['posts_per_page'] );
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $tag, $callback, $priority = 10 ) { return remove_filter( $tag, $callback ); }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		foreach ( (array) ( $GLOBALS['dpt_stub_filters'][ $tag ] ?? array() ) as $h ) { call_user_func_array( $h['callback'], $args ); }
	}
}
class DPT_Stub_Q {
	public $qv;
	public function __construct( $qv ) { $this->qv = $qv; }
	public function get( $k, $d = '' ) { return array_key_exists( $k, $this->qv ) ? $this->qv[ $k ] : $d; }
	public function set( $k, $v ) { $this->qv[ $k ] = $v; }
}

require_once dirname( __DIR__ ) . '/modules/related-fill/class-dpt-rf-module.php';

$GLOBALS['dpt_stub_filters'] = array();
$m = new DPT_Related_Fill_Module();
dpt_test_eq( $m->id(), 'related_fill', 'the module id' );
dpt_test_eq( $m->affects_frontend(), true, 'once a widget is configured, switching the module changes its page, so the toggle purges (PR #87 Codex P2)' );
$m->init();
dpt_test_ok( dpt_stub_has_filter( 'elementor/query/dpt_related_fill' ), 'it listens only on its own Query ID' );

$related = function ( $extra = array() ) {
	// What Elementor's related query hands the hook.
	return new DPT_Stub_Q( array_merge( array(
		'post_type'      => 'post',
		'posts_per_page' => 3,
		'post__not_in'   => array( 100 ),
		'tax_query'      => array( array( 'taxonomy' => 'category', 'terms' => array( 7 ) ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	), $extra ) );
};

// 100 is the current post. 1 and 2 share its category; 3..6 do not.
$GLOBALS['dpt_stub_pool'] = array( 100 => array( 7 ), 3 => array( 9 ), 1 => array( 7 ), 4 => array( 9 ), 2 => array( 7 ), 5 => array( 9 ), 99 => array( 9 ) );

$q = $related();
$m->fill( $q );
dpt_test_eq( array_slice( $q->get( 'post__in' ), 0, 3 ), array( 1, 2, 3 ), 'its two category posts first, then the newest other post: a full row of 3' );
dpt_test_eq( $q->get( 'orderby' ), 'post__in', 'shown in that order' );
dpt_test_eq( $q->get( 'tax_query' ), array(), 'the category filter is cleared, or it would drop the fill' );
dpt_test_ok( true === $q->get( 'ignore_sticky_posts' ), 'sticky posts do not push in' );
dpt_test_eq( (int) $q->get( 'posts_per_page' ), 3, 'and the row size is kept' );
$a = $GLOBALS['dpt_stub_get_posts'][0];
dpt_test_ok( 'publish' === $a['post_status'] && 'ids' === $a['fields'] && false === $a['suppress_filters'] && 'date' === $a['orderby'] && 'DESC' === $a['order'], 'published only, newest first, and language plugins still filter it' );

$GLOBALS['dpt_stub_get_posts'] = array();
$q = $related( array( 'post__not_in' => array( 100, 1 ) ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 2, 3, 4, 5, 99 ), 'the widget\'s own exclusions stay excluded, and nothing appears twice' );

// The whole ordered list goes to post__in, so WordPress applies the
// widget's offset and page and counts pages for its pagination; the hook
// leaves those alone. (PR #87 Codex P2 x2)
$q = $related( array( 'offset' => 1, 'paged' => 2 ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3, 4, 5, 99 ), 'every candidate, in order: category posts, then the newest' );
dpt_test_eq( array( (int) $q->get( 'offset' ), (int) $q->get( 'paged' ), (int) $q->get( 'posts_per_page' ) ), array( 1, 2, 3 ), 'offset, page and row size are the widget\'s own, for WordPress to apply' );
// Elementor keeps its offset callback on pre_get_posts while the widget's
// query is being built, and it sets the widget's offset on every query - the
// lookups included. They must not skip posts the outer query skips again.
// (PR #87 Codex P2)
$dpt_elementor_offset = function ( $wpq ) { $wpq->set( 'offset', 1 ); };
add_action( 'pre_get_posts', $dpt_elementor_offset, 1 );
$q = $related( array( 'offset' => 1 ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3, 4, 5, 99 ), 'the lookups ignore the widget\'s offset; only the outer query applies it' );
$dpt_left = array_filter( (array) $GLOBALS['dpt_stub_filters']['pre_get_posts'], function ( $h ) use ( $m ) { return is_array( $h['callback'] ) && $h['callback'][0] === $m; } );
dpt_test_eq( count( $dpt_left ), 0, 'and the guard is removed once the lookups are done, so no other query is touched' );
$GLOBALS['dpt_stub_filters']['pre_get_posts'] = array();

// The widget's other filters stay on the outer query, so the lookups apply
// them too - or the cap fills with posts the outer query then drops.
// (PR #87 Codex P2)
$GLOBALS['dpt_stub_get_posts'] = array();
$q = $related( array( 'author__in' => array( 5 ), 'author__not_in' => array( 6 ), 'author' => '5', 'date_query' => array( array( 'after' => '1 year ago' ) ), 'meta_query' => array( array( 'key' => 'k' ) ) ) );
$m->fill( $q );
foreach ( $GLOBALS['dpt_stub_get_posts'] as $dpt_a ) {
	dpt_test_ok( array( 5 ) === $dpt_a['author__in'] && array( 6 ) === $dpt_a['author__not_in'] && '5' === $dpt_a['author'] && ! empty( $dpt_a['date_query'] ) && ! empty( $dpt_a['meta_query'] ), 'a lookup carries the widget\'s author, date and meta filters' );
}
$GLOBALS['dpt_stub_get_posts'] = array();
$m->fill( $related() );
dpt_test_ok( ! array_key_exists( 'author__in', $GLOBALS['dpt_stub_get_posts'][0] ), 'and adds none the widget did not set' );

add_filter( 'dpt_related_fill_max', function () { return 4; } );
$q = $related();
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3, 4 ), 'the list is capped (dpt_related_fill_max)' );
$GLOBALS['dpt_stub_filters']['dpt_related_fill_max'] = array();
add_filter( 'dpt_related_fill_max', function () { return 1; } );
$q = $related();
$m->fill( $q );
dpt_test_eq( count( $q->get( 'post__in' ) ), 3, 'but never below one full row' );
$GLOBALS['dpt_stub_filters']['dpt_related_fill_max'] = array();

$q = $related( array( 'posts_per_page' => 5 ) );
$m->fill( $q );
dpt_test_eq( array_slice( $q->get( 'post__in' ), 0, 5 ), array( 1, 2, 3, 4, 5 ), 'a row of 5 fills to 5' );
$q = $related( array( 'posts_per_page' => -1 ) );
$m->fill( $q );
dpt_test_eq( (int) $q->get( 'posts_per_page' ), 3, 'no usable row size: 3' );

$GLOBALS['dpt_stub_cats'][100] = array( 7, 8 );
$GLOBALS['dpt_stub_pool'] = array( 100 => array( 7 ), 6 => array( 8 ), 1 => array( 7 ), 3 => array( 9 ) );
$q = $related();
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 6, 1, 3 ), 'any of the post\'s categories counts' );

$GLOBALS['dpt_stub_cats'][100] = array( 7 );
$GLOBALS['dpt_stub_pool'] = array( 100 => array( 7 ), 1 => array( 7 ), 2 => array( 7 ), 8 => array( 7 ), 3 => array( 9 ) );
$GLOBALS['dpt_stub_get_posts'] = array();
$q = $related();
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 8, 3 ), 'category posts first, then the rest' );

$GLOBALS['dpt_stub_pool'] = array( 100 => array( 7 ) );
$q = $related();
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in', 'unset' ), 'unset', 'nothing to show: the query is left as it was, so Elementor\'s own fallback can act' );

$GLOBALS['dpt_stub_queried'] = 0;
$GLOBALS['dpt_stub_pool'] = array( 1 => array( 7 ), 2 => array( 9 ), 3 => array( 9 ) );
$q = $related( array( 'post__not_in' => array() ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3 ), 'outside a single post: just the newest posts' );

$src = (string) file_get_contents( dirname( __DIR__ ) . '/modules/related-fill/class-dpt-rf-module.php' );
dpt_test_ok( false === strpos( $src, 'render' ) && false === strpos( $src, 'echo' ), 'it changes which posts, never the card markup' );
$registry = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-dpt-plugin.php' );
dpt_test_ok( 1 === preg_match( "/'related_fill'\s*=>\s*array\(\s*'file'\s*=>\s*DPT_PATH \. 'modules\/related-fill\/class-dpt-rf-module\.php',\s*'class'\s*=>\s*'DPT_Related_Fill_Module',\s*'default'\s*=>\s*'0'/", $registry ), 'registered, off by default like every module' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
