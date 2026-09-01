<?php
/**
 * Content Control - rule engine: AND/OR with short-circuit, one level of
 * groups, NOT per rule, the built-in content rules, and fail-closed
 * behaviour for unknown rules and empty conditions.
 *
 * The bootstrap already stubs get_post_type_object()/get_taxonomy() with
 * label-less objects; the engine must tolerate that and fall back to the
 * raw name for labels.
 */

require_once __DIR__ . '/bootstrap.php';

// --- the slice of WordPress the rule engine touches (not in bootstrap) ---
function get_post_types( $args = array(), $output = 'names' ) { return array( 'post' => 'post', 'page' => 'page' ); }
function get_taxonomies( $args = array(), $output = 'names' ) { return array( 'category' => 'category' ); }
function is_post_type_hierarchical( $t ) { return 'page' === $t; }
function is_taxonomy_hierarchical( $t ) { return 'category' === $t; }
function get_post_ancestors( $post ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return isset( $GLOBALS['dpt_stub_ancestors'][ $id ] ) ? $GLOBALS['dpt_stub_ancestors'][ $id ] : array();
}
function get_ancestors( $id, $tax = '' ) { return isset( $GLOBALS['dpt_stub_term_ancestors'][ $id ] ) ? $GLOBALS['dpt_stub_term_ancestors'][ $id ] : array(); }
function has_term( $ids, $tax, $post ) {
	$have = isset( $GLOBALS['dpt_stub_post_terms'][ $post->ID ][ $tax ] ) ? $GLOBALS['dpt_stub_post_terms'][ $post->ID ][ $tax ] : array();
	return (bool) array_intersect( array_map( 'intval', (array) $ids ), array_map( 'intval', $have ) );
}
function get_page_template_slug( $post ) { return isset( $post->page_template ) ? $post->page_template : ''; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-rules.php';

$page = (object) array( 'ID' => 10, 'post_type' => 'page', 'post_parent' => 3 );
$post = (object) array( 'ID' => 20, 'post_type' => 'post', 'post_parent' => 0 );
$ctx_page = array( 'type' => 'post', 'post' => $page, 'term' => null );
$ctx_post = array( 'type' => 'post', 'post' => $post, 'term' => null );

function dpt_rule( $name, $opts = array(), $not = false ) {
	return array( 'type' => 'rule', 'name' => $name, 'not' => $not, 'options' => $opts );
}
function dpt_conds( $op, ...$items ) {
	return array( 'operator' => $op, 'items' => $items );
}

/* ---- single rules ---- */
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'entire_site' ) ), $ctx_post ), 'entire_site matches anything' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page' ) ), $ctx_page ), 'content_is_page on a page' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page' ) ), $ctx_post ), 'content_is_page not on a post' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_selected_page', array( 'ids' => '9, 10' ) ) ), $ctx_page ), 'selected IDs hit' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_selected_page', array( 'ids' => '9,11' ) ) ), $ctx_page ), 'selected IDs miss' );

/* ---- NOT ---- */
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page', array(), true ) ), $ctx_post ), 'NOT inverts a false into a true' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'no_such_rule', array(), true ) ), $ctx_post ), 'NOT does not rescue an unknown rule' );

/* ---- AND / OR ---- */
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page' ), dpt_rule( 'content_is_selected_page', array( 'ids' => '11' ) ) ), $ctx_page ), 'AND: one false makes false' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'or', dpt_rule( 'content_is_post' ), dpt_rule( 'content_is_page' ) ), $ctx_page ), 'OR: one true makes true' );

/* ---- group (one level) ---- */
$group = array( 'type' => 'group', 'operator' => 'or', 'items' => array( dpt_rule( 'content_is_post' ), dpt_rule( 'content_is_page' ) ) );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'entire_site' ), $group ), $ctx_page ), 'AND(entire_site, OR-group) matches' );
$and_group = array( 'type' => 'group', 'operator' => 'and', 'items' => array( dpt_rule( 'content_is_page' ), dpt_rule( 'content_is_selected_page', array( 'ids' => '99' ) ) ) );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', $and_group ), $ctx_page ), 'AND-group with a miss is false' );

/* ---- hierarchy ---- */
$GLOBALS['dpt_stub_ancestors'] = array( 10 => array( 3, 2 ) );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_child_of_page', array( 'ids' => '3' ) ) ), $ctx_page ), 'child_of via ancestors' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_child_of_page', array( 'ids' => '7' ) ) ), $ctx_page ), 'child_of miss' );
$GLOBALS['dpt_stub_ancestors'][55] = array( 10, 3 );
$parent10 = (object) array( 'ID' => 10, 'post_type' => 'page', 'post_parent' => 3 );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_ancestor_of_page', array( 'ids' => '55' ) ) ), array( 'type' => 'post', 'post' => $parent10, 'term' => null ) ), 'ancestor_of: page 10 is an ancestor of 55' );

/* ---- taxonomy on posts ---- */
$GLOBALS['dpt_stub_post_terms'] = array( 20 => array( 'category' => array( 5 ) ) );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_post_with_category', array( 'ids' => '5' ) ) ), $ctx_post ), 'post with term' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_post_with_category', array( 'ids' => '6' ) ) ), $ctx_post ), 'post with other term misses' );

/* ---- main-query rules ---- */
$ctx_main = array( 'type' => 'main', 'post' => null, 'term' => null, 'main' => array( 'is_front_page' => false, 'is_home' => true, 'is_search' => false, 'is_404' => false, 'is_post_type_archive' => array(), 'is_tax' => array() ) );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_blog_index' ) ), $ctx_main ), 'blog index' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_search_results' ) ), $ctx_main ), 'not search' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_post_archive' ) ), $ctx_main ), 'blog index counts as the post archive' );
$ctx_404 = $ctx_main; $ctx_404['main']['is_404'] = true;
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_404_page' ) ), $ctx_404 ), '404 rule' );

/* ---- term context ---- */
$term = (object) array( 'term_id' => 5, 'taxonomy' => 'category', 'parent' => 4 );
$ctx_term = array( 'type' => 'term', 'post' => null, 'term' => $term );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_selected_tax_category', array( 'ids' => '5' ) ) ), $ctx_term ), 'selected term' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_category_archive' ) ), $ctx_term ), 'term context counts as its taxonomy archive' );
$GLOBALS['dpt_stub_term_ancestors'] = array( 5 => array( 4 ) );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_child_of_tax_category', array( 'ids' => '4' ) ) ), $ctx_term ), 'child of term' );

/* ---- page template ---- */
$tpl = (object) array( 'ID' => 30, 'post_type' => 'page', 'post_parent' => 0, 'page_template' => 'tpl-landing.php' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page_with_template', array( 'template' => 'tpl-landing.php' ) ) ), array( 'type' => 'post', 'post' => $tpl, 'term' => null ) ), 'page template match' );
dpt_test_ok( DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'content_is_page_with_template', array( 'template' => 'default' ) ) ), $ctx_page ), 'default template = no slug' );

/* ---- fail closed ---- */
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'and', dpt_rule( 'no_such_rule' ) ), $ctx_page ), 'unknown rule is false' );
dpt_test_ok( ! DPT_CC_Rules::check( dpt_conds( 'or' ), $ctx_page ), 'empty conditions do not match' );
dpt_test_ok( ! DPT_CC_Rules::check( 'garbage', $ctx_page ), 'non-array conditions do not match' );

/* ---- definitions are exposed for the admin UI ---- */
$defs = DPT_CC_Rules::definitions();
dpt_test_ok( isset( $defs['entire_site']['label'] ) && isset( $defs['entire_site']['category'] ), 'definitions carry label and category' );
dpt_test_eq( $defs['content_is_selected_page']['option'], 'ids', 'ids rules declare their input' );
dpt_test_eq( $defs['content_is_page_with_template']['option'], 'template', 'template rule declares its input' );

exit( dpt_test_summary() );
