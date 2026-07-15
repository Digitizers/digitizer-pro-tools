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
		// Block themes (the core Comments block) query comments directly via
		// WP_Comment_Query and never pass through comments_array.
		add_action( 'pre_get_comments', array( $this, 'filter_comment_queries' ) );

		// Editor/REST support removal - after all post types registered.
		add_action( 'init', array( $this, 'remove_post_type_support' ), 100 );

		// Admin UI removal - only when nothing needs the comments screens.
		add_action( 'admin_init', array( $this, 'admin_lockdown' ) );
		add_action( 'admin_menu', array( $this, 'remove_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'remove_admin_bar_item' ), 0 );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_dashboard_widget' ) );
		add_action( 'admin_print_styles-index.php', array( $this, 'hide_dashboard_activity_comments' ) );

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
	 * Hide comments of disabled post types from every frontend/REST
	 * WP_Comment_Query - the block Comments template, the REST comments
	 * endpoint and direct get_comments() calls. Each constraint shape is
	 * narrowed rather than dropped, so protected types (WooCommerce
	 * reviews) keep working inside mixed and unconstrained queries. Admin
	 * queries are left alone so moderation screens still list everything.
	 *
	 * @param WP_Comment_Query $query Query, passed by reference.
	 */
	public function filter_comment_queries( $query ) {
		if ( is_admin() ) {
			return;
		}
		$post_id = ! empty( $query->query_vars['post_id'] ) ? (int) $query->query_vars['post_id'] : 0;
		if ( $post_id ) {
			if ( DPT_DC_Settings::disabled_for( (string) get_post_type( $post_id ) ) ) {
				$query->query_vars['comment__in'] = array( 0 );
			}
			return;
		}
		// The REST comments endpoint (?post=<id>) constrains via post__in,
		// not post_id - drop the disabled-type posts from the list.
		$post_in = ! empty( $query->query_vars['post__in'] ) ? array_map( 'intval', (array) $query->query_vars['post__in'] ) : array();
		if ( $post_in ) {
			$kept = array();
			foreach ( $post_in as $pid ) {
				if ( ! DPT_DC_Settings::disabled_for( (string) get_post_type( $pid ) ) ) {
					$kept[] = $pid;
				}
			}
			if ( empty( $kept ) ) {
				$query->query_vars['comment__in'] = array( 0 );
			} else {
				$query->query_vars['post__in'] = $kept;
			}
			return;
		}
		// post_type constraint: narrow the list to the non-disabled types.
		$post_types = isset( $query->query_vars['post_type'] ) ? $query->query_vars['post_type'] : '';
		if ( ! empty( $post_types ) ) {
			$kept = array();
			foreach ( (array) $post_types as $type ) {
				if ( ! DPT_DC_Settings::disabled_for( (string) $type ) ) {
					$kept[] = (string) $type;
				}
			}
			if ( empty( $kept ) ) {
				$query->query_vars['comment__in'] = array( 0 );
			} else {
				$query->query_vars['post_type'] = $kept;
			}
			return;
		}
		// Unconstrained query (e.g. recent-comments widgets, bare
		// /wp/v2/comments): restrict it to the still-allowed
		// comment-supporting types, or empty it when none remain.
		$allowed = array();
		foreach ( DPT_DC_Settings::comment_post_types() as $type ) {
			if ( ! DPT_DC_Settings::disabled_for( $type ) ) {
				$allowed[] = $type;
			}
		}
		if ( empty( $allowed ) ) {
			$query->query_vars['comment__in'] = array( 0 );
		} else {
			$query->query_vars['post_type'] = $allowed;
		}
	}

	/**
	 * Strip 'comments'/'trackbacks' support from disabled post types
	 * (removes the editor discussion panel and related REST fields).
	 */
	public function remove_post_type_support() {
		// Snapshot the pre-strip list first, so the settings UI and save
		// validation keep seeing the types this module disabled.
		$types = DPT_DC_Settings::snapshot_comment_post_types();
		foreach ( $types as $type ) {
			if ( DPT_DC_Settings::disabled_for( $type ) ) {
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	/**
	 * Redirect away from edit-comments.php - only when comments are
	 * disabled for every relevant post type.
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
	}

	/**
	 * Legacy dashboard comments widget - removed at wp_dashboard_setup,
	 * i.e. when dashboard widgets are actually registered.
	 */
	public function remove_dashboard_widget() {
		if ( DPT_DC_Settings::fully_disabled() ) {
			remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		}
	}

	/**
	 * Modern WordPress shows recent comments inside the Activity dashboard
	 * widget (the #latest-comments block) - there is no hook to drop just
	 * that section, so hide it the same way the classic Disable Comments
	 * plugin does.
	 */
	public function hide_dashboard_activity_comments() {
		if ( DPT_DC_Settings::fully_disabled() ) {
			echo '<style id="dpt-dc-dashboard">#dashboard_activity #latest-comments{display:none;}</style>' . "\n";
		}
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
