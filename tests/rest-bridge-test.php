<?php
require_once __DIR__ . '/bootstrap.php';

/* ---- the harness itself ---- */

// Every later assertion in this file rests on these stubs behaving like the
// WordPress functions they stand in for, so they are checked first.
$GLOBALS['dpt_stub_post_meta'] = array();
update_post_meta( 7, 'colour', 'blue' );
dpt_test_eq( get_post_meta( 7, 'colour', true ), 'blue', 'post meta round-trips' );
dpt_test_eq( get_post_meta( 7, 'missing', true ), '', 'an absent single meta reads as an empty string' );
dpt_test_ok( delete_post_meta( 7, 'colour' ), 'deleting meta that exists succeeds' );
dpt_test_ok( ! delete_post_meta( 7, 'colour' ), 'and deleting it again does not' );

$GLOBALS['dpt_stub_meta_write_fails'] = array( 'stubborn' );
dpt_test_ok( ! update_post_meta( 7, 'stubborn', 'x' ), 'a write the site refuses reports failure' );
$GLOBALS['dpt_stub_meta_write_fails'] = array();

$GLOBALS['dpt_stub_term_meta'] = array();
update_term_meta( 3, 'bio', 'hello' );
dpt_test_eq( get_term_meta( 3, 'bio', true ), 'hello', 'term meta round-trips' );

$GLOBALS['dpt_stub_rest_fields'] = array();
register_rest_field( 'post', 'thing', array( 'schema' => array( 'type' => 'string' ) ) );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['thing'] ), 'a registered REST field is recorded' );

$GLOBALS['dpt_stub_denied_post_caps'] = array( 9 );
dpt_test_ok( current_user_can( 'edit_post', 8 ), 'a post the user may edit' );
dpt_test_ok( ! current_user_can( 'edit_post', 9 ), 'and one they may not' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

// A later task hooks rest_api_init with add_action() and then asserts on it
// with dpt_stub_has_filter() - WordPress keeps one hook registry for both, so
// the stub must too.
$GLOBALS['dpt_stub_filters'] = array();
add_action( 'rest_api_init', '__return_true' );
dpt_test_ok( dpt_stub_has_filter( 'rest_api_init' ), 'add_action records into the same registry as add_filter' );
$GLOBALS['dpt_stub_filters'] = array();

require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-definitions.php';

/* ---- discovery reads JetEngine's own definitions ---- */

// This is the shape JetEngine's admin writes: a list of meta boxes, each with
// args describing what it is attached to and meta_fields describing the
// fields themselves. Tabs and accordions appear in the same list and are not
// data, so they are skipped without complaint.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-extras',
			'args'        => array(
				'name'              => 'Post Extras',
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post', 'page' ),
			),
			'meta_fields' => array(
				array( 'name' => 'reading_time', 'title' => 'Reading time', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'layout_tab', 'title' => 'Layout', 'object_type' => 'tab', 'type' => 'tab' ),
				array(
					'name'             => 'qna',
					'title'            => 'FAQ',
					'object_type'      => 'field',
					'type'             => 'repeater',
					'repeater-fields'  => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg' ),
					),
				),
				array( 'name' => 'mystery', 'title' => 'Mystery', 'object_type' => 'field', 'type' => 'nonesuch' ),
				array( 'title' => 'Nameless', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'author-extras',
			'args'        => array(
				'name'        => 'Author Extras',
				'object_type' => 'taxonomy',
				'allowed_tax' => array( 'authors' ),
			),
			'meta_fields' => array(
				array( 'name' => 'linkedin', 'title' => 'LinkedIn', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array( 'id' => 'broken' ),
		'not-even-an-array',
	),
);
DPT_RB_Definitions::reset();
$defs = DPT_RB_Definitions::all();

$by_key = array();
foreach ( $defs as $d ) {
	$by_key[ $d['meta_key'] ] = $d;
}

dpt_test_eq( count( $defs ), 3, 'three usable fields were found' );
dpt_test_ok( isset( $by_key['reading_time'] ), 'a plain post field' );
dpt_test_eq( $by_key['reading_time']['object'], 'post', 'attached to posts' );
dpt_test_eq( $by_key['reading_time']['targets'], array( 'post', 'page' ), 'on the post types the meta box names' );
dpt_test_eq( $by_key['reading_time']['type'], 'text', 'with its JetEngine type' );

dpt_test_ok( isset( $by_key['qna'] ), 'the repeater' );
dpt_test_eq( count( $by_key['qna']['fields'] ), 2, 'carrying its sub-fields' );
dpt_test_eq( $by_key['qna']['fields'][0]['meta_key'], 'question', 'named as JetEngine names them' );
dpt_test_eq( $by_key['qna']['fields'][1]['type'], 'wysiwyg', 'each with its own type' );

dpt_test_ok( isset( $by_key['linkedin'] ), 'a taxonomy field' );
dpt_test_eq( $by_key['linkedin']['object'], 'taxonomy', 'attached to a taxonomy' );
dpt_test_eq( $by_key['linkedin']['targets'], array( 'authors' ), 'the one the meta box names' );

// What was skipped has to be sayable, because a field that silently fails to
// appear in the API looks like a bug in the API.
$skipped = DPT_RB_Definitions::skipped();
dpt_test_eq( count( $skipped ), 3, 'three rows were skipped' );
$joined = implode( ' | ', $skipped );
dpt_test_ok( false !== strpos( $joined, 'mystery' ), 'the unknown type is named' );
dpt_test_ok( false !== strpos( $joined, 'nonesuch' ), 'along with the type itself' );
dpt_test_ok( false !== strpos( $joined, 'broken' ), 'and the meta box with no fields' );
dpt_test_ok( false === strpos( $joined, 'layout_tab' ), 'while chrome is skipped quietly - it was never data' );

// A site without JetEngine has no option at all, and that is not an error.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
dpt_test_eq( DPT_RB_Definitions::all(), array(), 'no JetEngine, no fields' );
dpt_test_eq( DPT_RB_Definitions::skipped(), array(), 'and nothing to report' );

// A corrupt option must not be fatal.
$GLOBALS['dpt_stub_options'] = array( 'jet_engine_meta_boxes' => 'garbage' );
DPT_RB_Definitions::reset();
dpt_test_eq( DPT_RB_Definitions::all(), array(), 'a corrupt option yields nothing' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
