<?php
/**
 * Content Control module - settings page (whole-site protection + defaults).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Admin {

	const PAGE_SLUG = 'dpt-content-control';

	public function __construct() {
		add_action( 'admin_post_dpt_cc_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Content Control', 'digitizer-pro-tools' ),
			__( 'Content Control', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_show_notices() {
		if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] && isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_cc_settings' );
		$data = isset( $_POST['dpt_cc'] ) && is_array( $_POST['dpt_cc'] ) ? wp_unslash( $_POST['dpt_cc'] ) : array();
		DPT_CC_Settings::save( $data );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$out = array();
		foreach ( get_editable_roles() as $key => $role ) {
			$out[ $key ] = translate_user_role( $role['name'] );
		}
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_CC_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-lock"></span>
				<?php esc_html_e( 'Content Control', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Protect the whole site behind login and set the default restriction message. Per-page restrictions and per-menu-item visibility are set on each page and menu item; wrap partial content with the [dpt_restrict] shortcode.', 'digitizer-pro-tools' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_cc_save" />
				<?php wp_nonce_field( 'dpt_cc_settings' ); ?>

				<div class="dpt-panel">
					<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Whole-site protection', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table dpt-form">
						<tr>
							<th><?php esc_html_e( 'Mode', 'digitizer-pro-tools' ); ?></th>
							<td>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_mode]" value="off" <?php checked( $o['site_mode'], 'off' ); ?> /> <?php esc_html_e( 'Off - the site is public', 'digitizer-pro-tools' ); ?></label>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_mode]" value="logged_in" <?php checked( $o['site_mode'], 'logged_in' ); ?> /> <?php esc_html_e( 'Require login for the whole site', 'digitizer-pro-tools' ); ?></label>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_mode]" value="roles" <?php checked( $o['site_mode'], 'roles' ); ?> /> <?php esc_html_e( 'Require one of these roles', 'digitizer-pro-tools' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Allowed roles', 'digitizer-pro-tools' ); ?></th>
							<td>
								<?php foreach ( self::roles() as $key => $name ) : ?>
									<label style="display:block;"><input type="checkbox" name="dpt_cc[site_roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $o['site_roles'], true ) ); ?> /> <?php echo esc_html( $name ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Used only in "Require one of these roles" mode. Administrators always have access.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'When access is denied', 'digitizer-pro-tools' ); ?></th>
							<td>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_action]" value="login" <?php checked( $o['site_action'], 'login' ); ?> /> <?php esc_html_e( 'Redirect to the login form', 'digitizer-pro-tools' ); ?></label>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_action]" value="page" <?php checked( $o['site_action'], 'page' ); ?> /> <?php esc_html_e( 'Redirect to a page', 'digitizer-pro-tools' ); ?></label>
								<label style="display:block;"><input type="radio" name="dpt_cc[site_action]" value="message" <?php checked( $o['site_action'], 'message' ); ?> /> <?php esc_html_e( 'Show a message', 'digitizer-pro-tools' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Redirect page', 'digitizer-pro-tools' ); ?></th>
							<td>
								<?php
								wp_dropdown_pages(
									array(
										'name'              => 'dpt_cc[site_redirect]',
										'selected'          => $o['site_redirect'],
										'show_option_none'  => __( '— none —', 'digitizer-pro-tools' ),
										'option_none_value' => 0,
									)
								);
								?>
								<p class="description"><?php esc_html_e( 'Used with "Redirect to a page". This page is always reachable.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Private-site message', 'digitizer-pro-tools' ); ?></th>
							<td><textarea name="dpt_cc[site_message]" rows="3" class="large-text"><?php echo esc_textarea( $o['site_message'] ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Always-allowed page IDs', 'digitizer-pro-tools' ); ?></th>
							<td>
								<input type="text" name="dpt_cc[exempt_ids]" value="<?php echo esc_attr( implode( ', ', $o['exempt_ids'] ) ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Comma-separated page/post IDs reachable even while the site is protected.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="dpt-panel">
					<h2><span class="dashicons dashicons-editor-help"></span> <?php esc_html_e( 'Default restriction message', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table dpt-form">
						<tr>
							<th><?php esc_html_e( 'Message', 'digitizer-pro-tools' ); ?></th>
							<td>
								<textarea name="dpt_cc[default_message]" rows="3" class="large-text"><?php echo esc_textarea( $o['default_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown in place of restricted page/post content when no per-page message is set.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<p class="dpt-actions"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
