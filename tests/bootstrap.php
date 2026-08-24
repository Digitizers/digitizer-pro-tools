<?php
/**
 * Shared harness for the Digitizer Pro Tools unit tests.
 *
 * There is no WordPress in this environment, so each test defines the small
 * slice of WordPress its subject touches and requires the real module file.
 * Stub state lives in globals so a test can rearrange the "site" between
 * assertions.
 *
 * Run one:  php tests/onb-manifest-test.php
 * Run all:  for f in tests/*-test.php; do php "$f" || exit 1; done
 */

define( 'ABSPATH', '/tmp/' );
define( 'DPT_VERSION', '1.20.0' );
define( 'DPT_PATH', dirname( __DIR__ ) . '/' );
define( 'DPT_URL', 'https://example.test/wp-content/plugins/digitizer-pro-tools/' );
define( 'DPT_BASENAME', 'digitizer-pro-tools/digitizer-pro-tools.php' );
define( 'DPT_OPTION', 'dpt_settings' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dpt_test_pass']           = 0;
$GLOBALS['dpt_test_fail']           = 0;
$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
$GLOBALS['dpt_stub_stylesheet']     = 'twentytwentyfour';
$GLOBALS['dpt_stub_themes']         = array();
$GLOBALS['dpt_stub_transients']     = array();
$GLOBALS['dpt_stub_http']           = array();
$GLOBALS['dpt_stub_options']        = array();

function dpt_test_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['dpt_test_pass']++;
		return;
	}
	$GLOBALS['dpt_test_fail']++;
	echo "FAIL: $label\n";
}

function dpt_test_eq( $actual, $expected, $label ) {
	if ( $actual === $expected ) {
		$GLOBALS['dpt_test_pass']++;
		return;
	}
	$GLOBALS['dpt_test_fail']++;
	echo "FAIL: $label\n";
	echo '  expected: ' . var_export( $expected, true ) . "\n";
	echo '  actual:   ' . var_export( $actual, true ) . "\n";
}

function dpt_test_summary() {
	printf( "\n%d passed, %d failed\n", $GLOBALS['dpt_test_pass'], $GLOBALS['dpt_test_fail'] );
	return $GLOBALS['dpt_test_fail'];
}

/**
 * A notice raised while a REST response is being built corrupts the JSON
 * before a single byte of it reaches a client, so a notice, warning or
 * deprecation raised while a test runs is not noise to be scrolled past - it
 * is the exact failure this plugin exists to avoid, and is made to fail the
 * assertion count like anything else the tests check.
 *
 * The handler's own error_reporting() & $errno check is how it tells "silenced
 * by @ for this one call" apart from "silenced for good" - and without
 * pinning the ambient level here first, a runner whose php.ini already hides
 * deprecations would make that check answer the same way for both, letting a
 * genuine deprecation through unfailed. Setting E_ALL first means the only
 * way a level can go missing from that check is a deliberate @ in the code
 * under test, which is the one case this handler is meant to let pass.
 */
error_reporting( E_ALL );
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) {
		// An @ before a call that is known to warn on bad input - unserialize()
		// on an untrusted string, say - lowers error_reporting() for the
		// duration of that one call rather than skipping this handler; honour
		// that the way PHP's own default handler would, or every deliberate
		// silencing in the modules under test would fail here instead.
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}
		$as_failure = E_NOTICE | E_WARNING | E_DEPRECATED | E_USER_NOTICE | E_USER_WARNING | E_USER_DEPRECATED;
		if ( 0 === ( $errno & $as_failure ) ) {
			// Not one of the levels this handler answers for; let PHP's
			// normal handling run instead of pretending to have handled it.
			return false;
		}
		$GLOBALS['dpt_test_fail']++;
		echo "FAIL: PHP raised: {$errstr} in {$errfile} on line {$errline}\n";
		return true;
	}
);

/* ------------------------------------------------------------ WP stubs */

/**
 * The data argument is kept, not dropped: it is where every one of this
 * plugin's WP_Errors puts the HTTP status, and a stub that threw it away made
 * "this is refused with a 401 rather than a 403" an assertion nobody could
 * write.
 */
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return esc_url_raw( $s ); }
/**
 * Core's esc_url_raw() allows a fixed list of protocols and answers with an
 * empty string for anything else - javascript: and data: among them, which is
 * the whole difference between a url field and a text field. A stub that only
 * cast to string would let a test claiming that difference pass without it.
 */
function esc_url_raw( $s ) {
	if ( ! is_scalar( $s ) ) {
		return '';
	}
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return '';
	}
	// Core drops every character a URL may not contain before it decides
	// anything else, so "java\tscript:alert(1)" is javascript:alert(1) by the
	// time the protocol check sees it. Stripped here for the same reason: a
	// stub that read the protocol off the string as typed would answer "safe"
	// to a spelling core refuses.
	$s = preg_replace( '#[^a-z0-9\-~+_.?\#=!&;,/:%@$|*\'()\[\]\x80-\xff]#i', '', $s );
	if ( '' === $s ) {
		return '';
	}
	if ( preg_match( '#^([a-z0-9+.\-]+):#i', $s, $m ) ) {
		return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel' ), true ) ? $s : '';
	}
	// And what core does to a string that names no protocol at all: it makes
	// an address of it. This is the half a lenient stub used to leave out,
	// and leaving it out hid a real defect - sanitize_media() ran every
	// member of a media pair through here, so an alt text of "A hero" really
	// became http://Ahero on a live site while the suite saw it survive.
	if ( '/' === $s[0] || '#' === $s[0] || '?' === $s[0] ) {
		return $s;
	}
	return 'http://' . $s;
}
/**
 * sanitize_key(), including the half a lenient stub leaves out: core hands
 * its argument straight to strtolower(), and PHP 8 makes that a TypeError for
 * an array or an object rather than a warning. A stub that cast to string
 * first would answer "array" to a malformed option row and hide the fatal
 * that row really causes - and a fatal raised while REST fields are being
 * registered takes the whole module down for every request on the site, not
 * just the one row.
 */
function sanitize_key( $s ) {
	if ( is_array( $s ) || is_object( $s ) ) {
		throw new TypeError( 'strtolower(): Argument #1 ($string) must be of type string, ' . gettype( $s ) . ' given' );
	}
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
/**
 * stripslashes_deep(), which is all core's wp_unslash() is: a string loses one
 * level of escaping, an array or an object is walked to the bottom, and
 * anything else - an int, a bool, null - is handed back as it was rather than
 * cast to a string.
 *
 * The recursion is not decoration. update_metadata() unslashes whatever it is
 * given before storing it, so a repeater's rows are exactly where a stub that
 * only mapped the top level would stop looking, and a module that forgot to
 * slash its write would look correct here while losing a backslash on a live
 * site.
 */
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = wp_unslash( $v );
		}
		return $value;
	}
	if ( is_object( $value ) ) {
		foreach ( get_object_vars( $value ) as $k => $v ) {
			$value->$k = wp_unslash( $v );
		}
		return $value;
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
/**
 * Filters are recorded and run: a test can ask whether something was hooked,
 * and - since a module that offers a filter is offering behaviour a site can
 * change - can hook one itself and see the change. Priorities and argument
 * counts are not modelled; callbacks run in the order they were added, each
 * receiving every argument apply_filters() was given.
 */
$GLOBALS['dpt_stub_filters'] = array();
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	if ( empty( $GLOBALS['dpt_stub_filters'][ $tag ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['dpt_stub_filters'][ $tag ] as $callback ) {
		if ( is_callable( $callback ) ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
	}
	return $args[0];
}
function add_filter( $tag = '', $callback = null ) {
	if ( ! isset( $GLOBALS['dpt_stub_filters'][ $tag ] ) ) {
		$GLOBALS['dpt_stub_filters'][ $tag ] = array();
	}
	$GLOBALS['dpt_stub_filters'][ $tag ][] = $callback;
}
// WordPress keeps actions and filters in one registry, so add_action shares
// the filter store rather than getting its own.
function add_action( $tag = '', $callback = null ) {
	add_filter( $tag, $callback );
}
function remove_filter( $tag = '', $callback = null ) {
	unset( $GLOBALS['dpt_stub_filters'][ $tag ] );
}
// Style registry, enough to see what a tweak removes.
$GLOBALS['dpt_stub_deregistered_styles'] = array();
$GLOBALS['dpt_stub_dequeued_styles']     = array();
$GLOBALS['dpt_stub_registered_styles'] = array();
function wp_deregister_style( $handle ) { $GLOBALS['dpt_stub_deregistered_styles'][] = $handle; }
function wp_register_style( $handle, $src = '', $deps = array(), $ver = null ) {
	$GLOBALS['dpt_stub_registered_styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps );
	return true;
}
function wp_dequeue_style( $handle ) { $GLOBALS['dpt_stub_dequeued_styles'][] = $handle; }

function dpt_stub_has_filter( $tag ) {
	return isset( $GLOBALS['dpt_stub_filters'][ $tag ] );
}
function did_action() { return 0; }
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['dpt_stub_options'] ) ? $GLOBALS['dpt_stub_options'][ $key ] : $default;
}
// Options whose writes fail, as a filter or a database error would make them.
$GLOBALS['dpt_stub_unwritable_options'] = array();
function update_option( $key, $value ) {
	if ( in_array( $key, $GLOBALS['dpt_stub_unwritable_options'], true ) ) {
		return false;
	}
	$GLOBALS['dpt_stub_options'][ $key ] = $value;
	return true;
}

// On a single site core's site-option functions are the option functions, and
// the stub store is shared for the same reason.
function get_site_option( $key, $default = false ) { return get_option( $key, $default ); }
function update_site_option( $key, $value ) { return update_option( $key, $value ); }

// Site transients share the stub store, as they share an implementation on a
// single site.
$GLOBALS['dpt_stub_site_transients'] = array();
function get_site_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['dpt_stub_site_transients'] ) ? $GLOBALS['dpt_stub_site_transients'][ $key ] : false;
}
function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['dpt_stub_site_transients'][ $key ] = $value;
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['dpt_stub_transients'] ) ? $GLOBALS['dpt_stub_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['dpt_stub_transients'][ $key ] = $value;
	return true;
}

function get_plugins() { return $GLOBALS['dpt_stub_plugins']; }
function is_plugin_active( $file ) { return in_array( $file, $GLOBALS['dpt_stub_active_plugins'], true ); }
function get_stylesheet() { return $GLOBALS['dpt_stub_stylesheet']; }
$GLOBALS['dpt_stub_denied_caps'] = array();
/**
 * Capabilities. A bare capability is granted unless denied; a post-specific
 * one - edit_post, say - is granted unless that post id was denied, which is
 * how a test reaches the "not your post" branch.
 */
$GLOBALS['dpt_stub_denied_post_caps'] = array();
// Whether there is a user at all. "This reader is not allowed" and "there is
// no reader" are two different answers, and a module that publishes a handful
// of keys anonymously on purpose has to keep telling them apart.
$GLOBALS['dpt_stub_no_user'] = false;
// Meta keys an auth_{$type}_meta_{$key} filter says no to. A site really does
// install these, and a controller that has established the request may edit
// the post or the term has not answered them.
$GLOBALS['dpt_stub_denied_meta_caps'] = array();

function is_user_logged_in() {
	return ! $GLOBALS['dpt_stub_no_user'];
}
function rest_authorization_required_code() {
	return is_user_logged_in() ? 403 : 401;
}
/**
 * Core's own rule, character for character: a meta key is protected when the
 * first character left after everything outside printable ASCII and the
 * Unicode letters is stripped out is an underscore. map_meta_cap() then denies
 * the per-key meta capability for it to every user there is, administrators
 * included.
 *
 * The stripping is not decoration and a stub that only looked at the first
 * byte would hide the answer core really gives: PCRE reads \p{L} a byte at a
 * time here - there is no /u modifier in core's pattern - so every byte of a
 * Hebrew name falls outside both classes and is removed, and a key like
 * "field_price" written in Hebrew before the underscore is left as "_price"
 * and really is protected. A module that has to explain why a field was
 * refused cannot get that from a stub which answers differently.
 */
function is_protected_meta( $key, $type = '' ) {
	$sanitized = preg_replace( "/[^\x20-\x7E\p{L}]/", '', (string) $key );
	return strlen( $sanitized ) > 0 && '_' === $sanitized[0];
}
function current_user_can( $cap, $id = null, $meta_key = null ) {
	if ( $GLOBALS['dpt_stub_no_user'] ) {
		return false;
	}
	// The per-key metadata capabilities, in the order map_meta_cap() resolves
	// them: a flat no for a protected key, then whatever an
	// auth_{$type}_meta_{$key} filter has said, then the containing object's
	// own edit capability below. The post and term controllers establish only
	// that last one before a field callback runs, which is exactly why a
	// callback that writes metadata has to ask for the rest itself.
	if ( 'edit_post_meta' === $cap || 'edit_term_meta' === $cap ) {
		if ( is_protected_meta( $meta_key ) ) {
			return false;
		}
		if ( in_array( $meta_key, $GLOBALS['dpt_stub_denied_meta_caps'], true ) ) {
			return false;
		}
	}
	if ( null !== $id ) {
		return ! in_array( (int) $id, $GLOBALS['dpt_stub_denied_post_caps'], true );
	}
	return ! in_array( $cap, $GLOBALS['dpt_stub_denied_caps'], true );
}
$GLOBALS['dpt_stub_multisite'] = false;
function is_multisite() { return (bool) $GLOBALS['dpt_stub_multisite']; }
$GLOBALS['dpt_stub_main_site'] = true;
function is_main_site() { return (bool) $GLOBALS['dpt_stub_main_site']; }
$GLOBALS['dpt_stub_is_admin'] = true;
function is_admin() { return (bool) $GLOBALS['dpt_stub_is_admin']; }
function wp_doing_cron() { return false; }
// Core's answer to "does this site run background updates for this kind of
// thing at all", which AUTOMATIC_UPDATER_DISABLED and several filters turn off
// without touching anybody's capabilities.
$GLOBALS['dpt_stub_auto_update_types_off'] = array();
function wp_is_auto_update_enabled_for_type( $type ) {
	return ! in_array( $type, $GLOBALS['dpt_stub_auto_update_types_off'], true );
}
function switch_theme( $slug ) { $GLOBALS['dpt_stub_stylesheet'] = $slug; }
$GLOBALS['dpt_stub_self_deactivating'] = array();
function activate_plugin( $file ) {
	// A plugin whose activation hook finds an unmet prerequisite deactivates
	// itself from inside that hook; activate_plugin() still returns success.
	if ( in_array( $file, $GLOBALS['dpt_stub_self_deactivating'], true ) ) {
		return null;
	}
	$GLOBALS['dpt_stub_active_plugins'][] = $file;
	return null;
}

$GLOBALS['dpt_stub_theme_authors']  = array();
$GLOBALS['dpt_stub_theme_versions'] = array();
$GLOBALS['dpt_stub_broken_themes'] = array();
$GLOBALS['dpt_stub_theme_parents'] = array();

class DPT_Stub_Theme {
	private $exists;
	private $slug;
	public function __construct( $exists, $slug = '' ) {
		$this->exists = $exists;
		$this->slug   = $slug;
	}
	public function exists() { return $this->exists; }
	public function errors() {
		return in_array( $this->slug, $GLOBALS['dpt_stub_broken_themes'], true )
			? new WP_Error( 'theme_no_index', 'Template is missing.' )
			: false;
	}
	public function get_template() {
		return isset( $GLOBALS['dpt_stub_theme_parents'][ $this->slug ] )
			? $GLOBALS['dpt_stub_theme_parents'][ $this->slug ]
			: $this->slug;
	}
	public function get( $header ) {
		if ( 'Version' === $header ) {
			return isset( $GLOBALS['dpt_stub_theme_versions'][ $this->slug ] )
				? $GLOBALS['dpt_stub_theme_versions'][ $this->slug ]
				: '1.0.0';
		}
		if ( 'Author' !== $header ) { return ''; }
		return isset( $GLOBALS['dpt_stub_theme_authors'][ $this->slug ] )
			? $GLOBALS['dpt_stub_theme_authors'][ $this->slug ]
			: 'the WordPress team';
	}
}
function wp_get_theme( $slug = '' ) {
	return new DPT_Stub_Theme( in_array( $slug, $GLOBALS['dpt_stub_themes'], true ), $slug );
}
function wp_get_themes() {
	$out = array();
	foreach ( $GLOBALS['dpt_stub_themes'] as $slug ) {
		$out[ $slug ] = new DPT_Stub_Theme( true, $slug );
	}
	return $out;
}
$GLOBALS['dpt_stub_deleted_themes'] = array();
function delete_theme( $slug ) {
	$GLOBALS['dpt_stub_deleted_themes'][] = $slug;
	$GLOBALS['dpt_stub_themes'] = array_values( array_diff( $GLOBALS['dpt_stub_themes'], array( $slug ) ) );
	return true;
}

/**
 * Canned HTTP. Keys are URLs; values are array( 'code' => int, 'body' => string ).
 * An unknown URL is a hard failure, so a test can never accidentally depend on
 * a real network call.
 */
$GLOBALS['dpt_stub_http_calls'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['dpt_stub_http_calls'][ $url ] = isset( $GLOBALS['dpt_stub_http_calls'][ $url ] )
		? $GLOBALS['dpt_stub_http_calls'][ $url ] + 1
		: 1;
	if ( ! isset( $GLOBALS['dpt_stub_http'][ $url ] ) ) {
		return new WP_Error( 'stub_miss', 'No canned response for ' . $url );
	}
	return $GLOBALS['dpt_stub_http'][ $url ];
}
function wp_remote_retrieve_response_code( $res ) { return is_array( $res ) ? (int) $res['code'] : 0; }
function wp_remote_retrieve_body( $res ) { return is_array( $res ) ? (string) $res['body'] : ''; }

/* ------------------------------------------------- REST, meta, post types */

/**
 * Post and term meta.
 *
 * A meta key listed in dpt_stub_meta_write_fails refuses writes and deletes,
 * which is how a test reaches the branch where WordPress says no.
 */
$GLOBALS['dpt_stub_post_meta']        = array();
$GLOBALS['dpt_stub_term_meta']        = array();
$GLOBALS['dpt_stub_meta_write_fails'] = array();

function dpt_stub_meta_get( &$store, $id, $key, $single ) {
	$id = (int) $id;
	if ( ! isset( $store[ $id ] ) || ! array_key_exists( $key, $store[ $id ] ) ) {
		return $single ? '' : array();
	}
	return $single ? $store[ $id ][ $key ] : array( $store[ $id ][ $key ] );
}

function dpt_stub_meta_update( &$store, $id, $key, $value ) {
	if ( in_array( $key, $GLOBALS['dpt_stub_meta_write_fails'], true ) ) {
		return false;
	}
	$id = (int) $id;
	// update_metadata() opens by unslashing what it was handed, so a caller
	// that did not slash its value has the backslashes in it stripped out on
	// the way to the database. Modelling that is what makes an assertion about
	// a Windows path or a regular expression mean anything: a stub that stored
	// what it was given would pass whether or not the caller slashed.
	$value = wp_unslash( $value );
	// Real meta storage is a text column: a scalar round-trips through a
	// string on the way in and back out, which is why get_*_meta() never
	// hands a number field its int back. Modelling that here is what makes
	// "the value already stored" comparison below - and the one code under
	// test has to make on a false return - a genuine round trip rather than
	// a same-PHP-type comparison that would never catch a real mismatch.
	$to_store = is_scalar( $value ) ? (string) $value : $value;
	if ( isset( $store[ $id ] ) && array_key_exists( $key, $store[ $id ] ) && $store[ $id ][ $key ] === $to_store ) {
		// update_metadata() answers false here too: the write asked for
		// nothing new, so nothing happened - not the same thing as a site
		// refusing the write above, but indistinguishable from the return
		// value alone.
		return false;
	}
	if ( ! isset( $store[ $id ] ) ) {
		$store[ $id ] = array();
	}
	$store[ $id ][ $key ] = $to_store;
	return true;
}

function dpt_stub_meta_delete( &$store, $id, $key ) {
	if ( in_array( $key, $GLOBALS['dpt_stub_meta_write_fails'], true ) ) {
		return false;
	}
	$id = (int) $id;
	if ( ! isset( $store[ $id ] ) || ! array_key_exists( $key, $store[ $id ] ) ) {
		return false;
	}
	unset( $store[ $id ][ $key ] );
	return true;
}

// Post meta, backed by the store above. Only the two writers send a revision
// id on to its parent, exactly as core's own post-meta functions do - see
// wp_is_post_revision() below for why the reader deliberately does not.
function get_post_meta( $id, $key = '', $single = false ) {
	return dpt_stub_meta_get( $GLOBALS['dpt_stub_post_meta'], $id, $key, $single );
}
function update_post_meta( $id, $key, $value ) {
	return dpt_stub_meta_update( $GLOBALS['dpt_stub_post_meta'], dpt_stub_meta_target( $id ), $key, $value );
}
function delete_post_meta( $id, $key ) {
	return dpt_stub_meta_delete( $GLOBALS['dpt_stub_post_meta'], dpt_stub_meta_target( $id ), $key );
}
/**
 * Which post a write to post meta really lands on. Core opens both
 * update_post_meta() and delete_post_meta() with "make sure meta is added to
 * the post, not a revision" and swaps the id for the parent's.
 */
function dpt_stub_meta_target( $id ) {
	$parent = wp_is_post_revision( $id );
	return $parent ? $parent : $id;
}
// Term meta, backed by its own store, the way core keeps posts and terms apart.
function get_term_meta( $id, $key = '', $single = false ) {
	return dpt_stub_meta_get( $GLOBALS['dpt_stub_term_meta'], $id, $key, $single );
}
function update_term_meta( $id, $key, $value ) {
	return dpt_stub_meta_update( $GLOBALS['dpt_stub_term_meta'], $id, $key, $value );
}
function delete_term_meta( $id, $key ) {
	return dpt_stub_meta_delete( $GLOBALS['dpt_stub_term_meta'], $id, $key );
}

/** REST registration, recorded rather than performed. */
$GLOBALS['dpt_stub_rest_fields']          = array();
$GLOBALS['dpt_stub_rest_routes']          = array();
$GLOBALS['dpt_stub_registered_post_meta'] = array();

// A field registered against several object types at once lands under each of
// them, the way register_rest_field() itself accepts an array of types.
function register_rest_field( $object_type, $attribute, $args = array() ) {
	foreach ( (array) $object_type as $type ) {
		$GLOBALS['dpt_stub_rest_fields'][ $type ][ $attribute ] = $args;
	}
}
// Routes are keyed by namespace + route so a test can assert a specific
// endpoint was registered without caring about registration order.
function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['dpt_stub_rest_routes'][ $namespace . $route ][] = $args;
	return true;
}
function register_post_meta( $post_type, $key, $args = array() ) {
	$GLOBALS['dpt_stub_registered_post_meta'][ $post_type ][ $key ] = $args;
	return true;
}

/** Which post types and taxonomies are visible to the REST API. */
$GLOBALS['dpt_stub_rest_post_types'] = array( 'post', 'page' );
$GLOBALS['dpt_stub_rest_taxonomies'] = array( 'category', 'post_tag', 'authors' );

// Which taxonomies are attached to which post type, and what each of them is
// called on the REST API. This is not decoration: WP_REST_Posts_Controller
// turns every REST-enabled taxonomy attached to a post type into a property
// of the post response, named by that taxonomy's rest base - which is how a
// site's own field can collide with a property no written list could predict.
$GLOBALS['dpt_stub_object_taxonomies']  = array( 'post' => array( 'category', 'post_tag' ) );
$GLOBALS['dpt_stub_taxonomy_rest_base'] = array( 'category' => 'categories', 'post_tag' => 'tags' );

// Shared shape for the post-type and taxonomy objects below: both real
// objects expose show_in_rest and rest_base, and those are the only fields a
// test needs.
function dpt_stub_rest_object( $name ) {
	return (object) array(
		'show_in_rest' => true,
		'name'         => $name,
		'rest_base'    => isset( $GLOBALS['dpt_stub_taxonomy_rest_base'][ $name ] ) ? $GLOBALS['dpt_stub_taxonomy_rest_base'][ $name ] : '',
	);
}
function get_post_type_object( $name ) {
	return in_array( $name, $GLOBALS['dpt_stub_rest_post_types'], true )
		? dpt_stub_rest_object( $name )
		: null;
}
function taxonomy_exists( $name ) {
	return in_array( $name, $GLOBALS['dpt_stub_rest_taxonomies'], true );
}
function get_taxonomy( $name ) {
	return taxonomy_exists( $name ) ? dpt_stub_rest_object( $name ) : false;
}
function get_object_taxonomies( $object, $output = 'names' ) {
	$names = isset( $GLOBALS['dpt_stub_object_taxonomies'][ $object ] )
		? array_values( array_filter( $GLOBALS['dpt_stub_object_taxonomies'][ $object ], 'taxonomy_exists' ) )
		: array();
	if ( 'objects' !== $output ) {
		return $names;
	}
	$objects = array();
	foreach ( $names as $name ) {
		$objects[ $name ] = get_taxonomy( $name );
	}
	return $objects;
}

/**
 * The properties core's own controllers put on an item, and what a response
 * really looks like once register_rest_field() has run over them.
 *
 * The distinction this models is the whole of the collision: register_rest_
 * field() does not add a property beside an existing one, it *replaces* the
 * schema and the value core had under that name. A stub that only recorded
 * additional fields in a bucket of their own could never show that, so an
 * assertion that a core property survived would pass whatever the module did.
 *
 * Only a handful of each controller's properties are listed - enough to model
 * the failure. The module carries the complete list; it is not read from here.
 */
// Every entry carries dpt_stub_core, which no schema this plugin produces
// has. Comparing types alone would be vacuous wherever a core property and a
// discovered field are both, say, a string: the marker is how an assertion
// says "this is still core's property" rather than "this happens to look
// like it".
$GLOBALS['dpt_stub_core_rest_properties'] = array(
	'post' => array(
		'id'      => array( 'type' => 'integer', 'dpt_stub_core' => true ),
		'slug'    => array( 'type' => 'string', 'dpt_stub_core' => true ),
		'status'  => array( 'type' => 'string', 'dpt_stub_core' => true ),
		'title'   => array( 'type' => 'object', 'dpt_stub_core' => true ),
		'content' => array( 'type' => 'object', 'dpt_stub_core' => true ),
		'excerpt' => array( 'type' => 'object', 'dpt_stub_core' => true ),
		'meta'    => array( 'type' => 'object', 'dpt_stub_core' => true ),
	),
	'term' => array(
		'id'          => array( 'type' => 'integer', 'dpt_stub_core' => true ),
		'name'        => array( 'type' => 'string', 'dpt_stub_core' => true ),
		'description' => array( 'type' => 'string', 'dpt_stub_core' => true ),
		'slug'        => array( 'type' => 'string', 'dpt_stub_core' => true ),
		'meta'        => array( 'type' => 'object', 'dpt_stub_core' => true ),
	),
);
function dpt_stub_rest_item_schema( $object_type ) {
	$kind       = get_post_type_object( $object_type ) ? 'post' : 'term';
	$properties = $GLOBALS['dpt_stub_core_rest_properties'][ $kind ];
	$extra      = isset( $GLOBALS['dpt_stub_rest_fields'][ $object_type ] )
		? $GLOBALS['dpt_stub_rest_fields'][ $object_type ]
		: array();
	foreach ( $extra as $name => $args ) {
		$properties[ $name ] = isset( $args['schema'] ) ? $args['schema'] : array();
	}
	return $properties;
}

// Posts exist when the test says they do. An entry is either the post type on
// its own - all a REST-field callback needs to decide what to do with a post -
// or array( 'post_type' => ..., 'post_parent' => ... ) for a post that hangs
// off another one. A revision is the only reason this stub needs a parent, and
// modelling it is what lets a test reach the redirect below.
$GLOBALS['dpt_stub_posts'] = array();
function dpt_stub_post_row( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['dpt_stub_posts'][ $id ] ) ) {
		return null;
	}
	$row = $GLOBALS['dpt_stub_posts'][ $id ];
	if ( ! is_array( $row ) ) {
		$row = array( 'post_type' => $row );
	}
	return array(
		'ID'          => $id,
		'post_type'   => isset( $row['post_type'] ) ? $row['post_type'] : 'post',
		'post_parent' => isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0,
	);
}
function get_post( $id = 0 ) {
	$row = dpt_stub_post_row( $id );
	return null === $row ? null : (object) $row;
}
/**
 * Core's answer to "is this id a revision": the id of the post it revises, or
 * false. update_post_meta() and delete_post_meta() ask it before they touch
 * anything and quietly work on the parent when it says yes, while
 * get_post_meta() does not and reads the revision's own row.
 *
 * That asymmetry is not a detail worth skipping in a stub: it is the whole
 * mechanism by which a request naming a revision could read one post and write
 * another. A harness that treated a revision as an ordinary post would report
 * a clean run over exactly that bug.
 */
function wp_is_post_revision( $id ) {
	$row = dpt_stub_post_row( $id );
	if ( null === $row || 'revision' !== $row['post_type'] || ! $row['post_parent'] ) {
		return false;
	}
	return $row['post_parent'];
}

// A counter rather than a boolean, so a test can tell "cleared once" from
// "cleared on every save", which is the kind of bug a cache-clearing hook invites.
$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;

/**
 * Elementor, in the one shape the module reaches for: Plugin::instance()
 * carrying a files_manager that can be told to forget its generated files.
 * Without it class_exists( '\Elementor\Plugin' ) is false, the module's
 * clear_cache() returns before it does anything, and the counter above can
 * never be anything but zero - which makes "the cache was not cleared" true
 * of every run and worth nothing as an assertion.
 *
 * class_alias() is what gives the stub its namespaced name: a namespace
 * declaration would force every line of these flat test files into a
 * namespace block of its own.
 */
class DPT_Stub_Elementor_Files_Manager {
	public function clear_cache() {
		$GLOBALS['dpt_stub_elementor_cache_cleared']++;
	}
}
class DPT_Stub_Elementor_Plugin {
	public $files_manager;
	public function __construct() {
		$this->files_manager = new DPT_Stub_Elementor_Files_Manager();
	}
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}
}
class_alias( 'DPT_Stub_Elementor_Plugin', 'Elementor\Plugin' );

// Sanitisers a REST callback runs input through before writing it, kept
// close enough to the real ones that a test can feed them markup and tags.
function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function wp_kses_post( $s ) { return is_scalar( $s ) ? (string) $s : ''; }
function absint( $v ) { return abs( (int) $v ); }
// wp_json_encode() already exists above, for the same purpose; not redeclared here.
/**
 * The other half of the pair above, and core's own asymmetry with it: strings
 * and arrays are escaped, objects are not walked. A value written through
 * wp_slash() must come back out of wp_unslash() as itself, which is the
 * property every meta write in this plugin leans on.
 */
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = wp_slash( $v );
		}
		return $value;
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}
// A REST callback's return value passes straight through: there is no
// transport for it to be prepared for here.
function rest_ensure_response( $v ) { return $v; }
