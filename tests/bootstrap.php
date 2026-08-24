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
 */
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) {
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

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return is_array( $s ) ? array_map( 'stripslashes', $s ) : stripslashes( (string) $s ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function apply_filters( $tag, $value ) { return $value; }
// Filters are recorded, not run: a test can ask whether something was hooked
// without the harness pretending to be WordPress's hook system.
$GLOBALS['dpt_stub_filters'] = array();
function add_filter( $tag = '', $callback = null ) {
	$GLOBALS['dpt_stub_filters'][ $tag ] = true;
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
function current_user_can( $cap, $id = null ) {
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
	if ( ! isset( $store[ $id ] ) ) {
		$store[ $id ] = array();
	}
	$store[ $id ][ $key ] = $value;
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

// Post meta, backed by the store above.
function get_post_meta( $id, $key = '', $single = false ) {
	return dpt_stub_meta_get( $GLOBALS['dpt_stub_post_meta'], $id, $key, $single );
}
function update_post_meta( $id, $key, $value ) {
	return dpt_stub_meta_update( $GLOBALS['dpt_stub_post_meta'], $id, $key, $value );
}
function delete_post_meta( $id, $key ) {
	return dpt_stub_meta_delete( $GLOBALS['dpt_stub_post_meta'], $id, $key );
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

// Shared shape for the post-type and taxonomy objects below: both real
// objects expose show_in_rest, and that is the only field a test needs.
function dpt_stub_rest_object( $names ) {
	return (object) array( 'show_in_rest' => true, 'name' => $names );
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

// Posts exist when the test says they do, each one carrying only the post
// type a REST-field callback would need to decide what to do with it.
$GLOBALS['dpt_stub_posts'] = array();
function get_post( $id = 0 ) {
	$id = (int) $id;
	return isset( $GLOBALS['dpt_stub_posts'][ $id ] )
		? (object) array( 'ID' => $id, 'post_type' => $GLOBALS['dpt_stub_posts'][ $id ] )
		: null;
}

// A counter rather than a boolean, so a test can tell "cleared once" from
// "cleared on every save", which is the kind of bug a cache-clearing hook invites.
$GLOBALS['dpt_stub_elementor_cache_cleared'] = 0;

// Sanitisers a REST callback runs input through before writing it, kept
// close enough to the real ones that a test can feed them markup and tags.
function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function wp_kses_post( $s ) { return is_scalar( $s ) ? (string) $s : ''; }
function absint( $v ) { return abs( (int) $v ); }
// wp_json_encode() already exists above, for the same purpose; not redeclared here.
function wp_slash( $v ) { return $v; }
// A REST callback's return value passes straight through: there is no
// transport for it to be prepared for here.
function rest_ensure_response( $v ) { return $v; }
