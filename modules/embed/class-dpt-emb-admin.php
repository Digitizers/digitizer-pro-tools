<?php
/**
 * Embed module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_EMB_Admin {

	const PAGE_SLUG = 'dpt-embed';

	public function __construct() {
		add_action( 'admin_post_dpt_emb_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Embed', 'digitizer-pro-tools' ),
			__( 'Embed', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_show_notices() {
		if ( isset( $_GET['page'], $_GET['dpt_saved'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_emb_settings' );

		$data = isset( $_POST['dpt_emb'] ) && is_array( $_POST['dpt_emb'] ) ? wp_unslash( $_POST['dpt_emb'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		DPT_EMB_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_EMB_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-media-document"></span>
				<?php esc_html_e( 'Embed', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Embed PDF files and Google Docs/Sheets/Slides/Forms/Drive links in a responsive frame with the [dpt_embed] shortcode.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_emb_save" />
						<?php wp_nonce_field( 'dpt_emb_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Frame defaults', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Default aspect ratio', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="text" class="regular-text" name="dpt_emb[default_ratio]" value="<?php echo esc_attr( $o['default_ratio'] ); ?>" placeholder="4:3" />
										<p class="description"><?php esc_html_e( 'Width:height, e.g. 16:9 or 4:3. Used when a shortcode sets no height.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Default height (px)', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="number" min="0" step="1" class="small-text" name="dpt_emb[default_height]" value="<?php echo esc_attr( $o['default_height'] ); ?>" />
										<p class="description"><?php esc_html_e( 'Leave blank to use the aspect ratio. A fixed height suits long PDFs.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Lazy-load frames', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_emb[lazy_load]" value="0" />
											<input type="checkbox" name="dpt_emb[lazy_load]" value="1" <?php checked( $o['lazy_load'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Defer loading each frame until it scrolls into view.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'How to use', 'digitizer-pro-tools' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Add the shortcode to any post, page or widget:', 'digitizer-pro-tools' ); ?></p>
							<p><code>[dpt_embed url="https://example.com/file.pdf"]</code></p>
							<p><code>[dpt_embed url="https://docs.google.com/document/d/ID/edit" ratio="16:9"]</code></p>
							<p><code>[dpt_embed url="https://example.com/file.pdf" height="800" title="Price list"]</code></p>
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
