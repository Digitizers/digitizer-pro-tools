<?php
/**
 * Update Policy module - reading and filtering WordPress's core update offers.
 *
 * Pure, and it never mutates what it is given: the transient it reads from is
 * shared with WordPress and with every other plugin on the site.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UP_Offers {

	/**
	 * The major branches offered to a site, in the order they appear.
	 *
	 * @param array  $updates   The transient's updates array.
	 * @param string $installed Version currently running.
	 * @return array Branch string => offered version.
	 */
	public static function majors( $updates, $installed ) {
		$out = array();
		if ( ! is_array( $updates ) ) {
			return $out;
		}
		foreach ( $updates as $offer ) {
			$version = self::version_of( $offer );
			if ( '' === $version || ! DPT_UP_Version::is_major( $installed, $version ) ) {
				continue;
			}
			$branch = DPT_UP_Version::branch( $version );
			if ( ! isset( $out[ $branch ] ) ) {
				$out[ $branch ] = $version;
			}
		}
		return $out;
	}

	/**
	 * The offers array with held majors removed.
	 *
	 * Everything that is not a held major is passed through untouched, in its
	 * original order: minor and security releases, offers for the branch the
	 * site already runs, an offer somebody released by hand, and anything this
	 * could not read.
	 *
	 * @param array  $updates   The transient's updates array.
	 * @param string $installed Version currently running.
	 * @param array  $stamps    Branch => first-seen timestamp.
	 * @param array  $released  Branch => truthy when the hold was lifted.
	 * @param int    $days      Length of the hold in days.
	 * @param int    $now       Current timestamp.
	 * @return array
	 */
	public static function filter( $updates, $installed, $stamps, $released, $days, $now ) {
		if ( ! is_array( $updates ) ) {
			return $updates;
		}
		$out = array();
		foreach ( $updates as $key => $offer ) {
			if ( self::is_held( $offer, $installed, $stamps, $released, $days, $now ) ) {
				continue;
			}
			$out[ $key ] = $offer;
		}
		return $out;
	}

	/**
	 * Whether one offer is a major this site is currently holding.
	 *
	 * @param mixed  $offer     One entry from the updates array.
	 * @param string $installed Version currently running.
	 * @param array  $stamps    Branch => first-seen timestamp.
	 * @param array  $released  Branch => truthy when the hold was lifted.
	 * @param int    $days      Length of the hold in days.
	 * @param int    $now       Current timestamp.
	 * @return bool
	 */
	public static function is_held( $offer, $installed, $stamps, $released, $days, $now ) {
		$version = self::version_of( $offer );
		if ( '' === $version || ! DPT_UP_Version::is_major( $installed, $version ) ) {
			return false;
		}
		$branch = DPT_UP_Version::branch( $version );
		if ( ! empty( $released[ $branch ] ) ) {
			return false;
		}
		$stamp = isset( $stamps[ $branch ] ) ? (int) $stamps[ $branch ] : 0;
		return DPT_UP_Version::is_held( $stamp, $days, $now );
	}

	/**
	 * Record the branches offered now, without moving one already recorded.
	 *
	 * Moving a stamp would restart the window on every check, and a window
	 * that restarts is not a window.
	 *
	 * @param array  $stamps    Existing branch => timestamp map.
	 * @param array  $updates   The transient's updates array.
	 * @param string $installed Version currently running.
	 * @param int    $now       Current timestamp.
	 * @return array
	 */
	public static function stamp( $stamps, $updates, $installed, $now ) {
		$stamps = is_array( $stamps ) ? $stamps : array();
		foreach ( self::majors( $updates, $installed ) as $branch => $version ) {
			if ( empty( $stamps[ $branch ] ) ) {
				$stamps[ $branch ] = (int) $now;
			}
		}
		return $stamps;
	}

	/**
	 * The version an offer names, or '' when it names none.
	 *
	 * WordPress fills 'current' with the version being offered. Objects and
	 * arrays both appear in the wild, because plugins rewrite this transient.
	 *
	 * @param mixed $offer One entry from the updates array.
	 * @return string
	 */
	public static function version_of( $offer ) {
		if ( is_object( $offer ) && isset( $offer->current ) ) {
			return (string) $offer->current;
		}
		if ( is_array( $offer ) && isset( $offer['current'] ) ) {
			return (string) $offer['current'];
		}
		return '';
	}
}
