<?php
/**
 * Clean up plugin options on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dpt_settings' );
delete_option( 'dpt_onboarding' );
delete_option( 'dpt_update_policy' );
// The update policy is network-wide on multisite, where it is stored as a
// site option rather than the blog's own.
if ( is_multisite() ) {
	delete_site_option( 'dpt_update_policy' );
}
delete_option( 'dpt_cookie_banner' );
delete_option( 'dpt_duplicate_post' );
delete_option( 'dpt_update_emails' );
delete_option( 'dpt_disable_comments' );
delete_option( 'dpt_hide_login' );
delete_option( 'dpt_user_role_editor' );
delete_option( 'dpt_content_control' );
delete_option( 'dpt_enlighter' );
delete_option( 'dpt_site_tweaks' );
delete_option( 'dpt_woo_checkout' );
delete_option( 'dpt_rankmath_breadcrumbs' );
delete_option( 'dpt_resend_mail' );
delete_option( 'dpt_name_your_price' );
delete_option( 'dpt_woo_checkout_fields' );
delete_option( 'dpt_embed' );
delete_option( 'dpt_resend_mail_log' );

// The Agent Log keeps a table of its own, plus the schema stamp that says the
// table exists and the throttle stamp for pruning. Uninstalling is the one
// place the log is removed: switching the module off deliberately leaves it
// alone, so without this a reinstall would silently recover stale audit rows
// and dpt_agent_log_schema would claim a table that had been dropped.
//
// The table is per site, and this file does not loop the network - the only
// multisite branch above is for a single network-wide site option - so on
// multisite this removes the log of the site being uninstalled from, which is
// the same reach the rest of the file has.
global $wpdb;
$dpt_agent_log_table = $wpdb->prefix . 'dpt_agent_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- this module's own table, named from the $wpdb prefix; there is no user input in the statement.
$wpdb->query( "DROP TABLE IF EXISTS `{$dpt_agent_log_table}`" );
delete_option( 'dpt_agent_log_schema' );
delete_option( 'dpt_agent_log_last_prune' );

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
