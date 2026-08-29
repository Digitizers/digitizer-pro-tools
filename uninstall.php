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
// The table and both stamps are per site, and WordPress runs an uninstaller
// once for the whole network, so on multisite this loops the sites: without
// that, every site but the one uninstalled from keeps a table that nothing
// will ever come back to drop, growing and invisible. switch_to_blog() moves
// $wpdb->prefix and the options API onto each site in turn, and is paired
// with restore_current_blog() rather than a second switch, which would leave
// core's switched stack unbalanced.
//
// Only the Agent Log's own data is looped. The delete_option() calls above
// keep their single-site reach; widening those changes data removal for every
// module and is a separate decision from this one.
//
// get_sites() returns at most 100 sites by default ('number' in
// WP_Site_Query::$query_var_defaults), so 'number' => 0 lifts the limit;
// 'fields' => 'ids' asks for blog IDs rather than the WP_Site objects nothing
// here reads.
global $wpdb;
$dpt_agent_log_network = is_multisite();
$dpt_agent_log_sites   = $dpt_agent_log_network
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );

foreach ( $dpt_agent_log_sites as $dpt_agent_log_site_id ) {
	if ( $dpt_agent_log_network ) {
		switch_to_blog( $dpt_agent_log_site_id );
	}

	$dpt_agent_log_table = $wpdb->prefix . 'dpt_agent_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- this module's own table, named from the $wpdb prefix; there is no user input in the statement.
	$wpdb->query( "DROP TABLE IF EXISTS `{$dpt_agent_log_table}`" );
	delete_option( 'dpt_agent_log_schema' );
	delete_option( 'dpt_agent_log_last_prune' );

	if ( $dpt_agent_log_network ) {
		restore_current_blog();
	}
}

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
