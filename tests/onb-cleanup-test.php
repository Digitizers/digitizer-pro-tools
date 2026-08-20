<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-installer.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-cleanup.php';

/* ---- the rules, as pure decisions ---- */

// The ordinary case: a finished site keeps the newest default as its fallback.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour', 'hello-elementor', 'hello-digitizer' ),
		'hello-digitizer'
	),
	array( 'twentytwentythree' ),
	'older defaults go, the newest stays as the fallback'
);

// Without a fallback, an unusable active theme takes the site down rather than
// degrading it - so one default is always left behind.
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'twentytwentyfour', 'hello-digitizer' ), 'hello-digitizer' ),
	array(),
	'a single default is never removed'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'hello-digitizer' ), 'hello-digitizer' ),
	array(),
	'and nothing happens when there are none'
);

// Deleting what the site is running takes it down immediately.
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'twentytwentythree', 'twentytwentyfour' ), 'twentytwentythree' ),
	array(),
	'the active theme is never removed, even when it is the older default'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ), 'twentytwentythree' ),
	array( 'twentytwentytwo' ),
	'but its siblings still go'
);

// A parent belongs to its child, including children we know nothing about.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour', 'client-child' ),
		'client-child',
		array( 'twentytwentythree' )
	),
	array(),
	'a default that another installed theme depends on is never removed'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour', 'client-child' ),
		'hello-digitizer',
		array( 'twentytwentythree' )
	),
	array( 'twentytwentytwo' ),
	'while unrelated defaults are still removed'
);

// Third-party themes are not ours to delete.
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'astra', 'twentytwentythree', 'twentytwentyfour' ), 'astra' ),
	array( 'twentytwentythree' ),
	'only WordPress default themes are candidates'
);

/* ---- parents_in_use() reads what the themes declare ---- */

$GLOBALS['dpt_stub_themes']        = array( 'twentytwentyfour', 'hello-elementor', 'hello-digitizer' );
$GLOBALS['dpt_stub_theme_parents'] = array( 'hello-digitizer' => 'hello-elementor' );
dpt_test_eq( DPT_ONB_Cleanup::parents_in_use(), array( 'hello-elementor' ), 'a child theme reports its parent' );

$GLOBALS['dpt_stub_theme_parents'] = array();
dpt_test_eq( DPT_ONB_Cleanup::parents_in_use(), array(), 'a site with no child themes reports none' );

/* ---- run() ---- */

$GLOBALS['dpt_stub_themes']         = array( 'twentytwentythree', 'twentytwentyfour', 'hello-elementor', 'hello-digitizer' );
$GLOBALS['dpt_stub_theme_parents']  = array( 'hello-digitizer' => 'hello-elementor' );
$GLOBALS['dpt_stub_stylesheet']     = 'hello-digitizer';
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( count( $results ), 1, 'one theme was removable' );
dpt_test_eq( $results[0]['outcome'], 'deleted', 'and it was removed' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array( 'twentytwentythree' ), 'exactly the older default was deleted' );
dpt_test_ok( in_array( 'twentytwentyfour', $GLOBALS['dpt_stub_themes'], true ), 'the fallback survived' );
dpt_test_ok( in_array( 'hello-elementor', $GLOBALS['dpt_stub_themes'], true ), 'and so did the parent theme' );

// Running again finds nothing left to do.
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( $results[0]['outcome'], 'skipped', 'a second run has nothing to remove' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array(), 'and deletes nothing' );

// A site that forbids theme deletion is refused, not worked around.
$GLOBALS['dpt_stub_themes']         = array( 'twentytwentythree', 'twentytwentyfour' );
$GLOBALS['dpt_stub_stylesheet']     = 'twentytwentyfour';
$GLOBALS['dpt_stub_denied_caps']    = array( 'delete_themes' );
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( $results[0]['outcome'], 'failed', 'deletion is refused without the capability' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array(), 'and nothing was deleted' );
$GLOBALS['dpt_stub_denied_caps'] = array();

exit( dpt_test_summary() > 0 ? 1 : 0 );
