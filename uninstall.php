<?php
/**
 * Clean up plugin options on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dpt_settings' );
delete_option( 'dpt_cookie_banner' );
delete_option( 'dpt_db_version' );
