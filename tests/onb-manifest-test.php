<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-manifest.php';

$items = DPT_ONB_Manifest::items();

dpt_test_eq( count( $items ), 14, 'the baseline has fourteen items' );

// Ids are unique - a duplicate would make get() ambiguous and would let the
// wizard apply the same item twice.
$ids = array_column( $items, 'id' );
dpt_test_eq( count( array_unique( $ids ) ), count( $ids ), 'every id is unique' );

// Slugs are unique per type, since the slug is the directory the item lands in.
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$slugs = array();
	foreach ( $items as $item ) {
		if ( $type === $item['type'] ) {
			$slugs[] = $item['slug'];
		}
	}
	dpt_test_eq( count( array_unique( $slugs ) ), count( $slugs ), "every $type slug is unique" );
}

foreach ( $items as $item ) {
	$id = $item['id'];
	dpt_test_ok( in_array( $item['type'], array( 'plugin', 'theme' ), true ), "$id has a valid type" );
	dpt_test_ok( in_array( $item['source'], array( 'wporg', 'github' ), true ), "$id has a valid source" );
	dpt_test_ok( isset( $item['label'] ) && '' !== $item['label'], "$id has a label" );
	dpt_test_ok( isset( $item['slug'] ) && '' !== $item['slug'], "$id declares the directory it installs into" );
	dpt_test_ok( $item['id'] === sanitize_key( $item['id'] ), "$id is a safe key" );
	if ( 'github' === $item['source'] ) {
		dpt_test_ok(
			isset( $item['repo'] ) && 1 === preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $item['repo'] ),
			"$id declares a well-formed owner/repo"
		);
	}
}

// A child theme must come after its parent: the wizard applies items in this
// order, and installing a child before its parent leaves a broken theme.
$positions = array();
foreach ( $items as $i => $item ) {
	$positions[ $item['slug'] ] = $i;
}
foreach ( $items as $i => $item ) {
	if ( ! empty( $item['parent'] ) ) {
		dpt_test_ok( isset( $positions[ $item['parent'] ] ), $item['id'] . ' names a parent that is in the manifest' );
		dpt_test_ok( $positions[ $item['parent'] ] < $i, $item['id'] . ' comes after its parent' );
	}
}

// Every slug the design fixed, spelled out here so a typo in the manifest is a
// test failure rather than a 404 on a client site.
$expected_slugs = array(
	'hello-elementor', 'hello-digitizer', 'angie', 'cloudflare', 'elementor',
	'fluent-smtp', 'imagify', 'contact-forms-anti-spam', 'seo-by-rank-math',
	'wordfence', 'insert-headers-and-footers', 'digitizer-site-worker',
	'elementor-mcp', 'mcp-adapter',
);
sort( $expected_slugs );
$actual_slugs = array_column( $items, 'slug' );
sort( $actual_slugs );
dpt_test_eq( $actual_slugs, $expected_slugs, 'the manifest holds exactly the agreed slugs' );

dpt_test_ok( null !== DPT_ONB_Manifest::get( 'elementor' ), 'get() finds a known id' );
dpt_test_eq( DPT_ONB_Manifest::get( 'no-such-item' ), null, 'get() returns null for an unknown id' );
dpt_test_eq( DPT_ONB_Manifest::get( '../../evil' ), null, 'get() returns null for a traversal attempt' );

/* ---- the activation policy ---- */

// Only these two are switched on. Everything else is installed and left for
// the operator to enable per client - an agency baseline is a set of tools
// available on the site, not a set running on it.
$activated = array();
foreach ( $items as $item ) {
	if ( ! isset( $item['activate'] ) || false !== $item['activate'] ) {
		$activated[] = $item['id'];
	}
}
dpt_test_eq( $activated, array( 'hello_digitizer', 'elementor' ), 'only the child theme and Elementor are activated' );

// The parent theme in particular must stay install-only: activating it would
// undo the child.
$parent = DPT_ONB_Manifest::get( 'hello_elementor' );
dpt_test_eq( $parent['activate'], false, 'the parent theme is install-only' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
