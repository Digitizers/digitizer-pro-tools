<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-state.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-source.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-installer.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-cleanup.php';

/* ---- the rules, as pure decisions ---- */

/**
 * Author map for themes that really are core's, which is the ordinary case.
 */
function dpt_core_authors( $slugs ) {
	$out = array();
	foreach ( $slugs as $slug ) {
		$out[ $slug ] = 'the WordPress team';
	}
	return $out;
}

// The ordinary case: a finished site keeps the newest default as its fallback.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour', 'hello-elementor', 'hello-digitizer' ),
		'hello-digitizer',
		array(),
		dpt_core_authors( array( 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array( 'twentytwentythree' ),
	'older defaults go, the newest stays as the fallback'
);

// Without a fallback, an unusable active theme takes the site down rather than
// degrading it - so one default is always left behind.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentyfour', 'hello-digitizer' ),
		'hello-digitizer',
		array(),
		dpt_core_authors( array( 'twentytwentyfour' ) )
	),
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
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour' ),
		'twentytwentythree',
		array(),
		dpt_core_authors( array( 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array(),
	'the active theme is never removed, even when it is the older default'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ),
		'twentytwentythree',
		array(),
		dpt_core_authors( array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array( 'twentytwentytwo' ),
	'but its siblings still go'
);

// A parent belongs to its child, including children we know nothing about.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour', 'client-child' ),
		'client-child',
		array( 'twentytwentythree' ),
		dpt_core_authors( array( 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array(),
	'a default that another installed theme depends on is never removed'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour', 'client-child' ),
		'hello-digitizer',
		array( 'twentytwentythree' ),
		dpt_core_authors( array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array( 'twentytwentytwo' ),
	'while unrelated defaults are still removed'
);

// Third-party themes are not ours to delete.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'astra', 'twentytwentythree', 'twentytwentyfour' ),
		'astra',
		array(),
		dpt_core_authors( array( 'twentytwentythree', 'twentytwentyfour' ) )
	),
	array( 'twentytwentythree' ),
	'only WordPress default themes are candidates'
);

// The directory name is not proof of what is inside it. A host that shipped
// its own design in twentytwentythree/ must not have it deleted for being
// older than twentytwentyfour.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour' ),
		'hello-digitizer',
		array(),
		array( 'twentytwentythree' => 'Acme Hosting', 'twentytwentyfour' => 'the WordPress team' )
	),
	array(),
	'a third-party theme in a core directory name is never deleted'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ),
		'hello-digitizer',
		array(),
		array(
			'twentytwentytwo'   => 'the WordPress team',
			'twentytwentythree' => 'Acme Hosting',
			'twentytwentyfour'  => 'the WordPress team',
		)
	),
	array( 'twentytwentytwo' ),
	'and its genuine siblings are still removed around it'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable( array( 'twentytwentythree', 'twentytwentyfour' ), 'hello-digitizer' ),
	array(),
	'a theme whose author the caller did not supply is left alone'
);

// WP_DEFAULT_THEME, not the newest slug in the list, is what core switches to
// when the active theme becomes invalid. A site on an older WordPress can have
// a newer default installed by hand, and deleting the configured one would
// take away the real fallback.
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentythree', 'twentytwentyfour' ),
		'hello-digitizer',
		array(),
		dpt_core_authors( array( 'twentytwentythree', 'twentytwentyfour' ) ),
		'twentytwentythree'
	),
	array(),
	'the theme core is configured to fall back to is never deleted'
);
dpt_test_eq(
	DPT_ONB_Cleanup::removable(
		array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ),
		'hello-digitizer',
		array(),
		dpt_core_authors( array( 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour' ) ),
		'twentytwentythree'
	),
	array( 'twentytwentytwo' ),
	'while the defaults around it still go'
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

// The same protection through run(), which reads the authors off the installed
// themes rather than trusting their directory names.
$GLOBALS['dpt_stub_themes']         = array( 'twentytwentythree', 'twentytwentyfour', 'hello-digitizer' );
$GLOBALS['dpt_stub_theme_authors']  = array( 'twentytwentythree' => 'Acme Hosting' );
$GLOBALS['dpt_stub_stylesheet']     = 'hello-digitizer';
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( $results[0]['outcome'], 'skipped', 'a host theme in a core directory leaves nothing to remove' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array(), 'and it is still on disk' );
$GLOBALS['dpt_stub_theme_authors'] = array();

// A site that forbids theme deletion is refused, not worked around.
$GLOBALS['dpt_stub_themes']         = array( 'twentytwentythree', 'twentytwentyfour' );
$GLOBALS['dpt_stub_stylesheet']     = 'twentytwentyfour';
$GLOBALS['dpt_stub_denied_caps']    = array( 'delete_themes' );
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( $results[0]['outcome'], 'failed', 'deletion is refused without the capability' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array(), 'and nothing was deleted' );
$GLOBALS['dpt_stub_denied_caps'] = array();

// A network shares one directory of themes, so what looks unused from this
// blog can be another blog's active theme. Cleanup declines rather than
// deleting on a partial view.
$GLOBALS['dpt_stub_multisite']      = true;
$GLOBALS['dpt_stub_themes']         = array( 'twentytwentythree', 'twentytwentyfour', 'hello-digitizer' );
$GLOBALS['dpt_stub_stylesheet']     = 'hello-digitizer';
$GLOBALS['dpt_stub_deleted_themes'] = array();
$results = DPT_ONB_Cleanup::run();
dpt_test_eq( $results[0]['outcome'], 'skipped', 'cleanup declines on multisite' );
dpt_test_eq( $GLOBALS['dpt_stub_deleted_themes'], array(), 'and deletes nothing there' );
$GLOBALS['dpt_stub_multisite'] = false;

exit( dpt_test_summary() > 0 ? 1 : 0 );
