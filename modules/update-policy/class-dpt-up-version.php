<?php
/**
 * Update Policy module - version arithmetic.
 *
 * Pure: no WordPress, no options, no clock of its own. Everything here is a
 * question with a defensible answer for any input, because the input comes
 * from an update transient that a host, a plugin or a filter can have touched.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UP_Version {

	/**
	 * The x.y branch of a version, or '' when it cannot be read.
	 *
	 * WordPress numbers its releases x.y, with x.y.z for the fixes on that
	 * branch. 7.1 and 7.1.2 are the same branch and the same decision.
	 *
	 * @param string $version Version string.
	 * @return string
	 */
	public static function branch( $version ) {
		$version = trim( (string) $version );
		if ( ! preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
			return '';
		}
		return $m[1] . '.' . $m[2];
	}

	/**
	 * Whether moving from one version to another crosses a branch upwards.
	 *
	 * A move to an older branch, a move within a branch and anything
	 * unreadable are all false: this decides whether to hold something back,
	 * and holding on a version it could not parse would be a site stuck on an
	 * old WordPress for a reason nobody can see.
	 *
	 * @param string $installed Version currently running.
	 * @param string $offered   Version being offered.
	 * @return bool
	 */
	public static function is_major( $installed, $offered ) {
		$from = self::branch( $installed );
		$to   = self::branch( $offered );
		if ( '' === $from || '' === $to ) {
			return false;
		}
		return version_compare( $to, $from, '>' );
	}

	/**
	 * When a hold that started at $stamp expires.
	 *
	 * @param int $stamp First-seen timestamp.
	 * @param int $days  Length of the hold in days.
	 * @return int
	 */
	public static function held_until( $stamp, $days ) {
		return (int) $stamp + ( (int) $days * DAY_IN_SECONDS );
	}

	/**
	 * Whether a hold is still running.
	 *
	 * A window of zero days - or anything that is not a positive number of
	 * days - holds nothing. The setting turning the feature off is the same
	 * code path as the setting being nonsense, on purpose.
	 *
	 * @param int $stamp First-seen timestamp, 0 when never seen.
	 * @param int $days  Length of the hold in days.
	 * @param int $now   Current timestamp.
	 * @return bool
	 */
	public static function is_held( $stamp, $days, $now ) {
		$days = (int) $days;
		if ( $days < 1 ) {
			return false;
		}
		$stamp = (int) $stamp;
		if ( $stamp < 1 ) {
			// Never seen before. Held until the next check records it, which
			// errs towards waiting rather than towards installing.
			return true;
		}
		return (int) $now < self::held_until( $stamp, $days );
	}
}
