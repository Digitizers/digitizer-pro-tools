<?php
/**
 * Clean up plugin options on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dpt_settings' );
delete_option( 'dpt_cookie_banner' );
delete_option( 'dpt_duplicate_post' );
delete_option( 'dpt_update_emails' );
delete_option( 'dpt_disable_comments' );
delete_option( 'dpt_hide_login' );
delete_option( 'dpt_user_role_editor' );

// Remove the User Role Editor's dedicated gating capability from every role.
if ( function_exists( 'wp_roles' ) ) {
	foreach ( array_keys( wp_roles()->roles ) as $dpt_role_key ) {
		$dpt_role = wp_roles()->get_role( $dpt_role_key );
		if ( $dpt_role ) {
			$dpt_role->remove_cap( 'dpt_manage_roles' );
		}
	}
}
delete_option( 'dpt_db_version' );
