<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-installer.php';

$plugin = array( 'id' => 'elementor', 'type' => 'plugin', 'slug' => 'elementor' );
$theme  = array( 'id' => 'hello_elementor', 'type' => 'theme', 'slug' => 'hello-elementor' );

/* ---- plugins ---- */

$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'missing', 'an absent plugin is missing' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'elementor' ), null, 'no file for an absent plugin' );

$GLOBALS['dpt_stub_plugins'] = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'inactive', 'an installed but inactive plugin is inactive' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'elementor' ), 'elementor/elementor.php', 'the plugin file is resolved' );

$GLOBALS['dpt_stub_active_plugins'] = array( 'elementor/elementor.php' );
dpt_test_eq( DPT_ONB_State::of( $plugin ), 'active', 'an active plugin is active' );

// The case a naive slug/slug.php implementation gets wrong. Several of the
// baseline plugins do not name their main file after their directory -
// Rank Math's is seo-by-rank-math/rank-math.php.
$GLOBALS['dpt_stub_plugins']        = array( 'seo-by-rank-math/rank-math.php' => array( 'Name' => 'Rank Math' ) );
$GLOBALS['dpt_stub_active_plugins'] = array();
$rm = array( 'id' => 'rank_math', 'type' => 'plugin', 'slug' => 'seo-by-rank-math' );
dpt_test_eq( DPT_ONB_State::plugin_file( 'seo-by-rank-math' ), 'seo-by-rank-math/rank-math.php', 'the main file need not match the directory' );
dpt_test_eq( DPT_ONB_State::of( $rm ), 'inactive', 'such a plugin is still detected' );

// A single-file plugin sits at the plugins root, where dirname() is '.'. It
// must never be mistaken for a directory match.
$GLOBALS['dpt_stub_plugins'] = array( 'hello.php' => array( 'Name' => 'Hello Dolly' ) );
dpt_test_eq( DPT_ONB_State::plugin_file( 'hello' ), null, 'a root-level single-file plugin is not matched' );
dpt_test_eq( DPT_ONB_State::plugin_file( '.' ), null, 'a dot slug matches nothing' );

/* ---- themes ---- */

$GLOBALS['dpt_stub_themes']     = array();
$GLOBALS['dpt_stub_stylesheet'] = 'twentytwentyfour';
dpt_test_eq( DPT_ONB_State::of( $theme ), 'missing', 'an absent theme is missing' );

$GLOBALS['dpt_stub_themes'] = array( 'hello-elementor' );
dpt_test_eq( DPT_ONB_State::of( $theme ), 'inactive', 'an installed but inactive theme is inactive' );

$GLOBALS['dpt_stub_stylesheet'] = 'hello-elementor';
dpt_test_eq( DPT_ONB_State::of( $theme ), 'active', 'the current stylesheet is the active theme' );

/* ---- all() ---- */

$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
$GLOBALS['dpt_stub_themes']         = array();
$GLOBALS['dpt_stub_stylesheet']     = 'twentytwentyfour';
$all = DPT_ONB_State::all();
dpt_test_eq( count( $all ), count( DPT_ONB_Manifest::items() ), 'all() covers every manifest item' );
dpt_test_eq( $all['elementor'], 'missing', 'all() reports a missing item' );
dpt_test_ok( array_keys( $all ) === array_column( DPT_ONB_Manifest::items(), 'id' ), 'all() preserves manifest order' );

/* ---- install-only plugins ---- */

// Most of the baseline is installed and left switched off. Reporting such an
// item as inactive would make the wizard try to activate it on every run.
$wordfence = DPT_ONB_Manifest::get( 'wordfence' );
dpt_test_eq( $wordfence['activate'], false, 'Wordfence is install-only' );

$GLOBALS['dpt_stub_plugins']        = array();
$GLOBALS['dpt_stub_active_plugins'] = array();
dpt_test_eq( DPT_ONB_State::of( $wordfence ), DPT_ONB_State::MISSING, 'an absent install-only plugin is missing' );

$GLOBALS['dpt_stub_plugins'] = array( 'wordfence/wordfence.php' => array( 'Name' => 'Wordfence' ) );
dpt_test_eq( DPT_ONB_State::of( $wordfence ), DPT_ONB_State::PRESENT, 'installed and off is its goal state' );
dpt_test_eq( DPT_ONB_Installer::action_for( DPT_ONB_State::of( $wordfence ) ), 'skip', 'so the wizard leaves it alone' );

// An item the operator switched on by hand still reports as active, because
// it is - the status column should not deny what the plugins screen shows.
$GLOBALS['dpt_stub_active_plugins'] = array( 'wordfence/wordfence.php' );
dpt_test_eq( DPT_ONB_State::of( $wordfence ), DPT_ONB_State::ACTIVE, 'one switched on by hand reports as active' );

// Elementor is the exception: it is meant to end up running.
$elementor = DPT_ONB_Manifest::get( 'elementor' );
dpt_test_ok( ! isset( $elementor['activate'] ), 'Elementor is not install-only' );
$GLOBALS['dpt_stub_plugins']        = array( 'elementor/elementor.php' => array( 'Name' => 'Elementor' ) );
$GLOBALS['dpt_stub_active_plugins'] = array();
dpt_test_eq( DPT_ONB_State::of( $elementor ), DPT_ONB_State::INACTIVE, 'so an inactive Elementor still needs activating' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
