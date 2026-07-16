<?php
/**
 * Content Control module - per-menu-item visibility.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Menu {

	const META_VISIBILITY = '_dpt_cc_menu_visibility';
	const META_ROLES      = '_dpt_cc_menu_roles';
	const NONCE           = 'dpt_cc_menu';

	public function init() {
		// Editing UI (WordPress 5.4+ provides the custom-fields hook).
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save' ), 10, 3 );

		// Front-end filtering (skip inside the menu editor / admin).
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_items' ), 20, 3 );
	}

	public function render_fields( $item_id, $item ) {
		$visibility = DPT_CC_Access::sanitize_visibility( get_post_meta( $item_id, self::META_VISIBILITY, true ) );
		$roles      = get_post_meta( $item_id, self::META_ROLES, true );
		$roles      = is_array( $roles ) ? $roles : array();
		wp_nonce_field( self::NONCE, self::NONCE . '_' . $item_id );
		?>
		<p class="field-dpt-cc description description-wide">
			<label><?php esc_html_e( 'Content Control visibility', 'digitizer-pro-tools' ); ?><br>
				<select name="dpt_cc_menu_visibility[<?php echo esc_attr( $item_id ); ?>]">
					<option value="public" <?php selected( $visibility, 'public' ); ?>><?php esc_html_e( 'Everyone', 'digitizer-pro-tools' ); ?></option>
					<option value="logged_in" <?php selected( $visibility, 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></option>
					<option value="logged_out" <?php selected( $visibility, 'logged_out' ); ?>><?php esc_html_e( 'Logged-out visitors', 'digitizer-pro-tools' ); ?></option>
					<option value="roles" <?php selected( $visibility, 'roles' ); ?>><?php esc_html_e( 'Specific roles', 'digitizer-pro-tools' ); ?></option>
				</select>
			</label>
		</p>
		<p class="field-dpt-cc-roles description description-wide">
			<label><?php esc_html_e( 'Roles (comma-separated keys, for "Specific roles")', 'digitizer-pro-tools' ); ?><br>
				<input type="text" name="dpt_cc_menu_roles[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( implode( ', ', array_map( 'sanitize_key', $roles ) ) ); ?>" class="widefat" />
			</label>
		</p>
		<?php
	}

	public function save( $menu_id, $item_id, $args ) {
		$nonce_key = self::NONCE . '_' . $item_id;
		if ( ! isset( $_POST[ $nonce_key ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ $nonce_key ] ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$visibility = 'public';
		if ( isset( $_POST['dpt_cc_menu_visibility'][ $item_id ] ) ) {
			$visibility = DPT_CC_Access::sanitize_visibility( wp_unslash( $_POST['dpt_cc_menu_visibility'][ $item_id ] ) );
		}

		$roles = array();
		if ( isset( $_POST['dpt_cc_menu_roles'][ $item_id ] ) ) {
			$raw = (string) wp_unslash( $_POST['dpt_cc_menu_roles'][ $item_id ] );
			$roles = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $raw ) ) ) );
		}

		if ( 'public' === $visibility ) {
			delete_post_meta( $item_id, self::META_VISIBILITY );
			delete_post_meta( $item_id, self::META_ROLES );
		} else {
			update_post_meta( $item_id, self::META_VISIBILITY, $visibility );
			update_post_meta( $item_id, self::META_ROLES, $roles );
		}
	}

	/**
	 * Remove menu items the current user cannot see, along with any
	 * descendants of a hidden item so no orphaned children remain.
	 */
	public function filter_items( $items, $menu, $args ) {
		if ( is_admin() || empty( $items ) ) {
			return $items;
		}

		$hidden = array();
		foreach ( $items as $item ) {
			$visibility = DPT_CC_Access::sanitize_visibility( get_post_meta( $item->ID, self::META_VISIBILITY, true ) );
			if ( 'public' === $visibility ) {
				continue;
			}
			$roles = get_post_meta( $item->ID, self::META_ROLES, true );
			$roles = is_array( $roles ) ? $roles : array();
			if ( ! DPT_CC_Access::can_view( $visibility, $roles ) ) {
				$hidden[ $item->ID ] = true;
			}
		}
		if ( ! $hidden ) {
			return $items;
		}

		// Propagate hiding to descendants.
		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( $items as $item ) {
				if ( ! isset( $hidden[ $item->ID ] ) && $item->menu_item_parent && isset( $hidden[ (int) $item->menu_item_parent ] ) ) {
					$hidden[ $item->ID ] = true;
					$changed = true;
				}
			}
		}

		return array_values( array_filter( $items, static function ( $item ) use ( $hidden ) {
			return ! isset( $hidden[ $item->ID ] );
		} ) );
	}
}
