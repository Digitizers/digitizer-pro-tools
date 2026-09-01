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
function home_url( $path = '' ) { return 'https://example.com/site' . $path; }
function is_ssl() { return true; }
$GLOBALS['dpt_stub_queried_id'] = 0;
$GLOBALS['dpt_stub_front_page'] = false;
function get_queried_object_id() { return (int) $GLOBALS['dpt_stub_queried_id']; }
function is_front_page() { return (bool) $GLOBALS['dpt_stub_front_page']; }
function is_home() { return false; }
function wp_login_url( $redirect = '' ) { return 'https://example.com/site/wp-login.php?redirect_to=' . rawurlencode( $redirect ); }

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
		if ( 'match_front' === $name ) {
			return ! empty( $context['main']['is_front_page'] );
		}
		if ( 'match_all' === $name ) {
			return true;
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
	public function is_singular() { return ! empty( $this->qv['singular'] ); }
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

/* ---- singular main queries are template_redirect's job (round-1 P1) ---- */

DPT_CC_Restrictions::save_all( array( dpt_cc_page_rule_row( 'r_hideall', array( 'archive_handling' => 'hide', 'query_handling' => 'hide' ) ) ) );
$qsing = new WP_Query( array( 'main' => 1, 'singular' => 1 ) );
$qsing->posts = array( $page );
$qsing->post_count = 1;
dpt_test_eq( count( $enf->filter_posts( $qsing->posts, $qsing ) ), 1, 'singular main query keeps its post so protection can run' );

/* ---- front-page rules reach the shared post cache slot (round-1 P1) ---- */

DPT_CC_Restrictions::save_all( array( array(
	'id'         => 'r_front',
	'enabled'    => true,
	'who'        => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'conditions' => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_front', 'not' => false, 'options' => array() ) ) ),
) ) );
$GLOBALS['dpt_stub_queried_id'] = 1;
$GLOBALS['dpt_stub_front_page'] = true;
$rowf = $enf->restriction_for_post( $page );
dpt_test_eq( $rowf ? $rowf['id'] : null, 'r_front', 'front-page-only rule bars the queried front page post' );
dpt_test_eq( $enf->restriction_for_post( $post ), null, 'the same rule leaves a non-queried post alone' );
$GLOBALS['dpt_stub_queried_id'] = 0;
$GLOBALS['dpt_stub_front_page'] = false;
DPT_CC_Restrictions::flush_cache();

/* ---- per-post meta always wins over global rules (round-1 P2) ---- */

DPT_CC_Restrictions::save_all( array( dpt_cc_page_rule_row( 'r_global' ) ) );
$GLOBALS['dpt_stub_post_meta'][1]['_dpt_cc_visibility'] = 'logged_in';
dpt_test_eq( $enf->restriction_for_post( $page ), null, 'a post governed by per-post meta is invisible to global enforcement' );
unset( $GLOBALS['dpt_stub_post_meta'][1] );
DPT_CC_Restrictions::flush_cache();
$roww = $enf->restriction_for_post( $page );
dpt_test_eq( $roww ? $roww['id'] : null, 'r_global', 'without the meta, the global rule applies again' );

/* ---- login return URL on subdirectory installs (round-1 P2) ---- */

$_SERVER['REQUEST_URI'] = '/site/private?x=1';
dpt_test_eq( DPT_CC_Enforce::current_request_url(), 'https://example.com/site/private?x=1', 'scheme+host from home_url, path from REQUEST_URI - subdirectory not doubled' );

/* ---- round-2 P1: main-query-only restrictions still show their message ---- */

$enf2 = new DPT_CC_Enforce();
dpt_test_eq( $enf2->filter_main_denial_content( 'body' ), 'body', 'no main denial - content untouched' );
$enf2->deny_main( DPT_CC_Restrictions::sanitize_row( array(
	'id'         => 'r_m',
	'enabled'    => true,
	'protection' => array( 'method' => 'replace', 'override_message' => true, 'custom_message' => 'Members search.' ),
) ) );
$denied = $enf2->filter_main_denial_content( 'body' );
dpt_test_ok( false !== strpos( $denied, 'Members search.' ), 'denied main query renders the row message' );
dpt_test_ok( false === strpos( $denied, 'body' ), 'and the original content is gone' );

/* ---- round-2 P1: redirect target never points back at the denied page ---- */

$_SERVER['REQUEST_URI'] = '/site/';
dpt_test_ok( false !== strpos( DPT_CC_Enforce::redirect_target( 'home', '' ), 'wp-login.php' ), 'redirect-home on the home page falls back to login' );
$_SERVER['REQUEST_URI'] = '/site/private';
dpt_test_eq( DPT_CC_Enforce::redirect_target( 'home', '' ), 'https://example.com/site/', 'redirect-home elsewhere goes home' );
dpt_test_ok( false !== strpos( DPT_CC_Enforce::redirect_target( 'custom', 'https://example.com/site/private' ), 'wp-login.php' ), 'custom target equal to the denied page falls back to login' );
dpt_test_eq( DPT_CC_Enforce::redirect_target( 'custom', 'https://other.test/x' ), 'https://other.test/x', 'other custom targets pass through' );

/* ---- round-2 P1: internal post types and taxonomies stay out of hiding ---- */

DPT_CC_Restrictions::save_all( array( array(
	'id'             => 'r_all',
	'enabled'        => true,
	'who'            => array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ),
	'query_handling' => 'hide',
	'conditions'     => array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'match_all', 'not' => false, 'options' => array() ) ) ),
) ) );
$tpl = (object) array( 'ID' => 40, 'post_type' => 'wp_template' );
$q3  = new WP_Query( array() );
$q3->posts = array( $tpl, $page );
$q3->post_count = 2;
$out3 = $enf->filter_posts( $q3->posts, $q3 );
dpt_test_eq( count( $out3 ), 1, 'public page hidden by the entire-site rule' );
dpt_test_eq( $out3[0]->ID, 40, 'wp_template row survives - plumbing, not content' );
$menu_term = (object) array( 'term_id' => 9, 'taxonomy' => 'nav_menu', 'parent' => 0 );
$out4 = $enf->filter_terms( array( $menu_term ), array( 'nav_menu' ), array(), null );
dpt_test_eq( count( $out4 ), 1, 'nav_menu term survives query hiding' );

exit( dpt_test_summary() );
