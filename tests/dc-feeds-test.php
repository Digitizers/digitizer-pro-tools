<?php
/**
 * Disable Comments - the comment feeds go with the comments (#81): no head
 * link to them, a 404 where they would list only switched-off comments, the
 * site-wide feed narrowed when some types keep their comments, and a comment
 * count of 0 where the comments are hidden.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';

/* The slice of WordPress this touches and the harness does not stub. */
$GLOBALS['dpt_stub_types'] = array( 'post' => 'comments', 'page' => 'comments', 'product' => 'comments', 'shop_order' => '' );
function get_post_types( $args = array(), $output = 'names' ) { return array_keys( $GLOBALS['dpt_stub_types'] ); }
function post_type_supports( $type, $feature ) { return isset( $GLOBALS['dpt_stub_types'][ $type ] ) && $feature === $GLOBALS['dpt_stub_types'][ $type ]; }
function add_option( $key, $value ) { $GLOBALS['dpt_stub_options'][ $key ] = $value; return true; }
$GLOBALS['dpt_stub_post_types'] = array( 1 => 'post', 2 => 'page', 3 => 'product', 4 => 'shop_order' );
function get_post_type( $post = null ) { return isset( $GLOBALS['dpt_stub_post_types'][ (int) $post ] ) ? $GLOBALS['dpt_stub_post_types'][ (int) $post ] : false; }
$GLOBALS['dpt_stub_req'] = array( 'comment_feed' => false, 'singular' => false, 'queried' => 0 );
function is_comment_feed() { return $GLOBALS['dpt_stub_req']['comment_feed']; }
function is_singular( $types = '' ) { return $GLOBALS['dpt_stub_req']['singular']; }
function get_queried_object_id() { return $GLOBALS['dpt_stub_req']['queried']; }
$GLOBALS['dpt_stub_status'] = 0;
function status_header( $code ) { $GLOBALS['dpt_stub_status'] = $code; }
function nocache_headers() {}
class DPT_Stub_Query {
	public $is_404 = false;
	public $singular = false;
	public function set_404() { $this->is_404 = true; }
	public function is_singular() { return $this->singular; }
}
$GLOBALS['wp_query'] = new DPT_Stub_Query();
class DPT_Stub_WPDB {
	public $posts = 'wp_posts';
	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $a ) { $sql = preg_replace( '/%s/', "'" . addslashes( $a ) . "'", $sql, 1 ); }
		return $sql;
	}
}
$GLOBALS['wpdb'] = new DPT_Stub_WPDB();

require_once dirname( __DIR__ ) . '/modules/disable-comments/class-dpt-dc-module.php';

$set = function ( $o ) {
	$GLOBALS['dpt_stub_options']['dpt_disable_comments'] = array_merge( DPT_DC_Settings::defaults(), $o );
};
$req = function ( $comment_feed, $singular = false, $queried = 0 ) {
	$GLOBALS['dpt_stub_req']           = compact( 'comment_feed', 'singular', 'queried' );
	$GLOBALS['wp_query']               = new DPT_Stub_Query();
	$GLOBALS['wp_query']->singular     = $singular;
	$GLOBALS['dpt_stub_status']        = 0;
};

$GLOBALS['dpt_stub_filters'] = array();
$m = new DPT_Disable_Comments_Module();
$m->init();
foreach ( array( 'feed_links_show_comments_feed', 'feed_links_extra_show_post_comments_feed', 'template_redirect', 'comment_feed_where', 'get_comments_number' ) as $hook ) {
	dpt_test_ok( dpt_stub_has_filter( $hook ), "hooked: $hook" );
}

/* ---- everything off, WooCommerce reviews switched off too ---- */
$set( array( 'mode' => 'all', 'keep_woo_reviews' => '0' ) );
dpt_test_eq( $m->filter_show_comments_feed( true ), false, 'all comments off: no site-wide comment feed link in the head' );
$req( true );
$m->block_comment_feeds();
dpt_test_ok( $GLOBALS['wp_query']->is_404 && 404 === $GLOBALS['dpt_stub_status'], 'and /comments/feed/ is a 404' );
$req( true, true, 1 );
$m->block_comment_feeds();
dpt_test_ok( $GLOBALS['wp_query']->is_404 && 404 === $GLOBALS['dpt_stub_status'], 'a post\'s own comment feed is a 404' );
$req( false );
$m->block_comment_feeds();
dpt_test_ok( ! $GLOBALS['wp_query']->is_404 && 0 === $GLOBALS['dpt_stub_status'], 'the posts feed and other pages are untouched' );

/* ---- all off, WooCommerce reviews kept (the default) ---- */
$set( array( 'mode' => 'all' ) );
dpt_test_eq( $m->filter_show_comments_feed( true ), true, 'reviews kept: the site-wide feed still has something to list, so its link stays' );
$req( true );
$m->block_comment_feeds();
dpt_test_ok( ! $GLOBALS['wp_query']->is_404, 'and it stays reachable' );
$q = new DPT_Stub_Query();
$where = $m->filter_comment_feed_where( ' AND x', $q );
dpt_test_ok( false !== strpos( $where, "wp_posts.post_type NOT IN ('post','page')" ) && 0 === strpos( $where, ' AND x' ), 'listing only the reviews' );
$req( true, true, 3 );
$m->block_comment_feeds();
dpt_test_ok( ! $GLOBALS['wp_query']->is_404, 'a product\'s review feed stays' );

/* ---- only posts off ---- */
$set( array( 'mode' => 'selected', 'post_types' => array( 'post' ) ) );
$GLOBALS['dpt_stub_req']['queried'] = 1;
dpt_test_eq( $m->filter_show_post_comments_feed( true ), false, 'on a post of a switched-off type, no link to its comment feed' );
$GLOBALS['dpt_stub_req']['queried'] = 2;
dpt_test_eq( $m->filter_show_post_comments_feed( true ), true, 'on a page that keeps comments, the link stays' );
dpt_test_eq( $m->filter_show_post_comments_feed( false ), false, 'and a link core already dropped stays dropped' );
$where = $m->filter_comment_feed_where( ' AND x', new DPT_Stub_Query() );
dpt_test_eq( $where, " AND x AND wp_posts.post_type NOT IN ('post')", 'the site-wide feed leaves out the switched-off type' );
$sq = new DPT_Stub_Query();
$sq->singular = true;
dpt_test_eq( $m->filter_comment_feed_where( ' AND x', $sq ), ' AND x', 'a single post\'s feed query is not narrowed (it is a 404 or allowed as a whole)' );

/* ---- nothing off ---- */
$set( array( 'mode' => 'selected', 'post_types' => array() ) );
dpt_test_eq( $m->filter_comment_feed_where( ' AND x', new DPT_Stub_Query() ), ' AND x', 'nothing off: the feed query is unchanged' );
dpt_test_eq( $m->filter_show_comments_feed( true ), true, 'and the link stays' );

/* ---- comment counts ---- */
$set( array( 'mode' => 'selected', 'post_types' => array( 'post' ) ) );
$GLOBALS['dpt_stub_is_admin'] = false;
dpt_test_eq( $m->filter_comments_number( 5, 1 ), 0, 'a switched-off type shows 0 comments: the comments themselves are hidden' );
dpt_test_eq( $m->filter_comments_number( 5, 2 ), 5, 'a type that keeps comments keeps its count' );
dpt_test_eq( $m->filter_comments_number( 5, 4 ), 5, 'internal types (order notes) are never touched' );
$GLOBALS['dpt_stub_is_admin'] = true;
dpt_test_eq( $m->filter_comments_number( 5, 1 ), 5, 'the admin keeps the real count' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
