<?php
/**
 * Cache Ignore Params module - tracking parameters WP Rocket does not ignore
 * on its own. A visit with such a parameter skips the page cache, so a campaign
 * sends its traffic to the slowest version of the page.
 *
 * Replaces the wp-rocket-cache-ignore-query-strings-hubspot helper plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Cache_Ignore_Module extends DPT_Module {

	/**
	 * The helper plugin's list, unchanged. Ignoring a name no URL carries costs
	 * nothing, so its odd hsa_mtsa_ne stays.
	 */
	const HUBSPOT = array(
		'hss_channel',
		'hsa_acc',
		'hsa_cam',
		'hsa_grp',
		'hsa_ad',
		'hsa_src',
		'hsa_tgt',
		'hsa_kw',
		'hsa_mtsa_ne',
		'hsa_ver',
		'hsa_mt',
		'hsa_net',
		'__hstc',
		'__hssc',
		'__hsfp',
		'_hsmi',
		'_hsenc',
		'hsutk',
		'hsCtaTracking',
		'submissionGuid',
		'hsa_la',
		'hsa_ol',
	);

	public function id() {
		return 'cache_ignore';
	}

	public function title() {
		return __( 'Cache Ignore Params', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'WP Rocket serves visits carrying HubSpot\'s tracking parameters from the page cache instead of building the page again. Does nothing without WP Rocket, and does not reach Cloudflare or a server cache. Replaces the WP Rocket helper plugin.', 'digitizer-pro-tools' );
	}

	/**
	 * No purge on toggle: It changes WP Rocket's config, which DPT_Rocket_Params rebuilds itself. (#85)
	 */
	public function affects_frontend() {
		return false;
	}

	public function init() {
		add_filter( 'dpt_rocket_ignored_params', array( $this, 'add_params' ) );
	}

	/**
	 * @param array $params The list so far.
	 * @return array
	 */
	public function add_params( $params ) {
		return array_merge( (array) $params, DPT_Rocket_Params::clean( apply_filters( 'dpt_cache_ignored_params', self::HUBSPOT ) ) );
	}
}
