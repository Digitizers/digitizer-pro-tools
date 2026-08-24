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

// The other thing update_metadata() does to every value it is handed, which
// a stub that stored what it was given would hide entirely: it unslashes it.
// A caller that does not slash loses its literal backslashes, so an
// assertion about a Windows path or a regular expression means nothing
// unless the stub loses them too.
$stub_path = 'C:\\Users\\hero.png';
dpt_test_eq( wp_unslash( wp_slash( $stub_path ) ), $stub_path, 'wp_slash() and wp_unslash() are inverses on a literal backslash' );
dpt_test_eq( wp_unslash( wp_slash( array( array( 'note' => $stub_path ) ) ) ), array( array( 'note' => $stub_path ) ), 'all the way down a nested array, where a repeater keeps its rows' );
dpt_test_ok( 42 === wp_unslash( 42 ), 'while a value that is not a string comes back as itself, not as a string of itself' );
update_post_meta( 7, 'path', $stub_path );
dpt_test_eq( get_post_meta( 7, 'path', true ), 'C:Usershero.png', 'an unslashed write reaches storage stripped, exactly as update_metadata() leaves it' );
update_post_meta( 7, 'path', wp_slash( $stub_path ) );
dpt_test_eq( get_post_meta( 7, 'path', true ), $stub_path, 'and a slashed one stores the value that was actually asked for' );
delete_post_meta( 7, 'path' );

// The one asymmetry in core's post-meta functions that a stub could not leave
// out without hiding a bug: both writers send a revision id on to the post it
// revises, and the reader does not. Checked here, before anything depends on
// it, because a harness that cannot reproduce "read the revision, write the
// parent" cannot prove that an endpoint has stopped doing it.
$GLOBALS['dpt_stub_posts'] = array(
	5 => 'page',
	6 => array( 'post_type' => 'revision', 'post_parent' => 5 ),
);
dpt_test_eq( wp_is_post_revision( 6 ), 5, 'a revision knows the post it revises' );
dpt_test_ok( ! wp_is_post_revision( 5 ), 'and an ordinary post is not one' );
$GLOBALS['dpt_stub_post_meta'][6] = array( 'sample' => 'the revision\'s own value' );
update_post_meta( 6, 'sample', 'written at the revision' );
dpt_test_eq( get_post_meta( 5, 'sample', true ), 'written at the revision', 'a write aimed at a revision lands on the parent, as core does it' );
dpt_test_eq( get_post_meta( 6, 'sample', true ), 'the revision\'s own value', 'while a read aimed at it still sees the revision - the asymmetry itself' );
delete_post_meta( 6, 'sample' );
dpt_test_eq( get_post_meta( 5, 'sample', true ), '', 'a delete follows the write to the parent' );
$GLOBALS['dpt_stub_posts']     = array();
$GLOBALS['dpt_stub_post_meta'] = array();

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

// A repeater this bridge cannot see a single column of is not registered at
// all. Exposing it would advertise a list of empty objects, and - because
// sanitize() only knows the sub-fields the descriptor carries - every write
// would have nothing to check and nothing to keep. Both halves of why it is
// missing have to be sayable.
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
dpt_test_eq( $defs, array(), 'a repeater with no sub-field this bridge can expose is not registered' );
$joined = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false !== strpos( $joined, 'broken_repeater' ), 'and the missing sub-fields are reported by name' );
dpt_test_ok( false !== strpos( $joined, 'no sub-field this bridge can expose' ), 'along with why the field itself is absent' );

// The same when the sub-fields are a perfectly good list of types this
// bridge does not map - an icon picker and a gallery next to each other is
// an ordinary JetEngine FAQ row.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'unmappable-repeater',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array(
					'name'            => 'icons_only',
					'title'           => 'Icons',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'icon', 'title' => 'Icon', 'type' => 'iconpicker' ),
						array( 'name' => 'shots', 'title' => 'Gallery', 'type' => 'gallery' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
dpt_test_eq( DPT_RB_Definitions::all(), array(), 'a repeater whose every column is an unmapped type is not registered either' );
$joined = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false !== strpos( $joined, 'icons_only' ), 'and the info endpoint can say which field is missing' );

// One mappable column is enough to be worth exposing: the rest are still
// reported, and sanitize() leaves them alone rather than dropping them.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'mixed-repeater',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array(
					'name'            => 'mixed',
					'title'           => 'Mixed',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'icon', 'title' => 'Icon', 'type' => 'iconpicker' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$defs = DPT_RB_Definitions::all();
dpt_test_eq( count( $defs ), 1, 'a repeater with one mappable column is still exposed' );
dpt_test_eq( count( $defs[0]['fields'] ), 1, 'carrying only the columns it understands' );

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
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media )['type'], array( 'integer', 'string', 'object' ), 'media is an id, a URL or the array of both' );

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
	array( 'question' => ' Why? ', 'answer' => '<b>Because</b>', 'icon' => 'fa-star' ),
) );
dpt_test_eq( $clean[0]['question'], 'Why?', 'sub-values are sanitized by their own type' );
dpt_test_eq( $clean[0]['answer'], '<b>Because</b>', 'including the rich one' );

// A key with no sub-field behind it is a column JetEngine has and this
// bridge could not map - an icon picker, a gallery, a nested repeater. read()
// hands those out, so dropping them here would delete one column of every
// row on the GET, modify, PUT round trip this API documents.
dpt_test_eq( $clean[0]['icon'], 'fa-star', 'a key the definition does not have is kept exactly as it arrived' );
dpt_test_eq( DPT_RB_Schema::sanitize( $rep, array() ), array(), 'an empty list stays empty - that is how a field is cleared' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, 'nope' ) ), 'a scalar is not a repeater' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, array( 'nope' ) ) ), 'nor is a list of scalars' );
dpt_test_ok( is_wp_error( DPT_RB_Schema::sanitize( $rep, false ) ), 'and false is not an empty list' );

// The item number in the refusal is the one a person can count to in their
// own payload: the first item is item 1, not item 0.
dpt_test_ok(
	false !== strpos( DPT_RB_Schema::sanitize( $rep, array( 'nope' ) )->get_error_message(), 'Item 1' ),
	'the first item is named as item 1, not item 0'
);
dpt_test_ok(
	false !== strpos( DPT_RB_Schema::sanitize( $rep, array( array( 'question' => 'ok' ), 'nope' ) )->get_error_message(), 'Item 2' ),
	'and the second as item 2'
);

/* ---- the gate WordPress puts in front of every one of these sanitizers ---- */

/**
 * Core validates a registered REST field against the schema it advertises, and
 * sanitizes the value to that type, before the field's own update_callback is
 * ever reached: get_endpoint_args_for_item_schema() gives every property a
 * validate_callback of rest_validate_request_arg and a sanitize_callback of
 * rest_sanitize_request_arg, and WP_REST_Server::dispatch() runs both before
 * the callback. So "the sanitizer is generous" and "the API accepts it" are
 * two different claims, and only the second one is worth anything to a client.
 *
 * These two model that gate for the types this module advertises - closely
 * enough to reproduce the failure they exist to prove is gone: while a switcher
 * was advertised as a string, { "featured": true } was a 400 and the
 * sanitizer's boolean branch could never be reached at all.
 *
 * @param array $schema A schema from DPT_RB_Schema::for_descriptor().
 * @param mixed $value  What a client sent.
 * @return bool
 */
function dpt_rb_test_core_accepts( $schema, $value ) {
	$type = isset( $schema['type'] ) ? $schema['type'] : '';

	if ( is_array( $type ) ) {
		// rest_handle_multi_type_schema(): core picks one type out of the
		// union and validates against that one alone, so a union refuses a
		// value only when nothing in it matches at all.
		$best = dpt_rb_test_core_best_type( $type, $value );
		if ( '' === $best ) {
			return false;
		}
		$schema['type'] = $best;
		return dpt_rb_test_core_accepts( $schema, $value );
	}

	switch ( $type ) {
		case 'boolean':
			// rest_is_boolean(): a real boolean, the strings 'true', 'false',
			// '1' and '0' in any case, and the integers 1 and 0.
			if ( is_bool( $value ) ) {
				return true;
			}
			if ( is_string( $value ) ) {
				return in_array( strtolower( $value ), array( 'false', 'true', '0', '1' ), true );
			}
			if ( is_int( $value ) ) {
				return in_array( $value, array( 0, 1 ), true );
			}
			return false;
		case 'string':
			return is_string( $value );
		case 'number':
			return is_numeric( $value );
		case 'integer':
			return is_numeric( $value ) && round( (float) $value ) === (float) $value;
		case 'object':
			return is_array( $value ) || $value instanceof stdClass;
		case 'array':
			if ( is_scalar( $value ) ) {
				// rest_is_array() answers yes to a scalar and
				// rest_sanitize_array() then makes a list of it with
				// wp_parse_list(), so core accepts one here rather than
				// refusing it - and the list it becomes is what the items
				// below are checked against.
				$value = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
			}
			if ( ! is_array( $value ) ) {
				return false;
			}
			foreach ( $value as $item ) {
				if ( isset( $schema['items'] ) && ! dpt_rb_test_core_accepts( $schema['items'], $item ) ) {
					return false;
				}
				if ( ! isset( $schema['items']['properties'] ) || ! is_array( $item ) ) {
					continue;
				}
				foreach ( $schema['items']['properties'] as $key => $sub ) {
					if ( array_key_exists( $key, $item ) && ! dpt_rb_test_core_accepts( $sub, $item[ $key ] ) ) {
						return false;
					}
				}
			}
			return true;
	}

	return true;
}

/**
 * Which member of a union type core resolves a value to.
 *
 * rest_get_best_type_for_value(): the empty string prefers string, then the
 * union is walked in the order the schema names it, and string is the answer
 * of last resort because it has no check of its own. Only array, object,
 * integer, number and boolean do.
 *
 * That ordering is the reason the schemas here name 'object' for the
 * container a scalar field can also hold: rest_is_array() answers yes to any
 * scalar - wp_parse_list() will happily make a list out of one - so naming
 * 'array' beside 'string' would split a plain select value on its spaces
 * before the module's own sanitizer ever saw it. rest_is_object() answers no
 * to a non-empty scalar, so a string stays a string.
 *
 * @param array $types The union the schema names.
 * @param mixed $value What a client sent.
 * @return string The type name, or '' when the union fits nothing.
 */
function dpt_rb_test_core_best_type( $types, $value ) {
	if ( '' === $value && in_array( 'string', $types, true ) ) {
		return 'string';
	}

	foreach ( $types as $type ) {
		switch ( $type ) {
			case 'array':
				// rest_is_array().
				if ( is_scalar( $value ) || is_array( $value ) ) {
					return 'array';
				}
				break;
			case 'object':
				// rest_is_object().
				if ( is_array( $value ) || $value instanceof stdClass ) {
					return 'object';
				}
				break;
			case 'integer':
				if ( is_int( $value ) ) {
					return 'integer';
				}
				break;
			case 'number':
				if ( is_numeric( $value ) ) {
					return 'number';
				}
				break;
			case 'boolean':
				if ( dpt_rb_test_core_accepts( array( 'type' => 'boolean' ), $value ) ) {
					return 'boolean';
				}
				break;
		}
	}

	return in_array( 'string', $types, true ) ? 'string' : '';
}

/**
 * The other half of that gate: what core hands the update_callback once the
 * value has passed. A boolean property arrives as a real PHP boolean however
 * the client spelled it, which is why the module's own sanitizer sees one.
 *
 * @param array $schema A schema from DPT_RB_Schema::for_descriptor().
 * @param mixed $value  What a client sent.
 * @return mixed
 */
function dpt_rb_test_core_sanitize( $schema, $value ) {
	$type = isset( $schema['type'] ) ? $schema['type'] : '';

	if ( is_array( $type ) ) {
		$best = dpt_rb_test_core_best_type( $type, $value );
		if ( '' === $best ) {
			return $value;
		}
		$schema['type'] = $best;
		return dpt_rb_test_core_sanitize( $schema, $value );
	}

	if ( 'integer' === $type ) {
		return (int) $value;
	}

	if ( 'object' === $type ) {
		// rest_sanitize_object(): an object becomes an array and an array is
		// left exactly as it is, keys and all.
		if ( $value instanceof stdClass ) {
			return get_object_vars( $value );
		}
		return is_array( $value ) ? $value : array();
	}

	if ( 'array' === $type && is_scalar( $value ) ) {
		// rest_sanitize_array() runs a scalar through wp_parse_list(), which
		// splits it on whitespace and commas.
		$value = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	}

	if ( 'array' === $type && is_array( $value ) && isset( $schema['items'] ) && ! isset( $schema['items']['properties'] ) ) {
		foreach ( $value as $index => $item ) {
			$value[ $index ] = dpt_rb_test_core_sanitize( $schema['items'], $item );
		}
		return $value;
	}

	if ( 'string' === $type ) {
		return is_scalar( $value ) ? (string) $value : $value;
	}

	if ( 'boolean' === $type ) {
		// rest_sanitize_boolean(): the strings 'false' and '0' are the two
		// that mean no; everything else follows PHP's own truthiness.
		if ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ) {
			return false;
		}
		return (bool) $value;
	}

	if ( 'array' === $type && is_array( $value ) && isset( $schema['items']['properties'] ) ) {
		foreach ( $value as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $schema['items']['properties'] as $key => $sub ) {
				if ( array_key_exists( $key, $item ) ) {
					$value[ $index ][ $key ] = dpt_rb_test_core_sanitize( $sub, $item[ $key ] );
				}
			}
		}
	}

	return $value;
}

/* ---- a switcher a REST client can actually write ---- */

$sw_schema = DPT_RB_Schema::for_descriptor( $sw );
dpt_test_eq( $sw_schema['type'], 'boolean', 'a switcher is advertised as the yes-or-no it means' );

// The bug this replaces, run against the model above so the model is known to
// be able to see it: with the string type the field used to advertise, the
// natural JSON payload was refused before any sanitizer ran.
dpt_test_ok( ! dpt_rb_test_core_accepts( array( 'type' => 'string' ), true ), 'a JSON boolean does not validate against a string schema - the 400 this fixes' );

// Both spellings get through the gate now, and both say the same thing at the
// end of the round trip: the string JetEngine's own admin reads in storage,
// and a boolean on the way back out to the client.
$switch_cases = array(
	array( true, 'true', true ),
	array( false, 'false', false ),
	array( 'true', 'true', true ),
	array( 'false', 'false', false ),
	array( '1', 'true', true ),
	array( '0', 'false', false ),
);
foreach ( $switch_cases as $case ) {
	$sent    = $case[0];
	$label   = var_export( $sent, true );
	$storage = $case[1];
	$read    = $case[2];

	dpt_test_ok( dpt_rb_test_core_accepts( $sw_schema, $sent ), "a switcher accepts $label" );
	$stored = DPT_RB_Schema::sanitize( $sw, dpt_rb_test_core_sanitize( $sw_schema, $sent ) );
	dpt_test_eq( $stored, $storage, "and stores $label as the string JetEngine keeps" );
	dpt_test_eq( DPT_RB_Schema::normalize_read( $sw, $stored ), $read, "and reads $label back as a boolean" );
	dpt_test_ok( dpt_rb_test_core_accepts( $sw_schema, DPT_RB_Schema::normalize_read( $sw, $stored ) ), "which is a value the advertised schema accepts, for $label" );
}

// And a value this API never wrote: JetEngine's own admin, an older definition
// that stored 1 and 0, a switch nobody has ever touched.
dpt_test_eq( DPT_RB_Schema::normalize_read( $sw, '' ), false, 'a switch JetEngine never wrote reads as off, not as an empty string' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $sw, 'true' ), true, "and one JetEngine's admin switched on reads as on" );
dpt_test_eq( DPT_RB_Schema::normalize_read( $sw, '1' ), true, 'an older 1 in storage means the same thing' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $sw, array( 'junk' ) ), false, 'and corrupt storage is off rather than an affirmative answer' );

// The same field inside a repeater, where the sub-schema does the advertising.
$rep_sw = array(
	'meta_key' => 'rows',
	'title'    => 'Rows',
	'type'     => 'repeater',
	'fields'   => array(
		array( 'meta_key' => 'label', 'title' => 'Label', 'type' => 'text', 'fields' => array() ),
		array( 'meta_key' => 'featured', 'title' => 'Featured', 'type' => 'switcher', 'fields' => array() ),
	),
);
$rep_sw_schema = DPT_RB_Schema::for_descriptor( $rep_sw );
dpt_test_eq( $rep_sw_schema['items']['properties']['featured']['type'], 'boolean', 'a switcher inside a repeater is advertised the same way' );

$sent_rows = array(
	array( 'label' => 'One', 'featured' => true ),
	array( 'label' => 'Two', 'featured' => 'false' ),
);
dpt_test_ok( dpt_rb_test_core_accepts( $rep_sw_schema, $sent_rows ), 'and a row carrying either spelling passes validation' );
$stored_rows = DPT_RB_Schema::sanitize( $rep_sw, dpt_rb_test_core_sanitize( $rep_sw_schema, $sent_rows ) );
dpt_test_eq( $stored_rows[0]['featured'], 'true', 'the row is stored as the string JetEngine keeps' );
dpt_test_eq( $stored_rows[1]['featured'], 'false', 'both ways round' );
$read_rows = DPT_RB_Schema::normalize_read( $rep_sw, $stored_rows );
dpt_test_eq( $read_rows[0]['featured'], true, 'and read back as a boolean' );
dpt_test_eq( $read_rows[1]['featured'], false, 'both ways round again' );
dpt_test_ok( dpt_rb_test_core_accepts( $rep_sw_schema, $read_rows ), 'what the read hands back satisfies the schema the repeater advertises' );

/* ---- a media field, in every format JetEngine can be told to store ---- */

/**
 * One value all the way round: out of storage through the read the API
 * promises, back in through the gate core puts in front of the update
 * callback, through the module's own sanitizer, and into storage again.
 *
 * The last step models what meta storage does to a scalar - it is a text
 * column, so an id goes in as 12 and comes back out as "12" - because a round
 * trip that skips it would not notice a read that only looks stable while the
 * value stays in PHP.
 *
 * @param array $descriptor Field descriptor.
 * @param mixed $stored     What storage holds to begin with.
 * @return array accepted, read, stored again and read again.
 */
function dpt_rb_test_round_trip( $descriptor, $stored ) {
	$schema = DPT_RB_Schema::for_descriptor( $descriptor );
	$read   = DPT_RB_Schema::normalize_read( $descriptor, $stored );

	if ( ! dpt_rb_test_core_accepts( $schema, $read ) ) {
		return array( 'accepted' => false, 'read' => $read );
	}

	$clean  = DPT_RB_Schema::sanitize( $descriptor, dpt_rb_test_core_sanitize( $schema, $read ) );
	$again  = is_scalar( $clean ) ? (string) $clean : $clean;

	return array(
		'accepted' => true,
		'read'     => $read,
		'stored'   => $again,
		'again'    => DPT_RB_Schema::normalize_read( $descriptor, $again ),
	);
}

// JetEngine settles a media field's storage format with a per-field setting,
// value_format, sitting beside the type in the same option row: 'id' for an
// attachment id, 'url' for the attachment's URL, 'both' for the array of the
// two. Discovery carries it, and a field saved before the setting existed
// reads as the id format JetEngine's own control defaults to.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'media-formats',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'photo', 'title' => 'Photo', 'object_type' => 'field', 'type' => 'media' ),
				array( 'name' => 'banner', 'title' => 'Banner', 'object_type' => 'field', 'type' => 'media', 'value_format' => 'url' ),
				array( 'name' => 'hero', 'title' => 'Hero', 'object_type' => 'field', 'type' => 'media', 'value_format' => 'both' ),
				array( 'name' => 'odd', 'title' => 'Odd', 'object_type' => 'field', 'type' => 'media', 'value_format' => array( 'nonsense' ) ),
				array(
					'name'             => 'slides',
					'title'            => 'Slides',
					'object_type'      => 'field',
					'type'             => 'repeater',
					'repeater-fields'  => array(
						array( 'name' => 'shot', 'title' => 'Shot', 'type' => 'media', 'value_format' => 'url' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$media_defs = array();
foreach ( DPT_RB_Definitions::all() as $d ) {
	$media_defs[ $d['meta_key'] ] = $d;
}
dpt_test_eq( $media_defs['photo']['value_format'], 'id', 'a media field with no setting is the id format JetEngine defaults to' );
dpt_test_eq( $media_defs['banner']['value_format'], 'url', 'and one told to store a URL says so' );
dpt_test_eq( $media_defs['hero']['value_format'], 'both', 'and one told to store both' );
dpt_test_eq( $media_defs['odd']['value_format'], 'id', 'while a format JetEngine does not have falls back rather than being passed on' );
dpt_test_eq( $media_defs['slides']['fields'][0]['value_format'], 'url', 'a media column inside a repeater carries its own format too' );

$media_id   = $media_defs['photo'];
$media_url  = $media_defs['banner'];
$media_both = $media_defs['hero'];
$union      = array( 'integer', 'string', 'object' );

// The schema names all three formats whatever this field's setting says. The
// setting belongs to JetEngine: a field can predate it, an editor can change
// it between two requests, and a future version can spell it differently. A
// schema that refuses the data a site really holds is the worse mistake, so
// what is advertised is the union and the shape in hand decides the rest.
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media_id )['type'], $union, 'an id-format media field advertises all three shapes' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media_url )['type'], $union, 'so does a URL-format one' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $media_both )['type'], $union, 'and so does one storing both' );
dpt_test_eq(
	DPT_RB_Schema::for_descriptor( $media_defs['slides'] )['items']['properties']['shot']['type'],
	$union,
	'and a media column inside a repeater is advertised the same way'
);

// The damage this replaces, stated as the test that would have caught it:
// absint() on a stored URL is 0, so the read handed a site back a number
// where its own picture had been.
dpt_test_eq( absint( 'https://example.test/hero.jpg' ), 0, 'absint() on a URL is 0 - the read this fixes' );

$media_shapes = array(
	array( $media_id, '12', 12, '12 an attachment id' ),
	array( $media_url, 'https://example.test/hero.jpg', 'https://example.test/hero.jpg', 'a URL' ),
	array( $media_both, array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ), null, 'an id and URL array' ),
);
foreach ( $media_shapes as $shape ) {
	$descriptor = $shape[0];
	$stored     = $shape[1];
	$label      = $shape[3];
	$trip       = dpt_rb_test_round_trip( $descriptor, $stored );

	dpt_test_ok( $trip['accepted'], "what a media field holding $label reads back is a value its own schema accepts" );
	dpt_test_eq( wp_json_encode( $trip['again'] ), wp_json_encode( $trip['read'] ), "and reading $label, writing it back and reading again changes nothing" );
	dpt_test_ok( dpt_rb_test_core_accepts( DPT_RB_Schema::for_descriptor( $descriptor ), $stored ), "a client can write $label to the field that holds it" );
}

// Each shape reads back as itself rather than as the shape the old integer
// type assumed. This is the finding in one line: a URL must not read as 0.
dpt_test_eq( DPT_RB_Schema::normalize_read( $media_url, 'https://example.test/hero.jpg' ), 'https://example.test/hero.jpg', 'a stored URL reads back as the URL, not as 0' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media_id, '12' ) ), '12', 'a stored id still reads back as the integer it is' );
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $media_both, array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ) ) ),
	wp_json_encode( (object) array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ) ),
	'and the pair reads back as the object the schema names, both halves intact'
);

// A field nobody has filled in. Only here does the site's own format get to
// answer, because there is no value in hand to read the shape off: an id
// field has always answered 0 and consumers rely on it, while 0 would be a
// lie about a field that has never held a number.
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media_id, '' ) ), '0', 'an unset attachment id is still 0' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $media_url, '' ), '', 'an unset URL is empty, not 0' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $media_both, '' ), '', 'and so is an unset pair' );

// Writing. The shape that arrives decides the cleaning, so a URL is still
// kept out of javascript: and data: and an id is still a positive integer.
dpt_test_eq( DPT_RB_Schema::sanitize( $media_url, 12 ), 12, 'an integer written to a URL-format field stays the id it is' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_id, 'https://example.test/hero.jpg' ), 'https://example.test/hero.jpg', 'and a URL written to an id-format field stays the URL it is' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_url, 'javascript:alert(1)' ), '', 'a javascript: URL is still refused' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_url, 'data:text/html;base64,PHN2Zz4=' ), '', 'and a data: one' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, true ), '', 'a boolean is neither an id nor a URL, and does not become http://1' );

// What core really does to a string that names no protocol: it makes an
// address of it. Pinned here because the assertion below is only worth
// anything against a harness that models it - a lenient esc_url_raw() hid
// the defect that assertion now guards, and would hide it again.
dpt_test_eq( esc_url_raw( 'A hero' ), 'http://Ahero', 'the harness models what core does to a string that is not a URL' );

// A media pair is cleaned by each member's key, not by each member's shape.
// Shape said "not an id, so a URL", so esc_url_raw() ran over every member
// the array had and an alt text of 'A hero' was really stored as
// http://Ahero - the same silent rewrite absint() over a stored URL was, and
// the assertion below used to claim the opposite because the stub was too
// lenient to show it. Only id and url have a format this module knows.
$pair = DPT_RB_Schema::sanitize( $media_both, array( 'id' => '12', 'url' => ' https://example.test/hero.jpg ', 'alt' => 'A hero', 'meta' => array( 'w' => 1 ) ) );
dpt_test_eq( $pair['id'], 12, 'the id half of a pair is cleaned as an id' );
dpt_test_eq( $pair['url'], 'https://example.test/hero.jpg', 'the URL half as a URL' );
dpt_test_eq( $pair['alt'], 'A hero', 'a member this bridge has no format for is kept as it arrived, not made an address of' );
dpt_test_eq( $pair['meta'], array( 'w' => 1 ), 'whatever shape it is in' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'caption' => 'Ben, 2026', 'size' => 'full' ) ), array( 'caption' => 'Ben, 2026', 'size' => 'full' ), 'and so is every other member JetEngine or a site puts in that array' );

// The url member is still a URL whatever it arrives as, which is the half of
// this that a theme's src depends on.
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'id' => 12, 'url' => 'javascript:alert(1)' ) ), array( 'id' => 12, 'url' => '' ), 'the url member is still kept out of javascript:' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'url' => true ) ), array( 'url' => '' ), 'and a boolean url does not become http://1' );

// The id member does not always arrive as an integer. Meta storage keeps a
// number as the string it wrote, a client sends whichever it read, and a
// site can have left something else there entirely - and absint() answers 0
// for all of the last kind, which is the "no attachment" value. A member
// this module cannot read as an id is left alone, so the pair a client just
// read still writes back as itself.
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'id' => '12' ) ), array( 'id' => 12 ), 'an id stored as the string meta storage kept is read as the integer it is' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'id' => 12 ) ), array( 'id' => 12 ), 'and one that arrives as an integer stays one' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'id' => '' ) ), array( 'id' => '' ), 'an empty id - a pair with no attachment chosen - is not turned into 0' );
dpt_test_eq( DPT_RB_Schema::sanitize( $media_both, array( 'id' => 'abc' ) ), array( 'id' => 'abc' ), 'nor is an id this module cannot read as one' );

// The read side shapes the same members the same way, or the schema, the
// write and the read stop agreeing one level inside the pair.
$read_pair = DPT_RB_Schema::normalize_read( $media_both, array( 'id' => '12', 'url' => 'https://example.test/hero.jpg', 'alt' => 'A hero' ) );
dpt_test_eq( $read_pair->id, 12, 'a stored id reads back as the integer it is' );
dpt_test_eq( $read_pair->alt, 'A hero', 'and an unmapped member reads back exactly as stored' );

// The property that matters is the round trip, not any single member: what
// a client reads has to write back as itself, whole.
$whole    = array( 'id' => '12', 'url' => 'https://example.test/hero.jpg', 'alt' => 'A hero', 'meta' => array( 'w' => 1 ) );
$one_trip = DPT_RB_Schema::sanitize( $media_both, DPT_RB_Schema::normalize_read( $media_both, $whole ) );
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $media_both, $one_trip ) ),
	wp_json_encode( DPT_RB_Schema::normalize_read( $media_both, $whole ) ),
	'a pair read and written straight back changes nothing, member for member'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $media_both, DPT_RB_Schema::normalize_read( $media_both, array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ) ) ),
	array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ),
	'and a pair composed straight from a read, with no JSON round trip to flatten the object, writes back unchanged'
);

// The pair a client sends as JSON is an object by the time core is done with
// it, which is the only spelling that ever reaches a live update callback.
$media_schema = DPT_RB_Schema::for_descriptor( $media_both );
$sent_pair    = json_decode( '{"id":12,"url":"https://example.test/hero.jpg"}' );
dpt_test_ok( dpt_rb_test_core_accepts( $media_schema, $sent_pair ), 'the pair a client sends passes validation' );
dpt_test_eq(
	DPT_RB_Schema::sanitize( $media_both, dpt_rb_test_core_sanitize( $media_schema, $sent_pair ) ),
	array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ),
	'and lands in storage as the array JetEngine reads' );

/* ---- a select field, single and multiple ---- */

// JetEngine's Multiple toggle writes is_multiple beside the type, and a
// select with it on stores and submits an array. Its spellings are the ones
// JetEngine's own reader settles with FILTER_VALIDATE_BOOLEAN.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'select-shapes',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'colour', 'title' => 'Colour', 'object_type' => 'field', 'type' => 'select' ),
				array( 'name' => 'tags', 'title' => 'Tags', 'object_type' => 'field', 'type' => 'select', 'is_multiple' => true ),
				array( 'name' => 'flags', 'title' => 'Flags', 'object_type' => 'field', 'type' => 'select', 'is_multiple' => 'true' ),
				array( 'name' => 'plain', 'title' => 'Plain', 'object_type' => 'field', 'type' => 'select', 'is_multiple' => 'false' ),
				array(
					'name'            => 'rows',
					'title'           => 'Rows',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'topics', 'title' => 'Topics', 'type' => 'select', 'is_multiple' => true ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$select_defs = array();
foreach ( DPT_RB_Definitions::all() as $d ) {
	$select_defs[ $d['meta_key'] ] = $d;
}
dpt_test_eq( $select_defs['colour']['multiple'], false, 'a select with no Multiple toggle is a single choice' );
dpt_test_eq( $select_defs['tags']['multiple'], true, 'and one with it on is a list' );
dpt_test_eq( $select_defs['flags']['multiple'], true, "including when the toggle was stored as the string 'true'" );
dpt_test_eq( $select_defs['plain']['multiple'], false, "and 'false' is off, not a non-empty string" );
dpt_test_eq( $select_defs['rows']['fields'][0]['multiple'], true, 'a select column inside a repeater carries its own toggle' );

$one   = $select_defs['colour'];
$many  = $select_defs['tags'];
$one_s = DPT_RB_Schema::for_descriptor( $one );
$many_s = DPT_RB_Schema::for_descriptor( $many );

dpt_test_eq( $many_s['type'], array( 'array', 'string', 'object' ), 'a multi-select is advertised as the list it stores' );
dpt_test_eq( $many_s['items']['type'], 'string', 'of the option strings it holds' );
dpt_test_eq( $one_s['type'], array( 'string', 'object' ), 'and a single select as the string it stores' );
dpt_test_eq(
	DPT_RB_Schema::for_descriptor( $select_defs['rows'] )['items']['properties']['topics']['type'],
	array( 'array', 'string', 'object' ),
	'and a multi-select inside a repeater the same way as one outside it'
);

// The bug this replaces, run against the model of core's gate so the model is
// known to be able to see it: a plain string type refused the list the field
// really holds, before any sanitizer ran.
dpt_test_ok( ! dpt_rb_test_core_accepts( array( 'type' => 'string' ), array( 'red', 'blue' ) ), 'a list does not validate against a string schema - the 400 this fixes' );
dpt_test_eq( DPT_RB_Schema::normalize_read( array( 'meta_key' => 'tags', 'title' => 'Tags', 'type' => 'text', 'fields' => array() ), array( 'red', 'blue' ) ), '', 'and the string read hands an array back as "" - the read this fixes' );

// Every shape, both ways round.
// The last column is whether a read, write, read of that shape is expected to
// leave storage exactly as it was. Every shape but one is: the exception is a
// field whose toggle has just been turned on while a single string is still
// in storage, where core's own array handling makes a one-item list of it on
// the way back in. Nothing is lost - the option is still there, in the shape
// the field now means - and it is asserted below rather than left implied.
$select_trips = array(
	array( $many, array( 'red', 'blue' ), 'a multi-select holding a list', true ),
	array( $many, '', 'a multi-select nobody has chosen from', true ),
	array( $many, 'red', 'a multi-select still holding the string it held before the toggle', false ),
	array( $one, 'New York', 'a single select holding a string', true ),
	array( $one, '', 'a single select nobody has chosen from', true ),
	array( $one, array( 'red', 'blue' ), 'a single select whose storage holds a list anyway', true ),
);
foreach ( $select_trips as $trip_case ) {
	$descriptor = $trip_case[0];
	$stored     = $trip_case[1];
	$label      = $trip_case[2];
	$trip       = dpt_rb_test_round_trip( $descriptor, $stored );

	dpt_test_ok( $trip['accepted'], "what $label reads back is a value its own schema accepts" );
	dpt_test_ok( dpt_rb_test_core_accepts( DPT_RB_Schema::for_descriptor( $descriptor ), $stored ), "and a client can write what $label holds" );
	if ( $trip_case[3] ) {
		dpt_test_eq( wp_json_encode( $trip['again'] ), wp_json_encode( $trip['read'] ), "and reading $label, writing it back and reading again changes nothing" );
	}
}

// The one shape that does not survive unchanged, said out loud: a string left
// in storage from before the Multiple toggle was turned on becomes the
// one-item list the field now means, because core makes a list of a scalar
// written to an array-typed field before this module sees it. The option
// itself is not lost, which is the part that matters.
$carried = dpt_rb_test_round_trip( $many, 'red' );
dpt_test_eq( $carried['read'], 'red', 'the string still in storage reads back as itself' );
dpt_test_eq( $carried['again'], array( 'red' ), 'and writing it back leaves the same option, now as the list the field means' );

// The list reads back as the list, which is the finding in one line.
dpt_test_eq( DPT_RB_Schema::normalize_read( $many, array( 'red', 'blue' ) ), array( 'red', 'blue' ), 'a multi-select reads its list back, not an empty string' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $many, array( 'red', 'blue' ) ) ), '["red","blue"]', 'and encodes as the JSON array its schema names' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $many, array() ), array(), 'an empty multi-select is an empty list' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $many, '' ), '', 'and one that has never been written is what storage holds' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $one, 'New York' ), 'New York', 'a single select still reads back its string' );

// A field whose Multiple toggle this module failed to see - the case the
// setting name being wrong would produce - still hands every option back
// rather than dropping the value on the floor.
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $one, array( 'red', 'blue' ) ) ),
	wp_json_encode( (object) array( 'red', 'blue' ) ),
	'a list stored on a field advertised as a string reads back with both options, as the object that schema names'
);
dpt_test_ok( dpt_rb_test_core_accepts( $one_s, array( 'red', 'blue' ) ), 'and a client can write that list back' );
dpt_test_eq( DPT_RB_Schema::sanitize( $one, dpt_rb_test_core_sanitize( $one_s, array( 'red', 'blue' ) ) ), array( 'red', 'blue' ), 'landing in storage as the list it was' );

// The reason the string-first variant names 'object' and not 'array' for its
// container: core resolves a union by walking it, rest_is_array() says yes to
// any scalar, and wp_parse_list() then splits it on whitespace and commas.
dpt_test_eq( dpt_rb_test_core_best_type( array( 'string', 'object' ), 'New York' ), 'string', 'a plain select value resolves as the string it is' );
dpt_test_eq( dpt_rb_test_core_sanitize( $one_s, 'New York' ), 'New York', 'and survives the gate whole' );
dpt_test_eq( dpt_rb_test_core_sanitize( array( 'type' => array( 'string', 'array' ) ), 'New York' ), array( 'New', 'York' ), 'while naming array beside string would have split it in two' );

// Writing. A list is cleaned option by option and a single value as itself.
dpt_test_eq( DPT_RB_Schema::sanitize( $many, array( ' red ', '<b>blue</b>' ) ), array( 'red', 'blue' ), 'each option of a list is sanitized on its own' );
dpt_test_eq( DPT_RB_Schema::sanitize( $one, ' <b>red</b> ' ), 'red', 'and a single value the same way' );
$odd_list = DPT_RB_Schema::sanitize( $many, array( 'red', array( 'nested' => 1 ) ) );
dpt_test_eq( $odd_list[1], array( 'nested' => 1 ), 'a member with no string form is kept as it arrived rather than emptied' );
dpt_test_eq(
	DPT_RB_Schema::sanitize( $many, DPT_RB_Schema::normalize_read( $many, array( 'red', 'blue' ) ) ),
	array( 'red', 'blue' ),
	'and a list composed straight from a read writes back unchanged'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $one, DPT_RB_Schema::normalize_read( $one, array( 'red', 'blue' ) ) ),
	array( 'red', 'blue' ),
	'as does the object a string-advertised field hands back, object and all'
);

// A url field is not a JetEngine type - discovery never produces one - but
// the legacy descriptors have two, and a theme prints both straight into an
// href or a src. A text sanitizer would leave a javascript: URL intact.
$url = array( 'meta_key' => 'linkedin', 'title' => 'LinkedIn', 'type' => 'url', 'fields' => array() );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $url )['type'], 'string', 'a url is advertised as a string, which is what it produces' );
dpt_test_eq( DPT_RB_Schema::sanitize( $url, 'https://example.test/in/someone' ), 'https://example.test/in/someone', 'a real URL survives' );
dpt_test_eq( DPT_RB_Schema::sanitize( $url, 'javascript:alert(1)' ), '', 'a javascript: URL does not' );
dpt_test_eq( DPT_RB_Schema::sanitize( $url, 'data:text/html;base64,PHN2Zz4=' ), '', 'nor a data: one' );
dpt_test_eq( DPT_RB_Schema::sanitize( $url, array( 'x' ) ), '', 'and an array is not a URL at all' );
dpt_test_ok( ! DPT_RB_Definitions::known_type( 'url' ), 'while url stays out of the types discovery maps - JetEngine has none' );

// Discovery finds every field a site has, and this module cannot tell an
// internal one from a public one. The safe default is the one that cannot
// hand a client's data to an anonymous GET /wp/v2/posts.
$discovered_note = array( 'meta_key' => 'internal_note', 'title' => 'Note', 'type' => 'text', 'fields' => array(), 'object' => 'post' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $discovered_note, 'post' )['context'], array( 'edit' ), 'a discovered field is readable only in the edit context' );

// The names the replaced plugin already published stay public, or upgrading
// to this module would break a theme reading them anonymously.
$legacy_reading = array( 'meta_key' => 'reading_time', 'title' => 'Reading time', 'type' => 'text', 'fields' => array(), 'object' => 'post' );
$legacy_faq     = array( 'meta_key' => 'qna', 'title' => 'FAQ', 'type' => 'repeater', 'fields' => array(), 'object' => 'post' );
$legacy_avatar  = array( 'meta_key' => 'author_image', 'title' => 'Avatar', 'type' => 'url', 'fields' => array(), 'object' => 'taxonomy' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $legacy_reading, 'post' )['context'], array( 'view', 'edit' ), 'a legacy field keeps its public read' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $legacy_faq, 'post' )['context'], array( 'view', 'edit' ), 'so does the FAQ, whichever name it is read under' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $legacy_avatar, 'authors' )['context'], array( 'view', 'edit' ), 'and the author fields on their taxonomy' );

// But only on the target the old plugin published them on. It put
// reading_time on post and the author fields on the authors taxonomy and
// nowhere else, so a site that happens to name its own field the same thing
// somewhere else is not thereby publishing it: the exemption is a statement
// about what was already public, not a licence for the name anywhere.
$reading_elsewhere = array( 'meta_key' => 'reading_time', 'title' => 'Reading time', 'type' => 'text', 'fields' => array(), 'object' => 'post' );
$bio_elsewhere     = array( 'meta_key' => 'author_description', 'title' => 'Bio', 'type' => 'wysiwyg', 'fields' => array(), 'object' => 'taxonomy' );
$faq_elsewhere     = array( 'meta_key' => 'qna', 'title' => 'FAQ', 'type' => 'repeater', 'fields' => array(), 'object' => 'post' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $reading_elsewhere, 'client_brief' )['context'], array( 'edit' ), 'a legacy key on another post type is not published by the name alone' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $bio_elsewhere, 'client' )['context'], array( 'edit' ), 'nor on another taxonomy' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $faq_elsewhere, 'page' )['context'], array( 'edit' ), 'nor a FAQ on a post type the old plugin never touched' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $reading_elsewhere, 'post' )['context'], array( 'view', 'edit' ), 'while the same key on its original target keeps its public read' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $bio_elsewhere, 'authors' )['context'], array( 'view', 'edit' ), 'and so does the author bio on the authors taxonomy' );

// A caller with no target to name gets the private answer: there is no
// target for the exemption to be true of, and the safe default is the one
// that cannot publish a site's data by accident.
dpt_test_eq( DPT_RB_Schema::for_descriptor( $legacy_reading )['context'], array( 'edit' ), 'a schema asked for with no target at all is private' );

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
// with type_schema(): anything that composes the read and the write in
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

// A number is advertised as 'number' by type_schema(), and a media field
// names 'integer' among its own three shapes; a read that hands either one
// back as a string would be the same
// promise-versus-delivery mismatch the checkbox object fix was for - only
// silent, because a JSON number and a JSON string that looks like one are
// easy to mistake for each other until something strict parses the response.
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $num, '42' ) ), '42', 'a number reads back as a number, not "42"' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $num, null ) ), '0', 'and an unset number reads back as 0, not ""' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media, '12' ) ), '12', 'media reads back as an integer, not "12"' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $media, null ) ), '0', 'and an unset attachment id reads back as 0, not ""' );

// A repeater advertises a type per sub-field, so the same promise holds one
// level down: a sub-field advertised as a number that reads back as the
// string storage happened to hold is the same mismatch, just harder to see.
$rep_typed = array(
	'meta_key' => 'stock',
	'title'    => 'Stock',
	'type'     => 'repeater',
	'fields'   => array(
		array( 'meta_key' => 'sku', 'title' => 'SKU', 'type' => 'text', 'fields' => array() ),
		array( 'meta_key' => 'qty', 'title' => 'Quantity', 'type' => 'number', 'fields' => array() ),
		array( 'meta_key' => 'perks', 'title' => 'Perks', 'type' => 'checkbox', 'fields' => array() ),
	),
);
$typed_read = DPT_RB_Schema::normalize_read( $rep_typed, array( array( 'sku' => 'A1', 'qty' => '42', 'perks' => array(), 'icon' => 'fa-star' ) ) );
dpt_test_eq( $typed_read[0]['qty'], 42, 'a numeric sub-field reads back as a number' );
dpt_test_ok( false !== strpos( wp_json_encode( $typed_read ), '"qty":42' ), 'and encodes as 42, not "42"' );
dpt_test_ok( false !== strpos( wp_json_encode( $typed_read ), '"perks":{}' ), 'a checkbox sub-field encodes as the object its schema advertises' );
dpt_test_eq( $typed_read[0]['icon'], 'fa-star', 'while a sub-key with no definition behind it is handed back untouched' );

// The same shaping has to happen whichever way storage held the list, or a
// site whose repeaters predate the array format reads back differently from
// one whose do not.
$typed_json = DPT_RB_Schema::normalize_read( $rep_typed, '[{"sku":"A1","qty":"42"}]' );
dpt_test_eq( $typed_json[0]['qty'], 42, 'a JSON-stored repeater is shaped the same way' );
$typed_ser = DPT_RB_Schema::normalize_read( $rep_typed, serialize( array( array( 'sku' => 'A1', 'qty' => '42' ) ) ) );
dpt_test_eq( $typed_ser[0]['qty'], 42, 'and so is a serialized one' );

// Corrupt storage can hold a scalar where an item should be. There is no
// sub-field to shape it by and no notice may be raised trying.
dpt_test_eq( DPT_RB_Schema::normalize_read( $rep_typed, array( 'not-an-item' ) ), array( 'not-an-item' ), 'an item that is not an object is handed back as found' );

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

// The two legacy URL fields are sanitized as URLs, not as text. A theme
// prints author_image into a src and linkedin into an href, and anyone who
// may edit a term on the authors taxonomy may write them - so a javascript:
// URL surviving into that meta is a hole the replaced plugin did not have.
$GLOBALS['dpt_stub_term_meta'] = array();
$avatar_write = $GLOBALS['dpt_stub_rest_fields']['authors']['author_image']['update_callback'];
$avatar_read  = $GLOBALS['dpt_stub_rest_fields']['authors']['author_image']['get_callback'];
dpt_test_ok( true === call_user_func( $avatar_write, 'https://example.test/avatar.png', (object) array( 'term_id' => 5 ) ), 'a real avatar URL is written' );
dpt_test_eq( call_user_func( $avatar_read, array( 'id' => 5 ) ), 'https://example.test/avatar.png', 'and reads back' );
call_user_func( $avatar_write, 'javascript:alert(1)', (object) array( 'term_id' => 5 ) );
dpt_test_eq( get_term_meta( 5, 'author_image', true ), '', 'a javascript: URL does not survive into the stored avatar' );
dpt_test_eq( DPT_RB_Schema::for_descriptor( array( 'meta_key' => 'author_image', 'title' => 'x', 'type' => 'url', 'fields' => array(), 'object' => 'taxonomy' ) )['type'], 'string', 'and the field is still advertised as the string it produces' );
dpt_test_ok( in_array( 'author_image', DPT_RB_Fields::compat(), true ), 'and here it is the compatibility layer that registered it, JetEngine defining no such field' );

/* ---- and discovery owning the name does not take the URL treatment away ---- */

// A real site - and the fixture above - defines linkedin on the authors
// taxonomy as a JetEngine text field. Discovery wins the name, so the legacy
// url descriptor is never registered, and the write used to go through
// sanitize_text_field(): javascript:alert(1) storable in metadata this
// module publishes to anonymous readers and the theme prints into an href.
// The object/target/meta-key pair decides the treatment, not the type
// JetEngine happens to give the field.
$fixture_boxes = $GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'];
$fixture_taxes = $GLOBALS['dpt_stub_rest_taxonomies'];

// A second taxonomy, so the site can have a linkedin of its own somewhere
// the replaced plugin never published one.
$GLOBALS['dpt_stub_rest_taxonomies']                    = array( 'category', 'post_tag', 'authors', 'client' );
$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'][] = array(
	'id'          => 'author-avatar',
	'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
	'meta_fields' => array(
		array( 'name' => 'author_image', 'title' => 'Avatar', 'object_type' => 'field', 'type' => 'text' ),
	),
);
$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'][] = array(
	'id'          => 'client-contacts',
	'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'client' ) ),
	'meta_fields' => array(
		array( 'name' => 'linkedin', 'title' => 'LinkedIn handle', 'object_type' => 'field', 'type' => 'text' ),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( ! in_array( 'author_image', DPT_RB_Fields::compat(), true ), 'with both names discovered, neither is a compatibility field any more' );

$disc_link_write = $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['update_callback'];
$disc_link_read  = $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['get_callback'];
call_user_func( $disc_link_write, 'javascript:alert(1)', (object) array( 'term_id' => 71 ) );
dpt_test_eq( get_term_meta( 71, 'linkedin', true ), '', 'a linkedin discovered as a text field on authors still refuses a javascript: URL' );

$disc_img_write = $GLOBALS['dpt_stub_rest_fields']['authors']['author_image']['update_callback'];
$disc_img_read  = $GLOBALS['dpt_stub_rest_fields']['authors']['author_image']['get_callback'];
call_user_func( $disc_img_write, 'javascript:alert(1)', (object) array( 'term_id' => 71 ) );
dpt_test_eq( get_term_meta( 71, 'author_image', true ), '', 'and so does an author_image discovered as one' );

// And the spelling that hides the protocol behind a character a URL may not
// contain, which core removes before it decides what the protocol is.
call_user_func( $disc_link_write, "java\tscript:alert(1)", (object) array( 'term_id' => 71 ) );
dpt_test_eq( get_term_meta( 71, 'linkedin', true ), '', 'an obfuscated javascript: URL is refused too' );

// The treatment has to leave a real URL alone, or it is not a sanitizer but
// a field nobody can use.
dpt_test_ok( true === call_user_func( $disc_link_write, 'https://example.test/in/ben', (object) array( 'term_id' => 71 ) ), 'a real profile URL is written' );
dpt_test_eq( call_user_func( $disc_link_read, array( 'id' => 71 ) ), 'https://example.test/in/ben', 'and round-trips unchanged' );
dpt_test_ok( true === call_user_func( $disc_img_write, 'https://example.test/avatar.png', (object) array( 'term_id' => 71 ) ), 'so is a real avatar URL' );
dpt_test_eq( call_user_func( $disc_img_read, array( 'id' => 71 ) ), 'https://example.test/avatar.png', 'and it round-trips too' );

// The invariant this class is held to: what is advertised, what the
// sanitizer produces and what the read hands back are one answer.
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['schema']['type'], 'string', 'the schema still advertises the string the sanitizer produces' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['schema']['context'], array( 'view', 'edit' ), 'and the field is as public as it always was' );

// The pair is the key, not the name alone. A linkedin on some other
// taxonomy is the text field the site defined it as - this module has no
// business deciding what a name it never published means there.
$client_write = $GLOBALS['dpt_stub_rest_fields']['client']['linkedin']['update_callback'];
call_user_func( $client_write, 'javascript:alert(1)', (object) array( 'term_id' => 72 ) );
dpt_test_eq( get_term_meta( 72, 'linkedin', true ), 'javascript:alert(1)', 'a linkedin on another taxonomy is left as the text field the site defined' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['client']['linkedin']['schema']['context'], array( 'edit' ), 'and it is not published to anonymous readers either' );

// Only a field the type map already calls a plain string is forced. A
// media author_image is a definition a real site has, it keeps its own URL
// half out of javascript: already, and esc_url_raw() over the id-and-URL
// pair would answer '' for the whole of it - deleting a shape rather than
// cleaning it.
$media_avatar = array( 'meta_key' => 'author_image', 'title' => 'Avatar', 'type' => 'media', 'fields' => array(), 'object' => 'taxonomy', 'value_format' => 'both' );
dpt_test_eq( DPT_RB_Schema::resolve_descriptor( $media_avatar, 'authors' )['type'], 'media', 'a media field of the same name keeps its own shape' );
$text_avatar = array( 'meta_key' => 'author_image', 'title' => 'Avatar', 'type' => 'text', 'fields' => array(), 'object' => 'taxonomy' );
dpt_test_eq( DPT_RB_Schema::resolve_descriptor( $text_avatar, 'authors' )['type'], 'url', 'while the text field it usually is becomes a URL' );
dpt_test_eq( DPT_RB_Schema::resolve_descriptor( $text_avatar, 'client' )['type'], 'text', 'on the authors taxonomy and nowhere else' );

$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'] = $fixture_boxes;
$GLOBALS['dpt_stub_rest_taxonomies']                  = $fixture_taxes;
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

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

/* ---- the documented round trip does not cost the row a column ---- */

// A FAQ repeater on a real site carries columns this bridge cannot map - an
// icon picker beside the question and answer. read() hands them out, so a
// consumer that does what this API documents - GET the field, change one
// value, PUT the whole list back - must get them back intact. Filtering
// them on the way in would delete that column from every row on the first
// write, and answer 200 while doing it.
$GLOBALS['dpt_stub_post_meta'] = array();
update_post_meta(
	12,
	'qna',
	array(
		array( 'question' => 'Why?', 'answer' => 'Because', 'icon' => 'fa-star' ),
		array( 'question' => 'How?', 'answer' => 'Like this', 'icon' => 'fa-bolt' ),
	)
);
$round_trip = DPT_RB_Fields::read( $qna, array( 'id' => 12 ) );
dpt_test_eq( $round_trip[0]['icon'], 'fa-star', 'a sub-field this bridge cannot map is still read out' );
$round_trip[0]['question'] = 'Why not?';
dpt_test_ok( true === DPT_RB_Fields::write( $qna, $round_trip, (object) array( 'ID' => 12 ) ), 'the modified list writes back' );
$after_trip = DPT_RB_Fields::read( $qna, array( 'id' => 12 ) );
dpt_test_eq( $after_trip[0]['question'], 'Why not?', 'the change landed' );
dpt_test_eq( $after_trip[0]['icon'], 'fa-star', 'and the unmapped column survived the round trip' );
dpt_test_eq( $after_trip[1]['icon'], 'fa-bolt', 'on every row, not only the one that changed' );

/* ---- a literal backslash is not the module's to remove ---- */

// update_metadata() unslashes what it is handed, so a value written straight
// through arrives at the database with its backslashes gone: a Windows path,
// a regular expression, an ID selector in a stylesheet snippet. Worse than
// the loss is that the endpoint used to answer 200 - a successful write
// short-circuits before the read-back comparison, so nothing noticed that
// what was stored was not what was sent. The slashing is checked on both
// meta stores and at both depths, because the repeater path hands a whole
// nested array to the same call.
$backslash_path  = 'C:\\Users\\brand\\hero.png';
$backslash_regex = '/^\\d{4}-\\d{2}$/';

$notes = array( 'meta_key' => 'notes', 'title' => 'Editor notes', 'type' => 'text', 'fields' => array(), 'object' => 'post' );
dpt_test_ok( true === DPT_RB_Fields::write( $notes, $backslash_path, (object) array( 'ID' => 14 ) ), 'a value carrying literal backslashes is written' );
dpt_test_eq( DPT_RB_Fields::read( $notes, array( 'id' => 14 ) ), $backslash_path, 'and storage holds every backslash that was sent, not the value core stripped them out of' );
// The other half of the fix: the read-back comparison compares storage
// against $clean, and storage is the unslashed side of the pair, so a
// re-write of the same value must still read as the success it is.
dpt_test_ok( true === DPT_RB_Fields::write( $notes, $backslash_path, (object) array( 'ID' => 14 ) ), 'writing it again is still a success - the read-back comparison and the slashed write agree about the same value' );

$tax_notes = array( 'meta_key' => 'notes', 'title' => 'Editor notes', 'type' => 'text', 'fields' => array(), 'object' => 'taxonomy' );
dpt_test_ok( true === DPT_RB_Fields::write( $tax_notes, $backslash_path, (object) array( 'term_id' => 6 ) ), 'the term-meta writer takes one too' );
dpt_test_eq( DPT_RB_Fields::read( $tax_notes, array( 'term_id' => 6 ) ), $backslash_path, 'and keeps it whole, the same as the post store' );

// Inside a repeater, on the column this bridge has no type for - the one it
// promises to keep verbatim. Verbatim has to include the backslashes.
dpt_test_ok(
	true === DPT_RB_Fields::write(
		$qna,
		array( array( 'question' => 'Which pattern?', 'answer' => $backslash_path, 'icon' => $backslash_regex ) ),
		(object) array( 'ID' => 14 )
	),
	'a repeater row carrying backslashes in a mapped column and an unmapped one is written'
);
$slashed_row = DPT_RB_Fields::read( $qna, array( 'id' => 14 ) );
dpt_test_eq( $slashed_row[0]['answer'], $backslash_path, 'the mapped column reads back with its backslashes' );
dpt_test_eq( $slashed_row[0]['icon'], $backslash_regex, 'and so does the column this bridge only carries' );
dpt_test_ok( true === DPT_RB_Fields::write( $qna, $slashed_row, (object) array( 'ID' => 14 ) ), 'and the list it just handed out writes back as a success' );
dpt_test_eq( DPT_RB_Fields::read( $qna, array( 'id' => 14 ) ), $slashed_row, 'unchanged, which is the whole property this round trip is for' );

/* ---- a checkbox written twice is not a failure the second time ---- */

// update_*_meta() answers false for a write that asked for nothing new, and
// the check that follows compares what each side reads back as. A checkbox
// reads back as a fresh object each time, and two objects are never
// identical - so the comparison has to be made on what the API would send.
$perks = array( 'meta_key' => 'perks', 'title' => 'Perks', 'type' => 'checkbox', 'fields' => array(), 'object' => 'post' );
dpt_test_ok( true === DPT_RB_Fields::write( $perks, array( 'wifi' => 'true' ), (object) array( 'ID' => 12 ) ), 'a checkbox writes' );
dpt_test_ok( true === DPT_RB_Fields::write( $perks, array( 'wifi' => 'true' ), (object) array( 'ID' => 12 ) ), 'and writing the same map again is still a success' );

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

/* ---- a media field through real meta storage, in all three formats ---- */

// The finding this answers was about a live read: a site whose media fields
// store URLs had every one of them handed back as 0, because the read path
// ran absint() over whatever storage held. Through the callbacks and the
// meta store, not just the schema class, because that is where it happened.
$photo_id   = array( 'meta_key' => 'photo', 'title' => 'Photo', 'type' => 'media', 'fields' => array(), 'object' => 'post', 'value_format' => 'id' );
$photo_url  = array( 'meta_key' => 'banner', 'title' => 'Banner', 'type' => 'media', 'fields' => array(), 'object' => 'post', 'value_format' => 'url' );
$photo_both = array( 'meta_key' => 'hero', 'title' => 'Hero', 'type' => 'media', 'fields' => array(), 'object' => 'post', 'value_format' => 'both' );

dpt_test_ok( true === DPT_RB_Fields::write( $photo_url, 'https://example.test/banner.jpg', (object) array( 'ID' => 11 ) ), 'a URL-format media field takes a URL' );
dpt_test_eq( DPT_RB_Fields::read( $photo_url, array( 'id' => 11 ) ), 'https://example.test/banner.jpg', 'and hands it back as the URL, not as 0' );
dpt_test_ok( true === DPT_RB_Fields::write( $photo_url, DPT_RB_Fields::read( $photo_url, array( 'id' => 11 ) ), (object) array( 'ID' => 11 ) ), 'writing back exactly what was read is a success, not a failed comparison' );
dpt_test_eq( DPT_RB_Fields::read( $photo_url, array( 'id' => 11 ) ), 'https://example.test/banner.jpg', 'and changes nothing' );

dpt_test_ok( true === DPT_RB_Fields::write( $photo_id, 12, (object) array( 'ID' => 11 ) ), 'an id-format field takes an id' );
dpt_test_eq( DPT_RB_Fields::read( $photo_id, array( 'id' => 11 ) ), 12, 'and hands back the integer, not the string meta storage kept' );

dpt_test_ok( true === DPT_RB_Fields::write( $photo_both, array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ), (object) array( 'ID' => 11 ) ), 'a both-format field takes the pair' );
dpt_test_eq( wp_json_encode( DPT_RB_Fields::read( $photo_both, array( 'id' => 11 ) ) ), wp_json_encode( (object) array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ) ), 'and hands back both halves' );
dpt_test_ok( true === DPT_RB_Fields::write( $photo_both, DPT_RB_Fields::read( $photo_both, array( 'id' => 11 ) ), (object) array( 'ID' => 11 ) ), 'and the object it just handed out writes back as a success' );
dpt_test_eq( wp_json_encode( DPT_RB_Fields::read( $photo_both, array( 'id' => 11 ) ) ), wp_json_encode( (object) array( 'id' => 12, 'url' => 'https://example.test/hero.jpg' ) ), 'still holding both halves' );

// And the same through the meta store with the members a real pair carries
// beside its two known ones. This is the finding stated as a round trip: an
// alt text that came back as http://Ahero the first time a client wrote back
// what it had just read was data destroyed by a 200.
$rich_pair = array( 'id' => 12, 'url' => 'https://example.test/hero.jpg', 'alt' => 'A hero', 'meta' => array( 'w' => 1 ) );
dpt_test_ok( true === DPT_RB_Fields::write( $photo_both, $rich_pair, (object) array( 'ID' => 13 ) ), 'a pair carrying members this bridge has no format for is written' );
$rich_read = DPT_RB_Fields::read( $photo_both, array( 'id' => 13 ) );
dpt_test_eq( $rich_read->alt, 'A hero', 'and the alt text reads back as itself, not as an address' );
dpt_test_ok( true === DPT_RB_Fields::write( $photo_both, $rich_read, (object) array( 'ID' => 13 ) ), 'writing back exactly what was read is a success' );
dpt_test_eq( wp_json_encode( DPT_RB_Fields::read( $photo_both, array( 'id' => 13 ) ) ), wp_json_encode( $rich_read ), 'and the whole pair survived the round trip unchanged' );

/* ---- a multi-select through real meta storage ---- */

// The other half of the same finding: a select with JetEngine's Multiple
// toggle on stores an array, and the string branch read that back as ''.
// Through the callbacks and the meta store, where it happened.
$topics = array( 'meta_key' => 'topics', 'title' => 'Topics', 'type' => 'select', 'fields' => array(), 'object' => 'post', 'multiple' => true );
$colour = array( 'meta_key' => 'colour', 'title' => 'Colour', 'type' => 'select', 'fields' => array(), 'object' => 'post', 'multiple' => false );

dpt_test_ok( true === DPT_RB_Fields::write( $topics, array( 'seo', 'wordpress' ), (object) array( 'ID' => 11 ) ), 'a multi-select takes a list' );
dpt_test_eq( DPT_RB_Fields::read( $topics, array( 'id' => 11 ) ), array( 'seo', 'wordpress' ), 'and hands the list back, not an empty string' );
dpt_test_ok( true === DPT_RB_Fields::write( $topics, DPT_RB_Fields::read( $topics, array( 'id' => 11 ) ), (object) array( 'ID' => 11 ) ), 'writing back exactly what was read is a success' );
dpt_test_eq( DPT_RB_Fields::read( $topics, array( 'id' => 11 ) ), array( 'seo', 'wordpress' ), 'and changes nothing' );
dpt_test_eq( DPT_RB_Fields::read( $topics, array( 'id' => 999 ) ), '', 'a post that has never had one reads back as what storage holds' );

dpt_test_ok( true === DPT_RB_Fields::write( $colour, 'New York', (object) array( 'ID' => 11 ) ), 'a single select takes a string' );
dpt_test_eq( DPT_RB_Fields::read( $colour, array( 'id' => 11 ) ), 'New York', 'and hands it back whole, spaces and all' );

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
dpt_test_ok( isset( $registered['post/post']['qna'] ), 'and lists the field there' );
dpt_test_eq( $registered['post/post']['qna']['type'], 'array', 'with the schema it was really registered with, not a bare name' );
dpt_test_ok( isset( $registered['taxonomy/authors'] ), 'and about the taxonomy' );

/* ---- what a field is readable by, and to whom ---- */

// Discovery exposes every JetEngine field on the site, and this module has
// no way to know which of them the site meant for the public - internal
// notes, pricing, a client's own details all look the same from here. So a
// discovered field is readable in the edit context, which both live
// consumers already authenticate for, and never on an anonymous
// GET /wp/v2/posts.
// The site's own note field is one nothing outside the editor should see.
$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'][] = array(
	'id'          => 'internal',
	'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
	'meta_fields' => array(
		array( 'name' => 'internal_note', 'title' => 'Internal note', 'object_type' => 'field', 'type' => 'textarea' ),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['internal_note']['schema']['context'], array( 'edit' ), 'a discovered field is not readable anonymously' );

// linkedin is discovered here and still public, because the meta key was
// public before this module existed - it is the key that was published, not
// the definition that happens to produce it.
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['schema']['context'], array( 'view', 'edit' ), 'a legacy name discovered as a JetEngine field stays as public as it was' );

// The names the replaced plugin already published stay published: each is
// public display content by nature, and taking them away would break a theme
// or a front end reading them today.
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['reading_time']['schema']['context'], array( 'view', 'edit' ), 'a legacy field keeps its public read' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['authors']['author_image']['schema']['context'], array( 'view', 'edit' ), 'including the author fields' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['qna']['schema']['context'], array( 'view', 'edit' ), 'and the FAQ, under its real name' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['context'], array( 'view', 'edit' ), 'as well as under the old plugin\'s name for it' );

// A site that knows one of its own fields is public says so with a filter -
// this module stores nothing, and a settings screen would change that.
add_filter(
	'dpt_rb_field_context',
	function ( $context, $descriptor, $target ) {
		return ( 'internal_note' === $descriptor['meta_key'] && 'post' === $target ) ? array( 'view', 'edit' ) : $context;
	}
);
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['internal_note']['schema']['context'], array( 'view', 'edit' ), 'a site can opt one discovered field back into public read' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['qna']['schema']['context'], array( 'view', 'edit' ), 'without disturbing the fields it did not name' );
remove_filter( 'dpt_rb_field_context' );

/* ---- a legacy name is public on its own target and nowhere else ---- */

// The five legacy keys keep view context because the replaced plugin already
// published them - on post and on the authors taxonomy, and nowhere else. A
// site that defines its own reading_time on a private custom post type, or
// its own author_description on some unrelated taxonomy, was never publishing
// those to anonymous callers, and a name collision is not a reason to start.
$saved_post_types                    = $GLOBALS['dpt_stub_rest_post_types'];
$saved_taxonomies                    = $GLOBALS['dpt_stub_rest_taxonomies'];
$GLOBALS['dpt_stub_rest_post_types'] = array( 'post', 'page', 'client_brief' );
$GLOBALS['dpt_stub_rest_taxonomies'] = array( 'category', 'post_tag', 'authors', 'client' );
$GLOBALS['dpt_stub_options']         = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'brief-extras',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'client_brief', 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'reading_time', 'title' => 'Reading time', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'client-extras',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'client', 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'author_description', 'title' => 'Description', 'object_type' => 'field', 'type' => 'wysiwyg' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['client_brief']['reading_time']['schema']['context'], array( 'edit' ), 'a legacy key on a post type of the site\'s own is not readable anonymously' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['reading_time']['schema']['context'], array( 'view', 'edit' ), 'while the same key on post - where it was published - still is' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['client']['author_description']['schema']['context'], array( 'edit' ), 'and a legacy key on an unrelated taxonomy stays private' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['authors']['author_description']['schema']['context'], array( 'view', 'edit' ), 'while the authors taxonomy keeps the read it always had' );

$GLOBALS['dpt_stub_rest_post_types'] = $saved_post_types;
$GLOBALS['dpt_stub_rest_taxonomies'] = $saved_taxonomies;

/* ---- jet_qna is a name on posts, and only on posts ---- */

// A qna repeater attached to a taxonomy is not the FAQ ContentEngine writes.
// Aliasing it there would put jet_qna where no consumer looks for it, and
// would leave the post-side fallback free to register the name a second time
// and report it twice.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'author-faq',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'Author FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'question', 'title' => 'Question', 'type' => 'text' ),
						array( 'name' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['qna'] ), 'the taxonomy repeater is exposed under its own name' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['authors']['jet_qna'] ), 'but the alias is not put on a taxonomy' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'while posts still get one, where the automation writes it' );
$jet_qna_reports = array_keys( DPT_RB_Fields::compat(), 'jet_qna', true );
dpt_test_eq( count( $jet_qna_reports ), 1, 'and the info endpoint is told about it exactly once' );

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

/* ---- the post FAQ is owned by the post target, not the post kind ---- */

// A descriptor's `object` is the broad kind - 'post' covers every post type
// there is, pages included - while `targets` is the concrete list the meta
// box is attached to. ContentEngine gates its pipeline on jet_qna being on
// /wp/v2/posts/{id}, so the question "does something already own the post
// FAQ" has to be asked of the post target. Asking it of the object kind is
// what used to let a page-only repeater suppress the alias on posts and the
// fallback both, leaving the gate with nothing at all.

// 1. The ordinary site: the FAQ repeater is attached to posts (and pages).
// The alias goes where the definition is, and the fallback stands down.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-faq',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post', 'page' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'q', 'title' => 'Q', 'type' => 'text' ),
						array( 'name' => 'a', 'title' => 'A', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'a qna repeater whose targets include post is aliased there' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['items']['properties']['q'] ), 'under the site\'s own sub-fields, not the legacy shape' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna'] ), 'and on the other post types the same definition names' );
dpt_test_eq( count( array_keys( DPT_RB_Fields::compat(), 'jet_qna', true ) ), 1, 'reported to the info endpoint once' );

// 2. The site this finding is about: the FAQ repeater exists, but only on
// pages. It is still object 'post' - every post type is - so the alias
// belongs on page, where the definition is, and the post FAQ is still
// unowned. ContentEngine's gate must survive that.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'page-faq',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'page' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'Page FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'q', 'title' => 'Q', 'type' => 'text' ),
						array( 'name' => 'a', 'title' => 'A', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna'] ), 'a page-only qna repeater is aliased on page, where it lives' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna']['schema']['items']['properties']['q'] ), 'with the sub-fields the site defined' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'and posts still get a jet_qna - ContentEngine\'s gate is not a page-only site\'s to remove' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['items']['properties']['question'] ), 'in the legacy shape, because nothing owns the qna key on posts' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna']['schema']['items']['properties']['question'] ), 'while the page alias keeps its own shape rather than the legacy one' );
dpt_test_eq( count( array_keys( DPT_RB_Fields::compat(), 'jet_qna', true ) ), 1, 'and the name is reported once, not once per pass that registered it' );
dpt_test_eq( implode( ' | ', DPT_RB_Fields::skipped() ), '', 'nothing was skipped, so nothing is explained away' );

// A write through each lands on the same meta key, on its own object.
// Read through isset() and short-circuited: a regression here removes the
// field entirely, and a fatal on a missing callback would take the rest of
// the file's assertions down with it instead of reporting the one that broke.
$GLOBALS['dpt_stub_post_meta'] = array();
$page_write = isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna']['update_callback'] ) ? $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna']['update_callback'] : null;
$post_write = isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['update_callback'] ) ? $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['update_callback'] : null;
dpt_test_ok( is_callable( $page_write ) && true === call_user_func( $page_write, array( array( 'q' => 'Where?', 'a' => 'Here' ) ), (object) array( 'ID' => 31 ) ), 'the page alias writes' );
dpt_test_ok( is_callable( $post_write ) && true === call_user_func( $post_write, array( array( 'question' => 'Why?', 'answer' => 'Because' ) ), (object) array( 'ID' => 32 ) ), 'and so does the post fallback' );
$stored_faq  = get_post_meta( 32, 'qna', true );
$stored_page = get_post_meta( 31, 'qna', true );
dpt_test_ok( isset( $stored_faq[0]['question'] ) && 'Why?' === $stored_faq[0]['question'], 'both onto the qna meta key the old plugin used' );
dpt_test_ok( isset( $stored_page[0]['q'] ) && 'Where?' === $stored_page[0]['q'], 'each on its own object, under its own sub-fields' );

// 3. A qna on posts that is not a repeater still refuses the fallback, and
// still says why: one meta key with two REST fields promising different
// shapes is the corruption this module exists to prevent.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-basics',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'qna', 'title' => 'Not a repeater', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'a non-repeater qna on posts still declines the fallback' );
dpt_test_ok( false !== strpos( implode( ' | ', DPT_RB_Fields::skipped() ), 'not a repeater' ), 'with the diagnostic that explains the gap' );
dpt_test_eq( count( array_keys( DPT_RB_Fields::compat(), 'jet_qna', true ) ), 0, 'and nothing claimed in compat' );

// 4. The same non-repeater qna, but on pages only. It owns nothing on posts,
// so it neither blocks the fallback nor earns a diagnostic - the gate is
// registered and the info endpoint has nothing to apologise for.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'page-basics',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'page' ) ),
			'meta_fields' => array(
				array( 'name' => 'qna', 'title' => 'Not a repeater', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'a non-repeater qna on pages alone does not withhold the post gate' );
dpt_test_eq( implode( ' | ', DPT_RB_Fields::skipped() ), '', 'and there is no gap to explain' );

/* ---- jet_qna is not the alias's to take when the site defines one ---- */

// A post type can define both a qna repeater and a field of its own literally
// called jet_qna. Discovery registers the real jet_qna first, and the alias
// used to be registered straight over it: callbacks and schema replaced by
// ones pointing at the qna meta key. Reads and writes under jet_qna then
// operated on the wrong metadata, and the site's own field was unreachable
// through the API at all. The rule the legacy list has always followed
// applies here too - the site's own definition wins, compatibility fills a
// gap rather than taking a name that is already someone's.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'post-faq-and-notes',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'q', 'title' => 'Q', 'type' => 'text' ),
						array( 'name' => 'a', 'title' => 'A', 'type' => 'wysiwyg' ),
					),
				),
				array( 'name' => 'jet_qna', 'title' => 'JetEngine QnA note', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
$GLOBALS['dpt_stub_post_meta']   = array();
DPT_RB_Fields::register();
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['type'], 'string', 'the site\'s own jet_qna keeps the shape it defined, not the repeater\'s' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['qna'] ), 'while the repeater is still there under its real name' );
dpt_test_ok( ! in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'and compat() does not claim an alias it did not register' );
dpt_test_ok( false !== strpos( implode( ' | ', DPT_RB_Fields::skipped() ), 'defines its own jet_qna field' ), 'the skip is recorded, so the info endpoint can tell an automation that jet_qna does not mean the FAQ here' );

// The registration is what a write actually goes through, so the meta key it
// lands on is the assertion that matters: an alias registered over this would
// send the write to qna and overwrite the FAQ with a string.
$own_write = isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['update_callback'] ) ? $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['update_callback'] : null;
$own_read  = isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['get_callback'] ) ? $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['get_callback'] : null;
update_post_meta( 41, 'qna', array( array( 'q' => 'Kept?', 'a' => 'Kept' ) ) );
dpt_test_ok( is_callable( $own_write ) && true === call_user_func( $own_write, 'a note', (object) array( 'ID' => 41 ) ), 'a write under jet_qna is accepted' );
dpt_test_eq( get_post_meta( 41, 'jet_qna', true ), 'a note', 'and lands on the jet_qna meta key, the one the site named' );
dpt_test_eq( get_post_meta( 41, 'qna', true ), array( array( 'q' => 'Kept?', 'a' => 'Kept' ) ), 'leaving the FAQ under qna exactly as it was' );
dpt_test_eq( is_callable( $own_read ) ? call_user_func( $own_read, array( 'id' => 41 ) ) : null, 'a note', 'and the read hands back the site\'s own field, not the FAQ' );

// The contrast, unchanged: with no jet_qna of the site's own, the alias is
// registered exactly as before.
$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'][0]['meta_fields'] = array(
	$GLOBALS['dpt_stub_options']['jet_engine_meta_boxes'][0]['meta_fields'][0],
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['items']['properties']['q'] ), 'a post type defining only qna still gets the alias, in the repeater\'s shape' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'and it is reported as the compatibility field it is' );
dpt_test_eq( implode( ' | ', DPT_RB_Fields::skipped() ), '', 'with nothing to explain away' );

// The decision is per target, like the legacy list's: a name taken on one of
// the repeater's targets must not withhold the alias from another that has no
// collision at all.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'shared-faq',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post', 'page' ) ),
			'meta_fields' => array(
				array(
					'name'            => 'qna',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array( array( 'name' => 'q', 'title' => 'Q', 'type' => 'text' ) ),
				),
			),
		),
		array(
			'id'          => 'post-only-note',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'jet_qna', 'title' => 'JetEngine QnA note', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['type'], 'string', 'on the target that defines its own jet_qna, the site keeps the name' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_qna']['schema']['items']['properties']['q'] ), 'while the target with no collision is aliased as usual' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'so the name really was added somewhere, and compat() says so' );
dpt_test_ok( false !== strpos( implode( ' | ', DPT_RB_Fields::skipped() ), 'not registered on post' ), 'and the target it was withheld from is named' );

// And the oldest behaviour of all, untouched: a site with nothing called qna
// anywhere still gets ContentEngine's gate in the legacy shape.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['schema']['items']['properties']['question'] ), 'no qna field at all, and the fallback still registers the legacy shape' );
dpt_test_ok( in_array( 'jet_qna', DPT_RB_Fields::compat(), true ), 'reported as a compatibility field, as it always was' );

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

/* ---- a write the site refuses is not reported as a success ---- */

// update_post_meta() answers false for a database error or a metadata filter
// that says no. The endpoint used to discard that answer, delete the rendered
// CSS, clear Elementor's cache and hand back success:true over a page whose
// stored layout had not changed at all - a caller believing it had edited a
// page it had not.
update_post_meta( 20, '_elementor_css', 'body{}' );
$before_refusal                              = get_post_meta( 20, '_elementor_data', true );
$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;
$GLOBALS['dpt_stub_meta_write_fails']        = array( '_elementor_data' );
$refused = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'Never lands' ) ),
	),
) ) );
$GLOBALS['dpt_stub_meta_write_fails'] = array();

dpt_test_ok( is_wp_error( $refused ), 'a refused write is an error, not success:true' );
dpt_test_eq( is_wp_error( $refused ) ? $refused->get_error_code() : '', 'save_failed', 'named as the save failure it is' );
dpt_test_eq( get_post_meta( 20, '_elementor_data', true ), $before_refusal, 'the stored layout is exactly as it was' );
dpt_test_eq( get_post_meta( 20, '_elementor_css', true ), 'body{}', 'so the rendered CSS still describes it and is not deleted' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_cache_cleared'], 0, 'and Elementor is not told to forget a change that never happened' );

/* ---- while a re-write of the same layout is still a success ---- */

// update_post_meta() answers false here too: the value asked for is the one
// already stored, so nothing happened - which is not the same as a refusal,
// and reading storage back is the only way to tell them apart. An agent that
// re-sends the settings it just sent must not be told the write failed.
$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;
$unchanged = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'New title' ) ),
	),
) ) );
dpt_test_ok( ! is_wp_error( $unchanged ), 'writing the layout that is already stored is not a failure' );
dpt_test_ok( isset( $unchanged['success'] ) && true === $unchanged['success'], 'it reports success' );
dpt_test_eq( $unchanged['updates_applied'], 1, 'with the widget it merged into' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_cache_cleared'], 1, 'and the cache is cleared once, as after any landed write' );

/* ---- and only for someone allowed to edit that post ---- */

// The plugin this replaces asked only whether the user could edit something,
// which let anyone with an author's rights rewrite every page on the site.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 20 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a post this user may not edit is refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'and one they may is allowed' );

/* ---- a revision id is refused, and refused the same way on both routes ---- */

// update_post_meta() redirects a revision id to its parent and get_post_meta()
// does not, so a POST naming a revision used to read the revision's layout,
// merge the caller's changes into it and write the result over the live parent
// page - while reporting the revision id as the thing it had updated. The
// permission check cannot catch that: WordPress maps edit_post on a revision
// to the parent's capability, so everyone who may edit the page passes it.
$GLOBALS['dpt_stub_posts'][40] = array( 'post_type' => 'revision', 'post_parent' => 20 );

// Written straight into the store, because update_post_meta() would send it to
// post 20 - which is the redirect this whole section is about.
$GLOBALS['dpt_stub_post_meta'][40] = array(
	'_elementor_data' => wp_json_encode(
		array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'The title this revision was taken with' ),
			),
		)
	),
);

$live_before = get_post_meta( 20, '_elementor_data', true );
update_post_meta( 20, '_elementor_css', 'body{}' );
$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;

$revision_read = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 40 ) ) );
dpt_test_ok( is_wp_error( $revision_read ), 'a GET naming a revision is refused' );
dpt_test_eq( is_wp_error( $revision_read ) ? $revision_read->get_error_code() : '', 'revision_not_supported', 'and named as the reason it is' );

$revision_write = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 40,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'Republished by accident' ) ),
	),
) ) );
dpt_test_ok( is_wp_error( $revision_write ), 'and so is a POST naming the same revision' );
dpt_test_eq(
	is_wp_error( $revision_write ) ? $revision_write->get_error_code() : '',
	is_wp_error( $revision_read ) ? $revision_read->get_error_code() : '',
	'with the answer the GET gave - the two routes cannot disagree about a revision'
);

$live_after = get_post_meta( 20, '_elementor_data', true );
dpt_test_eq( $live_after, $live_before, 'the live page keeps the layout it had' );
dpt_test_ok( false === strpos( $live_after, 'Republished by accident' ), 'nothing the caller sent reached it' );
dpt_test_ok( false === strpos( $live_after, 'The title this revision was taken with' ), 'and the revision was not republished over it either' );
dpt_test_eq( get_post_meta( 20, '_elementor_css', true ), 'body{}', 'its rendered CSS still describes it and is not deleted' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_cache_cleared'], 0, 'and Elementor is not told to forget a change that never happened' );

// An orphaned revision - one whose parent has already gone - is refused on the
// same terms, with no parent id to offer and no notice for the missing one.
$GLOBALS['dpt_stub_posts'][41] = array( 'post_type' => 'revision', 'post_parent' => 0 );
$orphan_read = DPT_RB_Elementor::get_tree( new DPT_Stub_Request( array( 'post_id' => 41 ) ) );
dpt_test_eq( is_wp_error( $orphan_read ) ? $orphan_read->get_error_code() : '', 'revision_not_supported', 'an orphaned revision is refused the same way' );

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_RB_Elementor::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/elementor/(?P<post_id>\d+)'] ), 'the route is registered where the old plugin had it' );

require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-rankmath.php';
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-info.php';

/* ---- Rank Math fields, only when Rank Math is here ---- */

$GLOBALS['dpt_stub_registered_post_meta'] = array();
dpt_test_ok( ! DPT_RB_Rankmath::active(), 'without Rank Math the module knows it' );
DPT_RB_Rankmath::register();
dpt_test_eq( $GLOBALS['dpt_stub_registered_post_meta'], array(), 'and registers nothing for a plugin that is not installed' );

// Declared inside a block so PHP does not hoist it above the assertion above.
if ( ! class_exists( 'RankMath' ) ) {
	class RankMath {}
}
dpt_test_ok( DPT_RB_Rankmath::active(), 'with Rank Math loaded it is seen' );
DPT_RB_Rankmath::register();
dpt_test_eq( count( $GLOBALS['dpt_stub_registered_post_meta']['post'] ), 12, 'all twelve fields land on posts' );
dpt_test_eq( count( $GLOBALS['dpt_stub_registered_post_meta']['page'] ), 12, 'and on pages, which the old plugin forgot' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_focus_keyword'] ), 'including the focus keyword' );
dpt_test_ok( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_title']['show_in_rest'], 'each visible to REST' );

// Rank Math's Open Graph data lives under rank_math_facebook_*; it has never
// read rank_math_og_*, so a field registered under that name writes to a
// meta key nothing reads.
dpt_test_ok( isset( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_facebook_image'] ), 'the Open Graph image is registered under the key Rank Math actually reads' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_og_image'] ), 'and not under the dead key the old plugin used' );

// rank_math_robots is a list of directives (e.g. noindex), never a single
// string - Rank Math's own admin columns read it with FILTER_REQUIRE_ARRAY.
dpt_test_eq( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_robots']['type'], 'array', 'robots is registered as an array, matching how Rank Math stores it' );

// rank_math_seo_score and rank_math_primary_category are both numbers in
// Rank Math's own code (an (int)-cast score and a parseInt()'d term id).
dpt_test_eq( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_seo_score']['type'], 'integer', 'the SEO score is a number, not a string' );
dpt_test_eq( $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_primary_category']['type'], 'integer', 'the primary category is a term id, not a string' );

// The auth_callback must check the post this meta belongs to, not a blanket
// edit_posts - the same bug DPT_RB_Elementor::may_edit() was written to
// avoid, reopened here would let any author-level account overwrite another
// author's SEO metadata.
$auth = $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_title']['auth_callback'];
$GLOBALS['dpt_stub_denied_post_caps'] = array( 30 );
dpt_test_ok( ! call_user_func( $auth, true, 'rank_math_title', 30 ), 'a post this user may not edit is refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
dpt_test_ok( call_user_func( $auth, true, 'rank_math_title', 30 ), 'and one they may edit is allowed' );

/* ---- the info endpoint tells an agent what this site exposes ---- */

$info = DPT_RB_Info::payload();
dpt_test_eq( $info['version'], DPT_VERSION, 'the payload names the plugin version' );
dpt_test_ok( isset( $info['fields']['post/post'] ), 'lists the fields per object' );
dpt_test_ok( in_array( 'jet_qna', $info['compat'], true ), 'names the compatibility aliases' );
dpt_test_ok( is_array( $info['skipped'] ), 'reports what discovery passed over' );
dpt_test_ok( $info['rank_math'], 'and whether Rank Math is here' );
dpt_test_ok( in_array( '/digitizer/v1/info', $info['routes'], true ), 'while naming its own route' );

// An agent is handed a site it has never seen. A list of names tells it what
// exists; only the schema tells it what a field will accept, so the payload
// carries the schemas the fields were really registered with.
dpt_test_ok( isset( $info['fields']['post/post']['jet_qna'] ), 'the field list is keyed by name' );
dpt_test_eq( $info['fields']['post/post']['jet_qna']['type'], 'array', 'and each name carries its schema' );
dpt_test_ok( isset( $info['fields']['post/post']['jet_qna']['items']['properties']['question'] ), 'down to a repeater\'s sub-fields' );
dpt_test_ok( isset( $info['fields']['post/post']['jet_qna']['context'] ), 'and what it may be read in' );

// The Elementor route both reads and writes; a list that says neither leaves
// an agent to guess that the write side is there at all.
dpt_test_ok( in_array( '/digitizer/v1/elementor/{post_id} (GET, POST)', $info['routes'], true ), 'the Elementor route names both its methods' );

$GLOBALS['dpt_stub_rest_routes'] = array();
DPT_RB_Info::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'] ), 'the info route is registered' );

// The old plugin's info endpoint was public and advertised versions to
// anyone who asked.
$route = $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'][0];
$GLOBALS['dpt_stub_denied_caps'] = array( 'edit_posts' );
dpt_test_ok( ! call_user_func( $route['permission_callback'] ), 'a visitor may not read it' );
$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_ok( call_user_func( $route['permission_callback'] ), 'an editor may' );

/* ---- a jet_qna gap reaches the info payload, not just Fields::skipped() ---- */

// The same non-repeater qna scenario tested against DPT_RB_Fields::skipped()
// above, but read through the endpoint an agent actually calls. jet_qna is a
// required workflow gate for a live automation; a diagnostic that is
// recorded and translated but never surfaced here is worse than none - it
// reads as done when it is not. An unrelated meta box with no fields at all
// is included too, so this also proves DPT_RB_Definitions::skipped() still
// leads the merged list, ahead of DPT_RB_Fields::skipped(), exactly as
// DPT_RB_Info::payload() promises to order them.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'empty-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(),
		),
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
$gap_info = DPT_RB_Info::payload();
dpt_test_ok( is_array( $gap_info['skipped'] ) && count( $gap_info['skipped'] ) >= 2, 'both a discovery skip and a registration skip are reported' );
dpt_test_eq( $gap_info['skipped'][0], DPT_RB_Definitions::skipped()[0], 'discovery\'s diagnostics come first' );
$gap_joined = implode( ' | ', $gap_info['skipped'] );
dpt_test_ok( false !== strpos( $gap_joined, 'empty-box' ), 'the discovery skip is in the merged list' );
dpt_test_ok( false !== strpos( $gap_joined, 'jet_qna' ), 'and so is the registration skip - the gap an agent needs explained does not go missing' );
dpt_test_ok( strpos( $gap_joined, 'empty-box' ) < strpos( $gap_joined, 'jet_qna' ), 'in order: discovery before registration' );

// Restore the no-qna-at-all scenario the module boot() test below expects.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/rest-bridge/class-dpt-rb-module.php';

/* ---- the module itself ---- */

$module = new DPT_Rest_Bridge_Module();
dpt_test_eq( $module->id(), 'rest_bridge', 'the module has the id the registry uses' );
dpt_test_ok( '' !== $module->title(), 'and a title' );
dpt_test_ok( '' !== $module->description(), 'and a description' );

$GLOBALS['dpt_stub_filters']     = array();
$GLOBALS['dpt_stub_rest_routes'] = array();
$GLOBALS['dpt_stub_rest_fields'] = array();

dpt_test_ok( ! DPT_Rest_Bridge_Module::legacy_plugin_active(), 'without the old plugin the module is in charge' );
dpt_test_eq( $module->standing_down_reason(), '', 'and has nothing to explain' );

$module->init();
dpt_test_ok( dpt_stub_has_filter( 'rest_api_init' ), 'so it hooks the REST API' );

// Booting registers the whole surface at once.
DPT_RB_Definitions::reset();
DPT_Rest_Bridge_Module::boot();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/info'] ), 'the info route is up' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_routes']['digitizer/v1/elementor/(?P<post_id>\d+)'] ), 'so are the Elementor routes' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna'] ), 'and the fields' );

/* ---- but not while the plugin it replaces is running ---- */

// Two registrations of the same field name on the same post type is a race
// nobody can debug from the outside, so the module steps back and says so.
if ( ! function_exists( 'digitizer_elementor_build_tree' ) ) {
	function digitizer_elementor_build_tree( $elements ) { return $elements; }
}
dpt_test_ok( DPT_Rest_Bridge_Module::legacy_plugin_active(), 'the old plugin is recognised' );
dpt_test_ok( '' !== $module->standing_down_reason(), 'and the Modules screen is told why nothing happens' );

$GLOBALS['dpt_stub_filters'] = array();
$module->init();
dpt_test_ok( ! dpt_stub_has_filter( 'rest_api_init' ), 'the module registers nothing at all' );

// If init() had already hooked boot() before the legacy plugin declared its
// function - a real possibility, since the two happen on different hooks -
// boot() itself must still refuse to register anything.
$GLOBALS['dpt_stub_rest_routes'] = array();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Definitions::reset();
DPT_Rest_Bridge_Module::boot();
dpt_test_ok( array() === $GLOBALS['dpt_stub_rest_routes'], 'even called directly, boot() registers no routes while the old plugin is active' );
dpt_test_ok( array() === $GLOBALS['dpt_stub_rest_fields'], 'nor any fields' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
