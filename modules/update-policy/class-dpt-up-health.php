<?php
/**
 * Update Policy module - the Site Health entry.
 *
 * A hold is a missing update, and a missing update is what someone sees when
 * they go looking for a problem. Site Health is where they look. The module
 * already puts a notice on the Updates screen and the dashboard; this answers
 * the same question in the third place people ask it, and answers it even when
 * nothing is currently held - "the policy is on and holding nothing" is the
 * reading that stops somebody hunting for a hold that was never there.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UP_Health {

	const TEST_ID = 'dpt_update_policy';

	/**
	 * Register the test.
	 *
	 * Called from DPT_UP_Policy::init(), which has already returned on a
	 * subsite of a network - so the entry appears exactly where the policy is
	 * decided, and a subsite is not told about a decision it cannot make.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Add this module's test to the direct tests Site Health runs.
	 *
	 * @param array $tests The registered tests.
	 * @return array
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}
		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Update Policy', 'digitizer-pro-tools' ),
			'test'  => array( __CLASS__, 'run' ),
		);
		return $tests;
	}

	/**
	 * The test result.
	 *
	 * @return array
	 */
	public static function run() {
		$days = DPT_UP_Settings::hold_days();

		if ( $days < 1 ) {
			return self::result(
				'recommended',
				__( 'Update Policy is switched on but holds nothing', 'digitizer-pro-tools' ),
				sprintf(
					'<p>%s</p>',
					esc_html__( 'The hold is set to zero days, so a major WordPress release is offered here the moment it appears - the same as if this module were switched off. Set a number of days, or switch the module off, so the site behaves the way the Modules screen says it does.', 'digitizer-pro-tools' )
				)
			);
		}

		$held = DPT_UP_Policy::held_majors();

		if ( empty( $held ) ) {
			return self::result(
				'good',
				__( 'No WordPress release is being held back', 'digitizer-pro-tools' ),
				sprintf(
					'<p>%s</p>',
					// The length of the hold is deliberately not quoted here.
					// Saying it needs a plural form, and this catalog has none
					// - adding the first one would put a shape into three
					// hand-maintained files that must agree byte for byte, to
					// win a number that the settings screen this entry links
					// to already shows.
					esc_html__( 'Update Policy is active. A major WordPress release will be held back after this site is first offered it, and offered again when the hold ends. Security and maintenance releases are never held.', 'digitizer-pro-tools' )
				)
			);
		}

		$format = get_option( 'date_format' );
		$lines  = array();
		foreach ( $held as $branch => $hold ) {
			$lines[] = sprintf(
				'<li>%s</li>',
				sprintf(
					/* translators: 1: WordPress version, 2: date the update was first offered, 3: date the hold ends */
					esc_html__( 'WordPress %1$s - first offered here on %2$s, held until %3$s.', 'digitizer-pro-tools' ),
					esc_html( $hold['version'] ),
					esc_html( date_i18n( $format, (int) $hold['seen'] ) ),
					esc_html( date_i18n( $format, (int) $hold['until'] ) )
				)
			);
		}

		// Singular even when two branches are held at once, which WordPress
		// can offer and this loop does handle. The heading takes the case that
		// happens; the list below it is exact either way.
		return self::result(
			'good',
			__( 'A major WordPress release is being held back', 'digitizer-pro-tools' ),
			sprintf(
				'<p>%s</p><ul>%s</ul><p>%s</p>',
				esc_html__( 'This is the policy working, not a fault. A major release is held so the plugins and themes on this site have time to catch up with it.', 'digitizer-pro-tools' ),
				implode( '', $lines ),
				esc_html__( 'Security and maintenance releases are installed as usual and are not affected. The hold can be lifted from the Updates screen.', 'digitizer-pro-tools' )
			)
		);
	}

	/**
	 * Assemble a result in the shape Site Health expects.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Short heading.
	 * @param string $description HTML body.
	 * @return array
	 */
	private static function result( $status, $label, $description ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'digitizer-pro-tools' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=' . DPT_UP_Admin::PAGE_SLUG ) ),
				esc_html__( 'Update policy settings', 'digitizer-pro-tools' )
			),
			'test'        => self::TEST_ID,
		);
	}
}
