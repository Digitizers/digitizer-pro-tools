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
