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

/* ---- a write that lands the value already stored is not an error ---- */

// update_*_meta() answers false both for a site refusing a write and for a
// write that asked for nothing new - the value already matched. Only the
// harness's write-fails list means the former; everything else must be told
// apart by reading storage back, not by trusting a bare false.
$weight = array( 'meta_key' => 'weight', 'title' => 'Weight', 'type' => 'number', 'fields' => array(), 'object' => 'post' );
dpt_test_ok( true === DPT_RB_Fields::write( $weight, 42, (object) array( 'ID' => 11 ) ), 'a numeric field writes' );
// Storage now holds "42" as a string, the way real meta storage would. The
// second write's sanitizer hands back the int 42 again - comparing raw
// values would find "42" !== 42 and wrongly report a failure; comparing
// what each side reads back as must not.
dpt_test_ok( true === DPT_RB_Fields::write( $weight, 42, (object) array( 'ID' => 11 ) ), 'writing the same value again still reports success, not a failure' );

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

// An id of literal 0 names an id key, but not a usable one - the same
// "cannot resolve" outcome as no id key at all, reached through the
// isset() branch instead of falling past it, so it needs its own check.
dpt_test_ok( is_wp_error( DPT_RB_Fields::write( $qna, array(), (object) array( 'ID' => 0 ) ) ), 'an id of literal 0 is unidentifiable, not post 0' );

// A shape naming both id and term_id is not one core sends, but the helper
// still has to pick one consistently. id/ID, the shape a post read or
// write actually carries, wins wherever both are present.
DPT_RB_Fields::write( $qna, array( array( 'question' => 'Which key wins?', 'answer' => 'id does' ) ), (object) array( 'ID' => 11 ) );
dpt_test_eq( DPT_RB_Fields::read( $qna, array( 'id' => 11, 'term_id' => 999 ) )[0]['question'], 'Which key wins?', 'when a shape carries both id and term_id, id wins' );

/* ---- what the site is told it exposes ---- */

$registered = DPT_RB_Fields::registered();
dpt_test_ok( isset( $registered['post/post'] ), 'the report knows about posts' );
dpt_test_ok( in_array( 'qna', $registered['post/post'], true ), 'and lists the field there' );
dpt_test_ok( isset( $registered['taxonomy/authors'] ), 'and about the taxonomy' );

/* ---- the qna fallback defers to whatever already owns the meta key ---- */

// A site whose own qna field is not a repeater must never have the legacy
// repeater shape laid over its meta key - one meta key with two REST fields
// promising different shapes is exactly the corruption this module exists
// to prevent. The name jet_qna was free (nothing was ever registered under
// that exact name), but the meta key qna was not, and the meta key is what
// matters here.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-basics',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'qna', 'title' => 'Not actually a repeater', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['qna'] ), 'the real, non-repeater qna field is still registered' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'jet_qna is not laid over a meta key a text field already owns' );
dpt_test_ok( ! in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'so it is not reported as a compatibility field either' );
$joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( false !== strpos( $joined, 'jet_qna' ), 'and the absence is explained rather than left for an automation to discover as a 404' );

// A site with no JetEngine qna field at all still gets the fallback shape,
// because ContentEngine's jet_qna writes have nowhere else to land.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'jet_qna falls back to the legacy shape when nothing owns the key' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'and is reported as a compatibility field' );

/* ---- compat() reports only names that actually landed somewhere ---- */

// A site where the authors taxonomy is not on the REST API at all must not
// have its legacy author fields claimed as compatibility fields - nothing
// was registered anywhere, so nothing should be reported as if it had been.
// registered() and compat() back the info endpoint an agent is told to
// trust, so this is the one place a lie cannot be tolerated.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields']     = array();
$saved_taxonomies                    = $GLOBALS['dpt_stub_rest_taxonomies'];
$GLOBALS['dpt_stub_rest_taxonomies'] = array( 'category', 'post_tag' );
DPT_RB_Fields::register();
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['authors'] ), 'nothing is registered for a taxonomy the site does not expose' );
dpt_test_ok( ! in_array( 'author_description', DPT_RB_Fields::compat(), true ), 'so it is not claimed as a compatibility field either' );
dpt_test_ok( ! in_array( 'author_image', DPT_RB_Fields::compat(), true ), 'nor is its sibling' );
dpt_test_ok( ! in_array( 'linkedin', DPT_RB_Fields::compat(), true ), 'nor any other legacy field for that taxonomy' );
$GLOBALS['dpt_stub_rest_taxonomies'] = $saved_taxonomies;

/* ================================================================== */
/* DPT_RB_Elementor - the ported Elementor endpoints                  */
/* ================================================================== */

require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-elementor.php';

/**
 * The slice of WP_REST_Request these endpoints touch: array access for the
 * URL parameter and get_param for the body.
 */
class DPT_Stub_Request implements ArrayAccess {
	private $params;
	public function __construct( $params ) { $this->params = $params; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
	#[\ReturnTypeWillChange]
	public function offsetExists( $key ) { return isset( $this->params[ $key ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $key ) { return $this->get_param( $key ); }
	#[\ReturnTypeWillChange]
	public function offsetSet( $key, $value ) { $this->params[ $key ] = $value; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $key ) { unset( $this->params[ $key ] ); }
}

/* ---- reading an Elementor page ---- */

$GLOBALS['dpt_stub_posts']     = array( 20 => 'page' );
$GLOBALS['dpt_stub_post_meta'] = array();
$layout                        = array(
	array(
		'id'       => 'sec1',
		'elType'   => 'section',
		'elements' => array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Old title', 'align' => 'center' ),
			),
			array(
				'id'         => 'w2',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => str_repeat( 'x', 250 ) ),
			),
		),
	),
);
update_post_meta( 20, '_elementor_data', wp_json_encode( $layout ) );

$response = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 20 ) ) );
dpt_test_eq( $response['widget_count'], 2, 'both widgets are counted' );
dpt_test_eq( $response['tree'][0]['type'], 'section', 'the section is the root' );
dpt_test_eq( $response['tree'][0]['children'][0]['widget'], 'heading', 'with the widget under it' );
dpt_test_eq( $response['tree'][0]['children'][0]['title'], 'Old title', 'and its text pulled out' );
dpt_test_eq( strlen( $response['tree'][0]['children'][1]['editor'] ), 203, 'long content is truncated for readability' );

dpt_test_ok( is_wp_error( DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 99 ) ) ) ), 'a post that does not exist is a 404' );

$GLOBALS['dpt_stub_posts'][21] = 'page';
dpt_test_ok( is_wp_error( DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ) ), 'a post with no Elementor data is a 404 too' );

/* ---- malformed / hand-edited Elementor JSON must not fatal a walk ---- */

// Every recursive walk (tree, count, apply, collect_ids) has to survive an
// element that is a bare scalar, an "elements" key that is not itself an
// array, and settings that are not an array - because the stored data is
// JSON written by another plugin across many versions, or hand-edited.
$GLOBALS['dpt_stub_posts'][22]     = 'page';
$malformed                         = array(
	'not an element at all',
	array(
		'id'       => 'sec2',
		'elType'   => 'section',
		'elements' => 'not-an-array',
	),
	array(
		'id'         => 'w3',
		'elType'     => 'widget',
		'widgetType' => 'heading',
		'settings'   => 'not-an-array-either',
	),
);
update_post_meta( 22, '_elementor_data', wp_json_encode( $malformed ) );
$malformed_response = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 22 ) ) );
dpt_test_ok( ! is_wp_error( $malformed_response ), 'a tree with scalar elements, a non-array "elements", and non-array settings does not fatal' );
dpt_test_eq( $malformed_response['widget_count'], 1, 'the one real widget is still counted past the malformed siblings' );

/* ---- a hand-edited id that is not a scalar must not warn apply() or collect_ids() ---- */

// isset( $element['id'] ) is true for an array id too, so the (string) cast
// that follows it is the one that would raise "Array to string conversion" -
// and then key the id list under the literal string "Array". Both apply()
// and collect_ids() are exercised in the same update() call.
$GLOBALS['dpt_stub_posts'][23] = 'page';
update_post_meta(
	23,
	'_elementor_data',
	wp_json_encode(
		array(
			array(
				'id'         => array( 'not', 'a', 'string' ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Untouched' ),
			),
			array(
				'id'         => 'w4',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Old' ),
			),
		)
	)
);
$array_id_result = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 23,
	'updates' => array(
		array( 'widget_id' => 'w4', 'settings' => array( 'title' => 'New' ) ),
	),
) ) );
dpt_test_ok( ! is_wp_error( $array_id_result ), 'a stored element with a non-scalar id does not fatal apply() or collect_ids()' );
dpt_test_eq( $array_id_result['updates_applied'], 1, 'and the real widget still gets updated' );
dpt_test_eq( $array_id_result['not_found'], array(), 'nothing is misreported as not found because of it' );

/* ---- truncation must not split a multi-byte UTF-8 character ---- */

// This plugin's own sites are Hebrew, two bytes per character in UTF-8. A
// byte-based substr() cuts mid-character routinely, leaving an invalid byte
// sequence in the tree node - which fails wp_json_encode() for the *whole*
// response when the REST server serializes it, not just the one field.
$GLOBALS['dpt_stub_posts'][24] = 'page';
$hebrew                        = str_repeat( 'שלום ', 60 ); // 300 characters, well past the 200-character boundary.
update_post_meta(
	24,
	'_elementor_data',
	wp_json_encode(
		array(
			array(
				'id'         => 'heb1',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => $hebrew ),
			),
		)
	)
);
$hebrew_response = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 24 ) ) );
dpt_test_ok( ! is_wp_error( $hebrew_response ), 'Hebrew content past the truncation boundary does not error' );
$truncated_title = $hebrew_response['tree'][0]['title'];
dpt_test_eq( mb_strlen( $truncated_title, 'UTF-8' ), 203, 'truncated to 200 characters plus the ellipsis, not split mid-character' );
dpt_test_ok( false !== wp_json_encode( $hebrew_response ), 'and the whole response, truncated Hebrew included, still encodes to JSON' );

/* ---- updating one widget without disturbing the rest ---- */

$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;
$result = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'New title' ) ),
		array( 'widget_id' => 'nope', 'settings' => array( 'title' => 'x' ) ),
	),
) ) );

dpt_test_eq( $result['updates_applied'], 1, 'the widget that exists was updated' );
dpt_test_eq( $result['not_found'], array( 'nope' ), 'and the one that does not is reported' );

$saved = json_decode( get_post_meta( 20, '_elementor_data', true ), true );
dpt_test_eq( $saved[0]['elements'][0]['settings']['title'], 'New title', 'the setting changed' );
dpt_test_eq( $saved[0]['elements'][0]['settings']['align'], 'center', 'and the settings around it did not' );
dpt_test_eq( get_post_meta( 20, '_elementor_css', true ), '', 'the stale CSS is gone' );

dpt_test_ok( is_wp_error( DPT_RB_Elementor::update( new DPT_Stub_Request( array( 'post_id' => 20, 'updates' => array() ) ) ) ), 'an empty update list is refused' );
dpt_test_ok( is_wp_error( DPT_RB_Elementor::update( new DPT_Stub_Request( array( 'post_id' => 20, 'updates' => array( array( 'widget_id' => 'w1' ) ) ) ) ) ), 'an update with no settings is refused' );

// widget_id off the wire, not merely stored data - an array here would
// otherwise reach a bare (string) cast, warn "Array to string conversion",
// and key the update under the literal string "Array" instead of being
// rejected.
dpt_test_ok( is_wp_error( DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => array( 'a', 'b' ), 'settings' => array( 'title' => 'x' ) ),
	),
) ) ) ), 'a widget_id that is not a scalar is refused, not silently stringified' );

/* ---- a write that cannot round-trip through JSON must not blank the page ---- */

// wp_json_encode() (json_encode() under the hood) returns false rather than
// a string when the payload contains bytes that are not valid UTF-8. If the
// endpoint wrote that straight to _elementor_data, the page would go blank.
// The pre-update data must survive untouched and the caller must see an error.
$before_bad_encode = get_post_meta( 20, '_elementor_data', true );
$bad_encode_result  = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => "Bad \xB1\x31 bytes" ) ),
	),
) ) );
dpt_test_ok( is_wp_error( $bad_encode_result ), 'invalid UTF-8 that cannot be encoded back to JSON is refused, not silently dropped' );
dpt_test_eq( get_post_meta( 20, '_elementor_data', true ), $before_bad_encode, 'and the previously saved layout is untouched' );

/* ---- and only for someone allowed to edit that post ---- */

// The plugin this replaces asked only whether the user could edit something,
// which let anyone with an author's rights rewrite every page on the site.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 20 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a post this user may not edit is refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'and one they may is allowed' );

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_RB_Elementor::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/elementor/(?P<post_id>\d+)'] ), 'the route is registered where the old plugin had it' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
