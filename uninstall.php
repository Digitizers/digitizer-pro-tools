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
delete_option( 'dpt_db_version' );
