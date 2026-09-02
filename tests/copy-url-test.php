<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';

/* The slice of WordPress this module touches and the harness does not stub. */

// The canonical home, fixed: the whole point of the shortcode is that the
// address comes from here and never from $_SERVER's host or port, which
// behind a reverse proxy name a backend no visitor can reach.
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
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

require_once dirname( __DIR__ ) . '/modules/copy-url/class-dpt-cu-module.php';

/* ---- the module registers exactly its two shortcodes ---- */

$module = new DPT_Copy_URL_Module();
$module->init();
dpt_test_ok( isset( $GLOBALS['dpt_stub_shortcodes']['digitizer_geturl'] ), 'the legacy shortcode keeps its tag - existing pages resolve it' );
dpt_test_ok( isset( $GLOBALS['dpt_stub_shortcodes']['digitizer_copy_url'] ), 'and the widget gets its own' );

/* ---- the address: canonical home, decoded, defused ---- */

$_SERVER['REQUEST_URI'] = '/%D7%9E%D7%93%D7%A8%D7%99%D7%9A/?step=2';
dpt_test_eq(
	DPT_CU_Shortcodes::current_url(),
	'https://example.test/מדריך/?step=2',
	'a Hebrew slug reads as itself, on the canonical home - never the proxy backend'
);

// REQUEST_URI belongs to the visitor, and urldecode() brings back exactly
// the characters an encoded URL could not carry into markup. Whatever
// interpolates this shortcode - Elementor's dynamic tag into a value
// attribute, say - must not receive a way out of the attribute.
$_SERVER['REQUEST_URI'] = '/p/%22%3E%3Cscript%3Ealert(1)%3C/script%3E?q=%27';
$url = DPT_CU_Shortcodes::current_url();
foreach ( array( '<', '>', '"', "'" ) as $ch ) {
	dpt_test_ok( false === strpos( $url, $ch ), 'a decoded ' . ( '"' === $ch ? 'quote' : $ch ) . ' never survives into the output' );
}

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

/* ---- assets ride only with the widget ---- */

dpt_test_ok( isset( $GLOBALS['dpt_stub_enqueued_styles_by_handle']['dpt-copy-url'] ), 'rendering the widget enqueues its stylesheet' );
dpt_test_ok( ! empty( $GLOBALS['dpt_stub_enqueued_scripts_by_handle']['dpt-copy-url']['footer'] ), 'and its script, in the footer' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
