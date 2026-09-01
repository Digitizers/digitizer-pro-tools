<?php
/**
 * Content Control module - classic widget visibility. Two fields on every
 * widget form (who sees it, which roles); widgets the viewer fails are
 * removed from sidebars_widgets so their markup never reaches the page.
 *
 * A malformed status fails OPEN here: a widget with a broken setting must
 * degrade to visible, not silently blank a sidebar.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Widgets {

	public function init() {
		add_action( 'in_widget_form', array( $this, 'render_fields' ), 5, 3 );
		add_filter( 'widget_update_callback', array( $this, 'save_fields' ), 5, 2 );
		add_filter( 'sidebars_widgets', array( $this, 'filter_sidebars' ), 10 );
	}

	/**
	 * @param array $instance Widget instance settings.
	 * @return bool
	 */
	public function should_show( $instance ) {
		$status = isset( $instance['dpt_cc_status'] ) ? $instance['dpt_cc_status'] : '';
		if ( ! in_array( $status, array( 'logged_in', 'logged_out' ), true ) ) {
			return true;
		}
		$roles = ( isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ) ? $instance['dpt_cc_roles'] : array();
		return DPT_CC_Access::who_allows(
			array(
				'status'     => $status,
				'role_match' => 'match',
				'roles'      => $roles,
			)
		);
	}

	/**
	 * Keep only known values; roles only make sense for logged-in gating.
	 */
	public function sanitize_update( $instance ) {
		$status = ( isset( $instance['dpt_cc_status'] ) && in_array( $instance['dpt_cc_status'], array( '', 'logged_in', 'logged_out' ), true ) )
			? $instance['dpt_cc_status'] : '';
		$roles = array();
		if ( 'logged_in' === $status && isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ) {
			$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $instance['dpt_cc_roles'] ) ) ) );
		}
		$instance['dpt_cc_status'] = $status;
		$instance['dpt_cc_roles']  = $roles;
		return $instance;
	}

	public function save_fields( $instance, $new_instance ) {
		$instance['dpt_cc_status'] = isset( $new_instance['dpt_cc_status'] ) ? $new_instance['dpt_cc_status'] : '';
		$instance['dpt_cc_roles']  = isset( $new_instance['dpt_cc_roles'] ) ? (array) $new_instance['dpt_cc_roles'] : array();
		return $this->sanitize_update( $instance );
	}

	public function render_fields( $widget, $return, $instance ) {
		$instance = is_array( $instance ) ? $instance : array();
		$status   = isset( $instance['dpt_cc_status'] ) ? $instance['dpt_cc_status'] : '';
		$roles    = ( isset( $instance['dpt_cc_roles'] ) && is_array( $instance['dpt_cc_roles'] ) ) ? $instance['dpt_cc_roles'] : array();
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		?>
		<p>
			<label for="<?php echo esc_attr( $widget->get_field_id( 'dpt_cc_status' ) ); ?>"><?php esc_html_e( 'Content Control: who sees this widget?', 'digitizer-pro-tools' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $widget->get_field_id( 'dpt_cc_status' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'dpt_cc_status' ) ); ?>">
				<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Everyone', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_in" <?php selected( $status, 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_out" <?php selected( $status, 'logged_out' ); ?>><?php esc_html_e( 'Logged-out visitors', 'digitizer-pro-tools' ); ?></option>
			</select>
		</p>
		<p>
			<?php esc_html_e( 'Roles (with "Logged-in users"; none selected = any role):', 'digitizer-pro-tools' ); ?><br>
			<?php foreach ( get_editable_roles() as $key => $role ) : ?>
				<label style="display:inline-block;margin-right:8px;">
					<input type="checkbox" name="<?php echo esc_attr( $widget->get_field_name( 'dpt_cc_roles' ) ); ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $roles, true ) ); ?> />
					<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * Drop widget ids the viewer fails. The customizer preview and admin
	 * stay unfiltered so editors always see what they are arranging.
	 */
	public function filter_sidebars( $sidebars ) {
		if ( is_admin() || is_customize_preview() || ! is_array( $sidebars ) ) {
			return $sidebars;
		}
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}
			$changed = false;
			foreach ( $widgets as $i => $widget_id ) {
				$instance = $this->instance_for( $widget_id );
				if ( null !== $instance && ! $this->should_show( $instance ) ) {
					unset( $sidebars[ $sidebar_id ][ $i ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				$sidebars[ $sidebar_id ] = array_values( $sidebars[ $sidebar_id ] );
			}
		}
		return $sidebars;
	}

	/**
	 * "{basename}-{index}" -> the instance array in option widget_{basename},
	 * or null when the id has another shape (legacy single-instance widgets).
	 */
	private function instance_for( $widget_id ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			return null;
		}
		$all = get_option( 'widget_' . $m[1], array() );
		$idx = (int) $m[2];
		return ( is_array( $all ) && isset( $all[ $idx ] ) && is_array( $all[ $idx ] ) ) ? $all[ $idx ] : null;
	}
}
