<?php
/**
 * Plugin Name:       Digitizer Pro Tools
 * Plugin URI:        https://github.com/digitizers/digitizer-pro-tools
 * Description:       One toolbox plugin by Digitizer: a multilingual cookie-consent banner, one-click post duplication, auto-update email silencing, and more modules to come.
 * Version:           1.23.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digitizer
 * Author URI:        https://www.digitizer.co.il
 * Text Domain:       digitizer-pro-tools
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DPT_VERSION', '1.23.0' );
define( 'DPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPT_URL', plugin_dir_url( __FILE__ ) );
define( 'DPT_BASENAME', plugin_basename( __FILE__ ) );
define( 'DPT_OPTION', 'dpt_settings' );

require_once DPT_PATH . 'includes/class-dpt-module.php';
require_once DPT_PATH . 'includes/class-dpt-plugin.php';
require_once DPT_PATH . 'includes/class-dpt-admin.php';
require_once DPT_PATH . 'includes/class-dpt-updater.php';

// Wire the GitHub self-updater as early as the main file loads, so updates are
// offered on the normal Plugins / Dashboard > Updates screens (including cron
// checks). Building it here rather than in a hook is the library's recommended
// usage.
DPT_Updater::init( __FILE__ );

function dpt_bootstrap() {
	DPT_Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'dpt_bootstrap' );

function dpt_activate() {
	DPT_Plugin::instance()->install_defaults();
}
register_activation_hook( __FILE__, 'dpt_activate' );

/**
 * The sanitized `page` query argument of the current admin request, or '' when
 * it is absent or malformed.
 *
 * Guards against an array-valued parameter (?page[]=x): sanitize_key() would
 * hand the array to string functions and raise a TypeError on PHP 8, which - as
 * these checks run on admin_notices - would break admin screens before they
 * render. Read-only screen identification, so no nonce is involved.
 *
 * @return string
 */
function dpt_current_admin_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check, not a state change.
	if ( ! isset( $_GET['page'] ) || ! is_scalar( $_GET['page'] ) ) {
		return '';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check, not a state change.
	return sanitize_key( wp_unslash( $_GET['page'] ) );
}

/**
 * Add Settings link on the plugins list screen.
 */
function dpt_plugin_action_links( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=digitizer-pro-tools' ) ) . '">' . esc_html__( 'Settings', 'digitizer-pro-tools' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . DPT_BASENAME, 'dpt_plugin_action_links' );
