<?php
// Isolated constants per process, real PUC selection, no network or WordPress.
require dirname( __DIR__ ) . '/bootstrap.php';
$mode = isset( $argv[1] ) ? $argv[1] : 'unset';
if ( 'unset' !== $mode ) {
	$values = array( 'off' => false, 'on' => true, 'string' => 'true', 'integer' => 1 );
	define( 'DPT_BETA', $values[ $mode ] );
}

// Only checker construction is replaced; release filtering is bundled PUC.
class DPT_Channel_Test_Factory {
	public static $builds = 0;
	public static $state_option;
	public static function addVersion( $general, $versioned, $version ) {}
	public static function buildUpdateChecker( $url, $file, $slug, $period = 12, $option = '' ) {
		self::$builds++;
		self::$state_option = $option;
		return $GLOBALS['channel_checker'];
	}
}
class_alias( 'DPT_Channel_Test_Factory', 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' );
require dirname( __DIR__, 2 ) . '/vendor/plugin-update-checker/plugin-update-checker.php';
require dirname( __DIR__, 2 ) . '/includes/class-dpt-updater.php';

class DPT_Channel_Test_Api extends \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi {
	public $releases = array();
	public $requests = array();
	protected function api( $url, $queryParams = array() ) {
		$this->requests[] = array( $url, $queryParams );
		if ( '/repos/:user/:repo/releases' === $url ) { return $this->releases; }
		if ( '/repos/:user/:repo/releases/latest' === $url ) {
			foreach ( $this->releases as $release ) {
				if ( ! $release->draft && ! $release->prerelease ) { return $release; }
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected tag/branch/API fallback: ' . $url );
	}
}
class DPT_Channel_Test_Checker {
	private $api;
	public function __construct( $api ) { $this->api = $api; }
	public function getVcsApi() { return $this->api; }
	public function getUniqueName( $tag ) { return 'puc_' . $tag . '-digitizer-pro-tools'; }
}
function channel_release( $version, $prerelease, $draft = false ) {
	return (object) array(
		'tag_name' => 'v' . $version, 'prerelease' => $prerelease, 'draft' => $draft,
		'zipball_url' => 'https://example.test/source-' . $version . '.zip', 'created_at' => '2026-11-19T00:00:00Z',
		'assets' => array( (object) array( 'name' => 'digitizer-pro-tools.zip', 'browser_download_url' => 'https://example.test/install-' . $version . '.zip', 'download_count' => 0 ) ),
	);
}
$api = new DPT_Channel_Test_Api( DPT_Updater::REPO );
$GLOBALS['channel_checker'] = new DPT_Channel_Test_Checker( $api );
$api->setStrategyFilterName( $GLOBALS['channel_checker']->getUniqueName( 'vcs_update_detection_strategies' ) );
DPT_Updater::init( __FILE__ );
DPT_Updater::init( __FILE__ );
$stable = channel_release( '1.54.0', false );
$beta = channel_release( '1.55.0-beta.1', true );
$draft = channel_release( '1.56.0', false, true );
$api->releases = array( $draft, $beta, $stable );
$selected = $api->chooseReference( 'main' );
$first = $api->requests[0];
$api->releases = array( $beta );
$beta_only = $api->chooseReference( 'main' );
$api->releases = array( $draft );
$draft_only = $api->chooseReference( 'main' );
$api->releases = array();
$empty = $api->chooseReference( 'main' );
$own = (object) array( 'plugin' => DPT_BASENAME );
$row = '<a>Enable auto-updates</a>';
echo json_encode( array(
	'version' => $selected ? $selected->version : null,
	'package' => $selected ? $selected->downloadUrl : null,
	'first_request' => $first,
	'beta_only' => $beta_only ? $beta_only->version : null,
	'draft_only' => $draft_only, 'empty' => $empty,
	'builds' => DPT_Channel_Test_Factory::$builds,
	'state_option' => DPT_Channel_Test_Factory::$state_option,
	'auto_updates' => array_map( function ( $value ) use ( $own ) { return apply_filters( 'auto_update_plugin', $value, $own ); }, array( true, false, null ) ),
	'plugins_row' => apply_filters( 'plugin_auto_update_setting_html', $row, DPT_BASENAME, array() ),
	'enrollment' => get_option( 'auto_update_plugins', array() ),
	'harness_failures' => $GLOBALS['dpt_test_fail'],
) );
