<?php
/**
 * Rank Math Breadcrumbs module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RMB_Admin {

	const PAGE_SLUG = 'dpt-rankmath-breadcrumbs';

	public function __construct() {
		add_action( 'admin_post_dpt_rmb_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Rank Math Breadcrumbs', 'digitizer-pro-tools' ),
			__( 'Rank Math Breadcrumbs', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_rmb_settings' );

		$data = isset( $_POST['dpt_rmb'] ) && is_array( $_POST['dpt_rmb'] ) ? wp_unslash( $_POST['dpt_rmb'] ) : array();
		DPT_RMB_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o           = DPT_RMB_Settings::all();
		$rm_active   = class_exists( 'RankMath' );
		$woo_active  = class_exists( 'WooCommerce' );
		$auto_blog   = get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : '';
		$auto_shop   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-networking"></span>
				<?php esc_html_e( 'Rank Math Breadcrumbs', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Adds a Blog crumb on post pages and a Shop crumb on product pages to the Rank Math breadcrumb trail. Leave a URL or label empty to detect it automatically.', 'digitizer-pro-tools' ); ?></p>

			<?php if ( ! $rm_active ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Rank Math is not active on this site right now - these crumbs apply only when it is.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_rmb_save" />
						<?php wp_nonce_field( 'dpt_rmb_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-post"></span> <?php esc_html_e( 'Blog crumb', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Add a Blog crumb', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_rmb[blog_crumb]" value="0" />
											<input type="checkbox" name="dpt_rmb[blog_crumb]" value="1" <?php checked( $o['blog_crumb'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Shown on single posts and blog archives (category, tag, author, date).', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Label', 'digitizer-pro-tools' ); ?></th>
									<td><input type="text" name="dpt_rmb[blog_label]" value="<?php echo esc_attr( $o['blog_label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Blog (auto)', 'digitizer-pro-tools' ); ?>" /></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'URL', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="url" name="dpt_rmb[blog_url]" value="<?php echo esc_attr( $o['blog_url'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( $auto_blog ? $auto_blog : 'https://example.com/blog/' ); ?>" />
										<p class="description">
											<?php if ( $auto_blog ) : ?>
												<?php
												/* translators: %s: detected URL */
												echo esc_html( sprintf( __( 'Auto-detected: %s', 'digitizer-pro-tools' ), $auto_blog ) );
												?>
											<?php else : ?>
												<?php esc_html_e( 'No posts page is set under Settings > Reading, so enter the blog URL manually.', 'digitizer-pro-tools' ); ?>
											<?php endif; ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Shop crumb', 'digitizer-pro-tools' ); ?></h2>
							<?php if ( ! $woo_active ) : ?>
								<p class="description"><em><?php esc_html_e( 'WooCommerce is not active - the Shop crumb applies to product pages only.', 'digitizer-pro-tools' ); ?></em></p>
							<?php endif; ?>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Add a Shop crumb', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_rmb[shop_crumb]" value="0" />
											<input type="checkbox" name="dpt_rmb[shop_crumb]" value="1" <?php checked( $o['shop_crumb'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Shown on single WooCommerce product pages.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Label', 'digitizer-pro-tools' ); ?></th>
									<td><input type="text" name="dpt_rmb[shop_label]" value="<?php echo esc_attr( $o['shop_label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Shop (auto)', 'digitizer-pro-tools' ); ?>" /></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'URL', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="url" name="dpt_rmb[shop_url]" value="<?php echo esc_attr( $o['shop_url'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( $auto_shop ? $auto_shop : 'https://example.com/shop/' ); ?>" />
										<p class="description">
											<?php if ( $auto_shop ) : ?>
												<?php
												/* translators: %s: detected URL */
												echo esc_html( sprintf( __( 'Auto-detected: %s', 'digitizer-pro-tools' ), $auto_shop ) );
												?>
											<?php else : ?>
												<?php esc_html_e( 'No WooCommerce shop page detected - enter the shop URL manually if needed.', 'digitizer-pro-tools' ); ?>
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
