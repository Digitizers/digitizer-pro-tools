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

/* ---- the front end never becomes a GitHub request ---- */

$GLOBALS['dpt_stub_is_admin'] = false;
dpt_test_ok( ! DPT_ONB_Updates::may_fetch(), 'a front-end request may not look anything up' );

$GLOBALS['dpt_stub_http']    = array(); // any request at all is a hard failure
$GLOBALS['dpt_stub_plugins'] = array(
	'mcp-adapter/mcp-adapter.php' => array( 'Name' => 'MCP Adapter', 'Version' => '0.4.1' ),
);
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq(
	$t->response['mcp-adapter/mcp-adapter.php']->new_version,
	'0.6.1',
	'but it still serves what is already cached, so the front end and the admin agree'
);

// With nothing cached it reports nothing rather than reaching out.
$GLOBALS['dpt_stub_transients'] = array();
$t = DPT_ONB_Updates::plugins( (object) array( 'response' => array(), 'no_update' => array() ) );
dpt_test_eq( $t->response, array(), 'an uncached item is not looked up from the front end' );
dpt_test_ok( isset( $t->no_update['mcp-adapter/mcp-adapter.php'] ), 'and is recorded as up to date rather than as unavailable' );

$GLOBALS['dpt_stub_is_admin'] = true;

exit( dpt_test_summary() > 0 ? 1 : 0 );
