<?php
/**
 * WooCommerce Checkout module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_WCC_Admin {

	const PAGE_SLUG = 'dpt-woo-checkout';

	public function __construct() {
		add_action( 'admin_post_dpt_wcc_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'WooCommerce Checkout', 'digitizer-pro-tools' ),
			__( 'WooCommerce Checkout', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_wcc_settings' );

		$data = isset( $_POST['dpt_wcc'] ) && is_array( $_POST['dpt_wcc'] ) ? wp_unslash( $_POST['dpt_wcc'] ) : array();
		DPT_WCC_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o          = DPT_WCC_Settings::all();
		$woo_active = class_exists( 'WooCommerce' );
		$domains    = implode( "\n", (array) $o['email_domains'] );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-cart"></span>
				<?php esc_html_e( 'WooCommerce Checkout', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Checkout helpers: suggest fixes for mistyped email domains, and validate Israeli phone numbers.', 'digitizer-pro-tools' ); ?></p>

			<?php if ( ! $woo_active ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active on this site right now - these helpers apply only when it is.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_wcc_save" />
						<?php wp_nonce_field( 'dpt_wcc_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Email suggestions', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Suggest email fixes', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_wcc[email_suggestion]" value="0" />
											<input type="checkbox" name="dpt_wcc[email_suggestion]" value="1" <?php checked( $o['email_suggestion'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'When a customer types an email whose domain looks like a typo of a known provider, offer a one-click correction.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Known email domains', 'digitizer-pro-tools' ); ?></th>
									<td>
										<textarea name="dpt_wcc[email_domains]" rows="8" class="large-text code" placeholder="gmail.com"><?php echo esc_textarea( $domains ); ?></textarea>
										<p class="description"><?php esc_html_e( 'One domain per line. Typos of these are what trigger a suggestion. Leave empty to restore the default list.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Phone validation', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Validate Israeli phone numbers', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_wcc[phone_validation]" value="0" />
											<input type="checkbox" name="dpt_wcc[phone_validation]" value="1" <?php checked( $o['phone_validation'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Accepts numbers starting with 05 (10 digits), 972 (12) or +972 (13). Checked both in the browser and on the server, so checkout is blocked on an invalid number.', 'digitizer-pro-tools' ); ?></p>
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
