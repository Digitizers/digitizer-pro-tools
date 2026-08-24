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

// A repeater whose sub-field list is not a list is still a field - dropping
// its sub-fields silently would make it indistinguishable from a repeater
// that legitimately has none, so it has to be reported.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'malformed-repeater',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array(
					'name'             => 'broken_repeater',
					'title'            => 'Broken',
					'object_type'      => 'field',
					'type'             => 'repeater',
					'repeater-fields'  => 'not-a-list',
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$defs = DPT_RB_Definitions::all();
dpt_test_eq( count( $defs ), 1, 'the repeater itself is still returned' );
dpt_test_eq( $defs[0]['fields'], array(), 'with no sub-fields' );
$joined = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false !== strpos( $joined, 'broken_repeater' ), 'and the missing sub-fields are reported by name' );

// Corrupted option data can put an array anywhere a scalar was expected. None
// of that may be fatal, and none of it may raise a PHP notice - a notice
// raised while a REST response is being built would corrupt the JSON.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => array( 'not', 'a', 'string' ),
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'weird_title', 'title' => array( 'x' ), 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'weird_type', 'title' => 'Weird Type', 'object_type' => 'field', 'type' => array( 'x' ) ),
				array( 'name' => 'weird_kind', 'title' => 'Weird Kind', 'object_type' => array( 'x' ) ),
				array(
					'name'            => 'weird_sub',
					'title'           => 'Weird Sub',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'sub_bad_title', 'type' => 'text', 'title' => array( 'x' ) ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$defs   = DPT_RB_Definitions::all();
$by_key = array();
foreach ( $defs as $d ) {
	$by_key[ $d['meta_key'] ] = $d;
}
dpt_test_ok( isset( $by_key['weird_title'] ), 'an array title does not block the field' );
dpt_test_eq( $by_key['weird_title']['title'], 'weird_title', 'and falls back to the field name' );
dpt_test_ok( ! isset( $by_key['weird_type'] ), 'an array type is treated as not exposed' );
dpt_test_eq( $by_key['weird_sub']['fields'][0]['title'], 'sub_bad_title', 'a sub-field with an array title falls back to its own name' );
dpt_test_ok( ! isset( $by_key['weird_kind'] ), 'a field row whose object_type is an array does not raise a notice, and is skipped' );

require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-schema.php';

/* ---- one field's type decides its schema and its sanitizer ---- */

$text  = array( 'meta_key' => 'reading_time', 'title' => 'Reading time', 'type' => 'text', 'fields' => array() );
$rich  = array( 'meta_key' => 'bio', 'title' => 'Bio', 'type' => 'wysiwyg', 'fields' => array() );
$num   = array( 'meta_key' => 'weight', 'title' => 'Weight', 'type' => 'number', 'fields' => array() );
$media = array( 'meta_key' => 'photo', 'title' => 'Photo', 'type' => 'media', 'fields' => array() );
$sw    = array( 'meta_key' => 'featured', 'title' => 'Featured', 'type' => 'switcher', 'fields' => array() );
$rep   = array(
	'meta_key' => 'qna',
	'title'    => 'FAQ',
	'type'     => 'repeater',
	'fields'   => array(
		array( 'meta_key' => 'question', 'title' => 'Question', 'type' => 'text', 'fields' => array() ),
		array( 'meta_key' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg', 'fields' => array() ),
	),
);

$schema = DPT_RB_Schema::for_descriptor( $text );
dpt_test_eq( $schema['type'], 'string', 'a text field is a string' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $num )['type'], 'number', 'a number is a number' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media )['type'], 'integer', 'media is an attachment id' );

$rs = DPT_RB_Schema::for_descriptor( $rep );
dpt_test_eq( $rs['type'], 'array', 'a repeater is an array' );
dpt_test_eq( $rs['items']['type'], 'object', 'of objects' );
dpt_test_ok( isset( $rs['items']['properties']['question'] ), 'whose properties are the sub-fields' );
dpt_test_eq( $rs['items']['properties']['answer']['type'], 'string', 'each typed from its own definition' );

// Sanitizing. Markup survives a wysiwyg and does not survive a text field:
// that difference is the whole reason the type is carried this far.
dpt_test_eq( DPT_RB_Schema::sanitize( $text, '<b>ten</b> minutes' ), 'ten minutes', 'text is stripped' );
dpt_test_eq( DPT_RB_Schema::sanitize( $rich, '<b>hello</b>' ), '<b>hello</b>', 'a wysiwyg keeps its markup' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media, '-42' ), 42, 'media is a positive integer' );
dpt_test_eq( DPT_RB_Schema::sanitize( $sw, true ), 'true', 'a switcher stores a string' );
dpt_test_eq( DPT_RB_Schema::sanitize( $sw, false ), 'false', 'either way' );
dpt_test_eq( DPT_RB_Schema::sanitize( $text, '0' ), '0', 'a value of "0" is content, not emptiness' );

// A repeater accepts a list of objects, keeps only the keys it knows, and
// says no to anything else.
$clean = DPT_RB_Schema::sanitize( $rep, array(
	array( 'question' => ' Why? ', 'answer' => '<b>Because</b>', 'sneaky' => 'x' ),
) );
dpt_test_eq( $clean[0]['question'], 'Why?', 'sub-values are sanitized by their own type' );
dpt_test_eq( $clean[0]['answer'], '<b>Because</b>', 'including the rich one' );
dpt_test_ok( ! isset( $clean[0]['sneaky'] ), 'a key the definition does not have is dropped' );
dpt_test_eq( DPT_RB_Schema::sanitize( $rep, array() ), array(), 'an empty list stays empty - that is how a field is cleared' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, 'nope' ) ), 'a scalar is not a repeater' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, array( 'nope' ) ) ), 'nor is a list of scalars' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, false ) ), 'and false is not an empty list' );

// Reading. JetEngine has stored repeaters as arrays, as JSON and as PHP
// serialization over the years; all three have to come back as an array.
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, array( array( 'question' => 'q', 'answer' => 'a' ) ) )[0]['question'], 'q', 'an array reads back' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, '[{"question":"q","answer":"a"}]' )[0]['question'], 'q', 'so does JSON' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, serialize( array( array( 'question' => 'q', 'answer' => 'a' ) ) ) )[0]['question'], 'q', 'so does serialized data' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, 'garbage' ), array(), 'and garbage reads as empty' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep, '' ), array(), 'as does nothing at all' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $text, null ), '', 'a scalar field with nothing stored reads as an empty string' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $text, '0' ), '0', 'and "0" reads back as "0"' );

// A checkbox's schema promises an object, so its wire format has to be one -
// wp_json_encode( array() ) is '[]', not '{}', and PHP itself turns option
// keys that look like "0" and "1" into integer array keys, so even a
// populated checkbox can encode as a JSON array unless the read path forces
// the point.
$checkbox = array( 'meta_key' => 'perks', 'title' => 'Perks', 'type' => 'checkbox', 'fields' => array() );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $checkbox )['type'], 'object', 'a checkbox is advertised as an object' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $checkbox, array() ) ), '{}', 'an empty checkbox encodes as an object, not a list' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $checkbox, array( '0' => 'true', '1' => 'false' ) ) ), '{"0":"true","1":"false"}', 'and numeric-looking keys still encode as an object' );

// normalize_read() and sanitize() have to agree with each other, not only
// with json_type(): anything that composes the read and the write in
// process - without an HTTP round trip through json_decode() in between to
// flatten the object back to an array - must get the same map back, or a
// checkbox silently loses every option it had.
dpt_test_eq(
	DPT_RB_Schema::sanitize( $checkbox, DPT_RB_Schema::normalize_read( $checkbox, array( 'wifi' => 'true', 'parking' => 'false' ) ) ),
	array( 'wifi' => 'true', 'parking' => 'false' ),
	'a checkbox read composes back into the same sanitized map'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $checkbox, DPT_RB_Schema::normalize_read( $checkbox, array( '0' => 'true', '1' => 'false' ) ) ),
	array( '0' => 'true', '1' => 'false' ),
	'even when its keys look numeric'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $checkbox, DPT_RB_Schema::normalize_read( $checkbox, array() ) ),
	array(),
	'and an empty checkbox composes back into an empty map, not a wipe'
);

// number and media are advertised as 'number' and 'integer' by json_type(),
// so a read that hands either one back as a string would be the same
// promise-versus-delivery mismatch the checkbox object fix was for - only
// silent, because a JSON number and a JSON string that looks like one are
// easy to mistake for each other until something strict parses the response.
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $num, '42' ) ), '42', 'a number reads back as a number, not "42"' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $num, null ) ), '0', 'and an unset number reads back as 0, not ""' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media, '12' ) ), '12', 'media reads back as an integer, not "12"' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media, null ) ), '0', 'and an unset attachment id reads back as 0, not ""' );

require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-fields.php';

/* ---- discovered fields become REST fields under their own names ---- */

$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-extras',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post', 'ghost' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
		array(
			'id'          => 'author-extras',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'linkedin', 'title' => 'LinkedIn', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['qna'] ), 'the repeater is exposed under its real name' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin'] ), 'and the taxonomy field on its taxonomy' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['ghost'] ), 'a post type the site does not expose to REST is left alone' );

$args = $GLOBALS['dpt_stub_rest_fields']['post']['qna'];
dpt_test_eq( $args['schema']['type'], 'array', 'carrying the schema its type earns' );
dpt_test_ok( is_callable( $args['get_callback'] ), 'with a reader' );
dpt_test_ok( is_callable( $args['update_callback'] ), 'and a writer' );

/* ---- the old plugin's names keep working ---- */

// ContentEngine writes jet_qna. The field is really called qna, and both must
// reach the same meta key, or a working automation breaks on upgrade day.
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'jet_qna is still there' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'and is named as a compatibility alias' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['reading_time'] ), 'so is reading_time, which JetEngine here does not define' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['author_description'] ), 'and the author fields the old plugin promised' );

// But a name the discovery already produced is never taken over by the
// compatibility layer - the real definition wins.
dpt_test_ok( ! in_array( 'linkedin', DPT_RB_Fields::compat(), true ), 'a discovered field is not also a compatibility field' );

/* ---- reading and writing through the callbacks ---- */

$defs = DPT_RB_Definitions::all();
$qna  = null;
foreach ( $defs as $d ) {
	if ( 'qna' === $d['meta_key'] ) {
		$qna = $d;
	}
}

$GLOBALS['dpt_stub_post_meta'] = array();
$post_object                   = array( 'id' => 11 );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object ), array(), 'a post with no FAQ reads as an empty list' );

$written = DPT_RB_Fields::write( $qna, array( array( 'question' => 'Why?', 'answer' => 'Because' ) ), (object) array( 'ID' => 11 ) );
dpt_test_ok( true === $written, 'a valid FAQ is written' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object )[0]['question'], 'Why?', 'and reads back' );

dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, 'nope', (object) array( 'ID' => 11 ) ) ), 'a malformed FAQ is refused' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object )[0]['question'], 'Why?', 'and the stored one is untouched' );

dpt_test_ok( true === DPT_RB_Fields::write( $qna, array(), (object) array( 'ID' => 11 ) ), 'an empty list clears the field' );
dpt_test_eq( DPT_RB_Fields::read( $qna, $post_object ), array(), 'which reads back as empty' );
dpt_test_ok( true === DPT_RB_Fields::write( $qna, array(), (object) array( 'ID' => 11 ) ), 'clearing an already-empty field is still a success' );

// A site that refuses the write must not be told it succeeded.
$GLOBALS['dpt_stub_meta_write_fails'] = array( 'qna' );
dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, array( array( 'question' => 'q', 'answer' => 'a' ) ), (object) array( 'ID' => 11 ) ) ), 'a refused write is an error, not a shrug' );
$GLOBALS['dpt_stub_meta_write_fails'] = array();

// Taxonomy fields live in term meta, and the callbacks are handed terms
// rather than posts.
$linkedin = null;
foreach ( $defs as $d ) {
	if ( 'linkedin' === $d['meta_key'] ) {
		$linkedin = $d;
	}
}
$GLOBALS['dpt_stub_term_meta'] = array();
DPT_RB_Fields::write( $linkedin, 'https://example.test/x', (object) array( 'term_id' => 4 ) );
dpt_test_eq( DPT_RB_Fields::read( $linkedin, array( 'id' => 4 ) ), 'https://example.test/x', 'a taxonomy field round-trips through term meta' );

/* ---- the id helper's other shapes ---- */

// Core always keys a read array as 'id', for posts and terms alike - the
// helper's term_id fallback on the array side is pure defence. It still
// needs its own assertion, or a change that quietly broke it would pass.
dpt_test_eq( DPT_RB_Fields::read( $linkedin, array( 'term_id' => 4 ) ), 'https://example.test/x', 'a read array keyed by term_id instead of id still resolves' );

// Neither shape names an id at all - not a real request core would send,
// but the helper still has to answer with "nothing" rather than a notice.
dpt_test_eq( DPT_RB_Fields::read( $qna, array() ), array(), 'a read target with no id at all reads as empty, not a fatal' );
dpt_test_eq( DPT_RB_Fields::read( $qna, null ), array(), 'and neither an array nor an object resolves to anything either' );
dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, array(), (object) array() ) ), 'a write target with no id at all is refused, not written nowhere' );

/* ---- what the site is told it exposes ---- */

$registered = DPT_RB_Fields::registered();
dpt_test_ok( isset( $registered['post/post'] ), 'the report knows about posts' );
dpt_test_ok( in_array( 'qna', $registered['post/post'], true ), 'and lists the field there' );
dpt_test_ok( isset( $registered['taxonomy/authors'] ), 'and about the taxonomy' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
