<?php
/**
 * Update Emails module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UE_Admin {

	const PAGE_SLUG = 'dpt-update-emails';

	public function __construct() {
		add_action( 'admin_post_dpt_ue_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Update Emails', 'digitizer-pro-tools' ),
			__( 'Update Emails', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_ue_settings' );

		$data = isset( $_POST['dpt_ue'] ) && is_array( $_POST['dpt_ue'] ) ? $_POST['dpt_ue'] : array();
		DPT_UE_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function switch_field( $name, $checked ) {
		?>
		<label class="dpt-switch">
			<input type="hidden" name="dpt_ue[<?php echo esc_attr( $name ); ?>]" value="0" />
			<input type="checkbox" name="dpt_ue[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked, '1' ); ?> />
			<span class="dpt-switch-slider"></span>
		</label>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_UE_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Update Emails', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'WordPress emails the site admin after every automatic update. On a maintained site these routine "success" notifications are noise - this module silences them. Emails about FAILED updates are never suppressed.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_ue_save" />
						<?php wp_nonce_field( 'dpt_ue_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Silence notifications for', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Plugin auto-updates', 'digitizer-pro-tools' ); ?></th>
									<td><?php $this->switch_field( 'disable_plugin_emails', $o['disable_plugin_emails'] ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Theme auto-updates', 'digitizer-pro-tools' ); ?></th>
									<td><?php $this->switch_field( 'disable_theme_emails', $o['disable_theme_emails'] ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Core auto-updates (successful only)', 'digitizer-pro-tools' ); ?></th>
									<td>
										<?php $this->switch_field( 'disable_core_success_emails', $o['disable_core_success_emails'] ); ?>
										<p class="description"><?php esc_html_e( 'Failure and critical core-update emails always go out.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<p class="dpt-actions">
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
