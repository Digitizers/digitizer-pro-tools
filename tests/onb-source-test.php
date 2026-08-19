<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';

/* ---- pick_asset(): pure ---- */

$with_zip = array(
	'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0',
	'assets'      => array(
		array( 'browser_download_url' => 'https://example.test/notes.txt' ),
		array( 'browser_download_url' => 'https://example.test/elementor-mcp.zip' ),
	),
);
dpt_test_eq(
	DPT_ONB_Source::pick_asset( $with_zip ),
	'https://example.test/elementor-mcp.zip',
	'a release asset ZIP wins over the zipball'
);

// A release whose assets are all source archives: GitHub attaches
// Source code (zip) as zipball_url, not as an asset, so "no ZIP assets" must
// fall back rather than fail.
$no_zip_assets = array(
	'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0',
	'assets'      => array( array( 'browser_download_url' => 'https://example.test/checksums.txt' ) ),
);
dpt_test_eq(
	DPT_ONB_Source::pick_asset( $no_zip_assets ),
	'https://api.github.com/repos/o/r/zipball/v1.0.0',
	'falls back to the zipball when no asset is a ZIP'
);

$no_assets = array( 'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.0.0', 'assets' => array() );
dpt_test_eq( DPT_ONB_Source::pick_asset( $no_assets ), 'https://api.github.com/repos/o/r/zipball/v1.0.0', 'an empty asset list falls back' );

dpt_test_eq( DPT_ONB_Source::pick_asset( array() ), null, 'a payload with neither assets nor a zipball yields null' );

// Case and query strings must not defeat the .zip check.
$upper = array( 'assets' => array( array( 'browser_download_url' => 'https://example.test/Build.ZIP' ) ) );
dpt_test_eq( DPT_ONB_Source::pick_asset( $upper ), 'https://example.test/Build.ZIP', 'the extension check is case-insensitive' );

// A non-https asset must never be chosen.
$insecure = array(
	'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1',
	'assets'      => array( array( 'browser_download_url' => 'http://example.test/plugin.zip' ) ),
);
dpt_test_eq( DPT_ONB_Source::pick_asset( $insecure ), 'https://api.github.com/repos/o/r/zipball/v1', 'a plain-http asset is refused' );

/* ---- github_zip_url(): HTTP + cache ---- */

$item = array( 'id' => 'elementor_mcp', 'type' => 'plugin', 'source' => 'github', 'repo' => 'Digitizers/elementor-mcp', 'slug' => 'elementor-mcp' );
$api  = 'https://api.github.com/repos/Digitizers/elementor-mcp/releases/latest';

$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array(
	$api => array( 'code' => 200, 'body' => wp_json_encode( $with_zip ) ),
);
dpt_test_eq( DPT_ONB_Source::github_zip_url( $item ), 'https://example.test/elementor-mcp.zip', 'a published release resolves to its asset' );

// Second call must not touch HTTP at all.
$GLOBALS['dpt_stub_http'] = array();
dpt_test_eq( DPT_ONB_Source::github_zip_url( $item ), 'https://example.test/elementor-mcp.zip', 'the resolved URL is cached' );

// No releases: GitHub answers 404, and the default-branch zipball is used.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 404, 'body' => '{"message":"Not Found"}' ) );
dpt_test_eq(
	DPT_ONB_Source::github_zip_url( $item ),
	'https://api.github.com/repos/Digitizers/elementor-mcp/zipball',
	'a repository with no releases falls back to the branch zipball'
);

// Rate limiting must surface as an error, not as a silent wrong URL.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 403, 'body' => '{"message":"API rate limit exceeded"}' ) );
$res = DPT_ONB_Source::github_zip_url( $item );
dpt_test_ok( is_wp_error( $res ), 'a rate-limit response is an error' );
dpt_test_eq( is_wp_error( $res ) ? $res->get_error_code() : '', 'dpt_onb_github_http', 'the error names the failing step' );

// A transport failure is an error too.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array();
dpt_test_ok( is_wp_error( DPT_ONB_Source::github_zip_url( $item ) ), 'a transport failure is an error' );

// Malformed JSON must not be treated as an empty release.
$GLOBALS['dpt_stub_transients'] = array();
$GLOBALS['dpt_stub_http']       = array( $api => array( 'code' => 200, 'body' => 'not json' ) );
dpt_test_ok( is_wp_error( DPT_ONB_Source::github_zip_url( $item ) ), 'malformed JSON is an error' );

// An error is never cached - the next attempt must retry.
dpt_test_eq( $GLOBALS['dpt_stub_transients'], array(), 'failures are not cached' );

/* ---- regression: the package download must not disclose the site URL ---- */

// The release lookup and the archive download are two separate requests.
// Setting the agent on the lookup alone leaves WordPress's default agent - which
// embeds the site URL - on the download, which is the request that happens for
// every install.
$ua = DPT_ONB_Source::user_agent();
dpt_test_ok( false !== strpos( $ua, 'Digitizer Pro Tools' ), 'the agent names the plugin' );
dpt_test_ok( false === strpos( $ua, 'example.test' ), 'the agent carries no site URL' );

foreach ( array(
	'https://api.github.com/repos/o/r/releases/latest',
	'https://codeload.github.com/o/r/legacy.zip/refs/heads/main',
	'https://objects.githubusercontent.com/some/asset.zip',
	'https://release-assets.githubusercontent.com/some/asset.zip',
	'https://github.com/o/r/releases/download/v1/plugin.zip',
) as $url ) {
	$args = DPT_ONB_Source::anonymize_request( array( 'user-agent' => 'WordPress/7.0; https://client.example' ), $url );
	dpt_test_eq( $args['user-agent'], $ua, 'the agent is replaced for ' . wp_parse_url( $url, PHP_URL_HOST ) );
}

// Requests to anywhere else are left exactly as they were.
$untouched = DPT_ONB_Source::anonymize_request( array( 'user-agent' => 'WordPress/7.0; https://client.example' ), 'https://downloads.wordpress.org/plugin/elementor.zip' );
dpt_test_eq( $untouched['user-agent'], 'WordPress/7.0; https://client.example', 'a non-GitHub request is untouched' );

$evil = DPT_ONB_Source::anonymize_request( array( 'user-agent' => 'x' ), 'https://api.github.com.attacker.test/o/r' );
dpt_test_eq( $evil['user-agent'], 'x', 'a look-alike host does not match' );

dpt_test_eq( DPT_ONB_Source::anonymize_request( 'not-an-array', 'https://api.github.com/' ), 'not-an-array', 'a non-array argument passes through' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
