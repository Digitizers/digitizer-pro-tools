# Shabbat Keeper Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Digitizer Pro Tools module that closes a site, or only its commerce and forms, from candle lighting to Havdalah every Shabbat and Israeli Yom Tov, with the times computed in PHP and no external service.

**Architecture:** Three pure-PHP classes (Hebrew calendar, NOAA sunset, closure windows) that know nothing about WordPress; one settings class around a single option; one enforcement class that decides "closed now" per request and applies the closed screen, banner, cache headers and cron purge; one integrations class for WooCommerce and four form plugins; one admin screen. Truth is always computed at request time - cron only purges caches.

**Tech Stack:** PHP 7.2+, WordPress 5.5+, the repo's stub test harness (`tests/bootstrap.php`, run with plain `php tests/<file>.php`), phpcs with the WordPress standard, gettext catalogs edited by hand plus `msgfmt`.

**Spec:** `docs/superpowers/specs/2026-09-10-shabbat-keeper-design.md`

## Global Constraints

- Branch `claude/shabbat-keeper` (exists, spec committed at `ff5ae62`). Version `1.39.0` in `digitizer-pro-tools.php` (header `Version:` and `DPT_VERSION`) and `readme.txt` `Stable tag`.
- Module id `shabbat_keeper`, class prefix `DPT_SK_`, module class `DPT_Shabbat_Keeper_Module`, files under `modules/shabbat-keeper/`, option `dpt_shabbat_keeper`. Module default `'0'` (off).
- No external HTTP calls anywhere in the module. No `ext/calendar` functions (`jdtojewish` etc.) - the plan ships its own conversion.
- Israel only: the eight Yom Tov keys in the spec table, one day each. Saturday is always a closure day.
- Users with `manage_options` are exempt unless previewing; filter `dpt_shabbat_keeper_exempt`. Preview is `?dpt_shabbat=preview` for `manage_options` only.
- Never touched: `is_admin()`, AJAX, cron, REST, WP-CLI, XML-RPC, `wp-login.php`. Commerce and form refusals still fire in REST (Store API) - they check closure, not request type.
- Closed mode answers front-end requests with HTTP 503 and `Retry-After`.
- Text domain `digitizer-pro-tools`. Every user-visible string goes through `__()`/`esc_html__()` and gets a Hebrew entry in the catalog. Vocabulary already in the catalog: WordPress is `וורדפרס`.
- Tests: plain PHP scripts `tests/sk-*-test.php` using `dpt_test_ok`, `dpt_test_eq`, `dpt_test_summary` from `tests/bootstrap.php`; each defines only the WordPress functions it needs, guarded by `function_exists`. Run all with `for f in tests/*-test.php; do php "$f" || exit 1; done`.
- Commit after every task with the attribution line `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Do not push, do not open the PR, do not release - the controller does that after the final review.
- phpcs: `vendor/bin/phpcs --standard=WordPress modules/shabbat-keeper` must be clean if `vendor/bin/phpcs` exists; otherwise skip and say so in the report.

---

## File map

| file | responsibility |
|---|---|
| `modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php` | Gregorian <-> Hebrew, leap years, month lengths, Yom Tov lookup |
| `modules/shabbat-keeper/class-dpt-sk-sun.php` | NOAA sunset for lat/lon/date, UTC timestamp |
| `modules/shabbat-keeper/class-dpt-sk-cities.php` | fixed city table |
| `modules/shabbat-keeper/class-dpt-sk-zmanim.php` | closure windows, `is_closed_at`, `current_window`, `next_transition` |
| `modules/shabbat-keeper/class-dpt-sk-settings.php` | option defaults, `all()`, `get()`, `text()`, `sanitize()`, `save()`, `install_defaults()` |
| `modules/shabbat-keeper/class-dpt-sk-enforce.php` | per-request decision, closed-mode 503, banner, contact CSS, cache header, transition cron |
| `modules/shabbat-keeper/class-dpt-sk-integrations.php` | WooCommerce, Elementor Pro Forms, CF7, WPForms, Gravity Forms |
| `modules/shabbat-keeper/class-dpt-sk-admin.php` | settings screen |
| `modules/shabbat-keeper/class-dpt-sk-module.php` | `DPT_Module` subclass, wiring |
| `modules/shabbat-keeper/views/closed-screen.php` | the 503 page |
| `modules/shabbat-keeper/assets/closed.css` | closed page + banner styles, inlined |
| `includes/class-dpt-plugin.php` | registry entry |
| `tests/sk-calendar-test.php`, `sk-sun-test.php`, `sk-zmanim-test.php`, `sk-settings-test.php`, `sk-enforce-test.php`, `sk-integrations-test.php` | tests |
| `languages/*`, `readme.txt`, `digitizer-pro-tools.php` | catalog, docs, version |

---

### Task 1: Hebrew calendar

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php`
- Test: `tests/sk-calendar-test.php`

**Interfaces:**
- Produces: `DPT_SK_Hebrew_Calendar::from_gregorian( int $y, int $m, int $d ): array{year:int, month:int, day:int}` (Nisan = 1 ... Adar = 12, Adar II = 13); `::to_gregorian( int $hy, int $hm, int $hd ): array{y:int, m:int, d:int}`; `::is_leap_year( int $hy ): bool`; `::holiday_on( int $y, int $m, int $d ): ?string` with the eight keys `rosh_hashana_1`, `rosh_hashana_2`, `yom_kippur`, `sukkot_1`, `shmini_atzeret`, `pesach_1`, `pesach_7`, `shavuot`.

- [ ] **Step 1: Write the failing test**

`tests/sk-calendar-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';

/* ---- known dates (Hebcal, checked 2026-09-10) ---- */
dpt_test_eq( DPT_SK_Hebrew_Calendar::to_gregorian( 5787, 7, 1 ), array( 'y' => 2026, 'm' => 9, 'd' => 12 ), '1 Tishrei 5787 is 2026-09-12' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::to_gregorian( 5786, 1, 15 ), array( 'y' => 2026, 'm' => 4, 'd' => 2 ), '15 Nisan 5786 is 2026-04-02' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::to_gregorian( 5786, 3, 6 ), array( 'y' => 2026, 'm' => 5, 'd' => 22 ), '6 Sivan 5786 is 2026-05-22' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::to_gregorian( 5787, 7, 10 ), array( 'y' => 2026, 'm' => 9, 'd' => 21 ), '10 Tishrei 5787 is 2026-09-21' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::to_gregorian( 5787, 7, 22 ), array( 'y' => 2026, 'm' => 10, 'd' => 3 ), '22 Tishrei 5787 is 2026-10-03' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::from_gregorian( 2026, 9, 12 ), array( 'year' => 5787, 'month' => 7, 'day' => 1 ), '2026-09-12 is 1 Tishrei 5787' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::from_gregorian( 2024, 3, 24 ), array( 'year' => 5784, 'month' => 13, 'day' => 14 ), '2024-03-24 is 14 Adar II 5784 (leap year, Purim)' );

/* ---- leap years ---- */
dpt_test_ok( DPT_SK_Hebrew_Calendar::is_leap_year( 5784 ), '5784 is a leap year' );
dpt_test_ok( ! DPT_SK_Hebrew_Calendar::is_leap_year( 5785 ), '5785 is not a leap year' );
dpt_test_ok( DPT_SK_Hebrew_Calendar::is_leap_year( 5787 ), '5787 is a leap year' );

/* ---- round trip over every day of 5784-5787 ---- */
$bad   = 0;
$start = gmmktime( 0, 0, 0, 9, 1, 2023 );
$end   = gmmktime( 0, 0, 0, 10, 15, 2027 );
for ( $ts = $start; $ts < $end; $ts += 86400 ) {
	$y = (int) gmdate( 'Y', $ts );
	$m = (int) gmdate( 'n', $ts );
	$d = (int) gmdate( 'j', $ts );
	$h = DPT_SK_Hebrew_Calendar::from_gregorian( $y, $m, $d );
	$g = DPT_SK_Hebrew_Calendar::to_gregorian( $h['year'], $h['month'], $h['day'] );
	if ( $g !== array( 'y' => $y, 'm' => $m, 'd' => $d ) ) {
		$bad++;
	}
}
dpt_test_eq( $bad, 0, 'every day round-trips' );

/* ---- holidays ---- */
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 9, 12 ), 'rosh_hashana_1', 'Rosh Hashana day 1' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 9, 13 ), 'rosh_hashana_2', 'Rosh Hashana day 2' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 9, 21 ), 'yom_kippur', 'Yom Kippur' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 9, 26 ), 'sukkot_1', 'Sukkot day 1' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 10, 3 ), 'shmini_atzeret', 'Shmini Atzeret' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 4, 2 ), 'pesach_1', 'Pesach day 1' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 4, 8 ), 'pesach_7', 'Pesach day 7' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 5, 22 ), 'shavuot', 'Shavuot' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 9, 27 ), null, 'Sukkot day 2 is Chol HaMoed in Israel, not a closure' );
dpt_test_eq( DPT_SK_Hebrew_Calendar::holiday_on( 2026, 3, 3 ), null, 'Purim is not a closure' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-calendar-test.php`
Expected: PHP fatal "Failed opening required" for the class file.

- [ ] **Step 3: Write the implementation**

`modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php`:

```php
<?php
/**
 * Hebrew calendar arithmetic with no dependency on ext/calendar.
 *
 * Fixed-day ("Rata Die") algorithms after Dershowitz & Reingold,
 * Calendrical Calculations. Months are numbered Nisan = 1 ... Elul = 6,
 * Tishrei = 7 ... Adar = 12, Adar II = 13 in a leap year.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Hebrew_Calendar {

	/** R.D. of 1 Tishrei, year 1. */
	const EPOCH = -1373427;

	/** R.D. of 1970-01-01, so Gregorian conversion can lean on gmmktime(). */
	const RD_UNIX = 719163;

	/** Hebrew month-day => Israeli Yom Tov key. */
	const HOLIDAYS = array(
		'7-1'  => 'rosh_hashana_1',
		'7-2'  => 'rosh_hashana_2',
		'7-10' => 'yom_kippur',
		'7-15' => 'sukkot_1',
		'7-22' => 'shmini_atzeret',
		'1-15' => 'pesach_1',
		'1-21' => 'pesach_7',
		'3-6'  => 'shavuot',
	);

	public static function is_leap_year( $y ) {
		return ( ( 7 * $y + 1 ) % 19 ) < 7;
	}

	public static function last_month( $y ) {
		return self::is_leap_year( $y ) ? 13 : 12;
	}

	/** Days from the epoch to the molad-based new year of $y, before the postponement rules. */
	private static function elapsed_days( $y ) {
		$months = intdiv( 235 * $y - 234, 19 );
		$parts  = 12084 + 13753 * $months;
		$day    = $months * 29 + intdiv( $parts, 25920 );
		if ( ( 3 * ( $day + 1 ) ) % 7 < 3 ) {
			$day++;
		}
		return $day;
	}

	/** The one- or two-day postponement that keeps year lengths legal. */
	private static function year_correction( $y ) {
		$ny0 = self::elapsed_days( $y - 1 );
		$ny1 = self::elapsed_days( $y );
		$ny2 = self::elapsed_days( $y + 1 );
		if ( 356 === $ny2 - $ny1 ) {
			return 2;
		}
		if ( 382 === $ny1 - $ny0 ) {
			return 1;
		}
		return 0;
	}

	private static function new_year( $y ) {
		return self::EPOCH + self::elapsed_days( $y ) + self::year_correction( $y );
	}

	public static function days_in_year( $y ) {
		return self::new_year( $y + 1 ) - self::new_year( $y );
	}

	public static function days_in_month( $y, $m ) {
		if ( in_array( $m, array( 2, 4, 6, 10, 13 ), true ) ) {
			return 29;
		}
		if ( 12 === $m && ! self::is_leap_year( $y ) ) {
			return 29;
		}
		$len = self::days_in_year( $y );
		if ( 8 === $m && ! in_array( $len, array( 355, 385 ), true ) ) {
			return 29; // Short Marheshvan.
		}
		if ( 9 === $m && in_array( $len, array( 353, 383 ), true ) ) {
			return 29; // Short Kislev.
		}
		return 30;
	}

	public static function fixed_from_hebrew( $y, $m, $d ) {
		$rd = self::new_year( $y ) + $d - 1;
		if ( $m < 7 ) {
			for ( $mm = 7; $mm <= self::last_month( $y ); $mm++ ) {
				$rd += self::days_in_month( $y, $mm );
			}
			for ( $mm = 1; $mm < $m; $mm++ ) {
				$rd += self::days_in_month( $y, $mm );
			}
		} else {
			for ( $mm = 7; $mm < $m; $mm++ ) {
				$rd += self::days_in_month( $y, $mm );
			}
		}
		return $rd;
	}

	public static function hebrew_from_fixed( $rd ) {
		$approx = intdiv( ( $rd - self::EPOCH ) * 98496, 35975351 ) + 1;
		$y      = $approx - 1;
		while ( self::new_year( $y + 1 ) <= $rd ) {
			$y++;
		}
		$m = ( $rd < self::fixed_from_hebrew( $y, 1, 1 ) ) ? 7 : 1;
		while ( $rd > self::fixed_from_hebrew( $y, $m, self::days_in_month( $y, $m ) ) ) {
			$m++;
		}
		$d = $rd - self::fixed_from_hebrew( $y, $m, 1 ) + 1;
		return array( 'year' => $y, 'month' => $m, 'day' => $d );
	}

	public static function fixed_from_gregorian( $y, $m, $d ) {
		return self::RD_UNIX + intdiv( gmmktime( 0, 0, 0, $m, $d, $y ), 86400 );
	}

	public static function gregorian_from_fixed( $rd ) {
		$ts = ( $rd - self::RD_UNIX ) * 86400;
		return array(
			'y' => (int) gmdate( 'Y', $ts ),
			'm' => (int) gmdate( 'n', $ts ),
			'd' => (int) gmdate( 'j', $ts ),
		);
	}

	public static function from_gregorian( $y, $m, $d ) {
		return self::hebrew_from_fixed( self::fixed_from_gregorian( $y, $m, $d ) );
	}

	public static function to_gregorian( $hy, $hm, $hd ) {
		return self::gregorian_from_fixed( self::fixed_from_hebrew( $hy, $hm, $hd ) );
	}

	/**
	 * The Israeli Yom Tov falling on a Gregorian civil date, or null.
	 */
	public static function holiday_on( $y, $m, $d ) {
		$h   = self::from_gregorian( $y, $m, $d );
		$key = $h['month'] . '-' . $h['day'];
		return isset( self::HOLIDAYS[ $key ] ) ? self::HOLIDAYS[ $key ] : null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/sk-calendar-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority).

- [ ] **Step 5: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php tests/sk-calendar-test.php
git commit -m "Shabbat Keeper: Hebrew calendar with no ext/calendar

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Sunset

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-sun.php`
- Test: `tests/sk-sun-test.php`

**Interfaces:**
- Produces: `DPT_SK_Sun::sunset( float $lat, float $lon, int $y, int $m, int $d ): ?int` - UTC timestamp of sunset on that civil date (east longitude positive), null when the sun does not set.

- [ ] **Step 1: Write the failing test**

`tests/sk-sun-test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-sun-test.php`
Expected: fatal, class file missing.

- [ ] **Step 3: Write the implementation**

`modules/shabbat-keeper/class-dpt-sk-sun.php`:

```php
<?php
/**
 * Sunset by the NOAA solar position algorithm (the "NOAA_Solar_Calculations"
 * spreadsheet), accurate to about a minute for the latitudes of Israel.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Sun {

	/** Zenith angle for sunset: 90 deg plus 50 arcminutes of refraction and solar radius. */
	const ZENITH = 90.833;

	/**
	 * UTC timestamp of sunset on a civil date, or null when the sun does not
	 * set (polar day/night). Longitude east is positive.
	 */
	public static function sunset( $lat, $lon, $y, $m, $d ) {
		$midnight = gmmktime( 0, 0, 0, (int) $m, (int) $d, (int) $y );
		$jd0      = $midnight / 86400 + 2440587.5;
		$minutes  = 720.0;

		// Two passes: the first with the sun at noon, the second at the
		// sunset the first pass found, so the position is taken at the
		// right moment.
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$t    = ( $jd0 + $minutes / 1440 - 2451545.0 ) / 36525;
			$l0   = fmod( 280.46646 + $t * ( 36000.76983 + $t * 0.0003032 ), 360 );
			$ma   = 357.52911 + $t * ( 35999.05029 - 0.0001537 * $t );
			$e    = 0.016708634 - $t * ( 0.000042037 + 0.0000001267 * $t );
			$c    = sin( deg2rad( $ma ) ) * ( 1.914602 - $t * ( 0.004817 + 0.000014 * $t ) )
				+ sin( deg2rad( 2 * $ma ) ) * ( 0.019993 - 0.000101 * $t )
				+ sin( deg2rad( 3 * $ma ) ) * 0.000289;
			$lam  = $l0 + $c - 0.00569 - 0.00478 * sin( deg2rad( 125.04 - 1934.136 * $t ) );
			$eps0 = 23 + ( 26 + ( 21.448 - $t * ( 46.815 + $t * ( 0.00059 - $t * 0.001813 ) ) ) / 60 ) / 60;
			$eps  = $eps0 + 0.00256 * cos( deg2rad( 125.04 - 1934.136 * $t ) );
			$dec  = rad2deg( asin( sin( deg2rad( $eps ) ) * sin( deg2rad( $lam ) ) ) );
			$vy   = tan( deg2rad( $eps / 2 ) ) ** 2;
			$eqt  = 4 * rad2deg(
				$vy * sin( 2 * deg2rad( $l0 ) )
				- 2 * $e * sin( deg2rad( $ma ) )
				+ 4 * $e * $vy * sin( deg2rad( $ma ) ) * cos( 2 * deg2rad( $l0 ) )
				- 0.5 * $vy * $vy * sin( 4 * deg2rad( $l0 ) )
				- 1.25 * $e * $e * sin( 2 * deg2rad( $ma ) )
			);

			$cos_ha = cos( deg2rad( self::ZENITH ) ) / ( cos( deg2rad( $lat ) ) * cos( deg2rad( $dec ) ) )
				- tan( deg2rad( $lat ) ) * tan( deg2rad( $dec ) );
			if ( $cos_ha < -1 || $cos_ha > 1 ) {
				return null;
			}
			$ha      = rad2deg( acos( $cos_ha ) );
			$minutes = 720 - 4 * $lon - $eqt + 4 * $ha;
		}

		return $midnight + (int) round( $minutes * 60 );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/sk-sun-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority). If a Jerusalem case misses by more than two minutes, the transcription is wrong - compare against the code above character by character; the reference values were produced by exactly this code and cross-checked against published candle-lighting tables (Jerusalem sunset 18:50 on 2026-09-11 gives the published 18:10 candle lighting at 40 minutes).

- [ ] **Step 5: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-sun.php tests/sk-sun-test.php
git commit -m "Shabbat Keeper: sunset by the NOAA algorithm

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Cities and closure windows

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-cities.php`
- Create: `modules/shabbat-keeper/class-dpt-sk-zmanim.php`
- Test: `tests/sk-zmanim-test.php`

**Interfaces:**
- Consumes: `DPT_SK_Hebrew_Calendar::holiday_on`, `DPT_SK_Sun::sunset`.
- Produces: `DPT_SK_Cities::all(): array<slug, array{name:string, lat:float, lon:float, candle:int}>`; `DPT_SK_Cities::get( string $slug ): ?array`. `new DPT_SK_Zmanim( float $lat, float $lon, int $candle_minutes, int $havdalah_minutes )` with `windows( int $from_ts, int $to_ts ): array<array{start:int, end:int, reason:string}>`, `is_closed_at( int $ts ): bool`, `current_window( int $ts ): ?array`, `next_transition( int $ts ): int`.

- [ ] **Step 1: Write the failing test**

`tests/sk-zmanim-test.php`:

```php
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

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-zmanim-test.php`
Expected: fatal, cities file missing.

- [ ] **Step 3: Write the cities table**

`modules/shabbat-keeper/class-dpt-sk-cities.php`:

```php
<?php
/**
 * Cities a site can pick its Shabbat times for. Coordinates are the city
 * centre to three decimals; a kilometre moves sunset by well under a
 * minute. Candle-lighting minutes follow the custom published for each
 * city.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Cities {

	public static function all() {
		return array(
			'jerusalem'   => array( 'name' => __( 'Jerusalem', 'digitizer-pro-tools' ),   'lat' => 31.778, 'lon' => 35.235, 'candle' => 40 ),
			'tel_aviv'    => array( 'name' => __( 'Tel Aviv', 'digitizer-pro-tools' ),    'lat' => 32.080, 'lon' => 34.780, 'candle' => 18 ),
			'haifa'       => array( 'name' => __( 'Haifa', 'digitizer-pro-tools' ),       'lat' => 32.794, 'lon' => 34.990, 'candle' => 30 ),
			'beer_sheva'  => array( 'name' => __( 'Beer Sheva', 'digitizer-pro-tools' ),  'lat' => 31.252, 'lon' => 34.791, 'candle' => 22 ),
			'eilat'       => array( 'name' => __( 'Eilat', 'digitizer-pro-tools' ),       'lat' => 29.558, 'lon' => 34.948, 'candle' => 18 ),
			'netanya'     => array( 'name' => __( 'Netanya', 'digitizer-pro-tools' ),     'lat' => 32.332, 'lon' => 34.860, 'candle' => 18 ),
			'petah_tikva' => array( 'name' => __( 'Petah Tikva', 'digitizer-pro-tools' ), 'lat' => 32.089, 'lon' => 34.888, 'candle' => 18 ),
			'ashdod'      => array( 'name' => __( 'Ashdod', 'digitizer-pro-tools' ),      'lat' => 31.804, 'lon' => 34.655, 'candle' => 18 ),
			'bnei_brak'   => array( 'name' => __( 'Bnei Brak', 'digitizer-pro-tools' ),   'lat' => 32.084, 'lon' => 34.834, 'candle' => 18 ),
			'modiin'      => array( 'name' => __( 'Modiin', 'digitizer-pro-tools' ),      'lat' => 31.898, 'lon' => 35.010, 'candle' => 18 ),
			'safed'       => array( 'name' => __( 'Safed', 'digitizer-pro-tools' ),       'lat' => 32.965, 'lon' => 35.496, 'candle' => 18 ),
			'tiberias'    => array( 'name' => __( 'Tiberias', 'digitizer-pro-tools' ),    'lat' => 32.796, 'lon' => 35.531, 'candle' => 18 ),
			'rehovot'     => array( 'name' => __( 'Rehovot', 'digitizer-pro-tools' ),     'lat' => 31.895, 'lon' => 34.809, 'candle' => 18 ),
			'hadera'      => array( 'name' => __( 'Hadera', 'digitizer-pro-tools' ),      'lat' => 32.434, 'lon' => 34.920, 'candle' => 18 ),
			'kfar_saba'   => array( 'name' => __( 'Kfar Saba', 'digitizer-pro-tools' ),   'lat' => 32.175, 'lon' => 34.907, 'candle' => 18 ),
		);
	}

	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}
}
```

- [ ] **Step 4: Write the windows engine**

`modules/shabbat-keeper/class-dpt-sk-zmanim.php`:

```php
<?php
/**
 * Closure windows: from candle lighting before a closure day to Havdalah
 * after it. A closure day is Saturday or an Israeli Yom Tov; adjacent
 * closure days share one window.
 *
 * Pure PHP. Timestamps are Unix UTC; civil dates are read in
 * Asia/Jerusalem, whose DST the DateTimeZone data handles.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Zmanim {

	const TIMEZONE = 'Asia/Jerusalem';

	/** @var float */
	private $lat;
	/** @var float */
	private $lon;
	/** @var int */
	private $candle;
	/** @var int */
	private $havdalah;
	/** @var DateTimeZone */
	private $tz;

	public function __construct( $lat, $lon, $candle_minutes, $havdalah_minutes ) {
		$this->lat      = (float) $lat;
		$this->lon      = (float) $lon;
		$this->candle   = (int) $candle_minutes;
		$this->havdalah = (int) $havdalah_minutes;
		$this->tz       = new DateTimeZone( self::TIMEZONE );
	}

	/**
	 * Closure windows overlapping [$from_ts, $to_ts], in order.
	 *
	 * @return array<int, array{start:int, end:int, reason:string}>
	 */
	public function windows( $from_ts, $to_ts ) {
		// Walk civil days from two days before the range (a window can start
		// the evening before a closure day that itself precedes the range)
		// to one day after it.
		$day = new DateTime( '@' . (int) $from_ts );
		$day->setTimezone( $this->tz );
		$day->setTime( 0, 0, 0 );
		$day->modify( '-2 days' );

		$limit = new DateTime( '@' . (int) $to_ts );
		$limit->setTimezone( $this->tz );
		$limit->setTime( 0, 0, 0 );
		$limit->modify( '+2 days' );

		$runs = array(); // Consecutive closure days: [ [ 'days' => [DateTime...], 'reasons' => [] ], ... ].
		$open = null;
		while ( $day <= $limit ) {
			$reason = $this->closure_reason( $day );
			if ( null !== $reason ) {
				if ( null === $open ) {
					$open = array( 'days' => array(), 'reasons' => array() );
				}
				$open['days'][]    = clone $day;
				$open['reasons'][] = $reason;
			} elseif ( null !== $open ) {
				$runs[] = $open;
				$open   = null;
			}
			$day->modify( '+1 day' );
		}
		if ( null !== $open ) {
			$runs[] = $open;
		}

		$out = array();
		foreach ( $runs as $run ) {
			$first = $run['days'][0];
			$last  = $run['days'][ count( $run['days'] ) - 1 ];
			$eve   = clone $first;
			$eve->modify( '-1 day' );

			$start = $this->sunset_of( $eve );
			$end   = $this->sunset_of( $last );
			if ( null === $start || null === $end ) {
				continue;
			}
			$start -= $this->candle * 60;
			$end   += $this->havdalah * 60;

			if ( $end <= $from_ts || $start > $to_ts ) {
				continue;
			}
			$out[] = array(
				'start'  => $start,
				'end'    => $end,
				'reason' => implode( '+', $run['reasons'] ),
			);
		}
		return $out;
	}

	public function is_closed_at( $ts ) {
		return null !== $this->current_window( $ts );
	}

	/** @return array{start:int, end:int, reason:string}|null */
	public function current_window( $ts ) {
		foreach ( $this->windows( $ts, $ts ) as $w ) {
			if ( $w['start'] <= $ts && $ts < $w['end'] ) {
				return $w;
			}
		}
		return null;
	}

	/** The next start or end strictly after $ts. */
	public function next_transition( $ts ) {
		$ts = (int) $ts;
		foreach ( $this->windows( $ts, $ts + 60 * DAY_IN_SECONDS ) as $w ) {
			if ( $ts < $w['start'] ) {
				return $w['start'];
			}
			if ( $ts < $w['end'] ) {
				return $w['end'];
			}
		}
		return $ts + 7 * DAY_IN_SECONDS; // Unreachable: there is a Shabbat every week.
	}

	/** 'shabbat', a holiday key, or null for a civil day in Asia/Jerusalem. */
	private function closure_reason( DateTime $day ) {
		if ( '6' === $day->format( 'N' ) ) {
			return 'shabbat';
		}
		return DPT_SK_Hebrew_Calendar::holiday_on( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
	}

	private function sunset_of( DateTime $day ) {
		return DPT_SK_Sun::sunset( $this->lat, $this->lon, (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
	}
}
```

A Saturday that is also a Yom Tov (Rosh Hashana 5787 day 1 falls on Shabbat 12.9) reports `shabbat`, because `closure_reason` checks the weekday first; it is a closure day either way, so the merged window for 11-13 September reads `shabbat+rosh_hashana_2`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/sk-zmanim-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority).

- [ ] **Step 6: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-cities.php modules/shabbat-keeper/class-dpt-sk-zmanim.php tests/sk-zmanim-test.php
git commit -m "Shabbat Keeper: cities and closure windows

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Settings, module shell, registry entry

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-settings.php`
- Create: `modules/shabbat-keeper/class-dpt-sk-module.php`
- Modify: `includes/class-dpt-plugin.php` (the `copy_url` entry ends around line 131)
- Test: `tests/sk-settings-test.php`

**Interfaces:**
- Consumes: `DPT_SK_Cities::get`.
- Produces: `DPT_SK_Settings::OPTION` (`'dpt_shabbat_keeper'`), `::defaults(): array`, `::all(): array`, `::get( string $key )`, `::text( string $key ): string` (saved text or the translated default for `closed_title`, `closed_message`, `banner_text`), `::sanitize( array $raw ): array`, `::save( array $raw ): void`, `::install_defaults(): void`, `::location(): array{name, lat, lon, candle}`. Class `DPT_Shabbat_Keeper_Module` with an empty `init()` that Tasks 5-7 fill.

- [ ] **Step 1: Write the failing test**

`tests/sk-settings-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value ) { return update_option( $key, $value ); }
}

/* ---- defaults ---- */
$d = DPT_SK_Settings::defaults();
dpt_test_eq( $d['mode'], 'business', 'default mode is business' );
dpt_test_eq( $d['city'], 'jerusalem', 'default city' );
dpt_test_eq( $d['havdalah'], '42', 'default havdalah' );
dpt_test_eq( $d['override'], 'auto', 'default override' );
dpt_test_eq( $d['hide_contact'], '0', 'contact links shown by default' );
dpt_test_eq( $d['block_woo'], '1', 'woo blocked by default' );
dpt_test_eq( count( $d ), 15, 'fifteen keys' );

/* ---- install_defaults seeds, then only fills gaps ---- */
DPT_SK_Settings::install_defaults();
dpt_test_eq( get_option( DPT_SK_Settings::OPTION ), $d, 'seeded with defaults' );
update_option( DPT_SK_Settings::OPTION, array( 'mode' => 'closed', 'stale' => 'x' ) );
DPT_SK_Settings::install_defaults();
$o = get_option( DPT_SK_Settings::OPTION );
dpt_test_eq( $o['mode'], 'closed', 'existing value kept' );
dpt_test_eq( $o['city'], 'jerusalem', 'missing key filled' );
dpt_test_ok( ! isset( $o['stale'] ), 'unknown key dropped' );

/* ---- sanitize ---- */
$c = DPT_SK_Settings::sanitize( array(
	'mode'          => 'banana',
	'city'          => 'atlantis',
	'havdalah'      => '99',
	'override'      => 'maybe',
	'custom_lat'    => '123.4',
	'custom_lon'    => '-200',
	'custom_candle' => '500',
	'block_woo'     => 'yes',
	'banner_on'     => '0',
	'closed_title'  => "  <b>Closed</b>\n",
	'closed_message' => '<p>Hello</p><script>alert(1)</script>',
	'banner_text'   => 'Back at %s',
	'evil'          => '1',
) );
dpt_test_eq( $c['mode'], 'business', 'unknown mode falls back' );
dpt_test_eq( $c['city'], 'jerusalem', 'unknown city falls back' );
dpt_test_eq( $c['havdalah'], '42', 'unknown havdalah falls back' );
dpt_test_eq( $c['override'], 'auto', 'unknown override falls back' );
dpt_test_eq( $c['custom_lat'], '90', 'latitude clamped' );
dpt_test_eq( $c['custom_lon'], '-180', 'longitude clamped' );
dpt_test_eq( $c['custom_candle'], '60', 'candle minutes clamped' );
dpt_test_eq( $c['block_woo'], '1', 'truthy bool is 1' );
dpt_test_eq( $c['banner_on'], '0', 'zero bool is 0' );
dpt_test_eq( $c['closed_title'], 'Closed', 'title is plain text, trimmed' );
dpt_test_ok( false === strpos( $c['closed_message'], '<script' ), 'script stripped from message' );
dpt_test_ok( false !== strpos( $c['closed_message'], '<p>' ), 'paragraph kept in message' );
dpt_test_eq( $c['banner_text'], 'Back at %s', 'banner keeps its placeholder' );
dpt_test_ok( ! isset( $c['evil'] ), 'unknown key dropped' );

$c = DPT_SK_Settings::sanitize( array( 'mode' => 'closed', 'city' => 'custom', 'havdalah' => '72', 'override' => 'force_closed', 'custom_lat' => '31.5', 'custom_lon' => '34.9', 'custom_candle' => '22' ) );
dpt_test_eq( $c['mode'], 'closed', 'valid mode kept' );
dpt_test_eq( $c['city'], 'custom', 'custom city kept' );
dpt_test_eq( $c['havdalah'], '72', 'Rabbeinu Tam kept' );
dpt_test_eq( $c['override'], 'force_closed', 'force kept' );
dpt_test_eq( $c['custom_lat'], '31.5', 'latitude kept' );

/* ---- text fallbacks ---- */
DPT_SK_Settings::save( array( 'closed_title' => '' ) );
dpt_test_eq( DPT_SK_Settings::text( 'closed_title' ), 'The site is closed for Shabbat', 'empty title falls back to the translated default' );
dpt_test_ok( false !== strpos( DPT_SK_Settings::text( 'banner_text' ), '%s' ), 'default banner text carries the placeholder' );
DPT_SK_Settings::save( array( 'closed_title' => 'Shhh' ) );
dpt_test_eq( DPT_SK_Settings::text( 'closed_title' ), 'Shhh', 'saved title wins' );

/* ---- location ---- */
DPT_SK_Settings::save( array( 'city' => 'haifa' ) );
dpt_test_eq( DPT_SK_Settings::location()['candle'], 30, 'city location comes from the table' );
DPT_SK_Settings::save( array( 'city' => 'custom', 'custom_lat' => '31.5', 'custom_lon' => '34.9', 'custom_candle' => '25' ) );
$loc = DPT_SK_Settings::location();
dpt_test_eq( array( $loc['lat'], $loc['lon'], $loc['candle'] ), array( 31.5, 34.9, 25 ), 'custom location comes from settings' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-settings-test.php`
Expected: fatal, settings file missing.

- [ ] **Step 3: Write the settings class**

`modules/shabbat-keeper/class-dpt-sk-settings.php`:

```php
<?php
/**
 * Shabbat Keeper settings: one option, whitelisted on the way in.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Settings {

	const OPTION = 'dpt_shabbat_keeper';

	const MODES     = array( 'closed', 'business' );
	const HAVDALAH  = array( '42', '72' );
	const OVERRIDES = array( 'auto', 'force_open', 'force_closed' );
	const BOOLS     = array( 'closed_show_times', 'block_woo', 'block_forms', 'hide_contact', 'banner_on' );

	public static function defaults() {
		return array(
			'mode'              => 'business',
			'city'              => 'jerusalem',
			'custom_lat'        => '0',
			'custom_lon'        => '0',
			'custom_candle'     => '18',
			'havdalah'          => '42',
			'override'          => 'auto',
			'closed_title'      => '',
			'closed_message'    => '',
			'closed_show_times' => '1',
			'block_woo'         => '1',
			'block_forms'       => '1',
			'hide_contact'      => '0',
			'banner_on'         => '1',
			'banner_text'       => '',
		);
	}

	/** Seed on activation; on upgrade fill missing keys and drop unknown ones. */
	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		$merged = array_merge( self::defaults(), array_intersect_key( $existing, self::defaults() ) );
		if ( $merged !== $existing ) {
			update_option( self::OPTION, $merged );
		}
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $opts, self::defaults() ) );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** A saved text, or its translated default when the site left it empty. */
	public static function text( $key ) {
		$saved = trim( (string) self::get( $key ) );
		if ( '' !== $saved ) {
			return $saved;
		}
		switch ( $key ) {
			case 'closed_title':
				return __( 'The site is closed for Shabbat', 'digitizer-pro-tools' );
			case 'closed_message':
				return __( 'We observe Shabbat and Jewish holidays. The site reopens automatically after Havdalah.', 'digitizer-pro-tools' );
			case 'banner_text':
				/* translators: %s: the day and time the site reopens. */
				return __( 'The site is closed for Shabbat. Orders and forms reopen at %s.', 'digitizer-pro-tools' );
		}
		return '';
	}

	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$d     = self::defaults();
		$clean = array();

		$clean['mode']     = isset( $raw['mode'] ) && in_array( $raw['mode'], self::MODES, true ) ? $raw['mode'] : $d['mode'];
		$clean['havdalah'] = isset( $raw['havdalah'] ) && in_array( (string) $raw['havdalah'], self::HAVDALAH, true ) ? (string) $raw['havdalah'] : $d['havdalah'];
		$clean['override'] = isset( $raw['override'] ) && in_array( $raw['override'], self::OVERRIDES, true ) ? $raw['override'] : $d['override'];

		$city          = isset( $raw['city'] ) ? sanitize_key( $raw['city'] ) : '';
		$clean['city'] = ( 'custom' === $city || null !== DPT_SK_Cities::get( $city ) ) ? $city : $d['city'];

		$clean['custom_lat']    = (string) self::clamp_float( isset( $raw['custom_lat'] ) ? $raw['custom_lat'] : 0, -90, 90 );
		$clean['custom_lon']    = (string) self::clamp_float( isset( $raw['custom_lon'] ) ? $raw['custom_lon'] : 0, -180, 180 );
		$clean['custom_candle'] = (string) max( 0, min( 60, absint( isset( $raw['custom_candle'] ) ? $raw['custom_candle'] : 18 ) ) );

		foreach ( self::BOOLS as $key ) {
			$clean[ $key ] = ! empty( $raw[ $key ] ) ? '1' : '0';
		}

		$clean['closed_title']   = isset( $raw['closed_title'] ) ? sanitize_text_field( wp_unslash( $raw['closed_title'] ) ) : '';
		$clean['closed_message'] = isset( $raw['closed_message'] ) ? wp_kses_post( wp_unslash( $raw['closed_message'] ) ) : '';
		$clean['banner_text']    = isset( $raw['banner_text'] ) ? sanitize_text_field( wp_unslash( $raw['banner_text'] ) ) : '';

		return $clean;
	}

	/** Replace the option with a sanitized copy of the submitted fields, defaults filling the rest. */
	public static function save( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $raw ) ) );
	}

	/** @return array{name:string, lat:float, lon:float, candle:int} */
	public static function location() {
		$o = self::all();
		if ( 'custom' === $o['city'] ) {
			return array(
				'name'   => __( 'Custom location', 'digitizer-pro-tools' ),
				'lat'    => (float) $o['custom_lat'],
				'lon'    => (float) $o['custom_lon'],
				'candle' => (int) $o['custom_candle'],
			);
		}
		$city = DPT_SK_Cities::get( $o['city'] );
		return $city ? $city : DPT_SK_Cities::get( 'jerusalem' );
	}

	private static function clamp_float( $v, $min, $max ) {
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		return max( $min, min( $max, $v ) );
	}
}
```

Note on `save()`: it merges the submitted keys over the current values before sanitizing, so a form that posts only some keys does not reset the others - the tests in Step 1 rely on that when they save one key at a time. Bools therefore must always be posted (the admin screen posts a hidden `0` before each checkbox, as Cookie Banner does).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/sk-settings-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority). If `custom_lat` compares as `'90'` vs `'90.0'`: PHP casts `(string) 90.0` to `'90'`, which is what the test expects; `(string) 31.5` is `'31.5'`.

- [ ] **Step 5: Write the module shell**

`modules/shabbat-keeper/class-dpt-sk-module.php`:

```php
<?php
/**
 * Shabbat Keeper module - closes the site, or only its shop and forms,
 * from candle lighting to Havdalah, with the times computed here.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-sk-hebrew-calendar.php';
require_once __DIR__ . '/class-dpt-sk-sun.php';
require_once __DIR__ . '/class-dpt-sk-cities.php';
require_once __DIR__ . '/class-dpt-sk-zmanim.php';
require_once __DIR__ . '/class-dpt-sk-settings.php';

class DPT_Shabbat_Keeper_Module extends DPT_Module {

	public function id() {
		return 'shabbat_keeper';
	}

	public function title() {
		return __( 'Shabbat Keeper', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for the city you pick; nothing is sent anywhere.', 'digitizer-pro-tools' );
	}

	public function init() {
	}

	public function install_defaults() {
		DPT_SK_Settings::install_defaults();
	}
}
```

- [ ] **Step 6: Register the module**

In `includes/class-dpt-plugin.php`, directly after the `copy_url` entry (the one whose `'file'` is `modules/copy-url/class-dpt-cu-module.php`) and before the closing `);` of the array, add:

```php
			'shabbat_keeper' => array(
				'file'    => DPT_PATH . 'modules/shabbat-keeper/class-dpt-sk-module.php',
				'class'   => 'DPT_Shabbat_Keeper_Module',
				'default' => '0',
			),
```

- [ ] **Step 7: Syntax check and run every test**

Run: `php -l includes/class-dpt-plugin.php && php -l modules/shabbat-keeper/class-dpt-sk-module.php && for f in tests/*-test.php; do php "$f" > /dev/null || { echo "FAIL $f"; break; }; done; echo done`
Expected: no syntax errors, `done` with no `FAIL` line.

- [ ] **Step 8: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-settings.php modules/shabbat-keeper/class-dpt-sk-module.php includes/class-dpt-plugin.php tests/sk-settings-test.php
git commit -m "Shabbat Keeper: settings, module shell, registry entry

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Enforcement - the per-request decision, closed screen, banner, cache header, transition cron

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-enforce.php`
- Create: `modules/shabbat-keeper/views/closed-screen.php`
- Create: `modules/shabbat-keeper/assets/closed.css`
- Modify: `modules/shabbat-keeper/class-dpt-sk-module.php` (require + `init()`)
- Test: `tests/sk-enforce-test.php`

**Interfaces:**
- Consumes: `DPT_SK_Settings::all/get/text/location`, `DPT_SK_Zmanim`.
- Produces: `DPT_SK_Enforce::register()`, `::reset()`, `::zmanim(): DPT_SK_Zmanim`, `::now(): int` (filter `dpt_shabbat_keeper_now`), `::is_closed(): bool`, `::is_exempt(): bool`, `::applies(): bool`, `::is_front_request(): bool`, `::is_preview(): bool`, `::should_block(): bool`, `::closed_headers(): string[]`, `::render_closed_screen(): string`, `::reopens_at(): ?int`, `::reopens_text(): string`, `::banner_html(): string`, `::cache_max_age(): ?int`, `::purge_caches(): void`, constant `CRON_HOOK = 'dpt_sk_transition'`, action `dpt_shabbat_keeper_transition( bool $now_closed )`, filter `dpt_shabbat_keeper_exempt( bool )`.

- [ ] **Step 1: Write the failing test**

`tests/sk-enforce-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-zmanim.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-enforce.php';

/* The slice of WordPress this class touches and the harness does not stub. */
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v ) { return update_option( $k, $v ); } }
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) { $args = func_get_args(); $GLOBALS['dpt_stub_actions_fired'][] = $args; call_user_func_array( 'apply_filters', array_merge( array( $tag, null ), array_slice( $args, 1 ) ) ); }
}
$GLOBALS['dpt_stub_actions_fired'] = array();
function wp_doing_ajax() { return (bool) $GLOBALS['dpt_stub_doing_ajax']; }
$GLOBALS['dpt_stub_doing_ajax'] = false;
function status_header( $code ) { $GLOBALS['dpt_stub_status'] = (int) $code; }
function nocache_headers() { $GLOBALS['dpt_stub_nocache'] = true; }
function wp_date( $format, $ts, $tz = null ) { $d = new DateTime( '@' . $ts ); $d->setTimezone( $tz ? $tz : new DateTimeZone( 'Asia/Jerusalem' ) ); return $d->format( $format ); }
function is_rtl() { return true; }
function get_site_icon_url( $size = 512 ) { return ''; }
function get_bloginfo( $show = '' ) { return 'Example Site'; }
function language_attributes() { echo 'lang="he-IL" dir="rtl"'; }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['dpt_stub_cron'][ $hook ] ) ? $GLOBALS['dpt_stub_cron'][ $hook ] : false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['dpt_stub_cron'][ $hook ] = $ts; return true; }
$GLOBALS['dpt_stub_cron'] = array();
$GLOBALS['dpt_stub_is_admin'] = false;
if ( ! function_exists( "__return_false" ) ) { function __return_false() { return false; } }

$tz = new DateTimeZone( 'Asia/Jerusalem' );
$at = function ( $local ) use ( $tz ) { return ( new DateTime( $local, $tz ) )->getTimestamp(); };
$clock = $at( '2026-09-02 12:00' ); // Wednesday.
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );

function sk_set( $pairs ) {
	DPT_SK_Settings::save( $pairs );
	DPT_SK_Enforce::reset();
}
function sk_anon() { $GLOBALS['dpt_stub_no_user'] = true; $GLOBALS['dpt_stub_denied_caps'] = array(); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }
function sk_admin() { $GLOBALS['dpt_stub_no_user'] = false; $GLOBALS['dpt_stub_denied_caps'] = array(); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }
function sk_editor() { $GLOBALS['dpt_stub_no_user'] = false; $GLOBALS['dpt_stub_denied_caps'] = array( 'manage_options' ); unset( $_GET['dpt_shabbat'] ); DPT_SK_Enforce::reset(); }

DPT_SK_Settings::install_defaults();
$z = DPT_SK_Enforce::zmanim();
$shabbat = $z->windows( $at( '2026-09-04 00:00' ), $at( '2026-09-06 00:00' ) )[0];

/* ---- Wednesday, anonymous: open ---- */
sk_anon();
dpt_test_ok( ! DPT_SK_Enforce::is_closed(), 'Wednesday is open' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'nothing to block on Wednesday' );
dpt_test_eq( DPT_SK_Enforce::banner_html(), '', 'no banner when open' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), $shabbat['start'] - $clock, 'cache lives until candle lighting' );

/* ---- Shabbat noon, business mode, anonymous ---- */
$clock = $at( '2026-09-05 12:00' );
sk_anon();
dpt_test_ok( DPT_SK_Enforce::is_closed(), 'Shabbat noon is closed' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'applies to a visitor' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'business mode does not block the page' );
dpt_test_eq( DPT_SK_Enforce::reopens_at(), $shabbat['end'], 'reopens at Havdalah' );
$banner = DPT_SK_Enforce::banner_html();
dpt_test_ok( false !== strpos( $banner, 'dpt-sk-banner' ), 'banner markup' );
dpt_test_ok( false !== strpos( $banner, wp_date( 'H:i', $shabbat['end'] ) ), 'banner names the reopening time' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), $shabbat['end'] - $clock, 'cache lives until Havdalah' );
ob_start(); DPT_SK_Enforce::print_banner(); DPT_SK_Enforce::print_banner_fallback(); $out = ob_get_clean();
dpt_test_eq( substr_count( $out, 'dpt-sk-banner' ), 1, 'banner printed once even with the fallback' );

/* ---- banner off ---- */
sk_set( array( 'banner_on' => '0' ) );
sk_anon();
dpt_test_eq( DPT_SK_Enforce::banner_html(), '', 'banner switched off' );
sk_set( array( 'banner_on' => '1' ) );

/* ---- contact CSS ---- */
sk_anon();
ob_start(); DPT_SK_Enforce::print_head_css(); $head = ob_get_clean();
dpt_test_ok( false === strpos( $head, 'tel:' ), 'contact links untouched by default' );
sk_set( array( 'hide_contact' => '1' ) );
sk_anon();
ob_start(); DPT_SK_Enforce::print_head_css(); $head = ob_get_clean();
dpt_test_ok( false !== strpos( $head, 'a[href^="tel:"]' ), 'contact links hidden when asked' );
sk_set( array( 'hide_contact' => '0' ) );

/* ---- Shabbat, closed mode, anonymous: 503 ---- */
sk_set( array( 'mode' => 'closed' ) );
sk_anon();
dpt_test_ok( DPT_SK_Enforce::should_block(), 'closed mode blocks the page' );
dpt_test_eq( DPT_SK_Enforce::closed_headers(), array( 'Retry-After: ' . ( $shabbat['end'] - $clock ) ), 'Retry-After until Havdalah' );
$html = DPT_SK_Enforce::render_closed_screen();
dpt_test_ok( false !== strpos( $html, 'The site is closed for Shabbat' ), 'closed screen carries the title' );
dpt_test_ok( false !== strpos( $html, 'dir="rtl"' ), 'closed screen is RTL on an RTL site' );
dpt_test_ok( false !== strpos( $html, 'Jerusalem' ), 'closed screen names the city' );
dpt_test_ok( false !== strpos( $html, '<style' ), 'styles inlined' );

/* ---- exemptions ---- */
sk_admin();
dpt_test_ok( ! DPT_SK_Enforce::applies(), 'administrator is exempt' );
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'administrator not blocked' );
sk_editor();
dpt_test_ok( DPT_SK_Enforce::applies(), 'editor is not exempt' );
sk_admin();
add_filter( 'dpt_shabbat_keeper_exempt', '__return_false' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'filter can revoke the exemption' );
remove_filter( 'dpt_shabbat_keeper_exempt', '__return_false' );

/* ---- requests never touched ---- */
sk_anon();
$GLOBALS['dpt_stub_is_admin'] = true;
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'wp-admin never blocked' );
$GLOBALS['dpt_stub_is_admin'] = false;
$GLOBALS['dpt_stub_doing_ajax'] = true;
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'ajax never blocked' );
$GLOBALS['dpt_stub_doing_ajax'] = false;
$GLOBALS['pagenow'] = 'wp-login.php';
dpt_test_ok( ! DPT_SK_Enforce::should_block(), 'login never blocked' );
unset( $GLOBALS['pagenow'] );
dpt_test_ok( DPT_SK_Enforce::should_block(), 'plain front request blocked again' );

/* ---- preview and overrides ---- */
$clock = $at( '2026-09-02 12:00' );
sk_admin();
$_GET['dpt_shabbat'] = 'preview';
DPT_SK_Enforce::reset();
dpt_test_ok( DPT_SK_Enforce::is_preview(), 'administrator preview recognised' );
dpt_test_ok( DPT_SK_Enforce::applies(), 'preview overrides the exemption' );
dpt_test_ok( DPT_SK_Enforce::should_block(), 'preview shows the closed site on a Wednesday' );
dpt_test_eq( DPT_SK_Enforce::reopens_at(), null, 'no reopening time outside a real window' );
dpt_test_ok( false !== strpos( DPT_SK_Enforce::render_closed_screen(), 'after Havdalah' ), 'closed screen says after Havdalah when there is no window' );
sk_editor();
$_GET['dpt_shabbat'] = 'preview';
DPT_SK_Enforce::reset();
dpt_test_ok( ! DPT_SK_Enforce::is_preview(), 'preview is for administrators only' );
sk_anon();
sk_set( array( 'override' => 'force_closed' ) );
dpt_test_ok( DPT_SK_Enforce::is_closed(), 'force_closed closes a Wednesday' );
dpt_test_eq( DPT_SK_Enforce::cache_max_age(), null, 'no cache bound under a manual override' );
$clock = $at( '2026-09-05 12:00' );
sk_set( array( 'override' => 'force_open' ) );
dpt_test_ok( ! DPT_SK_Enforce::is_closed(), 'force_open opens Shabbat' );
sk_set( array( 'override' => 'auto', 'mode' => 'business' ) );

/* ---- transition cron ---- */
$clock = $at( '2026-09-02 12:00' );
sk_anon();
DPT_SK_Enforce::ensure_transition_event();
dpt_test_eq( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ], $shabbat['start'] + 30, 'purge scheduled just after candle lighting' );
DPT_SK_Enforce::ensure_transition_event();
dpt_test_eq( count( $GLOBALS['dpt_stub_cron'] ), 1, 'not scheduled twice' );
$clock = $shabbat['start'] + 30;
$GLOBALS['dpt_stub_cron'] = array();
$GLOBALS['dpt_stub_actions_fired'] = array();
DPT_SK_Enforce::on_transition();
$fired = array_filter( $GLOBALS['dpt_stub_actions_fired'], function ( $a ) { return 'dpt_shabbat_keeper_transition' === $a[0]; } );
dpt_test_eq( count( $fired ), 1, 'transition action fired' );
dpt_test_eq( array_values( $fired )[0][1], true, 'transition reports now closed' );
dpt_test_eq( $GLOBALS['dpt_stub_cron'][ DPT_SK_Enforce::CRON_HOOK ], $shabbat['end'] + 30, 'next purge scheduled for Havdalah' );

exit( dpt_test_summary() );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-enforce-test.php`
Expected: fatal, enforce file missing.

- [ ] **Step 3: Write the stylesheet**

`modules/shabbat-keeper/assets/closed.css`:

```css
/* Shabbat Keeper - closed screen and banner. Inlined, no theme, no scripts. */
.dpt-sk-page{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f5f1;color:#1f2933;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans Hebrew",sans-serif;padding:24px;box-sizing:border-box}
.dpt-sk-card{max-width:560px;width:100%;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);padding:40px 32px;text-align:center}
.dpt-sk-icon{width:72px;height:72px;border-radius:50%;margin:0 auto 20px;display:block}
.dpt-sk-card h1{font-size:28px;margin:0 0 12px;font-weight:700}
.dpt-sk-card .dpt-sk-message{font-size:17px;line-height:1.6;margin:0 0 20px}
.dpt-sk-card .dpt-sk-reopens{font-size:15px;color:#52606d;margin:0}
.dpt-sk-card .dpt-sk-site{font-size:13px;color:#9aa5b1;margin:24px 0 0}
.dpt-sk-banner{position:sticky;top:0;z-index:99999;background:#1f2933;color:#fff;text-align:center;padding:10px 16px;font-size:15px;line-height:1.4}
```

- [ ] **Step 4: Write the closed-screen view**

`modules/shabbat-keeper/views/closed-screen.php`. Variables set by `DPT_SK_Enforce::render_closed_screen()`: `$title`, `$message` (already `wp_kses_post`-clean HTML), `$reopens` (string, may be empty), `$city` (string), `$css` (string), `$icon` (URL or ''), `$site` (string).

```php
<?php
/**
 * The page a visitor sees in closed mode. Rendered by DPT_SK_Enforce.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $title ); ?> - <?php echo esc_html( $site ); ?></title>
<style><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static stylesheet shipped with the plugin. ?></style>
</head>
<body class="dpt-sk-page">
<main class="dpt-sk-card" role="main">
	<?php if ( $icon ) : ?>
		<img class="dpt-sk-icon" src="<?php echo esc_url( $icon ); ?>" alt="">
	<?php endif; ?>
	<h1><?php echo esc_html( $title ); ?></h1>
	<div class="dpt-sk-message"><?php echo wp_kses_post( $message ); ?></div>
	<?php if ( '' !== $reopens ) : ?>
		<p class="dpt-sk-reopens"><?php echo esc_html( $reopens ); ?></p>
	<?php endif; ?>
	<p class="dpt-sk-site"><?php echo esc_html( $site ); ?><?php if ( '' !== $city ) : ?> &middot; <?php echo esc_html( $city ); ?><?php endif; ?></p>
</main>
</body>
</html>
```

The `robots noindex` meta is belt and braces on top of the 503: a crawler that ignores the status still does not index the closed page as the site's content.

- [ ] **Step 5: Write the enforcement class**

`modules/shabbat-keeper/class-dpt-sk-enforce.php`:

```php
<?php
/**
 * Decides "closed now" for the current request and applies it: the 503
 * page in closed mode, the banner and contact CSS in business mode, a
 * cache lifetime bounded by the next transition, and a cron purge at
 * that transition. Truth is always the computation, never the cron.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Enforce {

	const CRON_HOOK = 'dpt_sk_transition';

	/** @var bool|null */
	private static $closed = null;
	/** @var DPT_SK_Zmanim|null */
	private static $zmanim = null;
	/** @var bool */
	private static $banner_printed = false;

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_head_css' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'print_banner' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_banner_fallback' ) );
		add_action( 'send_headers', array( __CLASS__, 'send_cache_header' ) );
		add_action( 'wp', array( __CLASS__, 'ensure_transition_event' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_transition' ) );
	}

	/** Forget the per-request memo (after a settings save, in tests). */
	public static function reset() {
		self::$closed         = null;
		self::$zmanim         = null;
		self::$banner_printed = false;
	}

	public static function zmanim() {
		if ( null === self::$zmanim ) {
			$loc          = DPT_SK_Settings::location();
			self::$zmanim = new DPT_SK_Zmanim( $loc['lat'], $loc['lon'], $loc['candle'], (int) DPT_SK_Settings::get( 'havdalah' ) );
		}
		return self::$zmanim;
	}

	/** The clock, filterable so tests can pin it. */
	public static function now() {
		return (int) apply_filters( 'dpt_shabbat_keeper_now', time() );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview flag; it changes nothing and only an administrator can use it.
	public static function is_preview() {
		return isset( $_GET['dpt_shabbat'] ) && 'preview' === $_GET['dpt_shabbat'] && current_user_can( 'manage_options' );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public static function is_closed() {
		if ( null !== self::$closed ) {
			return self::$closed;
		}
		$override = DPT_SK_Settings::get( 'override' );
		if ( 'force_open' === $override ) {
			self::$closed = false;
		} elseif ( 'force_closed' === $override || self::is_preview() ) {
			self::$closed = true;
		} else {
			self::$closed = self::zmanim()->is_closed_at( self::now() );
		}
		return self::$closed;
	}

	public static function is_exempt() {
		if ( self::is_preview() ) {
			return false;
		}
		return (bool) apply_filters( 'dpt_shabbat_keeper_exempt', current_user_can( 'manage_options' ) );
	}

	/** Closed, and this user is not exempt. Request type is not considered here. */
	public static function applies() {
		return self::is_closed() && ! self::is_exempt();
	}

	/** A visitor-facing page request: not admin, ajax, cron, REST, CLI, XML-RPC or login. */
	public static function is_front_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}
		return true;
	}

	/* ---------------- closed mode ---------------- */

	public static function should_block() {
		return self::is_front_request() && 'closed' === DPT_SK_Settings::get( 'mode' ) && self::applies();
	}

	public static function maybe_block() {
		if ( ! self::should_block() ) {
			return;
		}
		status_header( 503 );
		nocache_headers();
		foreach ( self::closed_headers() as $header ) {
			header( $header );
		}
		echo self::render_closed_screen(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output, escaped in the view.
		exit;
	}

	/** Extra headers for the 503: Retry-After until Havdalah, or an hour under a manual override. */
	public static function closed_headers() {
		$end   = self::reopens_at();
		$delay = null === $end ? HOUR_IN_SECONDS : max( 60, $end - self::now() );
		return array( 'Retry-After: ' . $delay );
	}

	public static function render_closed_screen() {
		$title   = DPT_SK_Settings::text( 'closed_title' );
		$message = DPT_SK_Settings::text( 'closed_message' );
		$reopens = '1' === DPT_SK_Settings::get( 'closed_show_times' ) ? self::reopens_sentence() : '';
		$loc     = DPT_SK_Settings::location();
		$city    = $loc['name'];
		$css     = self::css();
		$icon    = get_site_icon_url( 128 );
		$site    = get_bloginfo( 'name' );
		ob_start();
		include __DIR__ . '/views/closed-screen.php';
		return (string) ob_get_clean();
	}

	public static function css() {
		$css = file_get_contents( __DIR__ . '/assets/closed.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin.
		return is_string( $css ) ? $css : '';
	}

	/* ---------------- times for people ---------------- */

	/** End of the current real window, or null (open, or closed only by override/preview). */
	public static function reopens_at() {
		$w = self::zmanim()->current_window( self::now() );
		return $w ? (int) $w['end'] : null;
	}

	/** "Saturday 19:32", or "after Havdalah" when there is no real window. */
	public static function reopens_text() {
		$end = self::reopens_at();
		if ( null === $end ) {
			return __( 'after Havdalah', 'digitizer-pro-tools' );
		}
		/* translators: PHP date format for the reopening moment: weekday name and time. */
		return wp_date( __( 'l H:i', 'digitizer-pro-tools' ), $end, new DateTimeZone( DPT_SK_Zmanim::TIMEZONE ) );
	}

	private static function reopens_sentence() {
		/* translators: %s: the day and time the site reopens, or "after Havdalah". */
		return sprintf( __( 'The site reopens %s.', 'digitizer-pro-tools' ), self::reopens_text() );
	}

	/* ---------------- business mode ---------------- */

	public static function banner_html() {
		if ( 'business' !== DPT_SK_Settings::get( 'mode' ) || '1' !== DPT_SK_Settings::get( 'banner_on' ) || ! self::applies() ) {
			return '';
		}
		$text = sprintf( DPT_SK_Settings::text( 'banner_text' ), self::reopens_text() );
		return '<div class="dpt-sk-banner" role="status">' . esc_html( $text ) . '</div>';
	}

	public static function print_banner() {
		if ( self::$banner_printed || ! self::is_front_request() ) {
			return;
		}
		$html = self::banner_html();
		if ( '' === $html ) {
			return;
		}
		self::$banner_printed = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in banner_html().
	}

	/** Themes that never fire wp_body_open get the banner from the footer, moved to the top. */
	public static function print_banner_fallback() {
		if ( self::$banner_printed || ! self::is_front_request() ) {
			return;
		}
		$html = self::banner_html();
		if ( '' === $html ) {
			return;
		}
		self::$banner_printed = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in banner_html().
		echo '<script>(function(){var b=document.querySelector(".dpt-sk-banner");if(b&&document.body.firstChild!==b){document.body.insertBefore(b,document.body.firstChild);}})();</script>';
	}

	public static function print_head_css() {
		if ( ! self::is_front_request() || 'business' !== DPT_SK_Settings::get( 'mode' ) || ! self::applies() ) {
			return;
		}
		$css = '';
		if ( '1' === DPT_SK_Settings::get( 'banner_on' ) ) {
			$css .= self::css();
		}
		if ( '1' === DPT_SK_Settings::get( 'hide_contact' ) ) {
			$css .= 'a[href^="tel:"],a[href*="wa.me"],a[href*="api.whatsapp.com"]{display:none !important}';
		}
		if ( '' !== $css ) {
			echo '<style id="dpt-sk-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS shipped with the plugin.
		}
	}

	/* ---------------- caches ---------------- */

	/** Seconds until the next transition, or null when nothing should be bounded. */
	public static function cache_max_age() {
		if ( 'auto' !== DPT_SK_Settings::get( 'override' ) ) {
			return null;
		}
		$now = self::now();
		return max( 60, self::zmanim()->next_transition( $now ) - $now );
	}

	public static function send_cache_header() {
		if ( ! self::is_front_request() || is_user_logged_in() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		$max = self::cache_max_age();
		if ( null === $max ) {
			return;
		}
		foreach ( headers_list() as $sent ) {
			if ( preg_match( '/^cache-control:.*max-age=(\d+)/i', $sent, $m ) && (int) $m[1] <= $max ) {
				return; // Something already asked for a shorter life.
			}
		}
		header( 'Cache-Control: public, max-age=' . (int) $max );
	}

	public static function ensure_transition_event() {
		if ( ! self::is_front_request() ) {
			return;
		}
		self::schedule_next_transition();
	}

	private static function schedule_next_transition() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( self::zmanim()->next_transition( self::now() ) + 30, self::CRON_HOOK );
	}

	public static function on_transition() {
		self::reset();
		do_action( 'dpt_shabbat_keeper_transition', self::zmanim()->is_closed_at( self::now() ) );
		self::purge_caches();
		self::schedule_next_transition();
	}

	/** Best effort, every known page cache; unknown ones can hook the transition action. */
	public static function purge_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); // SiteGround Optimizer.
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.
		do_action( 'breeze_clear_all_cache' ); // Breeze (Cloudways).
	}
}
```

- [ ] **Step 6: Wire it into the module**

In `modules/shabbat-keeper/class-dpt-sk-module.php`, after the `require_once __DIR__ . '/class-dpt-sk-settings.php';` line add:

```php
require_once __DIR__ . '/class-dpt-sk-enforce.php';
```

and make `init()`:

```php
	public function init() {
		DPT_SK_Enforce::register();
	}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php tests/sk-enforce-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority). Then `php -l` on every new file.

- [ ] **Step 8: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-enforce.php modules/shabbat-keeper/views/closed-screen.php modules/shabbat-keeper/assets/closed.css modules/shabbat-keeper/class-dpt-sk-module.php tests/sk-enforce-test.php
git commit -m "Shabbat Keeper: closed screen, banner, cache bound, transition purge

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: WooCommerce and form integrations

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-integrations.php`
- Modify: `modules/shabbat-keeper/class-dpt-sk-module.php` (require + `init()`)
- Test: `tests/sk-integrations-test.php`

**Interfaces:**
- Consumes: `DPT_SK_Enforce::applies()`, `::reopens_text()`, `DPT_SK_Settings::get/text`.
- Produces: `DPT_SK_Integrations::register()` (hooks `init` at 20), `::hook_plugins()`, `::message(): string`, `::closed_markup(): string`, and the callbacks named in the code.

- [ ] **Step 1: Write the failing test**

`tests/sk-integrations-test.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-hebrew-calendar.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-sun.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-cities.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-zmanim.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-settings.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-enforce.php';
require_once dirname( __DIR__ ) . '/modules/shabbat-keeper/class-dpt-sk-integrations.php';

if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v ) { return update_option( $k, $v ); } }
function wp_date( $format, $ts, $tz = null ) { $d = new DateTime( '@' . $ts ); $d->setTimezone( $tz ? $tz : new DateTimeZone( 'Asia/Jerusalem' ) ); return $d->format( $format ); }
function wp_doing_ajax() { return false; }
$GLOBALS['dpt_stub_notices'] = array();
function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['dpt_stub_notices'][] = array( $msg, $type ); }

/* Plugins present on this pretend site: WooCommerce, CF7, WPForms, Elementor Pro. Not Gravity. */
class WooCommerce {}
function wpcf7() {}
function wpforms() { return $GLOBALS['dpt_stub_wpforms']; }
$GLOBALS['dpt_stub_wpforms'] = (object) array( 'process' => (object) array( 'errors' => array() ) );
class ElementorPro_Plugin_Stub {}
class_alias( 'ElementorPro_Plugin_Stub', 'ElementorPro\Plugin' );

$tz = new DateTimeZone( 'Asia/Jerusalem' );
$at = function ( $local ) use ( $tz ) { return ( new DateTime( $local, $tz ) )->getTimestamp(); };
$clock = $at( '2026-09-05 12:00' ); // Shabbat noon.
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );
$GLOBALS['dpt_stub_no_user'] = true;

DPT_SK_Settings::install_defaults();
DPT_SK_Enforce::reset();
DPT_SK_Integrations::hook_plugins();

/* ---- what got hooked ---- */
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_is_purchasable' ), 'WooCommerce purchasable filter hooked' );
dpt_test_ok( dpt_stub_has_filter( 'woocommerce_store_api_cart_errors' ), 'Store API errors hooked' );
dpt_test_ok( dpt_stub_has_filter( 'wpcf7_validate' ), 'CF7 hooked' );
dpt_test_ok( dpt_stub_has_filter( 'wpforms_process_before' ), 'WPForms hooked' );
dpt_test_ok( dpt_stub_has_filter( 'elementor_pro/forms/validation' ), 'Elementor forms hooked' );
dpt_test_ok( ! dpt_stub_has_filter( 'gform_validation' ), 'Gravity Forms absent, not hooked' );
dpt_test_ok( dpt_stub_has_filter( 'do_shortcode_tag' ), 'shortcode markup hooked' );

/* ---- closed, visitor ---- */
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), false, 'nothing purchasable on Shabbat' );
dpt_test_eq( apply_filters( 'woocommerce_add_to_cart_validation', true, 7 ), false, 'add to cart refused' );
dpt_test_eq( $GLOBALS['dpt_stub_notices'][0][1], 'error', 'refusal is an error notice' );
$errors = new WP_Error();
apply_filters( 'woocommerce_store_api_cart_errors', $errors, null );
dpt_test_ok( in_array( 'dpt_shabbat_keeper', $errors->get_error_codes(), true ), 'Store API gets an error' );
$link = apply_filters( 'woocommerce_loop_add_to_cart_link', '<a class="button">Add</a>', null );
dpt_test_ok( false !== strpos( $link, 'dpt-sk-closed' ), 'shop grid button replaced' );

$out = apply_filters( 'do_shortcode_tag', '<form>cf7</form>', 'contact-form-7', array(), array() );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'CF7 shortcode replaced' );
$out = apply_filters( 'do_shortcode_tag', '<form>wpf</form>', 'wpforms', array(), array() );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'WPForms shortcode replaced' );
$out = apply_filters( 'do_shortcode_tag', '<div>gallery</div>', 'gallery', array(), array() );
dpt_test_eq( $out, '<div>gallery</div>', 'other shortcodes untouched' );

$widget = new class() { public function get_name() { return 'form'; } };
$out = apply_filters( 'elementor/widget/render_content', '<form>el</form>', $widget );
dpt_test_ok( false !== strpos( $out, 'dpt-sk-closed-form' ), 'Elementor form widget replaced' );
$heading = new class() { public function get_name() { return 'heading'; } };
dpt_test_eq( apply_filters( 'elementor/widget/render_content', '<h2>x</h2>', $heading ), '<h2>x</h2>', 'other widgets untouched' );

$cf7 = new class() { public $invalid = array(); public function invalidate( $tag, $msg ) { $this->invalid[] = array( $tag, $msg ); } };
apply_filters( 'wpcf7_validate', $cf7, array( 'your-name' ) );
dpt_test_eq( count( $cf7->invalid ), 1, 'CF7 submission invalidated' );

do_action_stub( 'wpforms_process_before', array(), array( 'id' => 9 ) );
dpt_test_ok( isset( $GLOBALS['dpt_stub_wpforms']->process->errors[9]['header'] ), 'WPForms submission refused' );

$ajax = new class() { public $errors = array(); public function add_error_message( $m ) { $this->errors[] = $m; } };
do_action_stub( 'elementor_pro/forms/validation', null, $ajax );
dpt_test_eq( count( $ajax->errors ), 1, 'Elementor submission refused' );

/* ---- open, visitor: everything passes through ---- */
$clock = $at( '2026-09-02 12:00' );
DPT_SK_Enforce::reset();
$GLOBALS['dpt_stub_notices'] = array();
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'purchasable on Wednesday' );
dpt_test_eq( apply_filters( 'woocommerce_add_to_cart_validation', true, 7 ), true, 'add to cart allowed' );
dpt_test_eq( apply_filters( 'do_shortcode_tag', '<form>cf7</form>', 'contact-form-7', array(), array() ), '<form>cf7</form>', 'form shown on Wednesday' );

/* ---- closed, administrator: exempt ---- */
$clock = $at( '2026-09-05 12:00' );
$GLOBALS['dpt_stub_no_user'] = false;
$GLOBALS['dpt_stub_denied_caps'] = array();
DPT_SK_Enforce::reset();
dpt_test_eq( apply_filters( 'woocommerce_is_purchasable', true, null ), true, 'administrator can still buy' );

/* ---- switches off ---- */
$GLOBALS['dpt_stub_no_user'] = true;
$GLOBALS['dpt_stub_filters'] = array();
add_filter( 'dpt_shabbat_keeper_now', function () use ( &$clock ) { return $clock; } );
DPT_SK_Settings::save( array( 'block_woo' => '0', 'block_forms' => '0' ) );
DPT_SK_Enforce::reset();
DPT_SK_Integrations::hook_plugins();
dpt_test_ok( ! dpt_stub_has_filter( 'woocommerce_is_purchasable' ), 'woo not hooked when switched off' );
dpt_test_ok( ! dpt_stub_has_filter( 'wpcf7_validate' ), 'forms not hooked when switched off' );

/* Actions in the harness share the filter registry; run one by hand. */
function do_action_stub( $tag ) {
	$args = func_get_args();
	call_user_func_array( 'apply_filters', array_merge( array( $tag, $args[1] ), array_slice( $args, 2 ) ) );
}

exit( dpt_test_summary() );
```

Note: `do_action_stub` is declared at the bottom on purpose - PHP hoists top-level function declarations, so it is callable above. Move it to the top if that reads better.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/sk-integrations-test.php`
Expected: fatal, integrations file missing.

- [ ] **Step 3: Write the integrations class**

`modules/shabbat-keeper/class-dpt-sk-integrations.php`:

```php
<?php
/**
 * Refuses purchases and form submissions while closed. The server-side
 * refusal is the truth; replacing the markup is a courtesy so a visitor
 * is told before trying.
 *
 * Every hook checks DPT_SK_Enforce::applies() - closed and not exempt -
 * and nothing else, so the Store API and AJAX submissions are covered.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Integrations {

	const FORM_SHORTCODES = array( 'contact-form-7', 'wpforms', 'gravityform' );

	public static function register() {
		add_action( 'init', array( __CLASS__, 'hook_plugins' ), 20 );
	}

	/** Hook only the plugins that are actually loaded, and only the switches that are on. */
	public static function hook_plugins() {
		if ( '1' === DPT_SK_Settings::get( 'block_woo' ) && class_exists( 'WooCommerce' ) ) {
			add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable' ), 10, 2 );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_notice' ), 31 );
			add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 10, 2 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'refuse_add_to_cart' ), 10, 2 );
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'refuse_checkout' ) );
			add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
		}
		if ( '1' === DPT_SK_Settings::get( 'block_forms' ) ) {
			if ( class_exists( '\ElementorPro\Plugin' ) ) {
				add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor_refuse' ), 10, 2 );
				add_filter( 'elementor/widget/render_content', array( __CLASS__, 'elementor_markup' ), 10, 2 );
			}
			if ( function_exists( 'wpcf7' ) ) {
				add_filter( 'wpcf7_validate', array( __CLASS__, 'cf7_refuse' ), 10, 2 );
			}
			if ( function_exists( 'wpforms' ) ) {
				add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms_refuse' ), 10, 2 );
			}
			if ( class_exists( 'GFForms' ) ) {
				add_filter( 'gform_validation', array( __CLASS__, 'gf_refuse' ) );
				add_filter( 'gform_validation_message', array( __CLASS__, 'gf_message' ), 10, 2 );
			}
			add_filter( 'do_shortcode_tag', array( __CLASS__, 'shortcode_markup' ), 10, 2 );
		}
	}

	/** The one sentence every refusal shows. */
	public static function message() {
		return sprintf( DPT_SK_Settings::text( 'banner_text' ), DPT_SK_Enforce::reopens_text() );
	}

	public static function closed_markup() {
		return '<div class="dpt-sk-closed-form"><p>' . esc_html( self::message() ) . '</p></div>';
	}

	/* ---------------- WooCommerce ---------------- */

	public static function not_purchasable( $purchasable, $product = null ) {
		return DPT_SK_Enforce::applies() ? false : $purchasable;
	}

	public static function product_notice() {
		if ( DPT_SK_Enforce::applies() ) {
			echo '<p class="dpt-sk-closed-notice">' . esc_html( self::message() ) . '</p>';
		}
	}

	public static function loop_button( $link, $product = null ) {
		if ( ! DPT_SK_Enforce::applies() ) {
			return $link;
		}
		return '<span class="button dpt-sk-closed">' . esc_html( DPT_SK_Settings::text( 'closed_title' ) ) . '</span>';
	}

	public static function refuse_add_to_cart( $passed, $product_id = 0 ) {
		if ( ! DPT_SK_Enforce::applies() ) {
			return $passed;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
		return false;
	}

	public static function refuse_checkout() {
		if ( DPT_SK_Enforce::applies() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	public static function store_api_errors( $errors, $cart = null ) {
		if ( DPT_SK_Enforce::applies() && $errors instanceof WP_Error ) {
			$errors->add( 'dpt_shabbat_keeper', self::message() );
		}
		return $errors;
	}

	/* ---------------- forms ---------------- */

	public static function elementor_refuse( $record, $ajax_handler ) {
		if ( DPT_SK_Enforce::applies() && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( self::message() );
		}
	}

	public static function elementor_markup( $content, $widget = null ) {
		if ( DPT_SK_Enforce::applies() && is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'form' === $widget->get_name() ) {
			return self::closed_markup();
		}
		return $content;
	}

	public static function cf7_refuse( $result, $tags = array() ) {
		if ( DPT_SK_Enforce::applies() && is_object( $result ) && method_exists( $result, 'invalidate' ) && ! empty( $tags ) ) {
			$result->invalidate( reset( $tags ), self::message() );
		}
		return $result;
	}

	public static function wpforms_refuse( $entry, $form_data = array() ) {
		if ( ! DPT_SK_Enforce::applies() || ! function_exists( 'wpforms' ) ) {
			return;
		}
		$id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		$wp = wpforms();
		if ( is_object( $wp ) && isset( $wp->process ) && is_object( $wp->process ) ) {
			if ( ! isset( $wp->process->errors ) || ! is_array( $wp->process->errors ) ) {
				$wp->process->errors = array();
			}
			$wp->process->errors[ $id ]['header'] = self::message();
		}
	}

	public static function gf_refuse( $result ) {
		if ( DPT_SK_Enforce::applies() && is_array( $result ) ) {
			$result['is_valid'] = false;
		}
		return $result;
	}

	public static function gf_message( $message, $form = null ) {
		return DPT_SK_Enforce::applies() ? '<div class="validation_error">' . esc_html( self::message() ) . '</div>' : $message;
	}

	public static function shortcode_markup( $output, $tag = '' ) {
		if ( DPT_SK_Enforce::applies() && in_array( $tag, self::FORM_SHORTCODES, true ) ) {
			return self::closed_markup();
		}
		return $output;
	}
}
```

- [ ] **Step 4: Wire it into the module**

In `modules/shabbat-keeper/class-dpt-sk-module.php`, after the enforce require add `require_once __DIR__ . '/class-dpt-sk-integrations.php';` and make `init()`:

```php
	public function init() {
		DPT_SK_Enforce::register();
		DPT_SK_Integrations::register();
	}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/sk-integrations-test.php`
Expected: `0 failed` (the passed count is the number of assertions in the file; if it differs by one or two from what you expected, the file is the authority). Two things that can trip the transcription: `class_alias` for the namespaced Elementor class needs the backslash-free string `'ElementorPro\Plugin'` exactly; and `WP_Error::get_error_codes()` must exist in the harness (`tests/bootstrap.php` line ~174) - if it does not, add a `get_error_codes()` that returns `array_keys( $this->errors )` to the stub class with a one-line comment.

- [ ] **Step 6: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-integrations.php modules/shabbat-keeper/class-dpt-sk-module.php tests/sk-integrations-test.php
git commit -m "Shabbat Keeper: refuse purchases and form submissions while closed

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Settings screen

**Files:**
- Create: `modules/shabbat-keeper/class-dpt-sk-admin.php`
- Modify: `modules/shabbat-keeper/class-dpt-sk-module.php` (require, `init()`, `register_admin_menu()`)

**Interfaces:**
- Consumes: `DPT_SK_Settings::all/save`, `DPT_SK_Cities::all`, `DPT_SK_Enforce::zmanim/now/is_closed/reopens_text/reset/CRON_HOOK`, `dpt_current_admin_page()` from `digitizer-pro-tools.php`, the `dpt-wrap`/`dpt-title`/`dpt-panel`/`dpt-actions` admin classes every DPT settings page uses (styles are enqueued by `DPT_Admin` for any `dpt-` page).
- Produces: `DPT_SK_Admin` with `register_menu( $parent_slug )`, `handle_save()`, `render_page()`, `maybe_show_notices()`, `reason_label( string ): string`.

No unit test: the screen is markup around already-tested classes. Verification is `php -l` and a manual walk-through in the report (see Step 4).

- [ ] **Step 1: Write the admin class**

`modules/shabbat-keeper/class-dpt-sk-admin.php`:

```php
<?php
/**
 * Shabbat Keeper settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Admin {

	const PAGE_SLUG = 'dpt-shabbat-keeper';
	const NONCE     = 'dpt_sk_settings_nonce';

	public function __construct() {
		add_action( 'admin_post_dpt_sk_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Shabbat Keeper', 'digitizer-pro-tools' ),
			__( 'Shabbat Keeper', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag from our own redirect.
	public function maybe_show_notices() {
		if ( self::PAGE_SLUG !== dpt_current_admin_page() ) {
			return;
		}
		if ( isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( self::NONCE );

		// Raw array on purpose: DPT_SK_Settings::sanitize() unslashes and
		// whitelists every field itself.
		$data = isset( $_POST['dpt_sk'] ) && is_array( $_POST['dpt_sk'] ) ? $_POST['dpt_sk'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized per field in DPT_SK_Settings::sanitize().
		DPT_SK_Settings::save( $data );
		DPT_SK_Enforce::reset();
		// The location may have changed; the next front-end request schedules the purge afresh.
		wp_clear_scheduled_hook( DPT_SK_Enforce::CRON_HOOK );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Human name for a window reason such as "shabbat+rosh_hashana_2". */
	public static function reason_label( $reason ) {
		$names = array(
			'shabbat'        => __( 'Shabbat', 'digitizer-pro-tools' ),
			'rosh_hashana_1' => __( 'Rosh Hashana I', 'digitizer-pro-tools' ),
			'rosh_hashana_2' => __( 'Rosh Hashana II', 'digitizer-pro-tools' ),
			'yom_kippur'     => __( 'Yom Kippur', 'digitizer-pro-tools' ),
			'sukkot_1'       => __( 'Sukkot', 'digitizer-pro-tools' ),
			'shmini_atzeret' => __( 'Shmini Atzeret', 'digitizer-pro-tools' ),
			'pesach_1'       => __( 'Pesach', 'digitizer-pro-tools' ),
			'pesach_7'       => __( 'Seventh day of Pesach', 'digitizer-pro-tools' ),
			'shavuot'        => __( 'Shavuot', 'digitizer-pro-tools' ),
		);
		$out = array();
		foreach ( explode( '+', (string) $reason ) as $key ) {
			$out[] = isset( $names[ $key ] ) ? $names[ $key ] : $key;
		}
		return implode( ' + ', $out );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = DPT_SK_Settings::all();
		$tz     = new DateTimeZone( DPT_SK_Zmanim::TIMEZONE );
		$now    = DPT_SK_Enforce::now();
		$zmanim = DPT_SK_Enforce::zmanim();
		$next   = array_slice( $zmanim->windows( $now, $now + 70 * DAY_IN_SECONDS ), 0, 7 );
		$closed = DPT_SK_Enforce::is_closed();
		/* translators: PHP date format for a closure start or end: weekday, day.month, time. */
		$fmt = __( 'D j.n H:i', 'digitizer-pro-tools' );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Shabbat Keeper', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>

			<div class="dpt-panel">
				<h2><?php esc_html_e( 'Status', 'digitizer-pro-tools' ); ?></h2>
				<p>
					<strong><?php echo $closed ? esc_html__( 'Now: closed', 'digitizer-pro-tools' ) : esc_html__( 'Now: open', 'digitizer-pro-tools' ); ?></strong>
					<?php if ( $closed ) : ?>
						&middot; <?php echo esc_html( sprintf( __( 'Reopens %s', 'digitizer-pro-tools' ), DPT_SK_Enforce::reopens_text() ) ); ?>
					<?php elseif ( ! empty( $next ) ) : ?>
						&middot; <?php echo esc_html( sprintf( __( 'Next closing: %s', 'digitizer-pro-tools' ), wp_date( $fmt, $next[0]['start'], $tz ) ) ); ?>
					<?php endif; ?>
					&middot; <?php echo esc_html( DPT_SK_Settings::location()['name'] ); ?>
				</p>
				<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'dpt_shabbat', 'preview', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Preview the closed site', 'digitizer-pro-tools' ); ?></a></p>
				<h3><?php esc_html_e( 'Upcoming closures', 'digitizer-pro-tools' ); ?></h3>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'From', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Until', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'digitizer-pro-tools' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $next as $w ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $fmt, $w['start'], $tz ) ); ?></td>
							<td><?php echo esc_html( wp_date( $fmt, $w['end'], $tz ) ); ?></td>
							<td><?php echo esc_html( self::reason_label( $w['reason'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Nothing leaves the site: the times are computed here from the city you pick.', 'digitizer-pro-tools' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_sk_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Mode', 'digitizer-pro-tools' ); ?></h2>
					<p><label><input type="radio" name="dpt_sk[mode]" value="closed" <?php checked( $o['mode'], 'closed' ); ?> /> <?php esc_html_e( 'Closed - every visitor sees a "closed for Shabbat" page', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[mode]" value="business" <?php checked( $o['mode'], 'business' ); ?> /> <?php esc_html_e( 'Open to read, closed for business - pages stay readable; purchases and forms are refused', 'digitizer-pro-tools' ); ?></label></p>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Location', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><label for="dpt_sk_city"><?php esc_html_e( 'City', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<select id="dpt_sk_city" name="dpt_sk[city]">
									<?php foreach ( DPT_SK_Cities::all() as $slug => $city ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['city'], $slug ); ?>><?php echo esc_html( $city['name'] ); ?> (<?php echo esc_html( $city['candle'] ); ?>)</option>
									<?php endforeach; ?>
									<option value="custom" <?php selected( $o['city'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'digitizer-pro-tools' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Custom', 'digitizer-pro-tools' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Latitude', 'digitizer-pro-tools' ); ?> <input type="number" step="0.001" min="-90" max="90" name="dpt_sk[custom_lat]" value="<?php echo esc_attr( $o['custom_lat'] ); ?>" class="small-text" /></label>
								<label><?php esc_html_e( 'Longitude', 'digitizer-pro-tools' ); ?> <input type="number" step="0.001" min="-180" max="180" name="dpt_sk[custom_lon]" value="<?php echo esc_attr( $o['custom_lon'] ); ?>" class="small-text" /></label>
								<label><?php esc_html_e( 'Candle lighting, minutes before sunset', 'digitizer-pro-tools' ); ?> <input type="number" min="0" max="60" name="dpt_sk[custom_candle]" value="<?php echo esc_attr( $o['custom_candle'] ); ?>" class="small-text" /></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Havdalah', 'digitizer-pro-tools' ); ?></th>
							<td>
								<p><label><input type="radio" name="dpt_sk[havdalah]" value="42" <?php checked( $o['havdalah'], '42' ); ?> /> <?php esc_html_e( '42 minutes after sunset', 'digitizer-pro-tools' ); ?></label></p>
								<p><label><input type="radio" name="dpt_sk[havdalah]" value="72" <?php checked( $o['havdalah'], '72' ); ?> /> <?php esc_html_e( '72 minutes after sunset (Rabbeinu Tam)', 'digitizer-pro-tools' ); ?></label></p>
							</td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Closed page', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><label for="dpt_sk_closed_title"><?php esc_html_e( 'Title', 'digitizer-pro-tools' ); ?></label></th>
							<td><input type="text" id="dpt_sk_closed_title" name="dpt_sk[closed_title]" value="<?php echo esc_attr( $o['closed_title'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( DPT_SK_Settings::text( 'closed_title' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="dpt_sk_closed_message"><?php esc_html_e( 'Message', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<?php wp_editor( $o['closed_message'], 'dpt_sk_closed_message', array( 'textarea_name' => 'dpt_sk[closed_message]', 'textarea_rows' => 5, 'teeny' => true, 'media_buttons' => false ) ); ?>
								<p class="description"><?php echo esc_html( DPT_SK_Settings::text( 'closed_message' ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show the reopening time', 'digitizer-pro-tools' ); ?></th>
							<td><?php $this->switch_field( 'closed_show_times', $o['closed_show_times'] ); ?></td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Business mode', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr><th scope="row"><?php esc_html_e( 'Refuse WooCommerce purchases', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'block_woo', $o['block_woo'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Refuse form submissions (Elementor, Contact Form 7, WPForms, Gravity Forms)', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'block_forms', $o['block_forms'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Hide phone and WhatsApp links', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'hide_contact', $o['hide_contact'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Show a banner at the top of every page', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'banner_on', $o['banner_on'] ); ?></td></tr>
						<tr>
							<th scope="row"><label for="dpt_sk_banner_text"><?php esc_html_e( 'Banner text', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<input type="text" id="dpt_sk_banner_text" name="dpt_sk[banner_text]" value="<?php echo esc_attr( $o['banner_text'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( DPT_SK_Settings::text( 'banner_text' ) ); ?>" />
								<p class="description"><?php esc_html_e( '%s is replaced by the reopening time.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Override', 'digitizer-pro-tools' ); ?></h2>
					<p><label><input type="radio" name="dpt_sk[override]" value="auto" <?php checked( $o['override'], 'auto' ); ?> /> <?php esc_html_e( 'Automatic (by the calendar)', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[override]" value="force_open" <?php checked( $o['override'], 'force_open' ); ?> /> <?php esc_html_e( 'Force open', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[override]" value="force_closed" <?php checked( $o['override'], 'force_closed' ); ?> /> <?php esc_html_e( 'Force closed', 'digitizer-pro-tools' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'A forced state ignores the calendar until you switch back to automatic.', 'digitizer-pro-tools' ); ?></p>
				</div>

				<p class="dpt-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/** Hidden 0 before the checkbox so an unticked switch still posts, as Cookie Banner does. */
	private function switch_field( $name, $checked ) {
		?>
		<label class="dpt-switch">
			<input type="hidden" name="dpt_sk[<?php echo esc_attr( $name ); ?>]" value="0" />
			<input type="checkbox" name="dpt_sk[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked, '1' ); ?> />
			<span class="dpt-switch-slider"></span>
		</label>
		<?php
	}
}
```

- [ ] **Step 2: Wire it into the module**

`modules/shabbat-keeper/class-dpt-sk-module.php` becomes, in full:

```php
<?php
/**
 * Shabbat Keeper module - closes the site, or only its shop and forms,
 * from candle lighting to Havdalah, with the times computed here.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-sk-hebrew-calendar.php';
require_once __DIR__ . '/class-dpt-sk-sun.php';
require_once __DIR__ . '/class-dpt-sk-cities.php';
require_once __DIR__ . '/class-dpt-sk-zmanim.php';
require_once __DIR__ . '/class-dpt-sk-settings.php';
require_once __DIR__ . '/class-dpt-sk-enforce.php';
require_once __DIR__ . '/class-dpt-sk-integrations.php';
require_once __DIR__ . '/class-dpt-sk-admin.php';

class DPT_Shabbat_Keeper_Module extends DPT_Module {

	/** @var DPT_SK_Admin */
	private $admin;

	public function id() {
		return 'shabbat_keeper';
	}

	public function title() {
		return __( 'Shabbat Keeper', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for the city you pick; nothing is sent anywhere.', 'digitizer-pro-tools' );
	}

	public function init() {
		DPT_SK_Enforce::register();
		DPT_SK_Integrations::register();
		$this->admin = new DPT_SK_Admin();
	}

	public function install_defaults() {
		DPT_SK_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
```

- [ ] **Step 3: Syntax check, phpcs, all tests**

Run:

```bash
for f in modules/shabbat-keeper/*.php modules/shabbat-keeper/views/*.php; do php -l "$f" || exit 1; done
[ -x vendor/bin/phpcs ] && vendor/bin/phpcs --standard=WordPress modules/shabbat-keeper || echo "phpcs not installed - skipped"
for f in tests/*-test.php; do php "$f" > /dev/null || { echo "FAIL $f"; exit 1; }; done; echo all-tests-ok
```

Expected: no syntax errors; phpcs clean or skipped; `all-tests-ok`. Fix any phpcs finding in the file it names (most likely a missing `// phpcs:ignore` reason or an unescaped output) - do not add blanket disables.

- [ ] **Step 4: Walk the screen mentally and record it in the report**

There is no WordPress here to load the page. In the report, list the settings key each form field posts, confirm every bool has its hidden `0`, and confirm each `__()` string appears verbatim in Task 8's catalog table (Task 8 depends on this list).

- [ ] **Step 5: Commit**

```bash
git add modules/shabbat-keeper/class-dpt-sk-admin.php modules/shabbat-keeper/class-dpt-sk-module.php
git commit -m "Shabbat Keeper: settings screen with status and upcoming closures

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Catalog, readme, version, build

**Files:**
- Modify: `languages/digitizer-pro-tools.pot`, `languages/digitizer-pro-tools-he_IL.po`, `languages/digitizer-pro-tools-he_IL.l10n.php`, regenerate `languages/digitizer-pro-tools-he_IL.mo`
- Modify: `readme.txt` (Description section, External services section, Changelog)
- Modify: `digitizer-pro-tools.php` (Version header and `DPT_VERSION`)

**Interfaces:** none new.

- [ ] **Step 1: Collect every new string**

Run: `grep -rhoE "(__|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\( '([^']|\\\\')*'" modules/shabbat-keeper | sed -E "s/^[a-z_]+\( '//; s/'$//" | sort -u > /tmp/sk-strings.txt; wc -l /tmp/sk-strings.txt`

Then for each line check whether it already exists in the catalog: `while IFS= read -r s; do grep -qF "msgid \"$s\"" languages/digitizer-pro-tools.pot || echo "NEW: $s"; done < /tmp/sk-strings.txt`. Already present and to be left alone: `Settings saved.`, `You are not allowed to do that.`, `Save Settings`, `Title`, `Message` (if present), `Custom` (if present). Every `NEW:` line must appear in the table below; if the grep finds one the table lacks, add it with a Hebrew translation in the same register.

- [ ] **Step 2: Add the strings to the three text catalogs**

Append to `languages/digitizer-pro-tools.pot` as `msgid "…"` / `msgstr ""` pairs, to `languages/digitizer-pro-tools-he_IL.po` as `msgid "…"` / `msgstr "<Hebrew>"`, and to the `messages` array in `languages/digitizer-pro-tools-he_IL.l10n.php` as `'<en>' => '<he>',`. Escape `"` and `\` in the PO files, `'` and `\` in the PHP file. Add a `#. translators:` comment line before `%s`/date-format entries in the `.pot` and `.po`, copied from the source comment.

| English | Hebrew |
|---|---|
| Shabbat Keeper | שומר שבת |
| Closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for the city you pick; nothing is sent anywhere. | סוגר את האתר, או רק את החנות והטפסים, מהדלקת נרות ועד הבדלה בכל שבת וחג. הזמנים מחושבים בשרת לפי העיר שבחרתם; שום דבר לא נשלח לשום מקום. |
| Jerusalem | ירושלים |
| Tel Aviv | תל אביב |
| Haifa | חיפה |
| Beer Sheva | באר שבע |
| Eilat | אילת |
| Netanya | נתניה |
| Petah Tikva | פתח תקווה |
| Ashdod | אשדוד |
| Bnei Brak | בני ברק |
| Modiin | מודיעין |
| Safed | צפת |
| Tiberias | טבריה |
| Rehovot | רחובות |
| Hadera | חדרה |
| Kfar Saba | כפר סבא |
| Custom location | מיקום מותאם |
| The site is closed for Shabbat | האתר סגור לשבת |
| We observe Shabbat and Jewish holidays. The site reopens automatically after Havdalah. | אנחנו שומרים שבת וחגי ישראל. האתר ייפתח מחדש אוטומטית לאחר ההבדלה. |
| The site is closed for Shabbat. Orders and forms reopen at %s. | האתר סגור לשבת. הזמנות וטפסים ייפתחו מחדש ב-%s. |
| after Havdalah | לאחר ההבדלה |
| l H:i | l H:i |
| The site reopens %s. | האתר ייפתח מחדש %s. |
| D j.n H:i | D j.n H:i |
| Status | סטטוס |
| Now: closed | עכשיו: סגור |
| Now: open | עכשיו: פתוח |
| Reopens %s | ייפתח מחדש %s |
| Next closing: %s | הסגירה הבאה: %s |
| Preview the closed site | תצוגה מקדימה של האתר הסגור |
| Upcoming closures | סגירות קרובות |
| From | מ־ |
| Until | עד |
| Reason | סיבה |
| Nothing leaves the site: the times are computed here from the city you pick. | שום דבר לא יוצא מהאתר: הזמנים מחושבים כאן לפי העיר שבחרתם. |
| Mode | מצב |
| Closed - every visitor sees a "closed for Shabbat" page | סגור - כל מבקר רואה עמוד "סגור לשבת" |
| Open to read, closed for business - pages stay readable; purchases and forms are refused | פתוח לקריאה, סגור לעסקים - העמודים נשארים קריאים; רכישות וטפסים נדחים |
| Location | מיקום |
| City | עיר |
| Custom | מותאם |
| Latitude | קו רוחב |
| Longitude | קו אורך |
| Candle lighting, minutes before sunset | הדלקת נרות, דקות לפני השקיעה |
| Havdalah | הבדלה |
| 42 minutes after sunset | 42 דקות אחרי השקיעה |
| 72 minutes after sunset (Rabbeinu Tam) | 72 דקות אחרי השקיעה (רבנו תם) |
| Closed page | עמוד סגור |
| Title | כותרת |
| Message | הודעה |
| Show the reopening time | הצגת שעת הפתיחה |
| Business mode | מצב עסקים |
| Refuse WooCommerce purchases | חסימת רכישות בווקומרס |
| Refuse form submissions (Elementor, Contact Form 7, WPForms, Gravity Forms) | חסימת שליחת טפסים (אלמנטור, Contact Form 7, WPForms, Gravity Forms) |
| Hide phone and WhatsApp links | הסתרת קישורי טלפון ווואטסאפ |
| Show a banner at the top of every page | הצגת באנר בראש כל עמוד |
| Banner text | טקסט הבאנר |
| %s is replaced by the reopening time. | %s מוחלף בשעת הפתיחה. |
| Override | עקיפה |
| Automatic (by the calendar) | אוטומטי (לפי הלוח) |
| Force open | פתוח בכוח |
| Force closed | סגור בכוח |
| A forced state ignores the calendar until you switch back to automatic. | מצב כפוי מתעלם מהלוח עד שתחזרו לאוטומטי. |
| Shabbat | שבת |
| Rosh Hashana I | ראש השנה א׳ |
| Rosh Hashana II | ראש השנה ב׳ |
| Yom Kippur | יום כיפור |
| Sukkot | סוכות |
| Shmini Atzeret | שמיני עצרת |
| Pesach | פסח |
| Seventh day of Pesach | שביעי של פסח |
| Shavuot | שבועות |

The two date-format entries (`l H:i`, `D j.n H:i`) stay identical in Hebrew: WordPress supplies Hebrew weekday names for `l` and `D` from the locale.

- [ ] **Step 3: Regenerate the binary catalog and check all three**

```bash
msgfmt --statistics -o /dev/null languages/digitizer-pro-tools-he_IL.po
msgfmt -o languages/digitizer-pro-tools-he_IL.mo languages/digitizer-pro-tools-he_IL.po
php -l languages/digitizer-pro-tools-he_IL.l10n.php
php -r '$l = require "languages/digitizer-pro-tools-he_IL.l10n.php"; $m = $l["messages"]; foreach ( array( "Shabbat Keeper", "Jerusalem", "after Havdalah", "Force closed" ) as $k ) { echo isset( $m[ $k ] ) ? "ok   $k\n" : "MISSING $k\n"; }'
```

Expected: `msgfmt` reports zero untranslated and zero fuzzy; four `ok` lines. If `msgfmt` is missing, `brew install gettext` and re-run - do not commit a stale `.mo`.

- [ ] **Step 4: readme.txt**

Three edits:

1. In `== Description ==`, after the `= Module: Copy URL =` block (search `= Module: Copy URL =`; add after the last bullet of that block, before the next `= Module:` or `== ` heading), add:

```
= Module: Shabbat Keeper =

Closes the site for Shabbat and Israeli holidays without anyone touching it (disabled by default; enable it on the Modules screen):

* Two modes: the whole site answers with a "closed for Shabbat" page, or pages stay readable while purchases and form submissions are refused and a banner says when the site reopens
* Times are computed on the server: pick a city (Jerusalem 40 minutes, Tel Aviv 18, Haifa 30, Beer Sheva 22 and eleven more) or enter coordinates; Havdalah at 42 or 72 minutes after sunset
* Covers every Shabbat and the Israeli Yom Tov days - Rosh Hashana, Yom Kippur, Sukkot, Shmini Atzeret, Pesach first and seventh day, Shavuot - and merges adjacent days into one closure
* Refuses WooCommerce purchases on the server (classic, blocks and the Store API) and submissions from Elementor Pro Forms, Contact Form 7, WPForms and Gravity Forms; optionally hides phone and WhatsApp links
* The closed page answers with HTTP 503 and Retry-After, so search engines treat it as a temporary outage
* Page caches are told to expire at the next transition, and known caches are purged when it arrives
* Administrators are never blocked and can preview the closed site with ?dpt_shabbat=preview; a manual override forces the site open or closed
```

2. In `== External services ==`, add a final paragraph:

```
**Shabbat Keeper** contacts nothing. Sunset and the Hebrew calendar are computed inside the plugin from the city you choose.
```

3. In `== Changelog ==`, above `= 1.38.0 =`, add:

```
= 1.39.0 =
* New module: Shabbat Keeper - closes the site, or only its shop and forms, from candle lighting to Havdalah every Shabbat and Israeli holiday. Times are computed on the server for a chosen city (no external service); WooCommerce purchases and Elementor, Contact Form 7, WPForms and Gravity Forms submissions are refused while closed; the closed page answers 503 with Retry-After; page caches are bounded to the next transition and purged when it comes; administrators are exempt and can preview
```

Also change `Stable tag: 1.38.0` to `Stable tag: 1.39.0`.

- [ ] **Step 5: Version**

In `digitizer-pro-tools.php` change the ` * Version: 1.38.0` header line to `1.39.0` and `define( 'DPT_VERSION', '1.38.0' )` to `'1.39.0'`. Run `grep -n "1.38.0\|1.39.0" digitizer-pro-tools.php readme.txt` and confirm only the changelog heading still says 1.38.0.

- [ ] **Step 6: Build and run everything**

```bash
for f in tests/*-test.php; do php "$f" > /dev/null || { echo "FAIL $f"; exit 1; }; done; echo all-tests-ok
bash bin/build-zip.sh && unzip -l dist/digitizer-pro-tools.zip | grep -c "shabbat-keeper/" 
```

Expected: `all-tests-ok`; the zip lists the module's files (count at least 11: eight classes, the view, the stylesheet, and their directories). Tests must not be inside the zip (`unzip -l dist/digitizer-pro-tools.zip | grep -c tests/` prints 0).

- [ ] **Step 7: Commit**

```bash
git add languages readme.txt digitizer-pro-tools.php
git commit -m "Shabbat Keeper: Hebrew catalog, readme, version 1.39.0

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## After the last task (controller, not implementer)

Ship loop from the handoff: build from the clean branch head, `gh pr create --repo Digitizers/digitizer-pro-tools`, `@codex review`, clean verdict on the exact head, squash-merge, `git checkout main && git pull`, then **ask Ben before publishing the release** and attach `dist/digitizer-pro-tools.zip` built from the merged main.
