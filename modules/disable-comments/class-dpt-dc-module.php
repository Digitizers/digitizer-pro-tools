<?php
/**
 * Disable Comments module - dynamic replacement for the hand-pasted
 * "disable comments everywhere" snippet, with WooCommerce review safety.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-dc-settings.php';
require_once __DIR__ . '/class-dpt-dc-admin.php';

class DPT_Disable_Comments_Module extends DPT_Module {

	/** @var DPT_DC_Admin */
	private $admin;

	public function id() {
		return 'disable_comments';
	}

	public function title() {
		return __( 'Disable Comments', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Turns off comments globally or per post type - closes forms, hides existing comments and removes the admin comments UI. WooCommerce product reviews are protected by default.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return true;
	}

	public function init() {
		// Frontend gates - per post type.
		add_filter( 'comments_open', array( $this, 'filter_comments_open' ), 20, 2 );
		add_filter( 'pings_open', array( $this, 'filter_comments_open' ), 20, 2 );
		add_filter( 'comments_array', array( $this, 'filter_comments_array' ), 10, 2 );

		// Editor/REST support removal - after all post types registered.
		add_action( 'init', array( $this, 'remove_post_type_support' ), 100 );

		// Admin UI removal - only when nothing needs the comments screens.
		add_action( 'admin_init', array( $this, 'admin_lockdown' ) );
		add_action( 'admin_menu', array( $this, 'remove_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'remove_admin_bar_item' ), 0 );

		if ( is_admin() ) {
			$this->admin = new DPT_DC_Admin();
		}
	}

	/**
	 * Close comment/ping forms for disabled post types (frontend).
	 */
	public function filter_comments_open( $open, $post_id ) {
		if ( $open && DPT_DC_Settings::disabled_for( (string) get_post_type( $post_id ) ) ) {
			return false;
		}
		return $open;
	}

	/**
	 * Hide already-existing comments on disabled post types.
	 */
	public function filter_comments_array( $comments, $post_id ) {
		if ( DPT_DC_Settings::disabled_for( (string) get_post_type( $post_id ) ) ) {
			return array();
		}
		return $comments;
	}

	/**
	 * Strip 'comments'/'trackbacks' support from disabled post types
	 * (removes the editor discussion panel and related REST fields).
	 */
	public function remove_post_type_support() {
		foreach ( DPT_DC_Settings::comment_post_types() as $type ) {
			if ( DPT_DC_Settings::disabled_for( $type ) ) {
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	/**
	 * Redirect away from edit-comments.php and drop the dashboard widget -
	 * only when comments are disabled for every relevant post type.
	 */
	public function admin_lockdown() {
		if ( ! DPT_DC_Settings::fully_disabled() ) {
			return;
		}
		global $pagenow;
		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	public function remove_admin_menu() {
		if ( DPT_DC_Settings::fully_disabled() ) {
			remove_menu_page( 'edit-comments.php' );
		}
	}

	public function remove_admin_bar_item() {
		if ( DPT_DC_Settings::fully_disabled() ) {
			remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
		}
	}

	public function install_defaults() {
		DPT_DC_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
