<?php
/**
 * Cookie Banner: a denied category's cookies are deleted from the browser
 * (issue #64). Off by default - no patterns, nothing deleted.
 *
 * The deleting lives in the head precheck, so it runs before any tag in
 * <head>. That script is run for real in Node, with a cookie jar that keeps
 * a cookie's domain and path the way a browser does, so a delete that
 * misses where GA wrote fails here as it would on the site.
 */
require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}

require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-settings.php';
require_once dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php';

/* ---- patterns ---- */

$d = DPT_CB_Settings::defaults();
dpt_test_eq( array( $d['cleanup_functional'], $d['cleanup_analytics'], $d['cleanup_marketing'] ), array( '', '', '' ), 'off by default: no patterns in any category' );
dpt_test_eq( DPT_CB_Frontend::cleanup_patterns( DPT_CB_Settings::all() ), array(), 'so the page is told nothing to delete' );

dpt_test_eq(
	DPT_CB_Settings::cookie_patterns( "_ga, _ga_*\n_gid  ph_*_posthog,_ga" ),
	array( '_ga', '_ga_*', '_gid', 'ph_*_posthog' ),
	'one per line or comma separated, with spaces, duplicates dropped'
);
dpt_test_eq( DPT_CB_Settings::cookie_patterns( '*, **, _ga' ), array( '_ga' ), 'a pattern made only of * would delete every cookie on the site: dropped' );
dpt_test_eq( DPT_CB_Settings::cookie_patterns( '_ga;alert(1) <b> _gid' ), array( '_gid' ), 'anything but cookie-name characters drops the whole token' );

$GLOBALS['dpt_stub_options'] = array();
DPT_CB_Settings::save( array( 'cleanup_analytics' => "_ga,\n_ga_*  , *", 'cleanup_marketing' => '<script>' ) );
$saved = get_option( DPT_CB_Settings::OPTION );
dpt_test_eq( array( $saved['cleanup_analytics'], $saved['cleanup_marketing'] ), array( "_ga\n_ga_*", '' ), 'saved clean, one per line' );
dpt_test_eq( DPT_CB_Frontend::cleanup_patterns( DPT_CB_Settings::all() ), array( 'analytics' => array( '_ga', '_ga_*' ) ), 'and only categories with patterns reach the page' );

/* ---- the head precheck in Node ---- */

$GLOBALS['dpt_stub_is_admin'] = false; // A page on the site.
$GLOBALS['dpt_stub_no_user']  = true;

/**
 * Run frontend.js on www.digitizer.co.il with these cookies, storage and
 * stored choice. Returns what is left, or null without Node.
 *
 * @param array $jar     [ [ name, domain ], ... ] - domain '' is host-only.
 * @param array $storage localStorage keys.
 * @param array|null $choice The stored choice, or null for none.
 * @param array $cleanup Patterns per category.
 */
function dpt_test_cc_run( $jar, $storage, $choice, $cleanup ) {
	static $node = null;
	if ( null === $node ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
	}
	if ( '' === $node ) {
		return null;
	}
	$saved = array();
	foreach ( $cleanup as $cat => $patterns ) {
		$saved[ 'cleanup_' . $cat ] = implode( "\n", $patterns );
	}
	$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ] = $saved;
	ob_start();
	( new DPT_CB_Frontend() )->inject_inline_consent_check();
	$html = (string) ob_get_clean();
	if ( ! preg_match( '#<script id="dpt-cb-precheck"[^>]*>(.*?)</script>#s', $html, $m ) ) {
		return array( 'no precheck' => $html );
	}
	if ( null !== $choice ) {
		$jar[] = array( 'dpt_consent', '.digitizer.co.il', json_encode( array_merge( array( 'v' => '1', 'ts' => (int) ( microtime( true ) * 1000 ) ), $choice ) ) );
	}
	$harness = <<<'JS'
var jar = __JAR__.map(function (c) { return [c[0], c[1], c[2] || 'v', c[3] || '/']; }); // [name, domain, value, path]
var store = {}; __STORAGE__.forEach(function (k) { store[k] = 'x'; });
function mkStorage(o) { return { get length() { return Object.keys(o).length; }, key: function (i) { return Object.keys(o)[i]; }, getItem: function (k) { return k in o ? o[k] : null; }, setItem: function (k, v) { o[k] = String(v); }, removeItem: function (k) { delete o[k]; } }; }
var localStorage = mkStorage(store), sessionStorage = mkStorage({});
var location = { hostname: 'www.digitizer.co.il', search: '', protocol: 'https:', pathname: '/shop/checkout/' };
var document = {
  readyState: 'loading',
  addEventListener: function () {},
  documentElement: { setAttribute: function () {} },
  get cookie() { return jar.map(function (c) { return c[0] + '=' + encodeURIComponent(c[2] || 'v'); }).join('; '); },
  set cookie(s) {
    var parts = s.split(';').map(function (p) { return p.trim(); });
    var name = parts[0].split('=')[0], value = decodeURIComponent(parts[0].slice(name.length + 1));
    var domain = '', path = '/', expired = false, secure = false;
    parts.slice(1).forEach(function (p) { var kv = p.split('='); var k = kv[0].toLowerCase();
      if (k === 'domain') domain = kv[1].charAt(0) === '.' ? kv[1] : '.' + kv[1];
      if (k === 'path') path = kv[1];
      if (k === 'secure') secure = true;
      if (k === 'max-age' && kv[1] === '0') expired = true;
      if (k === 'expires' && new Date(kv[1]) < new Date()) expired = true; });
    // A browser refuses a __Secure-/__Host- write without Secure, and
    // removes only the cookie with this exact name, domain and path.
    if (/^__(Secure|Host)-/.test(name) && !secure) return;
    jar = jar.filter(function (c) { return !(c[0] === name && c[1] === domain && c[3] === path); });
    if (!expired) jar.push([name, domain, value, path]);
  }
};
var window = { localStorage: localStorage, sessionStorage: sessionStorage, addEventListener: function () {} };
__SCRIPT__
process.stdout.write(JSON.stringify({ cookies: jar.map(function (c) { return c[0] + '@' + (c[1] || 'host') + (c[3] !== '/' ? c[3] : ''); }).sort(), storage: Object.keys(store).sort() }));
JS;
	$harness = str_replace(
		array( '__JAR__', '__STORAGE__', '__SCRIPT__' ),
		array( json_encode( $jar ), json_encode( $storage ), $m[1] ),
		$harness
	);
	$file = tempnam( sys_get_temp_dir(), 'dptcc' ) . '.js';
	file_put_contents( $file, $harness );
	$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	$decoded = json_decode( (string) $out, true );
	return is_array( $decoded ) ? $decoded : array( 'node error' => $out );
}

$jar = array(
	array( '_ga', '.digitizer.co.il' ),           // GA writes to the registrable domain.
	array( '_ga_ABC123', '.digitizer.co.il' ),
	array( '_gid', '' ),                          // Host-only.
	array( '_gcl_au', '.www.digitizer.co.il' ),
	array( 'wp-settings-1', '' ),                 // WordPress's own.
	array( 'theme_pref', '' ),                    // Matches no pattern.
);
$storage = array( 'ph_phc_123_posthog', 'other_key' );
$cleanup = array(
	'analytics' => array( '_ga', '_ga_*', '_gid', 'ph_*_posthog' ),
	'marketing' => array( '_gcl_*', 'wp-*' ),
);

$r = dpt_test_cc_run( $jar, $storage, array( 'functional' => true, 'analytics' => false, 'marketing' => true ), $cleanup );
if ( null === $r ) {
	echo "SKIP: node not found, the precheck was not executed\n";
} else {
	dpt_test_eq(
		$r['cookies'],
		array( '_gcl_au@.www.digitizer.co.il', 'dpt_consent@.digitizer.co.il', 'theme_pref@host', 'wp-settings-1@host' ),
		'analytics denied: _ga, _ga_* and _gid are gone - from the registrable domain and host-only alike - while marketing, granted, keeps its cookie'
	);
	dpt_test_eq( $r['storage'], array( 'other_key' ), 'and PostHog\'s localStorage key goes with them' );

	$r = dpt_test_cc_run( $jar, $storage, array( 'functional' => true, 'analytics' => true, 'marketing' => false ), $cleanup );
	dpt_test_ok( ! in_array( '_gcl_au@.www.digitizer.co.il', $r['cookies'], true ), 'marketing denied: a cookie on the www host\'s own domain is reached too' );
	dpt_test_ok( in_array( 'wp-settings-1@host', $r['cookies'], true ), 'but a WordPress cookie is never deleted, even when a pattern matches it' );
	dpt_test_ok( in_array( 'dpt_consent@.digitizer.co.il', $r['cookies'], true ), 'nor the banner\'s own choice' );

	$extra = array_merge( $jar, array( array( '_ga_cart', '', 'v', '/shop' ), array( '__Secure-_ga_x', '.digitizer.co.il' ) ) );
	$cleanup_secure = $cleanup;
	$cleanup_secure['analytics'][] = '__Secure-_ga_*';
	$r = dpt_test_cc_run( $extra, $storage, array( 'functional' => true, 'analytics' => false, 'marketing' => true ), $cleanup_secure );
	dpt_test_ok( ! in_array( '_ga_cart@host/shop', $r['cookies'], true ), 'a cookie set for /shop is reached from /shop/checkout/ - a cookie\'s path is part of its identity' );
	dpt_test_ok( ! in_array( '__Secure-_ga_x@.digitizer.co.il', $r['cookies'], true ), 'and a __Secure- cookie, which a browser lets go only with Secure' );

	$r = dpt_test_cc_run( array( array( '_ga', '.co.il' ), array( '_ga', '.digitizer.co.il' ) ), array(), array( 'analytics' => false ), $cleanup );
	dpt_test_eq( $r['cookies'], array( 'dpt_consent@.digitizer.co.il' ), 'every parent domain is tried, with no guess where the registrable one is - a cookie scoped higher than the guess is reached too' );

	$r = dpt_test_cc_run( $jar, $storage, null, $cleanup );
	dpt_test_eq( count( $r['cookies'] ), count( $jar ), 'a first visit, with no choice yet, deletes nothing' );

	$r = dpt_test_cc_run( $jar, $storage, array( 'functional' => false, 'analytics' => false, 'marketing' => false ), array() );
	dpt_test_eq( count( $r['cookies'] ), count( $jar ) + 1, 'with no patterns, refusing everything deletes nothing' );
}

$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/assets/js/frontend.js' );
$finish = substr( $js, (int) strpos( $js, 'function finishConsent' ) );
dpt_test_ok( false !== strpos( $finish, 'purgeDenied(consent);' ) && false !== strpos( $js, 'window.dptCbPurge(consent);' ), 'a choice on the page deletes what it denies at once, with the same code the head runs' );

$GLOBALS['dpt_stub_options'][ DPT_CB_Settings::OPTION ] = array();
ob_start();
( new DPT_CB_Frontend() )->inject_inline_consent_check();
dpt_test_ok( false === strpos( (string) ob_get_clean(), 'dptCbPurge = function' ), 'with no patterns the head carries no deleting code at all' );
dpt_test_ok( 1 === preg_match( '/PROTECTED = \/\^\(dpt_consent\|wordpress_\|wp-\|wp_\|woocommerce_\|wc_\)\//', (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php' ) ), 'WooCommerce\'s wc_ keys (cart hash, fragments) are protected too' );
dpt_test_ok( 1 === preg_match( "/try \\{\\s*(?:\\/\\/[^\\n]*\\s*)*var store = window\\[which\\];/", (string) file_get_contents( dirname( __DIR__ ) . '/modules/cookie-banner/class-dpt-cb-frontend.php' ) ), 'where storage is blocked, even reading window.localStorage throws - so it is read inside the try, and the page scripts after it still run' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
