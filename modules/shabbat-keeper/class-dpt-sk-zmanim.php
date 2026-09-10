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
		// Walk civil days from four days before the range (a run of holidays
		// and Shabbat can start several evenings before a closure day that
		// itself precedes the range) to one day after it, and beyond that
		// while a run is still open at the boundary - a multi-day closure
		// (e.g. two days of Rosh Hashana running into Shabbat) must not be
		// truncated just because it crosses the scan limit.
		$day = new DateTime( '@' . (int) $from_ts );
		$day->setTimezone( $this->tz );
		$day->setTime( 0, 0, 0 );
		$day->modify( '-4 days' );

		$limit = new DateTime( '@' . (int) $to_ts );
		$limit->setTimezone( $this->tz );
		$limit->setTime( 0, 0, 0 );
		$limit->modify( '+2 days' );

		$hard_limit = clone $limit;
		$hard_limit->modify( '+7 days' );

		$runs = array(); // Consecutive closure days: [ [ 'days' => [DateTime...], 'reasons' => [] ], ... ].
		$open = null;
		while ( $day <= $limit || ( null !== $open && $day <= $hard_limit ) ) {
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
