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
function add_action() {}
function add_filter() {}
function remove_filter() {}
function did_action() { return 0; }
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['dpt_stub_options'] ) ? $GLOBALS['dpt_stub_options'][ $key ] : $default;
}
function update_option( $key, $value ) {
	$GLOBALS['dpt_stub_options'][ $key ] = $value;
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
function current_user_can( $cap ) { return true; }
function switch_theme( $slug ) { $GLOBALS['dpt_stub_stylesheet'] = $slug; }
function activate_plugin( $file ) { $GLOBALS['dpt_stub_active_plugins'][] = $file; return null; }

class DPT_Stub_Theme {
	private $exists;
	public function __construct( $exists ) { $this->exists = $exists; }
	public function exists() { return $this->exists; }
}
function wp_get_theme( $slug = '' ) {
	return new DPT_Stub_Theme( in_array( $slug, $GLOBALS['dpt_stub_themes'], true ) );
}

/**
 * Canned HTTP. Keys are URLs; values are array( 'code' => int, 'body' => string ).
 * An unknown URL is a hard failure, so a test can never accidentally depend on
 * a real network call.
 */
function wp_remote_get( $url, $args = array() ) {
	if ( ! isset( $GLOBALS['dpt_stub_http'][ $url ] ) ) {
		return new WP_Error( 'stub_miss', 'No canned response for ' . $url );
	}
	return $GLOBALS['dpt_stub_http'][ $url ];
}
function wp_remote_retrieve_response_code( $res ) { return is_array( $res ) ? (int) $res['code'] : 0; }
function wp_remote_retrieve_body( $res ) { return is_array( $res ) ? (string) $res['body'] : ''; }
