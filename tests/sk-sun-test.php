<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';

/*
 * Reference values from the NOAA Solar Calculator
 * (gml.noaa.gov/grad/solcalc/), read 2026-09-10, rounded to the minute,
 * expressed in Asia/Jerusalem wall-clock time. Tolerance two minutes: the
 * algorithm is the spreadsheet's, the calculator adds refraction tables.
 */
$tz    = new DateTimeZone( 'Asia/Jerusalem' );
$local = function ( $ts ) use ( $tz ) {
	$d = new DateTime( '@' . $ts );
	$d->setTimezone( $tz );
	return $d->format( 'Y-m-d H:i' );
};
$within = function ( $ts, $expected_local ) use ( $tz ) {
	$e = new DateTime( $expected_local, $tz );
	return abs( $ts - $e->getTimestamp() ) <= 120;
};

$cases = array(
	array( 31.778, 35.235, 2026, 9, 11, '2026-09-11 18:50', 'Jerusalem, September' ),
	array( 31.778, 35.235, 2026, 6, 21, '2026-06-21 19:47', 'Jerusalem, June solstice' ),
	array( 31.778, 35.235, 2026, 12, 21, '2026-12-21 16:39', 'Jerusalem, December solstice' ),
	array( 29.558, 34.948, 2026, 9, 11, '2026-09-11 18:50', 'Eilat, September' ),
	array( 32.794, 34.990, 2026, 3, 27, '2026-03-27 18:56', 'Haifa, day DST starts' ),
);
foreach ( $cases as $c ) {
	$ts = DPT_SK_Sun::sunset( $c[0], $c[1], $c[2], $c[3], $c[4] );
	dpt_test_ok( is_int( $ts ), $c[6] . ' returns an int' );
	dpt_test_ok( $within( $ts, $c[5] ), $c[6] . ' within 2 min of ' . $c[5] . ' (got ' . $local( $ts ) . ')' );
}

dpt_test_eq( DPT_SK_Sun::sunset( 78.0, 15.0, 2026, 6, 21 ), null, 'midnight sun: no sunset' );

$a = DPT_SK_Sun::sunset( 31.778, 35.235, 2026, 9, 11 );
$b = DPT_SK_Sun::sunset( 31.778, 35.235, 2026, 9, 12 );
dpt_test_ok( $b - $a > 86400 - 180 && $b - $a < 86400, 'September sunsets come earlier day by day' );

exit( dpt_test_summary() );
