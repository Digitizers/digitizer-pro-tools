<?php
/**
 * UTM Passthrough module - a visitor who arrives with utm_* parameters keeps
 * them on the site's own links, so the campaign is still on the address when
 * they reach the form or the checkout.
 *
 * Replaces the snippet pasted into each site's header. It all happens in the
 * browser, so page caches are unaffected, and it stores nothing: no cookie,
 * no consent question.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-utm-settings.php';
require_once __DIR__ . '/class-dpt-utm-admin.php';

class DPT_UTM_Passthrough_Module extends DPT_Module {

	/**
	 * What the HubSpot switch carries: ad and channel data, the same kind of
	 * thing as a utm_* parameter. Not _hsenc, _hsmi or hsutk, which tie a visit
	 * to a contact, nor __hstc, __hssc and __hsfp, which matter on arrival only.
	 */
	const HUBSPOT_ADS = array(
		'hss_channel',
		'hsa_acc',
		'hsa_cam',
		'hsa_grp',
		'hsa_ad',
		'hsa_src',
		'hsa_tgt',
		'hsa_kw',
		'hsa_ver',
		'hsa_mt',
		'hsa_net',
		'hsa_la',
		'hsa_ol',
	);

	/** @var DPT_UTM_Admin */
	private $admin;

	public function id() {
		return 'utm_passthrough';
	}

	public function title() {
		return __( 'UTM Passthrough', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'A visitor who arrives with utm_* parameters keeps them on the site\'s internal links, so the campaign reaches the form or the checkout. Can carry HubSpot\'s ad parameters too. Runs in the browser, stores nothing, and leaves external links, downloads, the admin and add-to-cart links alone. Replaces the snippet pasted into the site header.', 'digitizer-pro-tools' );
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'dpt_rocket_ignored_params', array( $this, 'add_rocket_params' ) );

		if ( is_admin() ) {
			$this->admin = new DPT_UTM_Admin();
		}
	}

	public function install_defaults() {
		DPT_UTM_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}

	public function enqueue_assets() {
		wp_enqueue_script(
			'dpt-utm-passthrough',
			DPT_URL . 'modules/utm-passthrough/assets/js/utm-passthrough.js',
			array(),
			DPT_VERSION,
			true
		);
		wp_localize_script( 'dpt-utm-passthrough', 'DPT_UTM_CONFIG', array(
			'exclude' => self::excluded_paths(),
			'params'  => self::carried_params(),
			'linked'  => DPT_UTM_Settings::get( 'linked_hosts' ),
		) );
	}

	/**
	 * A parameter on every internal link would otherwise cost every page of the
	 * visit its cache. WP Rocket's own list already holds the common utm_* names.
	 *
	 * @param array $params The list so far.
	 * @return array
	 */
	public function add_rocket_params( $params ) {
		return array_merge( (array) $params, self::carried_params() );
	}

	/**
	 * Parameters carried beyond utm_*, matched by exact name.
	 *
	 * @return string[]
	 */
	private static function carried_params() {
		$params = '1' === DPT_UTM_Settings::get( 'hubspot_ads' ) ? self::HUBSPOT_ADS : array();
		return DPT_Rocket_Params::clean( apply_filters( 'dpt_utm_passthrough_params', $params ) );
	}

	/**
	 * Paths whose links never get the parameters: the admin and the login
	 * page, as this site's own URLs spell them (a subdirectory install has a
	 * longer path). Filter dpt_utm_excluded_paths adds more.
	 *
	 * @return string[]
	 */
	private static function excluded_paths() {
		$paths = array(
			(string) wp_parse_url( admin_url(), PHP_URL_PATH ),
			(string) wp_parse_url( wp_login_url(), PHP_URL_PATH ),
		);
		return array_values( array_filter( (array) apply_filters( 'dpt_utm_excluded_paths', $paths ) ) );
	}
}
