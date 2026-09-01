<?php
/**
 * Content Control - enforcement decisions for global restrictions: which
 * row bars a post or term, filter-vs-hide handling per query kind, hiding
 * in post and term lists, denial messages and the no-loop exemption of
 * replacement pages.
 *
 * Redirects and query replacement are exercised on a live site; this file
 * covers every decision they rely on.
 */

require_once __DIR__ . '/bootstrap.php';

// --- the slice of WordPress the enforcer touches (not in bootstrap) ---
$GLOBALS['dpt_stub_is_admin'] = false; // enforcement is a front-end concern
$GLOBALS['dpt_stub_user']     = (object) array( 'ID' => 0, 'roles' => array() );
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function wp_doing_ajax() { return false; }
function wpautop( $s ) { return $s; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';
require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions.php';

// Rule engine substitute: two scripted rules.
class DPT_CC_Rules {
	public static function check( $conditions, $context ) {
		$name = isset( $conditions['items'][0]['name'] ) ? $conditions['items'][0]['name'] : '';
		if ( 'match_page' === $name ) {
			return ! empty( $context['post'] ) && 'page' === $context['post']->post_type;
		}
		if ( 'match_term' === $name ) {
			return ! empty( $context['term'] );
		}
		return false;
	}
}

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-enforce.php';

// Minimal WP_Query stand-in for the list filters.
class WP_Query {
	public $posts = array();
	public $post_count = 0;
	public $qv = array();
	public function __construct( $qv = array() ) { $this->qv = $qv; }
	public function is_main_query() { return ! empty( $this->qv['main'] ); }
	public function is_search() { return ! empty( $this->qv['s'] ); }
	public function get( $k, $d = '' ) { return isset( $this->qv[ $k ] ) ? $this->qv[ $k ] : $d; }
}

function dpt_cc_page_rule_row( $id, $extra = array() ) {
	return array_merge( array(
		'id'         => $id,
		'enabled'    => true,
		'who'        => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
		'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_page', 'not' => false, 'options' => array() ) ) ),
	), $extra );
}

$GLOBALS['dpt_stub_options'] = array();
DPT_CC_Restrictions::save_all( array( dpt_cc_page_rule_row( 'r_pages', array( 'query_handling' => 'hide', 'archive_handling' => 'filter', 'show_in_search' => false ) ) ) );

$enf  = new DPT_CC_Enforce();
$page = (object) array( 'ID' => 1, 'post_type' => 'page' );
$post = (object) array( 'ID' => 2, 'post_type' => 'post' );

/* ---- restriction_for_post ---- */

$row = $enf->restriction_for_post( $page );
dpt_test_eq( $row ? $row['id'] : null, 'r_pages', 'restricted page yields its row for an anonymous visitor' );
dpt_test_eq( $enf->restriction_for_post( $post ), null, 'unmatched post yields null' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 9, 'roles' => array( 'subscriber' ) );
DPT_CC_Restrictions::flush_cache();
dpt_test_eq( $enf->restriction_for_post( $page ), null, 'a user the row admits yields null' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) );
DPT_CC_Restrictions::flush_cache();
dpt_test_eq( $enf->restriction_for_post( $page ), null, 'administrators bypass enforcement' );

$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 0, 'roles' => array() );
DPT_CC_Restrictions::flush_cache();

/* ---- handling_for ---- */

$r = DPT_CC_Restrictions::get( 'r_pages' );
dpt_test_eq( $enf->handling_for( $r, false, false ), 'hide', 'secondary query uses query_handling' );
dpt_test_eq( $enf->handling_for( $r, true, false ), 'filter', 'main archive uses archive_handling (filter)' );
dpt_test_eq( $enf->handling_for( $r, true, true ), 'hide', 'search forces hide while show_in_search is off' );
$r_searchable = DPT_CC_Restrictions::sanitize_row( dpt_cc_page_rule_row( 'r_s', array( 'show_in_search' => true, 'query_handling' => 'filter' ) ) );
dpt_test_eq( $enf->handling_for( $r_searchable, false, true ), 'filter', 'show_in_search lets search fall back to normal handling' );

/* ---- filter_posts ---- */

$q = new WP_Query( array() );
$q->posts = array( $page, $post );
$q->post_count = 2;
$out = $enf->filter_posts( $q->posts, $q );
dpt_test_eq( count( $out ), 1, 'restricted page hidden from a secondary query' );
dpt_test_eq( $out[0]->ID, 2, 'the unrestricted post survives' );
dpt_test_eq( $q->post_count, 1, 'post_count fixed after hiding' );

DPT_CC_Restrictions::flush_cache();
$qm = new WP_Query( array( 'main' => 1 ) );
$qm->posts = array( $page, $post );
$qm->post_count = 2;
dpt_test_eq( count( $enf->filter_posts( $qm->posts, $qm ) ), 2, 'main-query filter handling leaves items for the content filter' );

DPT_CC_Restrictions::flush_cache();
$qs = new WP_Query( array( 'main' => 1, 's' => 'find' ) );
$qs->posts = array( $page, $post );
$qs->post_count = 2;
dpt_test_eq( count( $enf->filter_posts( $qs->posts, $qs ) ), 1, 'search hides even where archives only filter' );

DPT_CC_Restrictions::flush_cache();
$qi = new WP_Query( array( 'ignore_restrictions' => true ) );
$qi->posts = array( $page );
$qi->post_count = 1;
dpt_test_eq( count( $enf->filter_posts( $qi->posts, $qi ) ), 1, 'ignore_restrictions bypasses the filter' );

/* ---- filter_terms ---- */

DPT_CC_Restrictions::save_all( array( array(
	'id'         => 'r_terms',
	'enabled'    => true,
	'who'        => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'query_handling' => 'hide',
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_term', 'not' => false, 'options' => array() ) ) ),
) ) );
$term = (object) array( 'term_id' => 5, 'taxonomy' => 'category', 'parent' => 0 );
$terms_out = $enf->filter_terms( array( $term, 'slug-only' ), array( 'category' ), array(), null );
dpt_test_eq( array_values( array_filter( $terms_out, 'is_object' ) ), array(), 'restricted term object hidden' );
dpt_test_ok( in_array( 'slug-only', $terms_out, true ), 'non-object entries pass through untouched' );

/* term rows with filter handling leave terms alone */
DPT_CC_Restrictions::save_all( array( array(
	'id'         => 'r_terms_soft',
	'enabled'    => true,
	'who'        => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'query_handling' => 'filter',
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_term', 'not' => false, 'options' => array() ) ) ),
) ) );
dpt_test_eq( count( $enf->filter_terms( array( $term ), array( 'category' ), array(), null ) ), 1, 'filter handling keeps the term in lists' );

/* ---- denial message + replacement-page exemption ---- */

DPT_CC_Restrictions::save_all( array( dpt_cc_page_rule_row( 'r_msg', array(
	'protection' => array( 'method' => 'replace', 'replacement_page' => 77, 'override_message' => true, 'custom_message' => 'Members only.' ),
) ) ) );
$rm = DPT_CC_Restrictions::get( 'r_msg' );
dpt_test_eq( $enf->denial_message( $rm ), 'Members only.', 'override message used' );
$rm_plain = DPT_CC_Restrictions::sanitize_row( dpt_cc_page_rule_row( 'r_plain' ) );
dpt_test_eq( $enf->denial_message( $rm_plain ), '', 'no override -> empty, caller falls back to the module default' );

dpt_test_ok( in_array( 77, $enf->exempt_ids(), true ), 'replacement page is exempt' );
$page77 = (object) array( 'ID' => 77, 'post_type' => 'page' );
dpt_test_eq( $enf->restriction_for_post( $page77 ), null, 'the replacement page itself is never restricted (no loop)' );

exit( dpt_test_summary() );
