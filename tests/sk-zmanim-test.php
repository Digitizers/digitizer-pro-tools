<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-zmanim.php';

$tz = new DateTimeZone( 'Asia/Jerusalem' );
$at = function ( $local ) use ( $tz ) {
	return ( new DateTime( $local, $tz ) )->getTimestamp();
};
$fmt = function ( $ts ) use ( $tz ) {
	$d = new DateTime( '@' . $ts );
	$d->setTimezone( $tz );
	return $d->format( 'D Y-m-d H:i' );
};

/* ---- cities ---- */
$j = DPT_SK_Cities::get( 'jerusalem' );
dpt_test_eq( $j['candle'], 40, 'Jerusalem lights 40 minutes before sunset' );
dpt_test_eq( DPT_SK_Cities::get( 'tel_aviv' )['candle'], 18, 'Tel Aviv 18' );
dpt_test_eq( DPT_SK_Cities::get( 'haifa' )['candle'], 30, 'Haifa 30' );
dpt_test_eq( DPT_SK_Cities::get( 'beer_sheva' )['candle'], 22, 'Beer Sheva 22' );
dpt_test_eq( count( DPT_SK_Cities::all() ), 15, 'fifteen cities' );
dpt_test_eq( DPT_SK_Cities::get( 'atlantis' ), null, 'unknown city is null' );

$z = new DPT_SK_Zmanim( $j['lat'], $j['lon'], 40, 42 );

/* ---- a plain Shabbat: Fri 2026-09-04 .. Sat 2026-09-05 ---- */
$w = $z->windows( $at( '2026-09-03 00:00' ), $at( '2026-09-06 23:59' ) );
dpt_test_eq( count( $w ), 1, 'one window in that span' );
dpt_test_eq( $w[0]['reason'], 'shabbat', 'reason is shabbat' );
$fri_sunset = DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2026, 9, 4 );
$sat_sunset = DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2026, 9, 5 );
dpt_test_eq( $w[0]['start'], $fri_sunset - 40 * 60, 'starts 40 minutes before Friday sunset' );
dpt_test_eq( $w[0]['end'], $sat_sunset + 42 * 60, 'ends 42 minutes after Saturday sunset' );

/* ---- Shabbat + Rosh Hashana 5787 (Sat 12.9, Sun 13.9) merge into one window ---- */
$w = $z->windows( $at( '2026-09-10 00:00' ), $at( '2026-09-14 23:59' ) );
dpt_test_eq( count( $w ), 1, 'Shabbat and two days of Rosh Hashana are one window' );
dpt_test_eq( $w[0]['reason'], 'shabbat+rosh_hashana_2', 'Saturday 12.9 reports as shabbat, Sunday as day two' );
dpt_test_eq( $w[0]['start'], DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2026, 9, 11 ) - 40 * 60, 'starts Friday 11.9' );
dpt_test_eq( $w[0]['end'], DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2026, 9, 13 ) + 42 * 60, 'ends Sunday 13.9 night' );

/* ---- Yom Kippur 2026-09-21 (Monday) is its own window ---- */
$w = $z->windows( $at( '2026-09-20 12:00' ), $at( '2026-09-22 12:00' ) );
dpt_test_eq( count( $w ), 1, 'Yom Kippur alone' );
dpt_test_eq( $w[0]['reason'], 'yom_kippur', 'reason yom_kippur' );
dpt_test_eq( $w[0]['start'], DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2026, 9, 20 ) - 40 * 60, 'starts Sunday evening' );

/* ---- is_closed_at at the edges ---- */
$w = $z->windows( $at( '2026-09-03 00:00' ), $at( '2026-09-06 23:59' ) );
dpt_test_ok( ! $z->is_closed_at( $w[0]['start'] - 1 ), 'open one second before candle lighting' );
dpt_test_ok( $z->is_closed_at( $w[0]['start'] ), 'closed at candle lighting' );
dpt_test_ok( $z->is_closed_at( $w[0]['end'] - 1 ), 'closed one second before Havdalah' );
dpt_test_ok( ! $z->is_closed_at( $w[0]['end'] ), 'open at Havdalah' );
dpt_test_ok( ! $z->is_closed_at( $at( '2026-09-02 12:00' ) ), 'Wednesday noon is open' );

/* ---- current_window / next_transition ---- */
$mid = $at( '2026-09-05 12:00' );
dpt_test_eq( $z->current_window( $mid )['reason'], 'shabbat', 'current window on Shabbat noon' );
dpt_test_eq( $z->current_window( $at( '2026-09-02 12:00' ) ), null, 'no current window on Wednesday' );
dpt_test_eq( $z->next_transition( $mid ), $w[0]['end'], 'inside a window the next transition is its end' );
dpt_test_eq( $z->next_transition( $at( '2026-09-02 12:00' ) ), $w[0]['start'], 'outside, the next transition is the next start' );

/* ---- window in the far past does not leak into the range ---- */
$w = $z->windows( $at( '2026-09-06 12:00' ), $at( '2026-09-06 13:00' ) );
dpt_test_eq( $w, array(), 'Sunday noon, one hour: no windows' );

/* ---- three-day closure (Rosh Hashana 5785 Thu+Fri, then Shabbat) is not truncated on its first evening ---- */
$w2 = $z->current_window( $at( '2024-10-02 20:00' ) );
dpt_test_ok( null !== $w2, 'a three-day run of closure days is a real window on its first evening' );
dpt_test_eq( $w2['reason'], 'rosh_hashana_1+rosh_hashana_2+shabbat', 'reason spans all three closure days' );
dpt_test_eq( $w2['start'], DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2024, 10, 2 ) - 40 * 60, 'starts Wednesday 2.10 evening' );
dpt_test_eq( $w2['end'], DPT_SK_Sun::sunset( $j['lat'], $j['lon'], 2024, 10, 5 ) + 42 * 60, 'ends Saturday 5.10 night' );

exit( dpt_test_summary() );
