<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';

/* The slice of WordPress this module touches and the harness does not stub. */

// The canonical home, settable: the whole point of the address shortcode is
// that scheme and host come from here and never from $_SERVER, which behind
// a reverse proxy names a backend no visitor can reach - and a subdirectory
// install is a home with a path, which is the case the assembly must not
// double.
$GLOBALS['dpt_stub_home'] = 'https://example.test';
function home_url( $path = '' ) {
	return $GLOBALS['dpt_stub_home'] . $path;
}
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = is_array( $atts ) ? $atts : array();
	$out  = array();
	foreach ( $defaults as $key => $value ) {
		$out[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $value;
	}
	return $out;
}
$GLOBALS['dpt_stub_shortcodes'] = array();
function add_shortcode( $tag, $callback ) {
	$GLOBALS['dpt_stub_shortcodes'][ $tag ] = $callback;
}
$GLOBALS['dpt_stub_enqueued_styles_by_handle']  = array();
$GLOBALS['dpt_stub_enqueued_scripts_by_handle'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = null ) {
	$GLOBALS['dpt_stub_enqueued_styles_by_handle'][ $handle ] = $src;
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = null, $footer = false ) {
	$GLOBALS['dpt_stub_enqueued_scripts_by_handle'][ $handle ] = array(
		'src'    => $src,
		'footer' => $footer,
	);
}
function wp_style_is( $handle, $status = 'enqueued' ) {
	return isset( $GLOBALS['dpt_stub_enqueued_styles_by_handle'][ $handle ] );
}
function wp_register_script( $handle, $src = '', $deps = array(), $ver = null, $footer = false ) {
	return true;
}

require_once dirname( __DIR__ ) . '/modules/copy-url/class-dpt-cu-module.php';

/* ---- the module registers its two shortcodes and its assets hook ---- */

$module = new DPT_Copy_URL_Module();
$module->init();
dpt_test_ok( isset( $GLOBALS['dpt_stub_shortcodes']['digitizer_geturl'] ), 'the legacy shortcode keeps its tag - existing pages resolve it' );
dpt_test_eq( $GLOBALS['dpt_stub_shortcodes']['digitizer_geturl'], array( 'DPT_CU_Shortcodes', 'current_url_shortcode' ), 'and resolves to the escaped boundary, not the raw builder' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_shortcodes']['digitizer_copy_url'] ), 'and the widget gets its own' );
dpt_test_ok( dpt_stub_has_filter( 'wp_enqueue_scripts' ), 'assets register before the head is printed' );

/* ---- the address: canonical scheme+host, decoded for reading ---- */

$_SERVER['REQUEST_URI'] = '/%D7%9E%D7%93%D7%A8%D7%99%D7%9A/?step=2';
dpt_test_eq(
	DPT_CU_Shortcodes::current_url(),
	'https://example.test/מדריך/?step=2',
	'a Hebrew slug reads as itself, on the canonical host - never the proxy backend'
);

// A subdirectory install's REQUEST_URI already carries the /site prefix, so
// home_url( $request_uri ) would print /site/site/... - the exact doubling
// DPT_CC_Enforce::current_request_url() exists to avoid. Only scheme+host
// may come from home. (Codex round-1 P1)
$GLOBALS['dpt_stub_home'] = 'https://example.test/site';
$_SERVER['REQUEST_URI']   = '/site/page/';
dpt_test_eq( DPT_CU_Shortcodes::current_url(), 'https://example.test/site/page/', 'a subdirectory install keeps its path single' );

// A home on a non-default port keeps it - that is part of the address a
// visitor would need to copy.
$GLOBALS['dpt_stub_home'] = 'https://example.test:8443';
$_SERVER['REQUEST_URI']   = '/x/';
dpt_test_eq( DPT_CU_Shortcodes::current_url(), 'https://example.test:8443/x/', 'and the home port survives' );
$GLOBALS['dpt_stub_home'] = 'https://example.test';

// Escaped ASCII is data wearing armour: decoded, %2F becomes a path
// delimiter and %3D a query one, so the copied address resolves somewhere
// the shown page is not; urldecode() would also eat a literal + as a space.
// Only non-ASCII bytes - the readable-slug case - are decoded.
// (Codex round-1 P2)
$_SERVER['REQUEST_URI'] = '/download/a%2Fb?next=%2Ffoo%3Fa%3D1&x=a+b&sp=%20';
dpt_test_eq(
	DPT_CU_Shortcodes::current_url(),
	'https://example.test/download/a%2Fb?next=%2Ffoo%3Fa%3D1&x=a+b&sp=%20',
	'escaped delimiters, literal + and %20 all round-trip untouched'
);

// A high-byte escape sheds its armour only in a run that decodes to valid
// UTF-8. Decoding a stray %FF into a raw byte would make the whole string
// invalid UTF-8 - and esc_attr() answers invalid UTF-8 with an empty
// string, so the widget would render an empty field instead of the
// address. (Codex round-2 P2)
$_SERVER['REQUEST_URI'] = '/about/?value=%FF&mix=%D7%FF';
dpt_test_eq(
	DPT_CU_Shortcodes::current_url(),
	'https://example.test/about/?value=%FF&mix=%D7%FF',
	'an escape that is not valid UTF-8 keeps its armour, alone or in a run'
);
$html = DPT_CU_Shortcodes::copy_widget();
dpt_test_ok( false !== strpos( $html, 'value="https://example.test/about/?value=%FF' ), 'and the field still carries the address, not an emptied string' );

// Escaped markup stays escaped by the same rule, and *literal* markup - a
// client that is not a browser can put it in REQUEST_URI - is encoded, so
// the shortcode is safe wherever a page builder interpolates it.
$_SERVER['REQUEST_URI'] = '/p/%22%3E%3Cscript%3E?q="><script>alert(1)</script>';
$url = DPT_CU_Shortcodes::current_url();
dpt_test_ok( false !== strpos( $url, '%22%3E%3Cscript%3E' ), 'escaped markup keeps its armour' );
foreach ( array( '<', '>', '"', "'" ) as $ch ) {
	dpt_test_ok( false === strpos( $url, $ch ), 'a literal ' . ( '"' === $ch ? 'quote' : $ch ) . ' never survives into the output' );
}

// Encoded, not deleted: an apostrophe is a legitimate URI character, and
// /authors/o'reilly/ with the apostrophe removed is a different address
// than the one the visitor asked to copy. %27 is the same one, wearing
// armour. (Codex round-3 P2)
$_SERVER['REQUEST_URI'] = "/authors/o'reilly/";
dpt_test_eq(
	DPT_CU_Shortcodes::current_url(),
	'https://example.test/authors/o%27reilly/',
	'a literal apostrophe survives, encoded - the address stays the same address'
);

// The shortcode's return value lands in HTML, where a raw ampersand starts
// a character reference - /?a=1&copy=x would display © - so the boundary
// escapes what the builder keeps raw. WordPress escaping downstream is
// idempotent (double_encode=false), so the Elementor attribute path shows
// the same bytes. (Codex round-4 P2)
$_SERVER['REQUEST_URI'] = '/?a=1&copy=x';
dpt_test_eq( DPT_CU_Shortcodes::current_url(), 'https://example.test/?a=1&copy=x', 'the builder keeps the raw address for attribute consumers' );
dpt_test_eq( DPT_CU_Shortcodes::current_url_shortcode(), 'https://example.test/?a=1&amp;copy=x', 'the bare shortcode escapes it for the HTML it lands in' );

$_SERVER['REQUEST_URI'] = '/about/';

/* ---- the widget: one shortcode where three Elementor widgets were ---- */

$html = DPT_CU_Shortcodes::copy_widget();
dpt_test_ok( false !== strpos( $html, 'value="https://example.test/about/"' ), 'the field carries the current address' );
dpt_test_ok( false !== strpos( $html, 'readonly' ), 'read-only - the field displays, the click copies' );
dpt_test_ok( false !== strpos( $html, '>Copy</button>' ), 'the button wears the copy label' );
dpt_test_ok( false !== strpos( $html, 'data-copied="Copied"' ), 'and carries the after-copy label for the script' );

$html = DPT_CU_Shortcodes::copy_widget( array( 'label_copy' => 'העתק', 'label_copied' => 'הועתק' ) );
dpt_test_ok( false !== strpos( $html, '>העתק</button>' ), 'a site can word the button per shortcode' );
dpt_test_ok( false !== strpos( $html, 'data-copied="הועתק"' ), 'both labels' );

// The address goes into a value attribute, so the widget's own escaping is
// the last line even after current_url()'s stripping.
$_SERVER['REQUEST_URI'] = '/a&b/';
$html = DPT_CU_Shortcodes::copy_widget();
dpt_test_ok( false !== strpos( $html, 'value="https://example.test/a&amp;b/"' ), 'the value attribute is escaped' );

/* ---- assets: stylesheet with the head, script with the render ---- */

// The stylesheet was enqueued by register_assets (wp_enqueue_scripts fires
// before wp_head prints), so the widget never paints unstyled; a render-time
// enqueue reaches the page only from the footer. (Codex round-1 P2)
$GLOBALS['dpt_stub_enqueued_styles_by_handle']  = array();
$GLOBALS['dpt_stub_enqueued_scripts_by_handle'] = array();
DPT_CU_Shortcodes::register_assets();
dpt_test_ok( isset( $GLOBALS['dpt_stub_enqueued_styles_by_handle']['dpt-copy-url'] ), 'the stylesheet rides with the head on every page while the module is on' );
dpt_test_ok( ! isset( $GLOBALS['dpt_stub_enqueued_scripts_by_handle']['dpt-copy-url'] ), 'the script does not - it rides only with a render' );

DPT_CU_Shortcodes::copy_widget();
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_enqueued_scripts_by_handle']['dpt-copy-url']['footer'] ), 'a render enqueues the script, in the footer' );

// And where wp_enqueue_scripts never ran (a render outside a normal
// front-end page), the render itself falls back to enqueueing the style.
$GLOBALS['dpt_stub_enqueued_styles_by_handle'] = array();
DPT_CU_Shortcodes::copy_widget();
dpt_test_ok( isset( $GLOBALS['dpt_stub_enqueued_styles_by_handle']['dpt-copy-url'] ), 'a render without the hook still styles itself, late' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
