<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-updates.php';

/* ---- reading a release payload ---- */

$release = array(
	'tag_name' => 'v0.6.1',
	'assets'   => array(
		array( 'browser_download_url' => 'https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip' ),
	),
);
dpt_test_eq(
	DPT_ONB_Source::release_from( $release ),
	array(
		'version' => '0.6.1',
		'package' => 'https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip',
	),
	'a tagged release with a zip asset reports a version and a package'
);

// The tag is the only place a version comes from, so a release without one is
// not an update - and neither is one nobody published on purpose.
dpt_test_eq( DPT_ONB_Source::release_from( array( 'assets' => array() ) ), null, 'a release with no tag is not an update' );
dpt_test_eq(
	DPT_ONB_Source::release_from( array( 'tag_name' => 'v1.0.0', 'draft' => true, 'assets' => array( array( 'browser_download_url' => 'https://x/a.zip' ) ) ) ),
	null,
	'nor is a draft'
);
dpt_test_eq(
	DPT_ONB_Source::release_from( array( 'tag_name' => 'v1.0.0', 'prerelease' => true, 'assets' => array( array( 'browser_download_url' => 'https://x/a.zip' ) ) ) ),
	null,
	'nor is a pre-release'
);

// A zipball exists at every commit and carries no version. Installing one over
// a tagged release would replace a known version with an unknown one, so the
// install path's fallback deliberately does not apply here.
dpt_test_eq(
	DPT_ONB_Source::release_from( array( 'tag_name' => 'v1.0.0', 'zipball_url' => 'https://api.github.com/repos/a/b/zipball', 'assets' => array() ) ),
	null,
	'a release without a built archive offers no update'
);

/* ---- deciding whether to offer it ---- */

$rel = array( 'version' => '0.6.1', 'package' => 'https://example.org/a.zip' );

dpt_test_ok( DPT_ONB_Updates::is_newer( '0.4.1', $rel ), 'a newer release is offered' );
dpt_test_ok( ! DPT_ONB_Updates::is_newer( '0.6.1', $rel ), 'the same version is not' );
dpt_test_ok( ! DPT_ONB_Updates::is_newer( '0.7.0', $rel ), 'and an older release is never offered over a newer install' );
dpt_test_ok( ! DPT_ONB_Updates::is_newer( '', $rel ), 'an unreadable installed version is not a comparison' );
dpt_test_ok( ! DPT_ONB_Updates::is_newer( '0.4.1', array( 'version' => '0.6.1' ) ), 'nor is a release with no package' );
dpt_test_ok( ! DPT_ONB_Updates::is_newer( '0.4.1', null ), 'nor a lookup that failed' );

$item  = DPT_ONB_Manifest::get( 'mcp_adapter' );
$entry = DPT_ONB_Updates::entry_for( $item, 'mcp-adapter/mcp-adapter.php', '0.4.1', $rel );
dpt_test_eq( $entry['new_version'], '0.6.1', 'the record carries the release version' );
dpt_test_eq( $entry['package'], 'https://example.org/a.zip', 'and the archive to install' );
dpt_test_eq( $entry['url'], 'https://github.com/WordPress/mcp-adapter', 'and points at the repository it came from' );
dpt_test_eq( DPT_ONB_Updates::entry_for( $item, 'mcp-adapter/mcp-adapter.php', '0.6.1', $rel ), null, 'and there is no record when nothing is newer' );

/* ---- which items it answers for ---- */

$plugins = DPT_ONB_Updates::items_of_type( 'plugin' );
$ids     = array_map( function ( $i ) { return $i['id']; }, $plugins );
sort( $ids );
dpt_test_eq( $ids, array( 'elementor_mcp', 'mcp_adapter' ), 'the GitHub plugins, and only those' );

$themes = DPT_ONB_Updates::items_of_type( 'theme' );
dpt_test_eq( count( $themes ), 1, 'one GitHub theme' );
dpt_test_eq( $themes[0]['id'], 'hello_digitizer', 'the child theme' );

/* ---- filling the plugins transient ---- */

$GLOBALS['dpt_stub_is_admin']  = true;
$GLOBALS['dpt_stub_transients'] = array(
	DPT_ONB_Source::RELEASE_PREFIX . md5( 'WordPress/mcp-adapter' ) => array(
		'version' => '0.6.1',
		'package' => 'https://example.org/mcp-adapter.zip',
	),
);
$GLOBALS['dpt_stub_plugins'] = array(
	'mcp-adapter/mcp-adapter.php' => array( 'Name' => 'MCP Adapter', 'Version' => '0.4.1' ),
);

$t = (object) array( 'response' => array(), 'no_update' => array() );
$t = DPT_ONB_Updates::plugins( $t );
dpt_test_ok( isset( $t->response['mcp-adapter/mcp-adapter.php'] ), 'an outdated GitHub plugin is offered an update' );
dpt_test_eq( $t->response['mcp-adapter/mcp-adapter.php']->new_version, '0.6.1', 'at the released version' );
dpt_test_eq( $t->response['mcp-adapter/mcp-adapter.php']->package, 'https://example.org/mcp-adapter.zip', 'with the release asset as the package' );
dpt_test_eq( $t->response['mcp-adapter/mcp-adapter.php']->plugin, 'mcp-adapter/mcp-adapter.php', 'keyed and labelled by its own plugin file' );

// Being inactive is the whole point: the plugin's own code cannot run, which
// is why nothing else would ever report this.
dpt_test_eq( $GLOBALS['dpt_stub_active_plugins'], array(), 'and it was never active during any of that' );

// Up to date is not the same as unknown. WordPress reads no_update to decide
// whether a plugin may be put on automatic updates at all.
$GLOBALS['dpt_stub_plugins'] = array(
	'mcp-adapter/mcp-adapter.php' => array( 'Name' => 'MCP Adapter', 'Version' => '0.6.1' ),
);
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_ok( ! isset( $t->response['mcp-adapter/mcp-adapter.php'] ), 'a current plugin is not offered an update' );
dpt_test_ok( isset( $t->no_update['mcp-adapter/mcp-adapter.php'] ), 'but it is recorded as up to date' );
dpt_test_eq( $t->no_update['mcp-adapter/mcp-adapter.php']->package, '', 'with no package, because there is nothing to install' );

// Somebody else's answer is left alone: the plugin's own checker when it is
// active, or WordPress.org if it is ever listed there.
$GLOBALS['dpt_stub_plugins'] = array(
	'mcp-adapter/mcp-adapter.php' => array( 'Name' => 'MCP Adapter', 'Version' => '0.4.1' ),
);
$t = (object) array(
	'response'  => array( 'mcp-adapter/mcp-adapter.php' => (object) array( 'new_version' => '9.9.9', 'package' => 'https://elsewhere.example/x.zip' ) ),
	'no_update' => array(),
);
$t = DPT_ONB_Updates::plugins( $t );
dpt_test_eq( $t->response['mcp-adapter/mcp-adapter.php']->new_version, '9.9.9', 'an entry another source already made is never overwritten' );

// A plugin that is not installed is not something to report on.
$GLOBALS['dpt_stub_plugins'] = array();
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq( $t->response, array(), 'an uninstalled item is left out of the response' );
dpt_test_eq( $t->no_update, array(), 'and out of no_update' );

/* ---- filling the themes transient ---- */

$GLOBALS['dpt_stub_themes']          = array( 'hello-digitizer' );
$GLOBALS['dpt_stub_theme_versions']  = array( 'hello-digitizer' => '1.0.1' );
$GLOBALS['dpt_stub_transients'][ DPT_ONB_Source::RELEASE_PREFIX . md5( 'Digitizers/hello-digitizer' ) ] = array(
	'version' => '2.0.0',
	'package' => 'https://example.org/hello-digitizer.zip',
);

$t = DPT_ONB_Updates::themes( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq( $t->response['hello-digitizer']['new_version'], '2.0.0', 'the child theme is offered its release' );
dpt_test_eq( $t->response['hello-digitizer']['theme'], 'hello-digitizer', 'keyed by stylesheet, as themes are' );

$GLOBALS['dpt_stub_theme_versions'] = array( 'hello-digitizer' => '2.0.0' );
$t = DPT_ONB_Updates::themes( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_ok( ! isset( $t->response['hello-digitizer'] ), 'a current theme is not offered one' );
dpt_test_ok( isset( $t->no_update['hello-digitizer'] ), 'and is recorded as up to date' );

/* ---- reading never reaches the network, wherever it happens ---- */

// Not "not on the front end" - not on any read at all. A transient read in the
// admin is just as much a page someone is waiting for, and three repositories
// asked in sequence is a stalled dashboard.
dpt_test_ok( ! DPT_ONB_Updates::may_fetch(), 'an ordinary read may not look anything up' );

$GLOBALS['dpt_stub_http']    = array(); // any request at all is a hard failure
$GLOBALS['dpt_stub_plugins'] = array(
	'mcp-adapter/mcp-adapter.php' => array( 'Name' => 'MCP Adapter', 'Version' => '0.4.1' ),
);
$GLOBALS['dpt_stub_transients'] = array(
	DPT_ONB_Source::RELEASE_PREFIX . md5( 'WordPress/mcp-adapter' ) => array(
		'version' => '0.6.1',
		'package' => 'https://example.org/mcp-adapter.zip',
	),
);
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq(
	$t->response['mcp-adapter/mcp-adapter.php']->new_version,
	'0.6.1',
	'but a read still serves what is already cached'
);

// With nothing cached it reports nothing rather than reaching out.
$GLOBALS['dpt_stub_transients'] = array();
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq( $t->response, array(), 'an uncached item is not looked up on a read' );
dpt_test_ok( isset( $t->no_update['mcp-adapter/mcp-adapter.php'] ), 'and is recorded as up to date rather than as unavailable' );

/* ---- the update check is the one place that fetches ---- */

$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array(
	'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest' => array(
		'code' => 200,
		'body' => wp_json_encode(
			array(
				'tag_name' => 'v0.6.1',
				'assets'   => array(
					array( 'browser_download_url' => 'https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip' ),
				),
			)
		),
	),
	'https://api.github.com/repos/Digitizers/elementor-mcp/releases/latest' => array( 'code' => 404, 'body' => '' ),
);
// Runs after WordPress has stored its own value, so there is nothing to hand
// back and nothing of ours in what was stored. Writing an offer in there would
// leave it installable after the module - and the hooks that rename the
// archive and strip the site address - had been switched off.
$stored = (object) array( 'response' => array(), 'no_update' => array() );
DPT_ONB_Updates::after_plugins_check();
dpt_test_eq( $stored->response, array(), 'the update check stores nothing of ours' );
dpt_test_eq( $stored->no_update, array(), 'not even a no_update record' );
dpt_test_ok(
	isset( $GLOBALS['dpt_stub_transients'][ DPT_ONB_Source::RELEASE_PREFIX . md5( 'WordPress/mcp-adapter' ) ]['version'] ),
	'but the release cache is filled, which is what it is for'
);
dpt_test_ok( ! DPT_ONB_Updates::may_fetch(), 'and the permission is put back down afterwards' );

// The read that follows is what offers the update, from that cache.
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq( $t->response['mcp-adapter/mcp-adapter.php']->new_version, '0.6.1', 'the following read offers it' );

// A failed lookup is remembered, so an unreachable GitHub is asked once per
// check rather than on every page that follows.
dpt_test_ok(
	isset( $GLOBALS['dpt_stub_transients'][ DPT_ONB_Source::RELEASE_PREFIX . md5( 'Digitizers/elementor-mcp' ) ]['error'] ),
	'a failed lookup caches the failure'
);

// A check asks again even when an answer is cached: the point of a check is
// what is published now, not what was published last time. Without this the
// cache lifetime would be the freshness interval, and a release published just
// after a check would wait days rather than hours.
$GLOBALS['dpt_stub_transients'] = array(
	DPT_ONB_Source::RELEASE_PREFIX . md5( 'WordPress/mcp-adapter' ) => array(
		'version' => '0.6.1',
		'package' => 'https://example.org/old.zip',
	),
);
$GLOBALS['dpt_stub_http'] = array(
	'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest' => array(
		'code' => 200,
		'body' => wp_json_encode(
			array(
				'tag_name' => 'v0.7.0',
				'assets'   => array( array( 'browser_download_url' => 'https://example.org/new.zip' ) ),
			)
		),
	),
);
dpt_test_eq(
	DPT_ONB_Source::github_release( 'WordPress/mcp-adapter', 8, true ),
	array( 'version' => '0.7.0', 'package' => 'https://example.org/new.zip' ),
	'a forced lookup goes past a cached answer'
);
dpt_test_eq(
	DPT_ONB_Source::github_release( 'WordPress/mcp-adapter' ),
	array( 'version' => '0.7.0', 'package' => 'https://example.org/new.zip' ),
	'and the reads that follow get the newer one'
);

// A refresh that fails keeps what it was refreshing. Replacing a known release
// with an error would report the item as up to date, which is how an enrolled
// item quietly stops being updated.
$GLOBALS['dpt_stub_http'] = array();
dpt_test_eq(
	DPT_ONB_Source::github_release( 'WordPress/mcp-adapter', 8, true ),
	array( 'version' => '0.7.0', 'package' => 'https://example.org/new.zip' ),
	'a failed refresh keeps the answer it was refreshing'
);
dpt_test_ok(
	! isset( $GLOBALS['dpt_stub_transients'][ DPT_ONB_Source::RELEASE_PREFIX . md5( 'WordPress/mcp-adapter' ) ]['error'] ),
	'and does not write a failure over it'
);

// The cache has to outlive the gap between two of WordPress's update checks.
// Core's plugin and theme checks are twice daily; a cache shorter than that
// leaves a window where a read finds nothing, reports "up to date", and an
// automatic-update run landing in it silently skips the item - every cycle, if
// the cron offset is stable.
dpt_test_ok(
	DPT_ONB_Source::RELEASE_TTL > 12 * HOUR_IN_SECONDS,
	'the release cache outlives the interval between core update checks'
);
dpt_test_ok(
	DPT_ONB_Source::FAILURE_TTL < DPT_ONB_Source::RELEASE_TTL,
	'while a failure is forgotten long before a good answer is'
);

// Core writes the update transient twice during one wp_update_plugins() run -
// once to mark the check in progress, once with its result - and both writes
// reach the refresh hook. Each repository is still asked once.
$GLOBALS['dpt_stub_transients']  = array();
$GLOBALS['dpt_stub_http_calls']  = array();
$url = 'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest';
$GLOBALS['dpt_stub_http'] = array(
	$url => array(
		'code' => 200,
		'body' => wp_json_encode(
			array(
				'tag_name' => 'v0.6.1',
				'assets'   => array( array( 'browser_download_url' => 'https://example.org/mcp-adapter.zip' ) ),
			)
		),
	),
	'https://api.github.com/repos/Digitizers/elementor-mcp/releases/latest' => array( 'code' => 404, 'body' => '' ),
);
DPT_ONB_Updates::after_plugins_check();
DPT_ONB_Updates::after_plugins_check();
dpt_test_eq( $GLOBALS['dpt_stub_http_calls'][ $url ], 1, 'one core check asks each repository once, not once per transient write' );

/* ---- an update is installed over the item, not beside it ---- */

$files = array(
	'mcp-adapter'   => 'mcp-adapter/mcp-adapter.php',
	'elementor-mcp' => 'elementor-mcp/plugin.php',
);
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'plugin' => 'mcp-adapter/mcp-adapter.php' ), $files ), 'mcp-adapter', 'an upgrade of one of ours is recognised by its plugin file' );
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'plugin' => 'elementor-mcp/plugin.php' ), $files ), 'elementor-mcp', 'including one whose file is not slug/slug.php' );
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'theme' => 'hello-digitizer' ), $files ), 'hello-digitizer', 'and a theme by its stylesheet' );

// Somebody else's install in the same request must never be renamed.
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'plugin' => 'akismet/akismet.php' ), $files ), null, 'an unrelated plugin is not ours to rename' );
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'theme' => 'astra' ), $files ), null, 'nor an unrelated theme' );
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array(), $files ), null, 'nor an install that names nothing at all' );
dpt_test_eq( DPT_ONB_Updates::slug_for_upgrade( array( 'plugin' => 'hello-elementor/hello-elementor.php' ), $files ), null, 'nor a WordPress.org item, which core already names correctly' );

// The filter leaves a source alone when the upgrade is not ours, which is the
// case that would otherwise move another plugin's files.
dpt_test_eq(
	DPT_ONB_Updates::normalize_source( '/tmp/x/akismet-1.2.3/', '/tmp/x/', null, array( 'plugin' => 'akismet/akismet.php' ) ),
	'/tmp/x/akismet-1.2.3/',
	'the source of an unrelated upgrade is returned untouched'
);

/* ---- the download carries no site address ---- */

dpt_test_ok( ! dpt_stub_has_filter( 'http_request_args' ), 'nothing is hooked to begin with' );
DPT_ONB_Updates::before_package_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' );
dpt_test_ok( ! dpt_stub_has_filter( 'http_request_args' ), 'a download that is not ours is left alone' );
DPT_ONB_Updates::before_package_download( false, 'https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip' );
dpt_test_ok( dpt_stub_has_filter( 'http_request_args' ), 'ours is anonymised' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
