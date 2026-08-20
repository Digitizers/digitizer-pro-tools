<?php
/**
 * Update Policy module - the WordPress wiring.
 *
 * The only file here with side effects, and the two halves are kept apart on
 * purpose: applying the hold is a read and writes nothing, recording a first
 * sighting happens after WordPress has stored an update check of its own.
 *
 * A read filter that writes turns every page load into a side effect, and
 * state written into WordPress's own stored value outlives the module that put
 * it there. Disabling this module restores WordPress's behaviour in the same
 * request; the stamps stay in the option, inert, and are picked up again if it
 * is switched back on.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UP_Policy {

	/**
	 * Register the filters.
	 */
	public static function init() {
		// On a network this is the main site's decision and nobody else's.
		//
		// A core update is network-wide - one WordPress, one transient, one
		// Updates screen under Network Admin - while the plugin's module
		// switch is per blog. Left alone, a subsite could switch this module
		// on, hold nothing anyone else can see, and look protected: the
		// network Updates screen and the update cron would go on offering the
		// major from the main site's context. Answering only where core
		// updates are actually administered means the switch either governs
		// the whole network or governs nothing, and never appears to do one
		// while doing the other.
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}

		add_filter( 'site_transient_update_core', array( __CLASS__, 'apply_hold' ) );
		add_action( 'set_site_transient_update_core', array( __CLASS__, 'record_sightings' ) );

		// Also once per admin page load. A module switched on while a major is
		// already offered has missed the check that would have stamped it, and
		// an unstamped hold has no dates to show - the notice would say the
		// site first saw the release in 1970. Idempotent: it writes only when
		// a branch has no stamp yet.
		add_action( 'admin_init', array( __CLASS__, 'record_sightings' ) );

		// The unattended path has to agree with the visible one. Major core
		// auto-updates are off by default, so this is belt and braces - but a
		// site that turned them on should not quietly bypass the hold.
		add_filter( 'allow_major_auto_core_updates', array( __CLASS__, 'allow_major_auto' ) );
	}

	/**
	 * The version this site is running.
	 *
	 * @return string
	 */
	public static function installed_version() {
		return isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	}

	/**
	 * Remove held majors from the offers WordPress is about to report.
	 *
	 * @param mixed $transient The update_core site transient.
	 * @return mixed
	 */
	public static function apply_hold( $transient ) {
		if ( ! is_object( $transient ) || ! isset( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return $transient;
		}
		$policy = DPT_UP_Settings::all();
		$kept   = DPT_UP_Offers::filter(
			$transient->updates,
			self::installed_version(),
			$policy['seen'],
			$policy['released'],
			DPT_UP_Settings::hold_days(),
			time()
		);
		if ( count( $kept ) === count( $transient->updates ) ) {
			return $transient;
		}

		// Copied rather than edited: the object is WordPress's, and other
		// readers of this transient in the same request are entitled to it
		// unchanged if this module is later switched off mid-request.
		$out          = clone $transient;
		$out->updates = $kept;
		return $out;
	}

	/**
	 * Record the first time this site was offered each major.
	 *
	 * Runs after the write rather than before it, so the check WordPress just
	 * performed is already recorded when this reads its result.
	 *
	 * @return void
	 */
	public static function record_sightings() {
		$raw = self::stored_transient();
		if ( ! is_object( $raw ) || ! isset( $raw->updates ) || ! is_array( $raw->updates ) ) {
			return;
		}
		$policy  = DPT_UP_Settings::all();
		$stamped = DPT_UP_Offers::stamp( $policy['seen'], $raw->updates, self::installed_version(), time() );
		if ( $stamped === $policy['seen'] ) {
			return;
		}
		$policy['seen'] = $stamped;
		DPT_UP_Settings::save( $policy );
	}

	/**
	 * The stored transient, read past this module's own filter.
	 *
	 * Reading it through get_site_transient() would hand back the filtered
	 * copy, and a major that is currently held would then never be recorded -
	 * so it would stay held forever, which is the one outcome this module must
	 * not produce.
	 *
	 * @return mixed
	 */
	private static function stored_transient() {
		remove_filter( 'site_transient_update_core', array( __CLASS__, 'apply_hold' ) );
		$raw = get_site_transient( 'update_core' );
		add_filter( 'site_transient_update_core', array( __CLASS__, 'apply_hold' ) );
		return $raw;
	}

	/**
	 * Refuse unattended major updates while any major is held.
	 *
	 * @param mixed $allow Whatever WordPress or another plugin decided.
	 * @return mixed
	 */
	public static function allow_major_auto( $allow ) {
		return self::held_majors() ? false : $allow;
	}

	/**
	 * The majors currently held: branch => offered version.
	 *
	 * @return array
	 */
	public static function held_majors() {
		$raw = self::stored_transient();
		if ( ! is_object( $raw ) || ! isset( $raw->updates ) || ! is_array( $raw->updates ) ) {
			return array();
		}
		$policy    = DPT_UP_Settings::all();
		$installed = self::installed_version();
		$days      = DPT_UP_Settings::hold_days();
		$now       = time();

		$out = array();
		foreach ( DPT_UP_Offers::majors( $raw->updates, $installed ) as $branch => $version ) {
			$stamp = isset( $policy['seen'][ $branch ] ) ? (int) $policy['seen'][ $branch ] : 0;
			if ( empty( $policy['released'][ $branch ] ) && DPT_UP_Version::is_held( $stamp, $days, $now ) ) {
				$out[ $branch ] = array(
					'version' => $version,
					'seen'    => $stamp,
					'until'   => DPT_UP_Version::held_until( $stamp, $days ),
				);
			}
		}
		return $out;
	}

	/**
	 * Lift the hold on one branch, permanently.
	 *
	 * @param string $branch Branch string, e.g. 7.1.
	 * @return bool
	 */
	public static function release( $branch ) {
		$branch = DPT_UP_Version::branch( $branch );
		if ( '' === $branch || ! DPT_UP_Settings::may_decide() ) {
			return false;
		}
		$policy                        = DPT_UP_Settings::all();
		$policy['released'][ $branch ] = 1;
		DPT_UP_Settings::save( $policy );
		return true;
	}
}
