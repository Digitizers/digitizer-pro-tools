<?php
/**
 * User Role Editor module - role/capability operations on top of the
 * WordPress Roles API.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_URE_Manager {

	const OPTION = 'dpt_user_role_editor';

	public static function defaults() {
		return array(
			// Capabilities added through the editor that may be unchecked on
			// every role - kept here so they keep appearing in the matrix.
			'custom_caps' => array(),
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		update_option( self::OPTION, array_merge( self::defaults(), $existing ) );
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		$all  = array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
		if ( ! is_array( $all['custom_caps'] ) ) {
			$all['custom_caps'] = array();
		}
		return $all;
	}

	/**
	 * Capabilities that the administrator role must always keep so an admin
	 * can never lock themselves out of the site or this editor.
	 */
	public static function protected_admin_caps() {
		return apply_filters( 'dpt_ure_protected_admin_caps', array( 'read', 'manage_options' ) );
	}

	/**
	 * Roles the editor refuses to delete.
	 */
	public static function undeletable_roles() {
		return apply_filters( 'dpt_ure_undeletable_roles', array( 'administrator' ) );
	}

	/**
	 * @return WP_Roles
	 */
	private static function roles() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}
		return $wp_roles;
	}

	/**
	 * All roles: key => array( name, capabilities ).
	 */
	public static function get_roles() {
		return self::roles()->roles;
	}

	public static function role_exists( $key ) {
		return self::roles()->is_role( $key );
	}

	public static function role_name( $key ) {
		$names = self::roles()->get_names();
		return isset( $names[ $key ] ) ? $names[ $key ] : $key;
	}

	/**
	 * Every capability that should appear in the matrix: the union of all
	 * caps assigned to any role, the well-known WordPress core caps and any
	 * custom caps registered through the editor. Role-name pseudo-caps (a
	 * role key used as a cap, e.g. "administrator") are filtered out.
	 */
	public static function get_all_capabilities() {
		$caps = array();
		foreach ( self::get_roles() as $role ) {
			if ( empty( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				continue;
			}
			foreach ( $role['capabilities'] as $cap => $granted ) {
				$caps[ $cap ] = true;
			}
		}
		foreach ( self::core_capabilities() as $cap ) {
			$caps[ $cap ] = true;
		}
		foreach ( self::all()['custom_caps'] as $cap ) {
			$caps[ $cap ] = true;
		}

		// Drop role-key pseudo capabilities that WordPress stores on each
		// user's cap array (level_x and the role name itself).
		$role_keys = array_keys( self::get_roles() );
		foreach ( array_keys( $caps ) as $cap ) {
			if ( in_array( $cap, $role_keys, true ) || preg_match( '/^level_\d+$/', $cap ) ) {
				unset( $caps[ $cap ] );
			}
		}

		$list = array_keys( $caps );
		sort( $list, SORT_STRING );
		return $list;
	}

	/**
	 * A stable baseline of core capabilities so they always show even when
	 * no current role happens to grant them.
	 */
	public static function core_capabilities() {
		return array(
			'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users',
			'edit_files', 'manage_options', 'moderate_comments', 'manage_categories', 'manage_links',
			'upload_files', 'import', 'unfiltered_html', 'edit_posts', 'edit_others_posts',
			'edit_published_posts', 'publish_posts', 'edit_pages', 'read', 'publish_pages',
			'edit_others_pages', 'edit_published_pages', 'delete_pages', 'delete_others_pages',
			'delete_published_pages', 'delete_posts', 'delete_others_posts', 'delete_published_posts',
			'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages',
			'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users', 'unfiltered_upload',
			'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes',
			'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users',
			'edit_theme_options', 'delete_themes', 'export',
		);
	}

	/**
	 * Sanitize a role key: lowercase, alnum + underscore, WP-style.
	 */
	public static function sanitize_role_key( $key ) {
		$key = sanitize_key( is_string( $key ) ? $key : '' );
		return $key;
	}

	/**
	 * Sanitize a capability name (same character class as role keys).
	 */
	public static function sanitize_capability( $cap ) {
		$cap = is_string( $cap ) ? strtolower( trim( $cap ) ) : '';
		$cap = preg_replace( '/[^a-z0-9_]/', '', $cap );
		return $cap;
	}

	/**
	 * Add a role. Returns WP_Error on failure.
	 */
	public static function add_role( $key, $display_name, $clone_from = '' ) {
		$key = self::sanitize_role_key( $key );
		if ( '' === $key ) {
			return new WP_Error( 'dpt_ure_bad_key', __( 'Invalid role key.', 'digitizer-pro-tools' ) );
		}
		if ( self::role_exists( $key ) ) {
			return new WP_Error( 'dpt_ure_exists', __( 'A role with that key already exists.', 'digitizer-pro-tools' ) );
		}
		$display_name = trim( wp_strip_all_tags( (string) $display_name ) );
		if ( '' === $display_name ) {
			return new WP_Error( 'dpt_ure_bad_name', __( 'Role display name is required.', 'digitizer-pro-tools' ) );
		}
		$caps = array();
		if ( $clone_from && self::role_exists( $clone_from ) ) {
			$src = self::roles()->get_role( $clone_from );
			if ( $src && is_array( $src->capabilities ) ) {
				$caps = array_filter( $src->capabilities );
			}
		}
		$role = add_role( $key, $display_name, $caps );
		if ( null === $role ) {
			return new WP_Error( 'dpt_ure_failed', __( 'Could not create the role.', 'digitizer-pro-tools' ) );
		}
		return true;
	}

	/**
	 * Delete a role, first reassigning its users to $reassign_to.
	 */
	public static function delete_role( $key, $reassign_to ) {
		$key = self::sanitize_role_key( $key );
		if ( in_array( $key, self::undeletable_roles(), true ) ) {
			return new WP_Error( 'dpt_ure_protected', __( 'That role cannot be deleted.', 'digitizer-pro-tools' ) );
		}
		if ( ! self::role_exists( $key ) ) {
			return new WP_Error( 'dpt_ure_missing', __( 'Role not found.', 'digitizer-pro-tools' ) );
		}
		if ( (string) get_option( 'default_role' ) === $key ) {
			return new WP_Error( 'dpt_ure_default', __( 'This is the default role for new users - change the default role first.', 'digitizer-pro-tools' ) );
		}
		$reassign_to = self::sanitize_role_key( $reassign_to );
		if ( '' === $reassign_to || ! self::role_exists( $reassign_to ) || $reassign_to === $key ) {
			return new WP_Error( 'dpt_ure_reassign', __( 'Choose a valid role to move existing users to.', 'digitizer-pro-tools' ) );
		}

		$users = get_users( array( 'role' => $key, 'fields' => array( 'ID' ) ) );
		foreach ( $users as $u ) {
			$user = new WP_User( $u->ID );
			$user->set_role( $reassign_to );
		}

		remove_role( $key );
		return true;
	}

	/**
	 * Replace a role's capabilities with exactly $desired_caps (a list of
	 * capability names to grant). Protected admin caps and any cap that
	 * would lock the current user out are force-kept.
	 */
	public static function update_role_caps( $key, $desired_caps ) {
		$key = self::sanitize_role_key( $key );
		if ( ! self::role_exists( $key ) ) {
			return new WP_Error( 'dpt_ure_missing', __( 'Role not found.', 'digitizer-pro-tools' ) );
		}
		$role = self::roles()->get_role( $key );
		if ( ! $role ) {
			return new WP_Error( 'dpt_ure_missing', __( 'Role not found.', 'digitizer-pro-tools' ) );
		}

		$valid   = self::get_all_capabilities();
		$desired = array();
		foreach ( (array) $desired_caps as $cap ) {
			$cap = self::sanitize_capability( is_array( $cap ) ? '' : $cap );
			if ( '' !== $cap && in_array( $cap, $valid, true ) ) {
				$desired[ $cap ] = true;
			}
		}

		// Never let the administrator role lose the caps that keep the site
		// manageable, and never let the current user strip their own access.
		if ( 'administrator' === $key ) {
			foreach ( self::protected_admin_caps() as $cap ) {
				$desired[ $cap ] = true;
			}
		}
		if ( self::current_user_has_role( $key ) ) {
			$desired['manage_options'] = true;
			$desired['read']           = true;
		}

		// Apply the diff against the role's current caps.
		$current = is_array( $role->capabilities ) ? $role->capabilities : array();
		foreach ( array_keys( $current ) as $cap ) {
			if ( empty( $desired[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( array_keys( $desired ) as $cap ) {
			$role->add_cap( $cap );
		}
		return true;
	}

	/**
	 * Register a custom capability and grant it to the given roles.
	 */
	public static function add_capability( $cap, $grant_roles = array() ) {
		$cap = self::sanitize_capability( $cap );
		if ( '' === $cap ) {
			return new WP_Error( 'dpt_ure_bad_cap', __( 'Invalid capability name.', 'digitizer-pro-tools' ) );
		}
		$opts = self::all();
		if ( ! in_array( $cap, $opts['custom_caps'], true ) ) {
			$opts['custom_caps'][] = $cap;
			update_option( self::OPTION, $opts );
		}
		foreach ( (array) $grant_roles as $rk ) {
			$rk = self::sanitize_role_key( $rk );
			if ( self::role_exists( $rk ) ) {
				$role = self::roles()->get_role( $rk );
				if ( $role ) {
					$role->add_cap( $cap );
				}
			}
		}
		return true;
	}

	private static function current_user_has_role( $key ) {
		$user = wp_get_current_user();
		return $user && ! empty( $user->roles ) && in_array( $key, (array) $user->roles, true );
	}
}
