<?php
/**
 * UTM Passthrough - a visitor who arrives with utm_* parameters keeps them on
 * the site's own links. The decisions all live in the browser script, so it
 * runs here under node, against a stand-in page.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';

function wp_login_url() { return 'https://example.test/site/wp-login.php'; }
$GLOBALS['dpt_stub_scripts_by_handle'] = array();
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = null, $footer = false ) {
	$GLOBALS['dpt_stub_scripts_by_handle'][ $handle ] = array( 'src' => $src, 'footer' => $footer );
}
$GLOBALS['dpt_stub_config'] = array();
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['dpt_stub_config'][ $handle ] = array( $name, $data );
}

require_once dirname( __DIR__ ) . '/modules/utm-passthrough/class-dpt-utm-module.php';

/* ---- the module ---- */

$GLOBALS['dpt_stub_filters'] = array();
$module = new DPT_UTM_Passthrough_Module();
dpt_test_eq( $module->id(), 'utm_passthrough', 'the module id' );
$module->init();
dpt_test_ok( dpt_stub_has_filter( 'wp_enqueue_scripts' ), 'the script is enqueued on the front end hook' );

$module->enqueue_assets();
$script = $GLOBALS['dpt_stub_scripts_by_handle']['dpt-utm-passthrough'] ?? null;
dpt_test_ok( $script && $script['footer'], 'the script loads in the footer' );
dpt_test_ok( $script && false !== strpos( $script['src'], 'modules/utm-passthrough/assets/js/utm-passthrough.js' ), 'from the module\'s own assets' );
list( $name, $cfg ) = $GLOBALS['dpt_stub_config']['dpt-utm-passthrough'];
dpt_test_eq( $name, 'DPT_UTM_CONFIG', 'its config global' );
dpt_test_ok( in_array( '/site/wp-login.php', $cfg['exclude'], true ), 'the login path comes from the site, subdirectory included' );
dpt_test_ok( in_array( '/wp-admin/', $cfg['exclude'], true ), 'and the admin path' );

add_filter( 'dpt_utm_excluded_paths', function ( $paths ) { $paths[] = '/members/'; return $paths; } );
$module->enqueue_assets();
dpt_test_ok( in_array( '/members/', $GLOBALS['dpt_stub_config']['dpt-utm-passthrough'][1]['exclude'], true ), 'a site can add its own exclusions' );
$GLOBALS['dpt_stub_filters'] = array();

$registry = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-dpt-plugin.php' );
dpt_test_ok( 1 === preg_match( "/'utm_passthrough'\s*=>\s*array\(\s*'file'\s*=>\s*DPT_PATH \. 'modules\/utm-passthrough\/class-dpt-utm-module\.php',\s*'class'\s*=>\s*'DPT_UTM_Passthrough_Module',\s*'default'\s*=>\s*'0'/", $registry ), 'registered, off by default' );

/* ---- the script ---- */

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $node ) {
	dpt_test_ok( false, 'node available' );
	exit( dpt_test_summary() > 0 ? 1 : 0 );
}
$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/utm-passthrough/assets/js/utm-passthrough.js' );

/**
 * Run the script on a page holding these links.
 *
 * @param string $page   The address the visitor is on.
 * @param array  $links  href attribute => extra attributes.
 * @param array  $late   Links added after the script ran.
 * @return array href attribute => the href after the script.
 */
$run = function ( $page, $links, $late = array() ) use ( $node, $js ) {
	$harness = <<<'JS'
var PAGE = __PAGE__;
function A(href, attrs) { this.attr = href; this.attrs = attrs || {}; this.nodeType = 1; }
Object.defineProperty(A.prototype, 'href', {
  get: function () { return new URL(this.attr, PAGE).href; },
  set: function (v) { this.attr = v; }
});
A.prototype.getAttribute = function (n) { return n === 'href' ? this.attr : (n in this.attrs ? this.attrs[n] : null); };
A.prototype.hasAttribute = function (n) { return this.getAttribute(n) !== null; };
A.prototype.matches = function (sel) { return sel === 'a[href]'; };
A.prototype.querySelectorAll = function () { return []; };
function wrap(list) { return { nodeType: 1, matches: function () { return false; }, querySelectorAll: function () { return list; } }; }
var links = [];
var observers = [];
var u = new URL(PAGE);
var window = { DPT_UTM_CONFIG: { exclude: ['/wp-admin/', '/site/wp-login.php'] }, location: u };
var location = u;
var document = {
  readyState: 'complete',
  documentElement: {},
  querySelectorAll: function (sel) { return sel === 'a[href]' ? links : []; },
  addEventListener: function () {}
};
function MutationObserver(cb) { this.cb = cb; observers.push(this); }
MutationObserver.prototype.observe = function () {};
var order = [];
__LINKS__.forEach(function (l) { var a = new A(l[0], l[1]); links.push(a); order.push(a); });
__SCRIPT__
var late = [];
__LATE__.forEach(function (l) { var a = new A(l[0], l[1]); late.push(a); order.push(a); });
if (late.length) {
  if (!observers.length) { late.forEach(function (a) { a.unwatched = true; }); }
  observers.forEach(function (o) { o.cb([{ addedNodes: [late[0], wrap(late.slice(1)), { nodeType: 3 }] }]); });
}
process.stdout.write(JSON.stringify({ hrefs: order.map(function (a) { return a.attr; }), observers: observers.length }));
JS;
	$harness = str_replace(
		array( '__PAGE__', '__LINKS__', '__LATE__', '__SCRIPT__' ),
		array( json_encode( $page ), json_encode( $links ), json_encode( $late ), $js ),
		$harness
	);
	$file = tempnam( sys_get_temp_dir(), 'dpt' ) . '.js';
	file_put_contents( $file, $harness );
	$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	$decoded = json_decode( (string) $out, true );
	return is_array( $decoded ) ? $decoded : array( 'hrefs' => array( 'node error: ' . $out ), 'observers' => 0 );
};
$one = function ( $page, $href, $attrs = null ) use ( $run ) {
	$r = $run( $page, array( array( $href, $attrs ) ) );
	return $r['hrefs'][0];
};

$page = 'https://example.test/landing?utm_source=fb&utm_campaign=sale&ref=abc';

dpt_test_eq( $one( $page, 'https://example.test/about/' ), 'https://example.test/about/?utm_source=fb&utm_campaign=sale', 'an internal link gets the utm_* parameters, and only them' );
dpt_test_eq( $one( $page, '/about/' ), 'https://example.test/about/?utm_source=fb&utm_campaign=sale', 'a relative link is internal too' );
dpt_test_eq( $one( $page, 'https://example.test/shop/?color=red%20dark' ), 'https://example.test/shop/?color=red%20dark&utm_source=fb&utm_campaign=sale', 'an existing query is kept exactly as written' );
dpt_test_eq( $one( $page, 'https://example.test/about/#team' ), 'https://example.test/about/?utm_source=fb&utm_campaign=sale#team', 'the parameters go before a fragment, where the server can read them' );
dpt_test_eq( $one( 'https://www.example.test/?utm_source=fb', 'https://example.test/x' ), 'https://example.test/x?utm_source=fb', 'www and the bare domain are one site' );
dpt_test_eq( $one( 'https://example.test/?utm_source=fb', 'https://www.example.test/x' ), 'https://www.example.test/x?utm_source=fb', 'in both directions' );
dpt_test_eq( $one( 'https://example.test/?utm_term=a%20b%26c%20%D7%A9%D7%9C%D7%95%D7%9D', '/x' ), 'https://example.test/x?utm_term=a+b%26c+%D7%A9%D7%9C%D7%95%D7%9D', 'values are encoded: spaces, ampersands, Hebrew' );

foreach ( array(
	'an external link'                        => 'https://other.test/about/',
	'a host that only contains the site name' => 'https://other.test/?x=example.test',
	'a subdomain of the site'                 => 'https://shop.example.test/',
	'a look-alike host'                       => 'https://example.test.evil.test/',
	'a same-page anchor'                      => '#top',
	'an Elementor action link'                => '#elementor-action%3Aaction%3Dpopup',
	'mailto'                                  => 'mailto:a@example.test',
	'tel'                                     => 'tel:+97250000000',
	'a script link'                           => 'javascript:void(0)',
	'a link already carrying its own utm'     => 'https://example.test/x?utm_source=newsletter',
	'the admin'                               => 'https://example.test/wp-admin/post.php',
	'the login page'                          => 'https://example.test/site/wp-login.php?action=logout',
	'a PDF'                                   => 'https://example.test/wp-content/uploads/a.PDF',
	'a zip'                                   => '/files/a.zip',
	'an image'                                => '/wp-content/uploads/a.webp?v=2',
	'an add-to-cart link'                     => 'https://example.test/shop/?add-to-cart=12',
) as $label => $href ) {
	dpt_test_eq( $one( $page, $href ), $href, "left alone: $label" );
}
dpt_test_eq( $one( $page, '/report', array( 'download' => '' ) ), '/report', 'left alone: a link with the download attribute' );
dpt_test_eq( $one( $page, '/wp-admin-guide/' ), 'https://example.test/wp-admin-guide/?utm_source=fb&utm_campaign=sale', 'an excluded path matches whole segments, not a prefix of a slug' );

$none = $run( 'https://example.test/landing?ref=abc', array( array( '/about/', null ) ) );
dpt_test_eq( $none['hrefs'][0], '/about/', 'no utm_* on the address: nothing changes' );
dpt_test_eq( $none['observers'], 0, 'and nothing keeps watching the page' );

$late = $run( $page, array(), array( array( '/popup-link/', null ), array( '/in-a-loop-grid/', null ), array( 'https://other.test/', null ) ) );
dpt_test_eq( $late['hrefs'], array( 'https://example.test/popup-link/?utm_source=fb&utm_campaign=sale', 'https://example.test/in-a-loop-grid/?utm_source=fb&utm_campaign=sale', 'https://other.test/' ), 'links added after load are handled, the same way' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
