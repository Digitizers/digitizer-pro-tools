<?php
/**
 * Content Control module - central access-decision logic.
 *
 * A "rule" is a visibility mode plus an optional list of roles. Every
 * enforcement point (single view, listings, REST, feeds, shortcode, menu)
 * routes its decision through can_view() so the logic lives in one place.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Access {

	const META_VISIBILITY = '_dpt_cc_visibility';
	const META_ROLES      = '_dpt_cc_roles';
	const META_MESSAGE    = '_dpt_cc_message';

	/**
	 * Valid visibility modes.
	 */
	public static function visibilities() {
		return array( 'public', 'logged_in', 'logged_out', 'roles' );
	}

	public static function sanitize_visibility( $v ) {
		$v = is_string( $v ) ? $v : '';
		return in_array( $v, self::visibilities(), true ) ? $v : 'public';
	}

	/**
	 * Whether a user bypasses all content restrictions. Administrators do by
	 * default so they never lock themselves out of their own content.
	 */
	public static function user_can_bypass( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		$can  = $user && user_can( $user, 'manage_options' );
		return (bool) apply_filters( 'dpt_cc_user_can_bypass', $can, $user );
	}

	/**
	 * Valid role-match modes: any (login is enough), match (must hold a
	 * listed role), exclude (any logged-in user EXCEPT the listed roles).
	 */
	public static function sanitize_role_match( $m ) {
		return in_array( $m, array( 'any', 'match', 'exclude' ), true ) ? $m : 'match';
	}

	/**
	 * Core decision: can $user see content governed by ($visibility, $roles)?
	 *
	 * @param string       $visibility public | logged_in | logged_out | roles.
	 * @param array        $roles      Role slugs, used in 'roles' mode.
	 * @param WP_User|null $user       Defaults to the current user.
	 * @param string       $role_match any | match | exclude. Default 'match'
	 *                                 keeps the long-standing behaviour of
	 *                                 every existing caller.
	 */
	public static function can_view( $visibility, $roles = array(), $user = null, $role_match = 'match' ) {
		$user       = $user ? $user : wp_get_current_user();
		$visibility = self::sanitize_visibility( $visibility );
		$role_match = self::sanitize_role_match( $role_match );

		if ( self::user_can_bypass( $user ) ) {
			return true;
		}

		// Derive the logged-in state from the supplied user (its ID is 0 when
		// logged out) rather than the global is_user_logged_in(), so a rule
		// can be evaluated for any WP_User - background jobs, REST helpers -
		// not only the current request user.
		$logged_in = $user && ! empty( $user->ID );

		switch ( $visibility ) {
			case 'logged_out':
				$allowed = ! $logged_in;
				break;
			case 'logged_in':
				$allowed = $logged_in;
				break;
			case 'roles':
				if ( ! $logged_in ) {
					$allowed = false;
				} elseif ( 'any' === $role_match ) {
					$allowed = true;
				} elseif ( 'exclude' === $role_match ) {
					$allowed = ! self::user_has_any_role( $user, $roles );
				} else {
					$allowed = self::user_has_any_role( $user, $roles );
				}
				break;
			case 'public':
			default:
				$allowed = true;
				break;
		}
		return (bool) apply_filters( 'dpt_cc_can_view', $allowed, $visibility, $roles, $user, $role_match );
	}

	/**
	 * Restriction-style audience check: {status, role_match, roles}.
	 * Missing or malformed input fails closed - an audience nobody defined
	 * is an audience nobody joins.
	 *
	 * @param array        $who  array( 'status' => logged_in|logged_out,
	 *                           'role_match' => any|match|exclude,
	 *                           'roles' => string[] ).
	 * @param WP_User|null $user Defaults to the current user.
	 * @return bool
	 */
	public static function who_allows( $who, $user = null ) {
		if ( ! is_array( $who ) || empty( $who['status'] ) || ! in_array( $who['status'], array( 'logged_in', 'logged_out' ), true ) ) {
			return false;
		}
		if ( 'logged_out' === $who['status'] ) {
			return self::can_view( 'logged_out', array(), $user );
		}
		$roles = isset( $who['roles'] ) && is_array( $who['roles'] ) ? $who['roles'] : array();
		$match = isset( $who['role_match'] ) ? self::sanitize_role_match( $who['role_match'] ) : 'any';
		// An empty role list cannot mean "no role qualifies" here - the row
		// simply did not narrow by role - so it collapses to plain login.
		if ( empty( $roles ) ) {
			$match = 'any';
		}
		return self::can_view( 'roles', $roles, $user, $match );
	}

	public static function user_has_any_role( $user, $roles ) {
		if ( ! $user || empty( $user->roles ) || empty( $roles ) ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, array_map( 'strval', (array) $roles ) );
	}

	/**
	 * The restriction rule stored on a post: array( visibility, roles ).
	 */
	public static function post_rule( $post_id ) {
		$visibility = self::sanitize_visibility( get_post_meta( $post_id, self::META_VISIBILITY, true ) );
		$roles      = get_post_meta( $post_id, self::META_ROLES, true );
		$roles      = is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : array();
		return array(
			'visibility' => $visibility,
			'roles'      => $roles,
		);
	}

	public static function post_is_restricted( $post_id ) {
		return 'public' !== self::post_rule( $post_id )['visibility'];
	}

	public static function can_view_post( $post_id, $user = null ) {
		$rule = self::post_rule( $post_id );
		return self::can_view( $rule['visibility'], $rule['roles'], $user );
	}

	/**
	 * The message shown in place of restricted content.
	 */
	public static function restriction_message( $post_id = 0 ) {
		$custom = $post_id ? (string) get_post_meta( $post_id, self::META_MESSAGE, true ) : '';
		if ( '' === trim( $custom ) && class_exists( 'DPT_CC_Settings' ) ) {
			$custom = (string) DPT_CC_Settings::get( 'default_message' );
		}
		if ( '' === trim( $custom ) ) {
			$custom = __( 'This content is restricted.', 'digitizer-pro-tools' );
		}
		/**
		 * The rendered restriction notice. Wrapped so themes can style it.
		 */
		$html = '<div class="dpt-cc-restricted">' . wp_kses_post( wpautop( $custom ) ) . '</div>';
		return apply_filters( 'dpt_cc_restriction_message', $html, $post_id );
	}
}
