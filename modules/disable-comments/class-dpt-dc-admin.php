<?php
/**
 * Disable Comments module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_DC_Admin {

	const PAGE_SLUG = 'dpt-disable-comments';

	public function __construct() {
		add_action( 'admin_post_dpt_dc_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Disable Comments', 'digitizer-pro-tools' ),
			__( 'Disable Comments', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_dc_settings' );

		$data = isset( $_POST['dpt_dc'] ) && is_array( $_POST['dpt_dc'] ) ? $_POST['dpt_dc'] : array();
		DPT_DC_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o           = DPT_DC_Settings::all();
		$woo_active  = post_type_exists( 'product' );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-testimonial"></span>
				<?php esc_html_e( 'Disable Comments', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Closes comment forms, hides existing comments and removes the admin comments UI (menu, admin-bar bubble, dashboard widget, edit-comments screen). The admin UI is removed only when comments are disabled for every relevant post type.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_dc_save" />
						<?php wp_nonce_field( 'dpt_dc_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-testimonial"></span> <?php esc_html_e( 'Where to disable comments', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Mode', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label style="display:block;margin-bottom:6px;">
											<input type="radio" name="dpt_dc[mode]" value="all" <?php checked( $o['mode'], 'all' ); ?> />
											<?php esc_html_e( 'Everywhere (all post types)', 'digitizer-pro-tools' ); ?>
										</label>
										<label style="display:block;">
											<input type="radio" name="dpt_dc[mode]" value="selected" <?php checked( $o['mode'], 'selected' ); ?> />
											<?php esc_html_e( 'Only on selected post types', 'digitizer-pro-tools' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Selected post types', 'digitizer-pro-tools' ); ?></th>
									<td>
										<?php foreach ( DPT_DC_Settings::comment_post_types() as $type ) :
											$type_object = get_post_type_object( $type );
											if ( ! $type_object ) {
												continue;
											}
											?>
											<label style="display:block;margin-bottom:6px;">
												<input type="checkbox" name="dpt_dc[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $o['post_types'], true ) ); ?> />
												<?php echo esc_html( $type_object->labels->name ); ?> <code><?php echo esc_html( $type ); ?></code>
											</label>
										<?php endforeach; ?>
										<p class="description"><?php esc_html_e( 'Used only in "Only on selected post types" mode.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Keep WooCommerce product reviews', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_dc[keep_woo_reviews]" value="0" />
											<input type="checkbox" name="dpt_dc[keep_woo_reviews]" value="1" <?php checked( $o['keep_woo_reviews'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description">
											<?php esc_html_e( 'Product reviews are technically comments - this keeps them working even when comments are disabled everywhere else.', 'digitizer-pro-tools' ); ?>
											<?php if ( ! $woo_active ) : ?>
												<em><?php esc_html_e( '(WooCommerce is not active on this site right now.)', 'digitizer-pro-tools' ); ?></em>
											<?php endif; ?>
										</p>
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
