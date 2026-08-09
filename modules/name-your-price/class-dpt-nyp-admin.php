<?php
/**
 * Name Your Price module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_NYP_Admin {

	const PAGE_SLUG = 'dpt-name-your-price';

	public function __construct() {
		add_action( 'admin_post_dpt_nyp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Name Your Price', 'digitizer-pro-tools' ),
			__( 'Name Your Price', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_nyp_settings' );

		$data = isset( $_POST['dpt_nyp'] ) && is_array( $_POST['dpt_nyp'] ) ? wp_unslash( $_POST['dpt_nyp'] ) : array();
		DPT_NYP_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o          = DPT_NYP_Settings::all();
		$woo_active = class_exists( 'WooCommerce' );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-tag"></span>
				<?php esc_html_e( 'Name Your Price', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Let customers set their own price on chosen products. Enable it per product on the product edit screen (Product data > General), then set optional minimum, maximum and suggested prices.', 'digitizer-pro-tools' ); ?></p>

			<?php if ( ! $woo_active ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active on this site right now - this module applies only when it is.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_nyp_save" />
						<?php wp_nonce_field( 'dpt_nyp_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'Display', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Field label', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="text" name="dpt_nyp[label]" value="<?php echo esc_attr( $o['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Name your price', 'digitizer-pro-tools' ); ?>" />
										<p class="description"><?php esc_html_e( 'Shown next to the price input on the product page.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Show allowed range', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_nyp[show_range_hint]" value="0" />
											<input type="checkbox" name="dpt_nyp[show_range_hint]" value="1" <?php checked( $o['show_range_hint'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Display the allowed minimum/maximum under the price field.', 'digitizer-pro-tools' ); ?></p>
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
