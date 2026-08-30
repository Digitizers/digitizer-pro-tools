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
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['thing'] ), 'and appears in the response schema beside core\'s own properties' );

// The behaviour the whole of the collision rests on, and the one a stub that
// only kept additional fields in a bucket of their own could never show:
// register_rest_field() does not add a property beside an existing one - for
// a name the controller already defines it *replaces* that property's schema
// and its value.
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['title']['dpt_stub_core'] ), 'the post response starts with core\'s own title' );
register_rest_field( 'post', 'title', array( 'schema' => array( 'type' => 'string' ) ) );
dpt_test_ok( empty( dpt_stub_rest_item_schema( 'post' )['title']['dpt_stub_core'] ), 'and a field registered under that name replaces it rather than sitting beside it' );
dpt_test_eq( dpt_stub_rest_item_schema( 'post' )['title']['type'], 'string', 'leaving the meta field\'s schema where core\'s object was' );
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'authors' )['description']['dpt_stub_core'] ), 'a taxonomy response is modelled from the terms controller instead' );
$GLOBALS['dpt_stub_rest_fields'] = array();

// The taxonomy registry, which decides some of a post response's property
// names: core's own post type carries category and post_tag, and the REST
// API calls those properties categories and tags.
dpt_test_eq( get_object_taxonomies( 'post' ), array( 'category', 'post_tag' ), 'the post type carries its taxonomies' );
dpt_test_eq( get_object_taxonomies( 'post', 'objects' )['category']->rest_base, 'categories', 'each with a rest base of its own' );
dpt_test_eq( get_object_taxonomies( 'page' ), array(), 'while a post type with none carries none' );

$GLOBALS['dpt_stub_denied_post_caps'] = array( 9 );
dpt_test_ok( current_user_can( 'edit_post', 8 ), 'a post the user may edit' );
dpt_test_ok( ! current_user_can( 'edit_post', 9 ), 'and one they may not' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

// The per-key metadata capabilities, which are a different question from
// "may this request edit the post". map_meta_cap() answers them with three
// things the object's own capability never covers: a protected key is denied
// to everybody, an auth_{$type}_meta_{$key} filter can deny any other key,
// and with no user there is nothing to grant anything to.
dpt_test_ok( current_user_can( 'edit_post_meta', 8, 'reading_time' ), 'an ordinary post meta key may be edited' );
dpt_test_ok( ! current_user_can( 'edit_post_meta', 8, '_secret' ), 'a protected key may not, by anyone' );
dpt_test_ok( is_protected_meta( '_secret', 'post' ), 'because that is what protected means' );
dpt_test_ok( ! is_protected_meta( 'secret', 'post' ), 'and a key without the underscore is not' );
$GLOBALS['dpt_stub_denied_meta_caps'] = array( 'salary' );
dpt_test_ok( ! current_user_can( 'edit_post_meta', 8, 'salary' ), 'nor a key the site has put an auth filter on' );
dpt_test_ok( ! current_user_can( 'edit_term_meta', 8, 'salary' ), 'on terms as well as posts' );
dpt_test_ok( current_user_can( 'edit_post_meta', 8, 'reading_time' ), 'while its neighbours are untouched' );
$GLOBALS['dpt_stub_denied_meta_caps'] = array();
$GLOBALS['dpt_stub_denied_post_caps'] = array( 9 );
dpt_test_ok( ! current_user_can( 'edit_post_meta', 9, 'reading_time' ), 'and the per-key capability still falls through to the object\'s own, which map_meta_cap() resolves first' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
$GLOBALS['dpt_stub_no_user'] = true;
dpt_test_ok( ! is_user_logged_in(), 'with no user, nobody is logged in' );
dpt_test_eq( rest_authorization_required_code(), 401, 'and an authorization failure is a 401, not a 403' );
dpt_test_ok( ! current_user_can( 'edit_post_meta', 8, 'reading_time' ), 'and no capability is granted at all' );
$GLOBALS['dpt_stub_no_user'] = false;
dpt_test_eq( rest_authorization_required_code(), 403, 'while a logged-in user who is refused gets a 403' );

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

// An object type is read the way JetEngine's own register_instances() reads
// it: absent means `post`, and `tax` is the older spelling of `taxonomy`. A
// row saved before that key existed is registered by JetEngine on post types
// and read by every template on the site, so passing the whole meta box over
// cost it every field it holds - and said the object type was unknown when
// JetEngine's answer for it is not unknown at all.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'older-jetengine',
			'args'        => array(
				'name'              => 'Older JetEngine',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'subtitle', 'title' => 'Subtitle', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'older-spelling',
			'args'        => array(
				'name'        => 'Older Spelling',
				'object_type' => 'tax',
				'allowed_tax' => array( 'authors' ),
			),
			'meta_fields' => array(
				array( 'name' => 'twitter', 'title' => 'Twitter', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'still-refused',
			'args'        => array(
				'name'              => 'Still Refused',
				'object_type'       => array( 'post' ),
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'never', 'title' => 'Never', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'users',
			'args'        => array(
				'name'        => 'Users',
				'object_type' => 'user',
			),
			'meta_fields' => array(
				array( 'name' => 'nickname', 'title' => 'Nickname', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$defaulted = array();
foreach ( DPT_RB_Definitions::all() as $d ) {
	$defaulted[ $d['meta_key'] ] = $d;
}
dpt_test_ok( isset( $defaulted['subtitle'] ), 'a meta box with no object_type at all is not passed over' );
dpt_test_eq( $defaulted['subtitle']['object'], 'post', 'it is a post meta box, which is what JetEngine defaults it to' );
dpt_test_eq( $defaulted['subtitle']['targets'], array( 'post' ), 'on the post types it names' );
dpt_test_ok( isset( $defaulted['twitter'] ), 'and one saved under the older spelling tax is not either' );
dpt_test_eq( $defaulted['twitter']['object'], 'taxonomy', 'normalized to the one spelling the rest of this module uses' );
dpt_test_eq( $defaulted['twitter']['targets'], array( 'authors' ), 'on the taxonomy it names' );
dpt_test_ok( ! isset( $defaulted['never'] ), 'an object_type that is not a string is still refused' );
dpt_test_ok( ! isset( $defaulted['nickname'] ), 'and a kind JetEngine has but this bridge does not expose still is' );
$defaulted_skips = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false !== strpos( $defaulted_skips, 'still-refused' ), 'the malformed one is named' );
dpt_test_ok( false !== strpos( $defaulted_skips, 'not a string' ), 'with the reason it is really refused for' );
dpt_test_ok( false !== strpos( $defaulted_skips, 'object type user is not exposed' ), 'while a user meta box keeps the sentence it always had' );
dpt_test_ok( false === strpos( $defaulted_skips, 'older-jetengine' ), 'and nothing is said about the meta box that now works' );

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
				// The one that is not a warning but a TypeError:
				// sanitize_key() hands this to strtolower(), so a name that
				// is not a string ends the request rather than the row.
				array( 'name' => array( 'x' ), 'title' => 'Weird Name', 'object_type' => 'field', 'type' => 'text' ),
				array(
					'name'            => 'weird_sub',
					'title'           => 'Weird Sub',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'sub_bad_title', 'type' => 'text', 'title' => array( 'x' ) ),
						array( 'name' => array( 'x' ), 'type' => 'text', 'title' => 'Weird sub name' ),
					),
				),
			),
		),
		// And the same shape one level up, where the list of post types a
		// meta box is attached to goes through sanitize_key() member by
		// member.
		array(
			'id'          => 'weird-targets',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( array( 'x' ), 'page' ),
			),
			'meta_fields' => array(
				array( 'name' => 'weird_target_field', 'title' => 'Fine', 'object_type' => 'field', 'type' => 'text' ),
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

// The name is the one that used to be fatal rather than merely wrong:
// sanitize_key() hands what it is given to strtolower(), which is a TypeError
// on PHP 8, and this runs on rest_api_init - so one malformed row in another
// vendor's option aborted REST Bridge's registration for every REST request
// the site served. The property that matters is the last of these: the rest
// of the meta box still arrives.
dpt_test_ok( ! isset( $by_key['weird_name'] ), 'a field whose name is not a string is skipped rather than fatal' );
dpt_test_ok( isset( $by_key['weird_title'] ), 'and the well-formed field in the same meta box is still registered' );
dpt_test_eq( count( $by_key['weird_sub']['fields'] ), 1, 'a repeater keeps the sub-field whose name is a name' );
dpt_test_eq( $by_key['weird_sub']['fields'][0]['meta_key'], 'sub_bad_title', 'which is the one that was not an array' );
dpt_test_ok( isset( $by_key['weird_target_field'] ), 'a meta box whose target list holds an array still exposes its fields' );
dpt_test_eq( $by_key['weird_target_field']['targets'], array( 'page' ), 'on the targets that really are post type names' );

// And every one of them is sayable, because a field that silently fails to
// appear looks like a bug in the API rather than a row nobody can read.
$joined = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false !== strpos( $joined, 'a field whose name is not a string' ), 'the malformed field name is recorded with a reason' );
dpt_test_ok( false !== strpos( $joined, 'a sub-field whose name is not a string' ), 'and so is the malformed sub-field name' );

/* ---- the meta key is the one JetEngine stored, not one derived from it ---- */

// JetEngine's save loop is update_post_meta( $post_id, $key, $value ) with
// $key taken straight off the stored definition (cherry-x-post-meta.php), and
// a repeater column's name and a checkbox option's key are never touched by
// JetEngine's server side at all. So a key this module derives rather than
// reads is a key nothing else on the site uses: reads come back empty, writes
// create a row no template looks at, and the API answers 200 either way.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'hebrew-box',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'מחיר', 'title' => 'מחיר', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'précio', 'title' => 'Precio', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'שדה_price', 'title' => 'Price', 'object_type' => 'field', 'type' => 'text' ),
				array(
					'name'            => 'שאלות',
					'title'           => 'FAQ',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'שאלה', 'title' => 'Question', 'type' => 'text' ),
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
dpt_test_eq( count( $defs ), 4, 'every field in a Hebrew meta box is found' );
dpt_test_ok( isset( $by_key['מחיר'] ), 'a wholly Hebrew name is the meta key JetEngine stored' );
dpt_test_ok( isset( $by_key['précio'] ), 'an accented name is kept whole rather than reduced to a valid but wrong key' );
dpt_test_ok( isset( $by_key['שדה_price'] ), 'and a mixed name keeps both of its halves' );
dpt_test_eq( $by_key['שאלות']['fields'][0]['meta_key'], 'שאלה', 'a repeater column keeps its own name too - JetEngine never sanitizes those at all' );
$joined = implode( ' | ', DPT_RB_Definitions::skipped() );
dpt_test_ok( false === strpos( $joined, 'a field with no name' ), 'and none of them is reported as a field with no name, which was never true of any of them' );

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
/**
 * wp_is_numeric_array(), which is where rest_is_array() ends up: every key an
 * integer, in any order, and an empty array counts.
 *
 * @param mixed $value Whatever is being tested.
 * @return bool
 */
function dpt_rb_test_is_numeric_array( $value ) {
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( array_keys( $value ) as $key ) {
		if ( ! is_int( $key ) ) {
			return false;
		}
	}
	return true;
}

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
			if ( ! dpt_rb_test_is_numeric_array( $value ) ) {
				// rest_is_array() ends at wp_is_numeric_array(), so a map is
				// not an array however array-shaped PHP thinks it is. A stub
				// that stopped at is_array() would let a union resolve a
				// checkbox's option map to its list member and hide the very
				// confusion the two shapes cause.
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
				// rest_is_array(): a scalar is split by wp_parse_list() first,
				// and then - either way - the answer is wp_is_numeric_array().
				// A map is not an array to core however array-shaped PHP
				// thinks it is, which is what lets one union name both shapes
				// a checkbox or a select can be in without either one
				// swallowing the other.
				if ( is_scalar( $value ) || dpt_rb_test_is_numeric_array( $value ) ) {
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
				// rest_is_integer(): a real integer, a canonical integer
				// string of any magnitude, or any other numeric value with no
				// fractional part. `is_int()` alone - which is what this
				// modelled - said no to "1767225600", so a union naming
				// integer beside string resolved a stored timestamp to string
				// and the assertions about the two would have been measuring
				// the stub rather than core.
				if ( is_int( $value ) ) {
					return 'integer';
				}
				if ( is_string( $value ) && preg_match( '/^\s*[+-]?[0-9]+\s*$/', $value ) ) {
					return 'integer';
				}
				if ( is_numeric( $value ) && floor( (float) $value ) === (float) $value ) {
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
			case 'string':
				// is_string(), and answered *in its place in the union* -
				// core's $checks map has an entry for 'string' like every
				// other type, so a union naming string before array resolves
				// a string to string and never reaches rest_is_array(). This
				// case was missing, and its absence was not neutral: 'string'
				// fell out of the switch and the walk carried on to 'array',
				// which says yes to any scalar. Every union here that names
				// both would have resolved a plain string to 'array' in the
				// harness whatever order it was written in, so the assertion
				// that the order protects a value with a space in it could
				// not have failed.
				if ( is_string( $value ) ) {
					return 'string';
				}
				break;
			case 'null':
				if ( null === $value ) {
					return 'null';
				}
				break;
		}
	}

	// rest_get_best_type_for_value() has no fallback: a union that fits
	// nothing answers with the empty string, and rest_handle_multi_type_schema()
	// then refuses the value. Falling back to 'string' - which is what this
	// did - would have called a float a string on the media union and made
	// a refusal look like an acceptance.
	return '';
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

// String before array on both variants, and that order is load-bearing:
// rest_is_array() says yes to any scalar because wp_parse_list() will make a
// list of one, so a union that named array first shredded a plain option
// before the module's own sanitizer ever saw it. A real JSON list is not a
// string, so naming string first costs the list nothing.
dpt_test_eq( $many_s['type'], array( 'string', 'array', 'object' ), 'a multi-select names the list it stores, behind the string it may still hold' );
dpt_test_eq( $many_s['items']['type'], 'string', 'of the option strings it holds' );
dpt_test_eq( $one_s['type'], array( 'string', 'object' ), 'and a single select as the string it stores' );
dpt_test_eq(
	DPT_RB_Schema::for_descriptor( $select_defs['rows'] )['items']['properties']['topics']['type'],
	array( 'string', 'array', 'object' ),
	'and a multi-select inside a repeater the same way as one outside it'
);

// The shredding itself, on the field the union exists for: one whose Multiple
// toggle has just been turned on while yesterday's plain string is still in
// storage. Both of wp_parse_list()'s separators, through the model of the
// gate core runs before any sanitizer here.
dpt_test_eq( dpt_rb_test_core_best_type( $many_s['type'], 'New York' ), 'string', 'a plain option with a space in it resolves as the string it is' );
dpt_test_eq( DPT_RB_Schema::sanitize( $many, dpt_rb_test_core_sanitize( $many_s, 'New York' ) ), 'New York', 'and reaches storage whole, rather than as two options' );
dpt_test_eq( DPT_RB_Schema::sanitize( $many, dpt_rb_test_core_sanitize( $many_s, 'Tel Aviv,Jaffa' ) ), 'Tel Aviv,Jaffa', 'and so does one with a comma in it' );
dpt_test_eq( DPT_RB_Schema::sanitize( $many, dpt_rb_test_core_sanitize( $many_s, array( 'red', 'blue' ) ) ), array( 'red', 'blue' ), 'while a real list still resolves to the array member and arrives as the list it is' );

// The bug this replaces, run against the model of core's gate so the model is
// known to be able to see it: a plain string type refused the list the field
// really holds, before any sanitizer ran.
dpt_test_ok( ! dpt_rb_test_core_accepts( array( 'type' => 'string' ), array( 'red', 'blue' ) ), 'a list does not validate against a string schema - the 400 this fixes' );
dpt_test_eq( DPT_RB_Schema::normalize_read( array( 'meta_key' => 'tags', 'title' => 'Tags', 'type' => 'text', 'fields' => array() ), array( 'red', 'blue' ) ), '', 'and the string read hands an array back as "" - the read this fixes' );

// Every shape, both ways round.
// The last column is whether a read, write, read of that shape is expected to
// leave storage exactly as it was. Every one of them is now, the string still
// in storage from before the toggle included: naming string ahead of array
// closed the last shape that did not survive its own round trip.
$select_trips = array(
	array( $many, array( 'red', 'blue' ), 'a multi-select holding a list', true ),
	array( $many, '', 'a multi-select nobody has chosen from', true ),
	array( $many, 'red', 'a multi-select still holding the string it held before the toggle', true ),
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

// The shape that used to be the exception, and no longer is. A string left in
// storage from before the Multiple toggle was turned on was made into a
// one-item list by core on the way back in, because the union named array
// ahead of string and rest_is_array() will make a list of any scalar. It
// reads back as itself and writes back as itself now.
$carried = dpt_rb_test_round_trip( $many, 'red' );
dpt_test_eq( $carried['read'], 'red', 'the string still in storage reads back as itself' );
dpt_test_eq( $carried['again'], 'red', 'and writing it back leaves it the string it was' );

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
dpt_test_eq( dpt_rb_test_core_sanitize( array( 'type' => array( 'array', 'string' ) ), 'New York' ), array( 'New', 'York' ), 'while a union that named array *first* would have split it in two' );
dpt_test_eq( dpt_rb_test_core_sanitize( array( 'type' => array( 'array', 'string' ) ), 'Tel Aviv,Jaffa' ), array( 'Tel', 'Aviv', 'Jaffa' ), 'and split a comma too, because wp_parse_list() splits on both' );
dpt_test_eq( dpt_rb_test_core_best_type( array( 'string', 'array' ), 'New York' ), 'string', 'while naming string first stops the walk before rest_is_array() is ever asked' );

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

// A plain checkbox's schema names the map first, so its wire format has to be
// one - wp_json_encode( array() ) is '[]', not '{}', and PHP itself turns
// option keys that look like "0" and "1" into integer array keys, so even a
// populated checkbox can encode as a JSON array unless the read path forces
// the point.
$checkbox = array( 'meta_key' => 'perks', 'title' => 'Perks', 'type' => 'checkbox', 'fields' => array() );
dpt_test_eq( DPT_RB_Schema::for_descriptor( $checkbox )['type'][0], 'object', 'a checkbox is advertised as an object first' );
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

// The option keys are JetEngine's too. They are free text - the "Add custom
// value" flow writes whatever an editor typed straight into the definition
// with nothing but esc_attr() over it - so a key this module reshapes on the
// way in is an option nothing else on the site has. The read path never
// reshaped them, which is what made the write side's reshaping a silent
// disagreement between the two.
$hebrew_options = array( 'כחול' => true, 'Sky Blue' => 'true', 'red' => false );
dpt_test_eq(
	DPT_RB_Schema::sanitize( $checkbox, $hebrew_options ),
	array( 'כחול' => 'true', 'Sky Blue' => 'true', 'red' => 'false' ),
	'a checkbox option key survives a write whatever it is spelled with'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $checkbox, DPT_RB_Schema::normalize_read( $checkbox, array( 'כחול' => 'true', 'Sky Blue' => 'false' ) ) ),
	array( 'כחול' => 'true', 'Sky Blue' => 'false' ),
	'and a read of those keys composes back into itself rather than into a different set of options'
);

/* ---- a checkbox stores two different shapes, and JetEngine says which ---- */

// JetEngine's checkbox carries an is_array toggle beside its type, read the
// way is_multiple is read - filter_var( ..., FILTER_VALIDATE_BOOLEAN ) - and
// with it on, cherry-x-post-meta's sanitize_meta() stores a plain list of the
// checked keys instead of the key => 'true'|'false' map. Modelled as the map
// alone, ["red","blue"] read back as {"0":"red","1":"blue"} and writing that
// object back stored {"0":"true","1":"true"}, which JetEngine then reads as
// ["0","1"] - every selection replaced by an array index, over a 200.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'checkbox-shapes',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'perks', 'title' => 'Perks', 'object_type' => 'field', 'type' => 'checkbox' ),
				array( 'name' => 'colours', 'title' => 'Colours', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => true ),
				array( 'name' => 'sizes', 'title' => 'Sizes', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => 'true' ),
				array( 'name' => 'flags', 'title' => 'Flags', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => 'false' ),
				array(
					'name'            => 'rows',
					'title'           => 'Rows',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'tags', 'title' => 'Tags', 'type' => 'checkbox', 'is_array' => true ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$check_defs = array();
foreach ( DPT_RB_Definitions::all() as $d ) {
	$check_defs[ $d['meta_key'] ] = $d;
}
dpt_test_eq( $check_defs['perks']['is_array'], false, 'a checkbox with no is_array toggle stores the map' );
dpt_test_eq( $check_defs['colours']['is_array'], true, 'and one with it on stores a list' );
dpt_test_eq( $check_defs['sizes']['is_array'], true, "including when the toggle was stored as the string 'true'" );
dpt_test_eq( $check_defs['flags']['is_array'], false, "and 'false' is off, not a non-empty string" );
dpt_test_eq( $check_defs['rows']['fields'][0]['is_array'], true, 'a checkbox column inside a repeater carries its own toggle' );

$cb_map    = $check_defs['perks'];
$cb_list   = $check_defs['colours'];
$cb_map_s  = DPT_RB_Schema::for_descriptor( $cb_map );
$cb_list_s = DPT_RB_Schema::for_descriptor( $cb_list );

// Each names the other's shape, for the reason a select does: the toggle is
// JetEngine's and can be turned over between two requests, so a value already
// in storage in the other form has to stay readable and writable.
dpt_test_eq( $cb_list_s['type'], array( 'array', 'object' ), 'an is_array checkbox is advertised as the list it stores' );
dpt_test_eq( $cb_list_s['items']['type'], 'string', 'of the option keys it holds' );
dpt_test_eq( $cb_map_s['type'], array( 'object', 'array' ), 'and a plain one as the map it stores' );

// The read: each form comes back as the shape storage really holds, and each
// one is a shape its own schema names.
$list_read = DPT_RB_Schema::normalize_read( $cb_list, array( 'red', 'blue' ) );
dpt_test_eq( wp_json_encode( $list_read ), '["red","blue"]', 'a stored list reads back as a list, not as an object of indices' );
dpt_test_ok( dpt_rb_test_core_accepts( $cb_list_s, $list_read ), 'and the schema the field advertises accepts it' );
$map_read = DPT_RB_Schema::normalize_read( $cb_map, array( 'wifi' => 'true', 'parking' => 'false' ) );
dpt_test_eq( wp_json_encode( $map_read ), '{"wifi":"true","parking":"false"}', 'a stored map reads back as a map' );
dpt_test_ok( dpt_rb_test_core_accepts( $cb_map_s, $map_read ), 'and its own schema accepts that' );

// The write: what a read handed out has to write back as itself, through
// core's gate and the module's sanitizer both.
dpt_test_eq(
	DPT_RB_Schema::sanitize( $cb_list, dpt_rb_test_core_sanitize( $cb_list_s, $list_read ) ),
	array( 'red', 'blue' ),
	'a stored list survives a write of the value that was read'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $cb_map, dpt_rb_test_core_sanitize( $cb_map_s, $map_read ) ),
	array( 'wifi' => 'true', 'parking' => 'false' ),
	'and so does a stored map'
);

// The shape decides, not the toggle - a value in the form the toggle does not
// name is one this module did not expect, and a shape it did not expect is
// not a shape it may convert into the other one.
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $cb_list, array( 'wifi' => 'true' ) ) ),
	'{"wifi":"true"}',
	'a map still in storage on a field whose toggle has just been turned on reads back as the map it is'
);
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $cb_map, array( 'red', 'blue' ) ) ),
	'["red","blue"]',
	'and a list still in storage on a field whose toggle has just been turned off keeps every option'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $cb_map, array( 'red', 'blue' ) ),
	array( 'red', 'blue' ),
	'a list written to a map field is stored as the list it is rather than as two options called 0 and 1'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $cb_list, array( 'wifi' => 'true' ) ),
	array( 'wifi' => 'true' ),
	'and a map written to a list field is stored as the map it is'
);

// The one shape the two forms genuinely share: a map whose option keys are
// 0 and 1 is a PHP list once JSON has been decoded, and its values are the
// switch strings, so nothing about the value tells the two apart. That is
// where - and only where - the field's own toggle breaks the tie.
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $cb_map, array( '0' => 'true', '1' => 'false' ) ) ),
	'{"0":"true","1":"false"}',
	'a map with numeric option keys still reads back as a map on a field that stores maps'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $cb_map, array( '0' => 'true', '1' => 'false' ) ),
	array( '0' => 'true', '1' => 'false' ),
	'and writes back as one'
);
dpt_test_eq(
	wp_json_encode( DPT_RB_Schema::normalize_read( $cb_list, array( 'true', 'false' ) ) ),
	'["true","false"]',
	'while a list of options literally called true and false stays a list on a field that stores lists'
);

// An empty checkbox: each form's own empty, so the JSON matches the schema.
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $cb_list, '' ) ), '[]', 'an untouched is_array checkbox reads back as an empty list' );
dpt_test_eq( wp_json_encode( DPT_RB_Schema::normalize_read( $cb_map, '' ) ), '{}', 'and an untouched plain one as an empty object' );

// A checkbox column inside a repeater says the same thing about itself as
// one outside it, or the sub-schema and the sub-read disagree.
dpt_test_eq(
	DPT_RB_Schema::for_descriptor( $check_defs['rows'] )['items']['properties']['tags']['type'],
	array( 'array', 'object' ),
	'an is_array checkbox inside a repeater is advertised the same way as one outside it'
);
$rep_cb_read = DPT_RB_Schema::normalize_read( $check_defs['rows'], array( array( 'tags' => array( 'red', 'blue' ) ) ) );
dpt_test_eq( wp_json_encode( $rep_cb_read[0]['tags'] ), '["red","blue"]', 'and reads its list back as a list' );
dpt_test_eq(
	DPT_RB_Schema::sanitize( $check_defs['rows'], $rep_cb_read )[0]['tags'],
	array( 'red', 'blue' ),
	'and writes it back unchanged'
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

/* ---- a date field with the Timestamp toggle stores a number, not a date ---- */

// The fourth of JetEngine's shape-changing settings, and the last one:
// cherry-x-post-meta's save_meta_option() stores strtotime( $posted ) rather
// than the text whenever to_timestamp() says so, and its get_meta() turns that
// integer back into 'Y-m-d' for the admin screen. Advertised as a string -
// which every date type was - a read handed back "1767225600" where the schema
// promised a date, and a write of "2026-01-01" put that literal string over a
// value JetEngine and every date_i18n() template read as a number.
//
// to_timestamp() narrows it twice, and both narrowings are asserted: it
// refuses an input_type that is not date or datetime-local, so a `time` field
// with the toggle on still stores its string; and
// prepare_repeater_fields() never puts is_timestamp on a repeater column at
// all, whose value goes through sanitize_meta() - which has no timestamp
// branch - so a date column inside a repeater is a string whatever its row
// says.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'date-shapes',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'deadline', 'title' => 'Deadline', 'object_type' => 'field', 'type' => 'date' ),
				array( 'name' => 'starts', 'title' => 'Starts', 'object_type' => 'field', 'type' => 'date', 'is_timestamp' => true ),
				array( 'name' => 'ends', 'title' => 'Ends', 'object_type' => 'field', 'type' => 'datetime-local', 'is_timestamp' => 'true' ),
				array( 'name' => 'paused', 'title' => 'Paused', 'object_type' => 'field', 'type' => 'date', 'is_timestamp' => 'false' ),
				array( 'name' => 'alarm', 'title' => 'Alarm', 'object_type' => 'field', 'type' => 'time', 'is_timestamp' => true ),
				array(
					'name'            => 'slots',
					'title'           => 'Slots',
					'object_type'     => 'field',
					'type'            => 'repeater',
					'repeater-fields' => array(
						array( 'name' => 'on', 'title' => 'On', 'type' => 'date', 'is_timestamp' => true ),
					),
				),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$date_defs = array();
foreach ( DPT_RB_Definitions::all() as $d ) {
	$date_defs[ $d['meta_key'] ] = $d;
}
dpt_test_eq( $date_defs['deadline']['is_timestamp'], false, 'a date field with no Timestamp toggle stores the string it posted' );
dpt_test_eq( $date_defs['starts']['is_timestamp'], true, 'and one with it on stores a Unix integer' );
dpt_test_eq( $date_defs['ends']['is_timestamp'], true, "including when the toggle was stored as the string 'true', on a datetime-local" );
dpt_test_eq( $date_defs['paused']['is_timestamp'], false, "and 'false' is off, not a non-empty string" );
dpt_test_ok( ! isset( $date_defs['alarm']['is_timestamp'] ), 'a time field carries no such setting, because to_timestamp() refuses its input_type' );
dpt_test_eq( $date_defs['slots']['fields'][0]['is_timestamp'], false, 'and a date column inside a repeater is a string however its row is spelled - JetEngine never carries the setting there' );

$stamp_field = $date_defs['starts'];
$plain_field = $date_defs['deadline'];
$stamp_s     = DPT_RB_Schema::for_descriptor( $stamp_field );
$plain_s     = DPT_RB_Schema::for_descriptor( $plain_field );

dpt_test_eq( $stamp_s['type'], array( 'integer', 'string' ), 'a timestamp field is advertised as the number it stores, with the date string it may still hold named beside it' );
dpt_test_eq( $plain_s['type'], 'string', 'while a plain date field keeps the bare string it always was' );

// The order in that union is what makes it work at all, and it is core's rule
// that decides: rest_get_best_type_for_value() walks the union as the schema
// names it, and rest_is_integer() answers yes to a canonical integer string -
// which is the only form meta storage can hand back.
dpt_test_eq( dpt_rb_test_core_best_type( array( 'integer', 'string' ), '1767225600' ), 'integer', 'core resolves a stored timestamp to the integer member' );
dpt_test_eq( dpt_rb_test_core_best_type( array( 'integer', 'string' ), '2026-01-01' ), 'string', 'and a date string to the string member' );
dpt_test_eq( dpt_rb_test_core_best_type( array( 'integer', 'string' ), '' ), 'string', 'and the empty string to string, as core does before it walks anything' );

// The read: the number comes out as a number rather than as the string meta
// storage kept it in, and each value is one the field's own schema accepts.
$stamp_read = DPT_RB_Schema::normalize_read( $stamp_field, '1767225600' );
dpt_test_ok( 1767225600 === $stamp_read, 'a stored timestamp reads back as the integer the schema now promises' );
dpt_test_ok( dpt_rb_test_core_accepts( $stamp_s, $stamp_read ), 'and the schema accepts it' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $stamp_field, '2026-01-01' ), '2026-01-01', 'a date string left behind by the toggle being turned over still reads out whole' );
dpt_test_ok( dpt_rb_test_core_accepts( $stamp_s, '2026-01-01' ), 'which is the other shape the union names' );
dpt_test_ok( '1767225600' === DPT_RB_Schema::normalize_read( $plain_field, '1767225600' ), 'while a plain date field hands back the string its own schema promises, whatever is in the row' );
dpt_test_eq( DPT_RB_Schema::normalize_read( $stamp_field, '' ), '', 'and a field nobody has filled in is empty on both' );

// The write, through core's gate and the module's sanitizer both.
dpt_test_ok(
	1767225600 === DPT_RB_Schema::sanitize( $stamp_field, dpt_rb_test_core_sanitize( $stamp_s, $stamp_read ) ),
	'the number a read handed out writes back as itself'
);
dpt_test_ok(
	1767225600 === DPT_RB_Schema::sanitize( $stamp_field, dpt_rb_test_core_sanitize( $stamp_s, '1767225600' ) ),
	'and so does the string form of it meta storage hands back'
);
dpt_test_ok(
	strtotime( '2026-01-01' ) === DPT_RB_Schema::sanitize( $stamp_field, dpt_rb_test_core_sanitize( $stamp_s, '2026-01-01' ) ),
	'a client sending the date instead gets JetEngine\'s own conversion, not the literal string over the number'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $stamp_field, dpt_rb_test_core_sanitize( $stamp_s, 'sometime next spring' ) ),
	'sometime next spring',
	'while a value nothing can make a date of is kept as it arrived rather than blanked, which is what JetEngine\'s own save would do to it'
);
dpt_test_eq(
	DPT_RB_Schema::sanitize( $plain_field, dpt_rb_test_core_sanitize( $plain_s, '2026-01-01' ) ),
	'2026-01-01',
	'a plain date field is untouched by any of this'
);
dpt_test_ok(
	'20260101' === DPT_RB_Schema::sanitize( $plain_field, dpt_rb_test_core_sanitize( $plain_s, '20260101' ) ),
	'including a site whose plain dates happen to look like numbers, which is why only the timestamp form names integer'
);

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

// The list form through the same path and the real meta store, which is where
// the loss actually happened: a GET, a change and a PUT is the round trip
// this module documents as its contract, and every selection used to come out
// of it replaced by an array index over a 200.
$colours = array( 'meta_key' => 'colours', 'title' => 'Colours', 'type' => 'checkbox', 'is_array' => true, 'fields' => array(), 'object' => 'post' );
$GLOBALS['dpt_stub_post_meta'][13] = array( 'colours' => array( 'red', 'blue' ) );
$colours_read = DPT_RB_Fields::read( $colours, array( 'id' => 13 ) );
dpt_test_eq( wp_json_encode( $colours_read ), '["red","blue"]', 'a stored list reads out of the meta store as a list' );
dpt_test_ok( true === DPT_RB_Fields::write( $colours, $colours_read, (object) array( 'ID' => 13 ) ), 'writing back what was just read is a success' );
dpt_test_eq( get_post_meta( 13, 'colours', true ), array( 'red', 'blue' ), 'and leaves storage holding the options JetEngine put there' );
$colours_changed = array_merge( (array) $colours_read, array( 'green' ) );
dpt_test_ok( true === DPT_RB_Fields::write( $colours, $colours_changed, (object) array( 'ID' => 13 ) ), 'a modified list writes' );
dpt_test_eq( get_post_meta( 13, 'colours', true ), array( 'red', 'blue', 'green' ), 'adding the option asked for and keeping the ones already chosen' );

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

/* ---- a timestamp date field through real meta storage ---- */

// The same finding for the fourth setting, and through the meta store because
// that is where the shape is really at stake: metadata keeps a scalar in a
// text column, so an integer written here comes back out as "1767225600" and
// a read that did not know the field's form handed that string to a client the
// schema had promised a date.
$when     = array( 'meta_key' => 'starts', 'title' => 'Starts', 'type' => 'date', 'fields' => array(), 'object' => 'post', 'is_timestamp' => true );
$when_str = array( 'meta_key' => 'deadline', 'title' => 'Deadline', 'type' => 'date', 'fields' => array(), 'object' => 'post', 'is_timestamp' => false );

dpt_test_ok( true === DPT_RB_Fields::write( $when, 1767225600, (object) array( 'ID' => 11 ) ), 'a timestamp date field takes a number' );
dpt_test_ok( 1767225600 === DPT_RB_Fields::read( $when, array( 'id' => 11 ) ), 'and hands back the integer, not the string meta storage kept' );
dpt_test_ok( true === DPT_RB_Fields::write( $when, DPT_RB_Fields::read( $when, array( 'id' => 11 ) ), (object) array( 'ID' => 11 ) ), 'writing back exactly what was read is a success' );
dpt_test_ok( 1767225600 === DPT_RB_Fields::read( $when, array( 'id' => 11 ) ), 'and changes nothing' );

dpt_test_ok( true === DPT_RB_Fields::write( $when, '2026-01-01', (object) array( 'ID' => 12 ) ), 'a client sending the date instead is accepted' );
dpt_test_ok( strtotime( '2026-01-01' ) === DPT_RB_Fields::read( $when, array( 'id' => 12 ) ), 'and what lands in storage is the number JetEngine would have stored, not the text over it' );

dpt_test_ok( true === DPT_RB_Fields::write( $when_str, '2026-01-01', (object) array( 'ID' => 13 ) ), 'a plain date field takes the date' );
dpt_test_ok( '2026-01-01' === DPT_RB_Fields::read( $when_str, array( 'id' => 13 ) ), 'and keeps it exactly as a date' );

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

/* ---- the per-key metadata capability, which the controllers do not apply ---- */

// The post and term controllers establish that the request may edit the
// *object* before a field callback runs. They do not apply the **per-key**
// metadata capability to a field registered with register_rest_field(), so
// without a check here a key the site has put an auth_post_meta_* filter on -
// or one WordPress protects outright - is readable and writable by anyone who
// may edit the containing post or term. WordPress's own meta endpoints refuse
// exactly this.
$saved_post_types = $GLOBALS['dpt_stub_rest_post_types'];
$saved_taxonomies = $GLOBALS['dpt_stub_rest_taxonomies'];

$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'private-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'notes', 'title' => 'Notes', 'object_type' => 'field', 'type' => 'textarea' ),
				array( 'name' => 'score', 'title' => 'Score', 'object_type' => 'field', 'type' => 'number' ),
				array( 'name' => '_secret', 'title' => 'Secret', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'author-private',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'staff_note', 'title' => 'Staff note', 'object_type' => 'field', 'type' => 'textarea' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

// A protected key is one map_meta_cap() refuses to everybody, administrators
// included, so a field on it could only ever read empty and refuse every
// write. It is refused at registration rather than left in the schema as a
// field nobody can use - and said out loud, because a JetEngine field the
// site can see in its own admin and cannot find in the API needs explaining.
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['_secret'] ), 'a protected meta key is not registered at all' );
$protected_joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( false !== strpos( $protected_joined, '_secret' ), 'and the field is named in the diagnostics' );
// "protected", not "begins with an underscore": core strips everything
// outside printable ASCII and the Unicode letters before it looks for the
// underscore, and is_protected_meta() is filterable besides, so a leading
// underscore is one way a key becomes protected rather than the definition
// of it. A diagnostic that names the wrong rule sends whoever reads it
// looking for a character their field does not have.
dpt_test_ok( false !== strpos( $protected_joined, 'protected' ), 'along with why WordPress will not have it' );

$notes_read  = $GLOBALS['dpt_stub_rest_fields']['post']['notes']['get_callback'];
$notes_write = $GLOBALS['dpt_stub_rest_fields']['post']['notes']['update_callback'];
$score_read  = $GLOBALS['dpt_stub_rest_fields']['post']['score']['get_callback'];
$staff_read  = $GLOBALS['dpt_stub_rest_fields']['authors']['staff_note']['get_callback'];
$staff_write = $GLOBALS['dpt_stub_rest_fields']['authors']['staff_note']['update_callback'];

$GLOBALS['dpt_stub_post_meta'] = array();
$GLOBALS['dpt_stub_term_meta'] = array();
update_post_meta( 41, 'notes', 'the client is unhappy' );
update_post_meta( 41, 'score', '7' );
update_term_meta( 42, 'staff_note', 'do not book again' );

// With the capability granted, nothing changes: this is a gate, not a wall.
dpt_test_eq( call_user_func( $notes_read, array( 'id' => 41 ) ), 'the client is unhappy', 'a reader who may edit the key reads it' );
dpt_test_ok( true === call_user_func( $notes_write, 'better now', (object) array( 'ID' => 41 ) ), 'and may write it' );
dpt_test_eq( get_post_meta( 41, 'notes', true ), 'better now', 'which really lands' );

/* ---- this reader is not allowed ---- */

$GLOBALS['dpt_stub_denied_meta_caps'] = array( 'notes', 'score', 'staff_note' );

dpt_test_eq( call_user_func( $notes_read, array( 'id' => 41 ) ), '', 'a key the site has put an auth filter on does not leak through the read' );
dpt_test_eq( call_user_func( $score_read, array( 'id' => 41 ) ), 0, 'and a refused read is the schema-honest empty for its own type, not an empty string' );
dpt_test_eq( call_user_func( $staff_read, array( 'id' => 42 ) ), '', 'the same on a term' );

$refused = call_user_func( $notes_write, 'written anyway', (object) array( 'ID' => 41 ) );
dpt_test_ok( is_wp_error( $refused ), 'a refused write is a WP_Error, in the style of the other error paths' );
dpt_test_eq( $refused->get_error_data()['status'], 403, 'with the status core uses for a logged-in user who may not' );
dpt_test_eq( get_post_meta( 41, 'notes', true ), 'better now', 'and storage is left exactly as it was' );

$refused_term = call_user_func( $staff_write, 'written anyway', (object) array( 'term_id' => 42 ) );
dpt_test_ok( is_wp_error( $refused_term ), 'a term write is refused the same way' );
dpt_test_eq( get_term_meta( 42, 'staff_note', true ), 'do not book again', 'and that storage is untouched too' );

$GLOBALS['dpt_stub_denied_meta_caps'] = array();

/* ---- and there is no reader, which is a different thing ---- */

// A small set of legacy keys is published anonymously on purpose, on exactly
// the targets the replaced plugin published them on. A capability check that
// simply ran with no user would silently un-publish every one of them, which
// is a regression for anything reading them today. The gate follows the
// context the field was really registered with: a field readable in view is
// one this site publishes, and its read is not gated at all.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

$GLOBALS['dpt_stub_post_meta'] = array();
$GLOBALS['dpt_stub_term_meta'] = array();
update_post_meta( 43, 'reading_time', '4 min' );
update_post_meta( 43, 'title', 'Questions people ask' );
update_post_meta( 43, 'qna', array( array( 'question' => 'Why?', 'answer' => 'Because.' ) ) );
update_term_meta( 44, 'author_description', 'Writes things' );
update_term_meta( 44, 'linkedin', 'https://example.test/in/ben' );

$public_reads = array(
	array( $GLOBALS['dpt_stub_rest_fields']['post']['reading_time']['get_callback'], array( 'id' => 43 ), '4 min', 'the reading time' ),
	array( $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['get_callback'], array( 'id' => 43 ), 'Questions people ask', 'the FAQ heading' ),
	array( $GLOBALS['dpt_stub_rest_fields']['authors']['author_description']['get_callback'], array( 'id' => 44 ), 'Writes things', 'the author bio' ),
	array( $GLOBALS['dpt_stub_rest_fields']['authors']['linkedin']['get_callback'], array( 'id' => 44 ), 'https://example.test/in/ben', 'the author link' ),
);
$faq_read     = $GLOBALS['dpt_stub_rest_fields']['post']['jet_qna']['get_callback'];
$notes_write2 = $GLOBALS['dpt_stub_rest_fields']['post']['reading_time']['update_callback'];

$GLOBALS['dpt_stub_no_user'] = true;
foreach ( $public_reads as $case ) {
	dpt_test_eq( call_user_func( $case[0], $case[1] ), $case[2], $case[3] . ' still reads for a caller with no user at all' );
}
dpt_test_eq( call_user_func( $faq_read, array( 'id' => 43 ) ), array( array( 'question' => 'Why?', 'answer' => 'Because.' ) ), 'and so does the FAQ, which is the whole point of the compatibility layer' );

// A write is never in that set: publishing a key anonymously says nothing
// about who may change it.
$anon_write = call_user_func( $notes_write2, '9 min', (object) array( 'ID' => 43 ) );
dpt_test_ok( is_wp_error( $anon_write ), 'a write with no user is refused even on a published key' );
dpt_test_eq( $anon_write->get_error_data()['status'], 401, 'as a 401 rather than a 403 - there is no reader to refuse' );
dpt_test_eq( get_post_meta( 43, 'reading_time', true ), '4 min', 'and the value stands' );
$GLOBALS['dpt_stub_no_user'] = false;

/* ---- a discovered field is not published, and reads as nothing to nobody ---- */

$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'private-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'notes', 'title' => 'Notes', 'object_type' => 'field', 'type' => 'textarea' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
$GLOBALS['dpt_stub_post_meta'] = array();
update_post_meta( 45, 'notes', 'the client is unhappy' );
$private_read = $GLOBALS['dpt_stub_rest_fields']['post']['notes']['get_callback'];

$GLOBALS['dpt_stub_no_user'] = true;
dpt_test_eq( call_user_func( $private_read, array( 'id' => 45 ) ), '', 'a discovered field hands nothing to a caller with no user' );
$GLOBALS['dpt_stub_no_user'] = false;
dpt_test_eq( call_user_func( $private_read, array( 'id' => 45 ) ), 'the client is unhappy', 'while an editor still reads it' );

// The filter a site uses to publish one of its own fields has to keep
// working, or the gate has quietly taken it away: the read follows the
// context the field was really registered with, not a list written here.
add_filter(
	'dpt_rb_field_context',
	function ( $context, $descriptor, $target ) {
		return ( 'notes' === $descriptor['meta_key'] && 'post' === $target ) ? array( 'view', 'edit' ) : $context;
	}
);
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
$opted_read = $GLOBALS['dpt_stub_rest_fields']['post']['notes']['get_callback'];
$GLOBALS['dpt_stub_no_user'] = true;
dpt_test_eq( call_user_func( $opted_read, array( 'id' => 45 ) ), 'the client is unhappy', 'a field the site opted into public read is still public with no user' );
$GLOBALS['dpt_stub_no_user'] = false;
remove_filter( 'dpt_rb_field_context' );

$GLOBALS['dpt_stub_rest_post_types'] = $saved_post_types;
$GLOBALS['dpt_stub_rest_taxonomies'] = $saved_taxonomies;

/* ---- a field named after a core REST property never takes that name ---- */

// This is not hypothetical for these sites. The plugin being replaced
// registered jet_faq_title as a REST field whose callbacks read and write the
// meta key `title` - it renamed the field precisely because exposing it as
// `title` would collide with core. So a JetEngine field with the meta key
// `title` exists, discovery finds it under its real name, and
// register_rest_field() would put a plain meta string where /wp/v2/posts
// keeps the post's own title object: not one field degraded, but the post
// type's REST API broken for every consumer, the block editor included.
$saved_post_types = $GLOBALS['dpt_stub_rest_post_types'];
$saved_taxonomies = $GLOBALS['dpt_stub_rest_taxonomies'];
$saved_object_tax = $GLOBALS['dpt_stub_object_taxonomies'];

$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'faq-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'title', 'title' => 'FAQ title', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'excerpt', 'title' => 'Short blurb', 'object_type' => 'field', 'type' => 'textarea' ),
			),
		),
		array(
			'id'          => 'author-box',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'description', 'title' => 'Bio', 'object_type' => 'field', 'type' => 'wysiwyg' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['title']['dpt_stub_core'] ), 'core\'s title on /wp/v2/posts is left exactly as core defined it' );
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['excerpt']['dpt_stub_core'] ), 'and so is its excerpt' );
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'authors' )['description']['dpt_stub_core'] ), 'and a term\'s own description on the taxonomy it belongs to' );

// A field the site defined is not this module's to lose, either. The meta key
// `title` on posts has a name already: the one the replaced plugin published
// and every automation written against it still sends.
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title'] ), 'the FAQ title is exposed under the name the replaced plugin gave it' );
dpt_test_ok( in_array( 'jet_faq_title', DPT_RB_Fields::compat(), true ), 'and is named as a compatibility name, because its own definition never called it that' );

// And the name really does reach the meta key it stands for, or it is an
// alias in name only.
$GLOBALS['dpt_stub_post_meta'] = array();
$faq_title_write               = $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['update_callback'];
$faq_title_read                = $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['get_callback'];
dpt_test_ok( true === call_user_func( $faq_title_write, 'Frequently asked', (object) array( 'ID' => 31 ) ), 'a write under the alias succeeds' );
dpt_test_eq( get_post_meta( 31, 'title', true ), 'Frequently asked', 'and lands on the title meta key, which is what the field really is' );
dpt_test_eq( call_user_func( $faq_title_read, array( 'id' => 31 ) ), 'Frequently asked', 'and reads back through the same name' );

// A colliding field with no legacy name is not dropped silently either: the
// rule is the jet_ prefix the legacy names already use, and no core property
// begins with it.
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_excerpt'] ), 'a colliding field with no legacy name is exposed under the documented jet_ prefix' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['jet_description'] ), 'on a taxonomy as well as on a post type' );
dpt_test_ok( in_array( 'jet_excerpt', DPT_RB_Fields::compat(), true ), 'and both are reported as names this module invented' );
dpt_test_ok( in_array( 'jet_description', DPT_RB_Fields::compat(), true ), 'rather than as names the site chose' );

$excerpt_write = $GLOBALS['dpt_stub_rest_fields']['post']['jet_excerpt']['update_callback'];
call_user_func( $excerpt_write, 'A blurb', (object) array( 'ID' => 31 ) );
dpt_test_eq( get_post_meta( 31, 'excerpt', true ), 'A blurb', 'a renamed field still writes the meta key its definition named' );

// An automation that asked for the real name and got nothing has to be able
// to find out why, and the info endpoint is where it looks.
$collide_joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( false !== strpos( $collide_joined, 'The field title was not registered on post' ), 'the absence of the real name is recorded, with the field and the target' );
dpt_test_ok( false !== strpos( $collide_joined, 'exposed as jet_faq_title instead' ), 'along with the name it answers to instead' );
dpt_test_ok( false !== strpos( $collide_joined, 'The field excerpt was not registered on post' ), 'and the same for a field with no legacy name' );

// The FAQ title was public before this module existed and stays public; the
// blurb is a field discovery found and nothing more, so it keeps the
// edit-only default.
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['schema']['context'], array( 'view', 'edit' ), 'the FAQ title keeps the anonymous read the replaced plugin gave it' );
dpt_test_eq( $GLOBALS['dpt_stub_rest_fields']['post']['jet_excerpt']['schema']['context'], array( 'edit' ), 'while a discovered field renamed by the same rule does not gain one' );

// Discovery already took jet_faq_title for the site's own title field, so
// the legacy entry for the same meta key stands down and says why - it would
// otherwise register a second field over the first.
dpt_test_ok( false !== strpos( $collide_joined, 'The legacy field jet_faq_title was not registered on post' ), 'the legacy entry for the same key declines when discovery has already claimed the name' );

/* ---- a property no written list could know: the site's own taxonomies ---- */

// A post type's controller turns every REST-enabled taxonomy attached to it
// into a property named by that taxonomy's rest base. On these sites that
// includes `authors`, which no hard-coded list of core properties could
// contain, so it is read from the taxonomy registry instead.
$GLOBALS['dpt_stub_object_taxonomies']['post'][] = 'authors';
$GLOBALS['dpt_stub_options']                     = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'byline-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'authors', 'title' => 'Byline', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['authors'] ), 'a field named after a taxonomy the site attached to posts does not take that property' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_authors'] ), 'it is renamed by the same rule' );
$GLOBALS['dpt_stub_object_taxonomies'] = $saved_object_tax;

/* ---- a name only one target's controller owns is reserved only there ---- */

// The reserved set is per target or it is a rename list for collisions that
// cannot happen, and a needless rename is the promise this module is here to
// keep: a site's fields under the names the site gave them. `description` is
// a property of a term and of an attachment; on /wp/v2/pages it is nothing at
// all. `sticky` is core's, on `post` and on no other post type. And the rest
// bases of the taxonomies attached to a target are the registry's answer, per
// target, not a written pair of names.
$GLOBALS['dpt_stub_rest_post_types'] = array( 'post', 'page', 'attachment' );
$GLOBALS['dpt_stub_options']         = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'shared-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post', 'page', 'attachment' ) ),
			'meta_fields' => array(
				array( 'name' => 'description', 'title' => 'Short description', 'object_type' => 'field', 'type' => 'textarea' ),
				array( 'name' => 'source_url', 'title' => 'Where it came from', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'sticky', 'title' => 'Pin this', 'object_type' => 'field', 'type' => 'switcher' ),
				array( 'name' => 'categories', 'title' => 'Category labels', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'author-box',
			'args'        => array( 'object_type' => 'taxonomy', 'allowed_tax' => array( 'authors' ) ),
			'meta_fields' => array(
				array( 'name' => 'description', 'title' => 'Bio', 'object_type' => 'field', 'type' => 'wysiwyg' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['description'] ), 'a description field on a page keeps the name the site gave it' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['page']['jet_description'] ), 'and is not renamed for a collision the page controller cannot have' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['authors']['jet_description'] ), 'while the same name on a taxonomy, whose controller really does define it, is still renamed' );
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'authors' )['description']['dpt_stub_core'] ), 'leaving the term\'s own description exactly as core defined it' );

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['attachment']['jet_source_url'] ), 'a property only the attachments controller adds is reserved on attachment' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['source_url'] ), 'and left alone on a post type that controller never serves' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['attachment']['jet_description'] ), 'description is reserved there too, by the same controller and on the same one target' );

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_sticky'] ), 'sticky is reserved on post, the one post type core adds it to' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['sticky'] ), 'and not on a post type it never appears on' );

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_categories'] ), 'a taxonomy rest base is reserved where that taxonomy is attached' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['page']['categories'] ), 'and nowhere else, because the registry answers it per target rather than a written list' );

$GLOBALS['dpt_stub_rest_post_types'] = $saved_post_types;

/* ---- and the alias is never laid over a name the site itself defined ---- */

// A site can define both `title` and a field of its own literally called
// jet_faq_title. Compatibility fills a gap; it never takes a name that is
// already someone's, so here the collision has nowhere to go and is left off
// rather than written over the site's own field.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'faq-box',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'jet_faq_title', 'title' => 'Our own heading', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'title', 'title' => 'FAQ title', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['title']['dpt_stub_core'] ), 'core\'s title is still core\'s' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title'] ), 'the site\'s own jet_faq_title is registered' );
$own_write = $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['update_callback'];
$GLOBALS['dpt_stub_post_meta'] = array();
call_user_func( $own_write, 'Ours', (object) array( 'ID' => 32 ) );
dpt_test_eq( get_post_meta( 32, 'jet_faq_title', true ), 'Ours', 'and still bound to its own meta key, not to title' );
dpt_test_eq( get_post_meta( 32, 'title', true ), '', 'while the colliding field is left off rather than laid over it' );
$taken_joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( false !== strpos( $taken_joined, 'the alias jet_faq_title is not free either' ), 'and the info endpoint can say that the alias was not free' );

/* ---- with no JetEngine at all, the legacy name is still there ---- */

// The old plugin registered jet_faq_title unconditionally, and an automation
// that has been writing it does not care whether this site's FAQ title is a
// JetEngine field or plain meta.
$GLOBALS['dpt_stub_options'] = array();
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title'] ), 'jet_faq_title is registered by the compatibility layer' );
dpt_test_ok( in_array( 'jet_faq_title', DPT_RB_Fields::compat(), true ), 'and reported as one of its names' );
$legacy_title_write = $GLOBALS['dpt_stub_rest_fields']['post']['jet_faq_title']['update_callback'];
$GLOBALS['dpt_stub_post_meta'] = array();
call_user_func( $legacy_title_write, 'Questions', (object) array( 'ID' => 33 ) );
dpt_test_eq( get_post_meta( 33, 'title', true ), 'Questions', 'reading and writing the same meta key the replaced plugin did' );
dpt_test_ok( ! empty( dpt_stub_rest_item_schema( 'post' )['title']['dpt_stub_core'] ), 'without ever touching core\'s own title' );

$GLOBALS['dpt_stub_rest_post_types'] = $saved_post_types;
$GLOBALS['dpt_stub_rest_taxonomies'] = $saved_taxonomies;

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

/* ---- a target's name is the site's own, not one this module derived ---- */

// register_post_type() sanitize_keys the name it stores, so a post type and
// sanitize_key() agree. register_taxonomy() does not: it checks the length
// and keys $wp_taxonomies by exactly the name it was handed. So a taxonomy
// registered as `Authors` was reduced here to `authors`, matched nothing in
// the registry, and took its whole meta box down without a word - the meta
// key bug one level up, on the target rather than on the key.
$saved_post_types                       = $GLOBALS['dpt_stub_rest_post_types'];
$saved_taxonomies                       = $GLOBALS['dpt_stub_rest_taxonomies'];
$saved_private_taxonomies               = $GLOBALS['dpt_stub_private_taxonomies'];
$GLOBALS['dpt_stub_rest_post_types']    = array( 'post' );
$GLOBALS['dpt_stub_rest_taxonomies']    = array( 'Authors' );
$GLOBALS['dpt_stub_private_taxonomies'] = array( 'internal_notes' );
$GLOBALS['dpt_stub_options']            = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'mixed-case-taxonomy',
			'args'        => array(
				'object_type' => 'taxonomy',
				'allowed_tax' => array( 'Authors', 'internal_notes', 'no_such_tax' ),
			),
			'meta_fields' => array(
				array( 'name' => 'bio_link', 'title' => 'Bio link', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$mixed = DPT_RB_Definitions::all();
dpt_test_eq( count( $mixed ), 1, 'the meta box on an uppercase taxonomy is found' );
dpt_test_eq( $mixed[0]['targets'], array( 'Authors', 'internal_notes', 'no_such_tax' ), 'and its targets are the names the meta box stored, letter for letter' );

$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['Authors']['bio_link'] ), 'the field really registers on the taxonomy the site registered' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['authors'] ), 'and not on a lowercased name nothing on this site answers to' );
$mixed_joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( false !== strpos( $mixed_joined, 'no_such_tax' ), 'a target that is not registered at all is named' );
dpt_test_ok( false !== strpos( $mixed_joined, 'no taxonomy of that name is registered on this site' ), 'with the reason it is really missing for' );
dpt_test_ok( false !== strpos( $mixed_joined, 'internal_notes' ), 'and so is one that exists but is off the REST API' );
dpt_test_ok( false !== strpos( $mixed_joined, 'show_in_rest off' ), 'with the different reason that one has' );
dpt_test_ok( false === strpos( $mixed_joined, 'author_description' ), 'while the compatibility layer stays quiet about an authors taxonomy this site simply does not have' );
$GLOBALS['dpt_stub_rest_post_types']    = $saved_post_types;
$GLOBALS['dpt_stub_rest_taxonomies']    = $saved_taxonomies;
$GLOBALS['dpt_stub_private_taxonomies'] = $saved_private_taxonomies;

/* ---- the object type a controller looks a field up under ---- */

// register_rest_field() files a field under the string it is handed, and a
// controller looks its own up by WP_REST_Controller::get_object_type(), which
// is $schema['title']. WP_REST_Terms_Controller titles itself
// `'post_tag' === $this->taxonomy ? 'tag' : $this->taxonomy` - the same rule
// core writes out a second time in
// WP_REST_Term_Meta_Fields::get_rest_field_type(), whose docblock points at
// register_rest_field(). So a field registered on post_tag was filed under a
// name nothing ever looks up: never read, never written, and still reported
// by registered() as a field this site has.
//
// The harness already gives category and post_tag the REST bases core gives
// them - `categories` and `tags`, both different from their names - which is
// the tempting wrong answer for this lookup, so the assertions below say what
// the object type is *not* as well as what it is.
$saved_taxonomies                    = $GLOBALS['dpt_stub_rest_taxonomies'];
$GLOBALS['dpt_stub_rest_taxonomies'] = array( 'category', 'post_tag', 'client' );
$GLOBALS['dpt_stub_options']         = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'term-extras',
			'args'        => array(
				'object_type' => 'taxonomy',
				'allowed_tax' => array( 'post_tag', 'category', 'client' ),
			),
			'meta_fields' => array(
				array( 'name' => 'pinned', 'title' => 'Pinned', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
		array(
			'id'          => 'post-extras-control',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'byline', 'title' => 'Byline', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();

$tag_fields = dpt_stub_controller_fields( 'post_tag', true );
dpt_test_ok( isset( $tag_fields['pinned'] ), 'a field on post_tag reaches the controller that serves post_tag terms' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['tag']['pinned'] ), 'because it is registered under tag, the title that controller gives itself' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post_tag'] ), 'and not under post_tag, where nothing would ever look for it' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['tags'] ), 'nor under its REST base' );

$cat_fields = dpt_stub_controller_fields( 'category', true );
dpt_test_ok( isset( $cat_fields['pinned'] ), 'a field on category reaches its controller too' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['category']['pinned'] ), 'under category, its own name - core remaps post_tag and nothing else' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['categories'] ), 'and not under categories, which is its REST base and a different thing entirely' );

$client_fields = dpt_stub_controller_fields( 'client', true );
dpt_test_ok( isset( $client_fields['pinned'] ), 'a custom taxonomy whose name core does not remap is registered as itself' );
dpt_test_ok( isset( dpt_stub_controller_fields( 'post' )['byline'] ), 'and a post type is its own name, as WP_REST_Posts_Controller titles itself' );

// The callbacks really are reachable from where the controller finds them,
// which is the half of this that a bookkeeping assertion cannot show.
// Read through isset() and short-circuited, the way the other callback
// assertions here are: without the fix there is no callback at that address
// at all, and a fatal would take the rest of the file down instead of
// reporting the one thing that broke.
$GLOBALS['dpt_stub_term_meta'] = array();
$pinned_write                  = isset( $tag_fields['pinned']['update_callback'] ) ? $tag_fields['pinned']['update_callback'] : null;
$pinned_read                   = isset( $tag_fields['pinned']['get_callback'] ) ? $tag_fields['pinned']['get_callback'] : null;
dpt_test_ok( is_callable( $pinned_write ) && true === call_user_func( $pinned_write, 'yes', (object) array( 'term_id' => 8 ) ), 'a write through the callback the tag controller holds lands' );
dpt_test_eq( get_term_meta( 8, 'pinned', true ), 'yes', 'on the term meta key JetEngine named' );
dpt_test_eq( is_callable( $pinned_read ) ? call_user_func( $pinned_read, array( 'id' => 8 ) ) : null, 'yes', 'and reads back through the same one' );

// What the info endpoint says about it stays the target the site named: an
// operator reads that report by taxonomy, and `tag` is core's internal name
// for a controller rather than anything the site ever wrote down.
$term_registered = DPT_RB_Fields::registered();
dpt_test_ok( isset( $term_registered['taxonomy/post_tag']['pinned'] ), 'the report names post_tag, which is the target the meta box named' );
dpt_test_ok( ! isset( $term_registered['taxonomy/tag'] ), 'and does not invent a taxonomy called tag that this site does not have' );

$GLOBALS['dpt_stub_rest_taxonomies'] = $saved_taxonomies;

/* ---- a Hebrew field is a field, all the way through ---- */

// The whole of the key fix, end to end and through the registered callbacks:
// a site whose editors name their fields in their own language has those
// fields on the API, reading and writing the meta key JetEngine really
// stored - and the keys WordPress genuinely will not carry are refused with
// the reason WordPress genuinely has, rather than with a sentence about a
// name the site never wrote.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'hebrew-live',
			'args'        => array(
				'object_type'       => 'post',
				'allowed_post_type' => array( 'post' ),
			),
			'meta_fields' => array(
				array( 'name' => 'מחיר', 'title' => 'מחיר', 'object_type' => 'field', 'type' => 'text' ),
				array( 'name' => 'צבעים', 'title' => 'צבעים', 'object_type' => 'field', 'type' => 'checkbox' ),
				// Core strips every byte outside printable ASCII and the
				// Unicode letters before it looks for the underscore, and PCRE
				// reads \p{L} a byte at a time in that pattern, so what is left
				// of this name is "_price" and WordPress really does protect
				// it. Refused - but for the reason it is really refused for.
				array( 'name' => 'שדה_price', 'title' => 'Price', 'object_type' => 'field', 'type' => 'text' ),
				// update_metadata() opens with `! $meta_key`, and PHP reads the
				// string "0" as empty, so this key can never be written at all.
				array( 'name' => '0', 'title' => 'Zero', 'object_type' => 'field', 'type' => 'text' ),
				// The meta_key column is varchar(255); a longer key is a
				// different key by the time it reaches storage.
				array( 'name' => str_repeat( 'x', 256 ), 'title' => 'Long', 'object_type' => 'field', 'type' => 'text' ),
				// And the other side of that limit, which is the one this
				// plugin's own sites walk into: varchar counts characters on
				// a utf8mb4 table, so 200 Hebrew characters is 400 bytes and
				// fits. Measuring bytes here would refuse a field for a limit
				// WordPress does not have - the same mistake in a new place.
				array( 'name' => str_repeat( 'ש', 200 ), 'title' => 'Long Hebrew', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
$GLOBALS['dpt_stub_post_meta']   = array();
DPT_RB_Fields::register();

dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['מחיר'] ), 'a Hebrew field is registered under the name the site gave it' );
$hebrew_write = $GLOBALS['dpt_stub_rest_fields']['post']['מחיר']['update_callback'];
$hebrew_read  = $GLOBALS['dpt_stub_rest_fields']['post']['מחיר']['get_callback'];
dpt_test_ok( true === call_user_func( $hebrew_write, 'שלוש מאות', (object) array( 'ID' => 71 ) ), 'and a write through it succeeds' );
dpt_test_eq( get_post_meta( 71, 'מחיר', true ), 'שלוש מאות', 'landing on the meta key JetEngine itself reads and writes' );
dpt_test_eq( call_user_func( $hebrew_read, array( 'id' => 71 ) ), 'שלוש מאות', 'and the read hands the same value back' );

// The checkbox beside it, whose option keys are the same free text one level
// down: read, modify and write has to leave the options the site defined.
$colours_write = $GLOBALS['dpt_stub_rest_fields']['post']['צבעים']['update_callback'];
$colours_read  = $GLOBALS['dpt_stub_rest_fields']['post']['צבעים']['get_callback'];
dpt_test_ok( true === call_user_func( $colours_write, array( 'כחול' => 'true', 'אדום' => 'false' ), (object) array( 'ID' => 71 ) ), 'a checkbox with Hebrew option keys writes' );
dpt_test_eq( get_post_meta( 71, 'צבעים', true ), array( 'כחול' => 'true', 'אדום' => 'false' ), 'storing the options the site defined, spelled as it defined them' );
$colours_back = call_user_func( $colours_read, array( 'id' => 71 ) );
dpt_test_eq( (array) $colours_back, array( 'כחול' => 'true', 'אדום' => 'false' ), 'and reads back as the same options' );
dpt_test_ok( true === call_user_func( $colours_write, $colours_back, (object) array( 'ID' => 71 ) ), 'writing back what was just read is a success' );
dpt_test_eq( get_post_meta( 71, 'צבעים', true ), array( 'כחול' => 'true', 'אדום' => 'false' ), 'and leaves storage holding exactly what it held' );

// And the three that WordPress will not carry, each named with its own
// reason rather than with a borrowed one.
$joined = implode( ' | ', DPT_RB_Fields::skipped() );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['שדה_price'] ), 'a key WordPress protects is not registered' );
dpt_test_ok( false !== strpos( $joined, 'שדה_price' ), 'and is named in the diagnostics by the name the site gave it' );
dpt_test_ok( false !== strpos( $joined, 'protected' ), 'with protection as the reason' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post']['0'] ), 'a key of "0" is not registered' );
dpt_test_ok( false !== strpos( $joined, 'cannot store' ), 'and says WordPress cannot store it, not that it is protected' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_rest_fields']['post'][ str_repeat( 'x', 256 ) ] ), 'nor is a key longer than the column that would have to hold it' );
dpt_test_ok( false !== strpos( $joined, '255' ), 'and the length is the reason given for that one' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post'][ str_repeat( 'ש', 200 ) ] ), 'while 200 Hebrew characters fit the column that counts characters, and are registered' );

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

/* ---- what a landed write invalidates, and what it leaves alone ---- */

// The old answer was files_manager->clear_cache(), which unlinks every
// generated CSS file on the site, drops the element cache and page assets of
// every post there is, and deletes the global CSS option - for one widget's
// text on one page. Elementor calls that from the Tools screen, a database
// upgrade, an experiment switch and a Kit save, and from nothing that edits a
// document; its own document save invalidates the edited document alone.
//
// It was nonetheless right about the thing that matters most, and that has to
// keep working: _elementor_element_cache holds the fully rendered HTML of the
// page for elementor_element_cache_ttl hours, so a narrower invalidation that
// dropped it would leave the front end serving yesterday's markup for a day.
$GLOBALS['dpt_stub_post_meta'][20]['_elementor_css']            = 'body{}';
$GLOBALS['dpt_stub_post_meta'][20]['_elementor_element_cache']  = '{"content":"the old page"}';
$GLOBALS['dpt_stub_post_meta'][20]['_elementor_page_assets']    = array( 'styles' => array( 'old-widget' ) );
// A second page, untouched by this request, with all three of its own.
$GLOBALS['dpt_stub_posts'][27]                                  = 'page';
$GLOBALS['dpt_stub_post_meta'][27]['_elementor_css']            = 'body{}';
$GLOBALS['dpt_stub_post_meta'][27]['_elementor_element_cache']  = '{"content":"another page"}';
$GLOBALS['dpt_stub_post_meta'][27]['_elementor_page_assets']    = array( 'styles' => array( 'other-widget' ) );
$GLOBALS['dpt_stub_elementor_cache_cleared']                    = 0;
$GLOBALS['dpt_stub_elementor_post_css_deleted']                 = array();

DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array( array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'New title' ) ) ),
) ) );

dpt_test_eq( get_post_meta( 20, '_elementor_element_cache', true ), '', 'the rendered HTML of the edited page is dropped - the one thing the site-wide purge was accidentally right about' );
dpt_test_eq( get_post_meta( 20, '_elementor_page_assets', true ), '', 'so is the list of assets its widgets needed, which a changed layout can change' );
dpt_test_eq( get_post_meta( 20, '_elementor_css', true ), '', 'and its generated CSS' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_post_css_deleted'], array( 20 ), 'through Elementor\'s own Post_CSS::delete(), which takes the file on disk with the meta row, and for this post alone' );

dpt_test_eq( get_post_meta( 27, '_elementor_element_cache', true ), '{"content":"another page"}', 'while a page nobody edited keeps its rendered HTML' );
dpt_test_eq( get_post_meta( 27, '_elementor_page_assets', true ), array( 'styles' => array( 'other-widget' ) ), 'and its assets' );
dpt_test_eq( get_post_meta( 27, '_elementor_css', true ), 'body{}', 'and its CSS' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_cache_cleared'], 0, 'because the site-wide purge is not what one widget edit invalidates, and is not called' );

unset( $GLOBALS['dpt_stub_posts'][27], $GLOBALS['dpt_stub_post_meta'][27] );

/* ---- and the post's plain text is rewritten, as Elementor's save does ---- */

// _elementor_data is the layout, but Elementor's own save_elements() follows
// the meta write with db->save_plain_text(), which renders the layout and
// wp_update_post()s it into post_content - the column WordPress search
// indexes, the_excerpt() falls back to, and RSS serves. Writing only the
// layout left all three describing the previous version of the page.
$GLOBALS['dpt_stub_posts'][28] = array(
	'post_type'    => 'page',
	'post_content' => 'The text of the page as it was before',
);
update_post_meta( 28, '_elementor_data', wp_slash( wp_json_encode( array(
	array(
		'id'       => 's1',
		'elType'   => 'section',
		'elements' => array(
			array( 'id' => 'p1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Old heading' ) ),
		),
	),
) ) ) );

DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 28,
	'updates' => array( array( 'widget_id' => 'p1', 'settings' => array( 'title' => 'A brand new heading' ) ) ),
) ) );

$plain = get_post( 28 );
dpt_test_ok( false !== strpos( $plain->post_content, 'A brand new heading' ), 'post_content carries the text of the layout that is now stored' );
dpt_test_ok( false === strpos( $plain->post_content, 'The text of the page as it was before' ), 'and not the text of the page as it was, which search and RSS would have gone on serving' );
dpt_test_ok( false === strpos( $plain->post_content, 'Old heading' ), 'nor the setting that was replaced' );

unset( $GLOBALS['dpt_stub_posts'][28], $GLOBALS['dpt_stub_post_meta'][28] );

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

/* ---- and settings arrive under the rule Elementor's own save applies ---- */

// Elementor prints widget settings raw - Html::render() calls
// print_unescaped_setting( 'html' ), Text_Editor echoes its content - so its
// own Document::save() runs map_deep( $data, 'wp_kses_post' ) over everything
// it is about to store whenever the current user lacks unfiltered_html. This
// endpoint writes the same meta key with the same caller-supplied values, so
// it was a weaker door into the same data: a Contributor, an Author or an
// Editor on multisite could POST a script tag Elementor's own editor would
// have stripped and have it printed on the front end.
$GLOBALS['dpt_stub_posts'][26] = 'page';
$kses_layout                   = array(
	array(
		'id'         => 'k1',
		'elType'     => 'widget',
		'widgetType' => 'html',
		'settings'   => array( 'html' => '<p>before</p>' ),
	),
);
update_post_meta( 26, '_elementor_data', wp_json_encode( $kses_layout ) );

$GLOBALS['dpt_stub_denied_caps'] = array( 'unfiltered_html' );
$kses_result                     = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 26,
	'updates' => array(
		array(
			'widget_id' => 'k1',
			'settings'  => array(
				'html'    => '<p>hello</p><script>alert(1)</script>',
				'nested'  => array( 'deep' => '<img src=x onerror=alert(1)>' ),
				'enabled' => true,
				'absent'  => null,
			),
		),
	),
) ) );
dpt_test_ok( ! is_wp_error( $kses_result ), 'a filtered write still succeeds' );
$kses_saved = json_decode( get_post_meta( 26, '_elementor_data', true ), true );
dpt_test_ok( false === strpos( $kses_saved[0]['settings']['html'], '<script' ), 'a user without unfiltered_html cannot store a script tag' );
dpt_test_ok( false !== strpos( $kses_saved[0]['settings']['html'], '<p>hello</p>' ), 'while the markup kses does allow is kept' );
dpt_test_ok( false === strpos( $kses_saved[0]['settings']['nested']['deep'], 'onerror' ), 'the walk reaches a setting nested inside another' );
dpt_test_ok( true === $kses_saved[0]['settings']['enabled'], 'a boolean is handed through untouched, as Elementor hands it through' );
dpt_test_ok( array_key_exists( 'absent', $kses_saved[0]['settings'] ) && null === $kses_saved[0]['settings']['absent'], 'and so is a null' );

// And the other side of the same gate: the capability is what decides, so a
// user who has it can store what Elementor would let them store. Anything
// stricter would be this endpoint refusing what the editor beside it allows.
$GLOBALS['dpt_stub_denied_caps'] = array();
$unfiltered_result               = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 26,
	'updates' => array(
		array( 'widget_id' => 'k1', 'settings' => array( 'html' => '<script>ok()</script>' ) ),
	),
) ) );
dpt_test_ok( ! is_wp_error( $unfiltered_result ), 'a user with unfiltered_html writes' );
$unfiltered_saved = json_decode( get_post_meta( 26, '_elementor_data', true ), true );
dpt_test_eq( $unfiltered_saved[0]['settings']['html'], '<script>ok()</script>', 'and their markup is stored exactly as Elementor would store it' );

// A setting the caller did not send is not the caller's value and is not
// filtered: kses over the whole stored layout would rewrite content that has
// been on the page for years on the strength of one unrelated widget edit.
$GLOBALS['dpt_stub_denied_caps'] = array( 'unfiltered_html' );
update_post_meta(
	26,
	'_elementor_data',
	wp_json_encode(
		array(
			array( 'id' => 'k1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>old()</script>' ) ),
			array( 'id' => 'k2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Heading' ) ),
		)
	)
);
DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 26,
	'updates' => array(
		array( 'widget_id' => 'k2', 'settings' => array( 'title' => 'New heading' ) ),
	),
) ) );
$untouched_saved = json_decode( get_post_meta( 26, '_elementor_data', true ), true );
dpt_test_eq( $untouched_saved[0]['settings']['html'], '<script>old()</script>', 'a stored setting nobody wrote to is left exactly as it was' );
dpt_test_eq( $untouched_saved[1]['settings']['title'], 'New heading', 'while the one that was written is the one that changed' );
$GLOBALS['dpt_stub_denied_caps'] = array();

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
$GLOBALS['dpt_stub_elementor_cache_cleared']    = 0;
$GLOBALS['dpt_stub_elementor_post_css_deleted'] = array();
$unchanged = DPT_RB_Elementor::update( new DPT_Stub_Request( array(
	'post_id' => 20,
	'updates' => array(
		array( 'widget_id' => 'w1', 'settings' => array( 'title' => 'New title' ) ),
	),
) ) );
dpt_test_ok( ! is_wp_error( $unchanged ), 'writing the layout that is already stored is not a failure' );
dpt_test_ok( isset( $unchanged['success'] ) && true === $unchanged['success'], 'it reports success' );
dpt_test_eq( $unchanged['updates_applied'], 1, 'with the widget it merged into' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_post_css_deleted'], array( 20 ), 'and the page is invalidated once, as after any landed write' );
dpt_test_eq( $GLOBALS['dpt_stub_elementor_cache_cleared'], 0, 'without reaching for the site-wide purge' );

/* ---- and only for someone allowed to edit that post ---- */

// The plugin this replaces asked only whether the user could edit something,
// which let anyone with an author's rights rewrite every page on the site.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 20 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a post this user may not edit is refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'and one they may is allowed' );

/* ---- with no Elementor at all, that capability is the whole of the gate ---- */

// This module supports a site whose Elementor is not installed or not active,
// where these routes still answer for a page whose layout is in the database.
// Asserted before the class exists, because class_alias() is a one-way door
// and a flag on a stub could not honestly reproduce an absent class.
dpt_test_ok( ! class_exists( 'Elementor\User', false ), 'this run has no Elementor User class yet' );
$GLOBALS['dpt_stub_posts'][21]                = array( 'post_type' => 'sheet_music', 'post_status' => 'publish' );
$GLOBALS['dpt_stub_elementor_excluded_roles'] = array( 'editor' );
$GLOBALS['dpt_stub_current_user_roles']       = array( 'editor' );
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a post the user may edit is allowed with no Elementor to ask' );
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ), 'and so is a post type Elementor would not have supported, since none of those settings exist here' );
$GLOBALS['dpt_stub_denied_post_caps'] = array( 20 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'while the module\'s own capability check still refuses' );
$GLOBALS['dpt_stub_denied_post_caps']   = array();
$GLOBALS['dpt_stub_current_user_roles'] = array( 'administrator' );

/* ---- and with Elementor here, its own gate is the one that answers ---- */

// Document::is_editable_by_current_user() defers to
// User::is_current_user_can_edit(), which refuses four things beyond the
// post's edit capability. These endpoints write the meta key that gate
// protects, so without them this was a weaker door into the same data than
// the editor beside it.
class_alias( 'DPT_Stub_Elementor_User', 'Elementor\User' );

dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'an ordinary page an allowed user may edit still passes' );

// 1. a role the site has excluded from editing with Elementor.
$GLOBALS['dpt_stub_current_user_roles'] = array( 'editor' );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a role listed in elementor_exclude_user_roles is refused, as Elementor\'s own editor refuses it' );
$GLOBALS['dpt_stub_current_user_roles'] = array( 'administrator' );
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'and a role that is not listed is not' );
$GLOBALS['dpt_stub_elementor_excluded_roles'] = array();

// 2. a trashed post.
$GLOBALS['dpt_stub_posts'][20] = array( 'post_type' => 'page', 'post_status' => 'trash' );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 20 ) ) ), 'a trashed page cannot be rewritten through this endpoint either' );
$GLOBALS['dpt_stub_posts'][20] = array( 'post_type' => 'page', 'post_status' => 'publish' );

// 3. a post type Elementor does not support.
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ), 'a post type Elementor was not switched on for is refused' );
$GLOBALS['dpt_stub_elementor_cpt_support'][] = 'sheet_music';
dpt_test_ok( DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ), 'and allowed once the site switches it on, which is the setting Elementor reads' );

// 4. the posts page, which Elementor refuses by id.
$GLOBALS['dpt_stub_page_for_posts'] = 21;
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ), 'and the site\'s posts page is refused by id, as it is in the editor' );
$GLOBALS['dpt_stub_page_for_posts'] = 0;

// And the module's own check still comes first, so a post the user may not
// edit is refused whatever Elementor would have said about it.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 21 );
dpt_test_ok( ! DPT_RB_Elementor::may_edit( new DPT_Stub_Request( array( 'post_id' => 21 ) ) ), 'a post this user may not edit is still refused with Elementor here' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();
unset( $GLOBALS['dpt_stub_posts'][21] );

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

// WordPress calls this as an auth_{$type}_meta_{$key} filter, not a
// standalone check: $allowed can already be false by the time it runs, when
// the site has its own auth_post_meta_rank_math_* filter hooked at the same
// or an earlier priority. A callback that returns current_user_can() alone
// discards that and re-grants what the site explicitly denied to anyone who
// may edit the post - the denial must survive regardless.
dpt_test_ok( ! call_user_func( $auth, false, 'rank_math_title', 30 ), 'a denial already in $allowed survives even when the user may edit the post' );
dpt_test_ok( call_user_func( $auth, true, 'rank_math_title', 30 ), 'while with no prior denial an editable post is still allowed' );
$GLOBALS['dpt_stub_denied_post_caps'] = array( 30 );
dpt_test_ok( ! call_user_func( $auth, true, 'rank_math_title', 30 ), 'and with no prior denial a post this user may not edit is still refused' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

// Everything above is with nothing filtering is_protected_meta, which is not
// the site this module runs on. Rank Math marks every rank_math_* key
// protected from Common::__construct() - unconditionally, on REST requests
// like any other - so map_meta_cap() seeds $allowed = ! is_protected_meta()
// as **false** before it runs this callback at all. A bare AND therefore kept
// it false for everybody, administrators included, and the Rank Math writes
// this module exists to enable were dead on every site that has Rank Math.
//
// The seed is the one thing an auth_callback on register_post_meta() is there
// to replace: a key registered with one is a key whose protected default the
// registration means to override. A denial a *site* made is not, and both
// arrive here as the same false. They are told apart by recomputing the seed
// and comparing: equal means nothing intervened and the decision is ours,
// different means somebody decided and that decision stands.
add_filter(
	'is_protected_meta',
	function ( $protected, $key ) {
		// Rank Math's own hide_rank_math_meta(), which is
		// Str::starts_with( 'rank_math_', $meta_key ) ? true : $is_protected.
		return 0 === strpos( (string) $key, 'rank_math_' ) ? true : $protected;
	}
);
dpt_test_ok( is_protected_meta( 'rank_math_title', 'post' ), 'with Rank Math loaded its keys are protected, which is what seeds $allowed false' );
dpt_test_ok( ! is_protected_meta( 'reading_time', 'post' ), 'while a key it does not own is untouched' );

// 1. Protected by Rank Math, nothing else hooked, and the user may edit the
//    post: allowed. This is the case that was refused to everybody.
dpt_test_ok( call_user_func( $auth, false, 'rank_math_title', 30 ), 'a Rank Math key is writable by someone who may edit the post' );

// 2. The same seed, a post they may not edit: still refused, because the
//    capability check is what the callback is really for.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 30 );
dpt_test_ok( ! call_user_func( $auth, false, 'rank_math_title', 30 ), 'and refused on a post they may not edit' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

// 4. A grant that differs from the seed came from somebody else, and stands -
//    even against this callback's own capability check, which is what
//    "respect what it decided" has to mean if it means anything. It grants
//    nothing by itself: map_meta_cap() has already put edit_post's mapping in
//    $caps at capabilities.php:472, and a true here only declines to add the
//    blocking capability on top of it.
$GLOBALS['dpt_stub_denied_post_caps'] = array( 30 );
dpt_test_ok( call_user_func( $auth, true, 'rank_math_title', 30 ), 'a grant another filter made where the seed refused survives this callback' );
$GLOBALS['dpt_stub_denied_post_caps'] = array();

remove_filter( 'is_protected_meta' );

// 3. A denial another filter made, which differs from the seed, is refused
//    even for a user who may edit the post - the whole of what the AND was
//    added to close, and it is still closed.
dpt_test_ok( ! call_user_func( $auth, false, 'rank_math_title', 30 ), 'a denial another filter made is refused even for a user who may edit the post' );

// Asserted with Rank Math's filter off, deliberately, because that is the
// only configuration in which such a denial is *visible*: with the filter on
// the seed is already false, so a site filter that also denies is
// byte-identical to nothing having spoken, and no rule reading $allowed alone
// can see it. The callback's docblock says so rather than implying a
// certainty it does not have. A site that needs a denial to hold on a Rank
// Math key hooks after this callback's priority 10, where its false is the
// last word.

/* ---- and none of it reaches an anonymous reader ---- */

// The auth_callback above gates writes and nothing else.
// WP_REST_Meta_Fields::get_value() performs no capability check at all -
// unlike update_meta_value() and delete_meta_value(), which both do - and
// rest_filter_response_by_context() leaves a property alone when its schema
// names no context. So registering these keys with show_in_rest => true put
// the focus keyword and the SEO score of every post and page on an
// unauthenticated GET /wp/v2/posts. Rank Math says what it thinks of that in
// its own code: Common::hide_rank_math_meta() filters is_protected_meta() to
// true for every rank_math_* key there is.
//
// This module already goes to some length to keep *discovered* fields out of
// the view context for exactly this reason. Same exposure, different door.
$GLOBALS['dpt_stub_post_meta'] = array();
update_post_meta( 31, 'rank_math_focus_keyword', 'hebrew seo' );
update_post_meta( 31, 'rank_math_seo_score', '81' );
update_post_meta( 31, 'rank_math_title', 'A title' );

$rm_view = dpt_stub_meta_response( 'post', 31, 'view' );
dpt_test_ok( ! array_key_exists( 'rank_math_focus_keyword', $rm_view ), 'the focus keyword is not on an unauthenticated read' );
dpt_test_ok( ! array_key_exists( 'rank_math_seo_score', $rm_view ), 'nor is the SEO score' );
dpt_test_ok( ! array_key_exists( 'rank_math_title', $rm_view ), 'nor any other rank_math key' );
dpt_test_eq( $rm_view, array(), 'none of the twelve survives the view context' );

// And the other half, which is what makes the first half a fix rather than a
// removal: a reader who asked for the edit context - which core gates on the
// post's own update capability before a field callback runs - still gets
// every one of them, with its value.
$rm_edit = dpt_stub_meta_response( 'post', 31, 'edit' );
dpt_test_eq( $rm_edit['rank_math_focus_keyword'], 'hebrew seo', 'while an authenticated edit-context read still returns the focus keyword' );
dpt_test_eq( $rm_edit['rank_math_seo_score'], '81', 'and the score' );
dpt_test_eq( count( $rm_edit ), 12, 'and all twelve keys are there' );

// The array-typed key needs its item schema as much as it needs its context;
// without the items core refuses to expose it at all, so the two have to
// travel together.
$robots = $GLOBALS['dpt_stub_registered_post_meta']['post']['rank_math_robots']['show_in_rest'];
dpt_test_eq( $robots['schema']['items'], array( 'type' => 'string' ), 'robots keeps the item schema core requires of an array' );
dpt_test_eq( $robots['schema']['context'], array( 'edit' ), 'beside the context that keeps it off an anonymous read' );
$GLOBALS['dpt_stub_post_meta'] = array();

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

/* ---- rehearsing the registration without performing it ---- */

// The preview screen exists because the fields are discovered from the site's
// own JetEngine definitions: until something reads them, nobody knows what
// this module would expose. It has to describe the real run exactly, so the
// two are compared here against one another rather than against a list
// written out by hand.
$GLOBALS['dpt_stub_options'] = array(
	'jet_engine_meta_boxes' => array(
		array(
			'id'          => 'rehearsal',
			'args'        => array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ) ),
			'meta_fields' => array(
				array( 'name' => 'rehearsed', 'title' => 'Rehearsed', 'object_type' => 'field', 'type' => 'text' ),
			),
		),
	),
);

DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
$dpt_real_fields  = DPT_RB_Fields::registered();
$dpt_real_skipped = DPT_RB_Fields::skipped();
$dpt_real_compat  = DPT_RB_Fields::compat();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['rehearsed'] ), 'a real run registers the field' );
// Named so the comparisons below cannot pass by both sides being empty.
dpt_test_ok( ! empty( $dpt_real_fields ), 'and reports it, so the comparisons below have something to compare' );

DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::rehearse();

dpt_test_eq( $GLOBALS['dpt_stub_rest_fields'], array(), 'a rehearsal registers nothing at all' );
dpt_test_eq( DPT_RB_Fields::registered(), $dpt_real_fields, 'while reporting the same fields the real run reported' );
dpt_test_eq( DPT_RB_Fields::skipped(), $dpt_real_skipped, 'and the same diagnostics' );
dpt_test_eq( DPT_RB_Fields::compat(), $dpt_real_compat, 'and the same compatibility names' );

// The whole value of the screen is that it describes what would happen. A
// rehearsal that left the flag set would make every later run a rehearsal too,
// and the module would go quiet on a site that had merely been looked at.
DPT_RB_Definitions::reset();
$GLOBALS['dpt_stub_rest_fields'] = array();
DPT_RB_Fields::register();
dpt_test_ok( isset( $GLOBALS['dpt_stub_rest_fields']['post']['rehearsed'] ), 'and the run after a rehearsal registers normally again' );

// One description of what this module does to a site, read by both the
// endpoint and the screen.
DPT_RB_Definitions::reset();
$dpt_preview = DPT_RB_Info::preview();
dpt_test_eq( $dpt_preview['fields'], $dpt_real_fields, 'the preview payload carries the fields' );
dpt_test_eq( $dpt_preview['routes'], DPT_RB_Info::payload()['routes'], 'and the same route list the endpoint reports' );

// Rank Math's keys are registered with register_post_meta() rather than
// register_rest_field(), so they are absent from 'fields' entirely. A preview
// that read only that array would tell a site with Rank Math and no JetEngine
// definitions that switching the module on exposes nothing - which is the
// opposite of true, and on exactly the site the screen exists for.
$GLOBALS['dpt_stub_registered_post_meta'] = array();
DPT_RB_Rankmath::register();
dpt_test_eq(
	$dpt_preview['rank_math_fields'],
	array_keys( $GLOBALS['dpt_stub_registered_post_meta']['post'] ),
	'the preview names every Rank Math key registration really registers'
);
dpt_test_eq( count( $dpt_preview['rank_math_fields'] ), 12, 'all twelve of them' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
