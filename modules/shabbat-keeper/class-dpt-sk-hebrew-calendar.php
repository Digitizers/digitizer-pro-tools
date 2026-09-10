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
