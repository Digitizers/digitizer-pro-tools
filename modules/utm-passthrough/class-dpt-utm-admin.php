<?php
/**
 * UTM Passthrough module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UTM_Admin {

	const PAGE_SLUG = 'dpt-utm-passthrough';

	public function __construct() {
		add_action( 'admin_post_dpt_utm_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'UTM Passthrough', 'digitizer-pro-tools' ),
			__( 'UTM Passthrough', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_show_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own post-save redirect.
		if ( self::PAGE_SLUG === dpt_current_admin_page() && isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_utm_settings' );

		$data = isset( $_POST['dpt_utm'] ) && is_array( $_POST['dpt_utm'] ) ? wp_unslash( $_POST['dpt_utm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The switch is only compared to '1'; linked_hosts is reduced to bare hostnames by DPT_UTM_Settings::clean_hosts().
		DPT_UTM_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_UTM_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'UTM Passthrough', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'utm_* parameters are always carried onto the site\'s internal links. Choose what else travels with them.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_utm_save" />
						<?php wp_nonce_field( 'dpt_utm_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Also carry', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'HubSpot ad parameters', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_utm[hubspot_ads]" value="0" />
											<input type="checkbox" name="dpt_utm[hubspot_ads]" value="1" <?php checked( $o['hubspot_ads'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'hsa_* and hss_channel. HubSpot\'s email and contact parameters, such as _hsenc and hsutk, are never carried: a shared link would spread them to other visitors.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
							<p class="description"><?php esc_html_e( 'Developers can add parameters by name with the dpt_utm_passthrough_params filter. Everything added here is also ignored by the WP Rocket cache, so it does not cost each page its cache.', 'digitizer-pro-tools' ); ?></p>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Linked domains', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><label for="dpt-utm-linked-hosts"><?php esc_html_e( 'Linked domains', 'digitizer-pro-tools' ); ?></label></th>
									<td>
										<textarea id="dpt-utm-linked-hosts" name="dpt_utm[linked_hosts]" rows="4" cols="40" class="code" dir="ltr" placeholder="www.example.com"><?php echo esc_textarea( implode( "\n", $o['linked_hosts'] ) ); ?></textarea>
										<p class="description"><?php esc_html_e( 'One domain per line, for example a site in another language on its own domain. Links to these domains get the same parameters as the site\'s own links, and nothing else from the address. Leave empty to carry them on this site\'s links only. List the same parameters in Cache Ignore Params on the other site, or each arrival there skips its cache.', 'digitizer-pro-tools' ); ?></p>
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
