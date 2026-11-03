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
	$out = array();
	foreach ( $GLOBALS['dpt_stub_pool'] as $id => $cats ) {
		if ( in_array( $id, (array) $args['post__not_in'], true ) ) { continue; }
		if ( ! empty( $args['category__in'] ) && ! array_intersect( $cats, $args['category__in'] ) ) { continue; }
		$out[] = $id;
	}
	// A nested query fires the widget's hook again in Elementor: model that.
	do_action( 'elementor/query/dpt_related_fill', new DPT_Stub_Q( array() ), null );
	return array_slice( $out, 0, $args['posts_per_page'] );
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
dpt_test_eq( $m->affects_frontend(), false, 'switching it on changes no page until a widget opts in, so no site-wide purge' );
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
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3 ), 'its two category posts first, then the newest other post: a full row of 3' );
dpt_test_eq( $q->get( 'orderby' ), 'post__in', 'shown in that order' );
dpt_test_eq( $q->get( 'tax_query' ), array(), 'the category filter is cleared, or it would drop the fill' );
dpt_test_ok( true === $q->get( 'ignore_sticky_posts' ), 'sticky posts do not push in' );
dpt_test_eq( (int) $q->get( 'posts_per_page' ), 3, 'and the row size is kept' );
$a = $GLOBALS['dpt_stub_get_posts'][0];
dpt_test_ok( 'publish' === $a['post_status'] && 'ids' === $a['fields'] && false === $a['suppress_filters'] && 'date' === $a['orderby'] && 'DESC' === $a['order'], 'published only, newest first, and language plugins still filter it' );

$GLOBALS['dpt_stub_get_posts'] = array();
$q = $related( array( 'post__not_in' => array( 100, 1 ) ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 2, 3, 4 ), 'the widget\'s own exclusions stay excluded, and nothing appears twice' );

$q = $related( array( 'posts_per_page' => 5 ) );
$m->fill( $q );
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 3, 4, 5 ), 'a row of 5 fills to 5' );
$q = $related( array( 'posts_per_page' => -1 ) );
$m->fill( $q );
dpt_test_eq( count( $q->get( 'post__in' ) ), 3, 'no usable row size: 3' );

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
dpt_test_eq( $q->get( 'post__in' ), array( 1, 2, 8 ), 'a full category row needs no fill' );
dpt_test_eq( count( $GLOBALS['dpt_stub_get_posts'] ), 1, 'and runs one lookup, not two' );

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
