<?php
/**
 * User Role Editor module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_URE_Admin {

	const PAGE_SLUG = 'dpt-user-role-editor';

	private function cap() {
		return DPT_URE_Manager::required_cap();
	}

	public function __construct() {
		add_action( 'admin_post_dpt_ure_save_caps', array( $this, 'handle_save_caps' ) );
		add_action( 'admin_post_dpt_ure_add_role', array( $this, 'handle_add_role' ) );
		add_action( 'admin_post_dpt_ure_delete_role', array( $this, 'handle_delete_role' ) );
		add_action( 'admin_post_dpt_ure_add_cap', array( $this, 'handle_add_cap' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'User Role Editor', 'digitizer-pro-tools' ),
			__( 'User Role Editor', 'digitizer-pro-tools' ),
			$this->cap(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	private function redirect( $args ) {
		$args['page'] = self::PAGE_SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function flash_error( $message ) {
		set_transient( 'dpt_ure_error_' . get_current_user_id(), $message, 60 );
	}

	public function maybe_show_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		$err = get_transient( 'dpt_ure_error_' . get_current_user_id() );
		if ( $err ) {
			delete_transient( 'dpt_ure_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	private function guard() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
	}

	private function result( $result, $ok_args ) {
		if ( is_wp_error( $result ) ) {
			$this->flash_error( $result->get_error_message() );
			$this->redirect( array() );
		}
		$this->redirect( $ok_args );
	}

	public function handle_save_caps() {
		$this->guard();
		check_admin_referer( 'dpt_ure_save_caps' );
		$role = isset( $_POST['dpt_ure_role'] ) ? DPT_URE_Manager::sanitize_role_key( wp_unslash( $_POST['dpt_ure_role'] ) ) : '';
		$caps = isset( $_POST['dpt_ure_caps'] ) && is_array( $_POST['dpt_ure_caps'] ) ? wp_unslash( $_POST['dpt_ure_caps'] ) : array();
		$this->result( DPT_URE_Manager::update_role_caps( $role, $caps ), array( 'role' => $role, 'dpt_saved' => 1 ) );
	}

	public function handle_add_role() {
		$this->guard();
		check_admin_referer( 'dpt_ure_add_role' );
		$key   = isset( $_POST['dpt_ure_key'] ) ? wp_unslash( $_POST['dpt_ure_key'] ) : '';
		$name  = isset( $_POST['dpt_ure_name'] ) ? wp_unslash( $_POST['dpt_ure_name'] ) : '';
		$clone = isset( $_POST['dpt_ure_clone'] ) ? wp_unslash( $_POST['dpt_ure_clone'] ) : '';
		$result = DPT_URE_Manager::add_role( $key, $name, $clone );
		$this->result( $result, array( 'role' => DPT_URE_Manager::sanitize_role_key( $key ), 'dpt_saved' => 1 ) );
	}

	public function handle_delete_role() {
		$this->guard();
		check_admin_referer( 'dpt_ure_delete_role' );
		$role     = isset( $_POST['dpt_ure_role'] ) ? wp_unslash( $_POST['dpt_ure_role'] ) : '';
		$reassign = isset( $_POST['dpt_ure_reassign'] ) ? wp_unslash( $_POST['dpt_ure_reassign'] ) : '';
		$this->result( DPT_URE_Manager::delete_role( $role, $reassign ), array( 'dpt_saved' => 1 ) );
	}

	public function handle_add_cap() {
		$this->guard();
		check_admin_referer( 'dpt_ure_add_cap' );
		$cap    = isset( $_POST['dpt_ure_cap'] ) ? wp_unslash( $_POST['dpt_ure_cap'] ) : '';
		$grants = isset( $_POST['dpt_ure_grant'] ) && is_array( $_POST['dpt_ure_grant'] ) ? wp_unslash( $_POST['dpt_ure_grant'] ) : array();
		$this->result( DPT_URE_Manager::add_capability( $cap, $grants ), array( 'dpt_saved' => 1 ) );
	}

	private function current_role() {
		$roles   = DPT_URE_Manager::get_roles();
		$default = isset( $roles['administrator'] ) ? 'administrator' : ( $roles ? array_key_first( $roles ) : '' );
		$role    = isset( $_GET['role'] ) ? DPT_URE_Manager::sanitize_role_key( wp_unslash( $_GET['role'] ) ) : $default;
		if ( ! DPT_URE_Manager::role_exists( $role ) ) {
			$role = $default;
		}
		return $role;
	}

	public function render_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$roles    = DPT_URE_Manager::get_roles();
		$role_key = $this->current_role();
		$role_obj = $role_key ? DPT_URE_Manager::get_roles()[ $role_key ] : array( 'capabilities' => array() );
		$role_caps = isset( $role_obj['capabilities'] ) && is_array( $role_obj['capabilities'] ) ? $role_obj['capabilities'] : array();
		$all_caps = DPT_URE_Manager::get_all_capabilities();
		$custom   = DPT_URE_Manager::all()['custom_caps'];
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-groups"></span>
				<?php esc_html_e( 'User Role Editor', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Edit exactly what each role can do. The administrator role always keeps the capabilities needed to manage the site, and you can never remove your own access.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-panel">
				<h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Choose a role', 'digitizer-pro-tools' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<select name="role" onchange="this.form.submit()">
						<?php foreach ( $roles as $rk => $r ) : ?>
							<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $rk, $role_key ); ?>>
								<?php echo esc_html( $r['name'] . ' (' . $rk . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<noscript><button type="submit" class="button"><?php esc_html_e( 'Load', 'digitizer-pro-tools' ); ?></button></noscript>
				</form>
			</div>

			<div class="dpt-panel">
				<h2><span class="dashicons dashicons-yes"></span>
					<?php
					/* translators: %s: role display name */
					printf( esc_html__( 'Capabilities for: %s', 'digitizer-pro-tools' ), '<code>' . esc_html( DPT_URE_Manager::role_name( $role_key ) ) . '</code>' );
					?>
				</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dpt_ure_save_caps" />
					<input type="hidden" name="dpt_ure_role" value="<?php echo esc_attr( $role_key ); ?>" />
					<?php wp_nonce_field( 'dpt_ure_save_caps' ); ?>

					<p>
						<label><input type="checkbox" id="dpt-ure-toggle-all" /> <strong><?php esc_html_e( 'Select / deselect all', 'digitizer-pro-tools' ); ?></strong></label>
					</p>
					<div class="dpt-ure-caps" style="column-width:280px;column-gap:24px;">
						<?php foreach ( $all_caps as $cap ) :
							$checked   = ! empty( $role_caps[ $cap ] );
							$is_custom = in_array( $cap, $custom, true );
							?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="dpt-ure-cap" name="dpt_ure_caps[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( $checked ); ?> />
								<code><?php echo esc_html( $cap ); ?></code>
								<?php if ( $is_custom ) : ?><span class="description">(<?php esc_html_e( 'custom', 'digitizer-pro-tools' ); ?>)</span><?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="dpt-actions">
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Capabilities', 'digitizer-pro-tools' ); ?></button>
					</p>
				</form>
				<script>
				( function () {
					var master = document.getElementById( 'dpt-ure-toggle-all' );
					if ( ! master ) { return; }
					master.addEventListener( 'change', function () {
						document.querySelectorAll( '.dpt-ure-cap' ).forEach( function ( cb ) { cb.checked = master.checked; } );
					} );
				} )();
				</script>
			</div>

			<div class="dpt-layout" style="display:flex;gap:24px;flex-wrap:wrap;">
				<div class="dpt-panel" style="flex:1;min-width:280px;">
					<h2><span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Add a role', 'digitizer-pro-tools' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_ure_add_role" />
						<?php wp_nonce_field( 'dpt_ure_add_role' ); ?>
						<p><label><?php esc_html_e( 'Role key', 'digitizer-pro-tools' ); ?><br>
							<input type="text" name="dpt_ure_key" class="regular-text" placeholder="shop_manager" required /></label>
							<span class="description"><?php esc_html_e( 'Lowercase letters, digits and underscores.', 'digitizer-pro-tools' ); ?></span></p>
						<p><label><?php esc_html_e( 'Display name', 'digitizer-pro-tools' ); ?><br>
							<input type="text" name="dpt_ure_name" class="regular-text" placeholder="Shop Manager" required /></label></p>
						<p><label><?php esc_html_e( 'Copy capabilities from', 'digitizer-pro-tools' ); ?><br>
							<select name="dpt_ure_clone">
								<option value=""><?php esc_html_e( '— none (empty role) —', 'digitizer-pro-tools' ); ?></option>
								<?php foreach ( $roles as $rk => $r ) : ?>
									<option value="<?php echo esc_attr( $rk ); ?>"><?php echo esc_html( $r['name'] ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add Role', 'digitizer-pro-tools' ); ?></button></p>
					</form>
				</div>

				<div class="dpt-panel" style="flex:1;min-width:280px;">
					<h2><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete a role', 'digitizer-pro-tools' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this role and move its users to the selected role?', 'digitizer-pro-tools' ) ); ?>');">
						<input type="hidden" name="action" value="dpt_ure_delete_role" />
						<?php wp_nonce_field( 'dpt_ure_delete_role' ); ?>
						<p><label><?php esc_html_e( 'Role to delete', 'digitizer-pro-tools' ); ?><br>
							<select name="dpt_ure_role">
								<?php foreach ( $roles as $rk => $r ) :
									if ( in_array( $rk, DPT_URE_Manager::undeletable_roles(), true ) ) { continue; } ?>
									<option value="<?php echo esc_attr( $rk ); ?>"><?php echo esc_html( $r['name'] . ' (' . $rk . ')' ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<p><label><?php esc_html_e( 'Move its users to', 'digitizer-pro-tools' ); ?><br>
							<select name="dpt_ure_reassign">
								<?php foreach ( $roles as $rk => $r ) : ?>
									<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $rk, (string) get_option( 'default_role' ) ); ?>><?php echo esc_html( $r['name'] ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Delete Role', 'digitizer-pro-tools' ); ?></button></p>
					</form>
				</div>

				<div class="dpt-panel" style="flex:1;min-width:280px;">
					<h2><span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'Add a custom capability', 'digitizer-pro-tools' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_ure_add_cap" />
						<?php wp_nonce_field( 'dpt_ure_add_cap' ); ?>
						<p><label><?php esc_html_e( 'Capability name', 'digitizer-pro-tools' ); ?><br>
							<input type="text" name="dpt_ure_cap" class="regular-text" placeholder="manage_bookings" required /></label></p>
						<p><strong><?php esc_html_e( 'Grant to roles', 'digitizer-pro-tools' ); ?></strong></p>
						<?php foreach ( $roles as $rk => $r ) : ?>
							<label style="display:block;"><input type="checkbox" name="dpt_ure_grant[]" value="<?php echo esc_attr( $rk ); ?>" <?php checked( 'administrator' === $rk ); ?> /> <?php echo esc_html( $r['name'] ); ?></label>
						<?php endforeach; ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add Capability', 'digitizer-pro-tools' ); ?></button></p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
