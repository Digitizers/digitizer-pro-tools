<?php
/**
 * Hide Login module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_HL_Admin {

	const PAGE_SLUG = 'dpt-hide-login';

	public function __construct() {
		add_action( 'admin_post_dpt_hl_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Hide Login', 'digitizer-pro-tools' ),
			__( 'Hide Login', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_show_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_invalid_slug'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That slug is empty, invalid or reserved by WordPress - the previous slug was kept.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_hl_settings' );

		$data = isset( $_POST['dpt_hl'] ) && is_array( $_POST['dpt_hl'] ) ? $_POST['dpt_hl'] : array();

		$requested = isset( $data['slug'] ) && ! is_array( $data['slug'] ) ? wp_unslash( $data['slug'] ) : '';
		$rejected  = '' === DPT_HL_Settings::sanitize_slug( $requested );

		DPT_HL_Settings::save( $data );

		$args = array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 );
		if ( $rejected ) {
			unset( $args['dpt_saved'] );
			$args['dpt_invalid_slug'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o         = DPT_HL_Settings::all();
		$login_url = DPT_HL_Settings::new_login_url();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-hidden"></span>
				<?php esc_html_e( 'Hide Login', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Moves the login page to a custom URL. The default wp-login.php returns a 404 page and logged-out visitors of wp-admin are sent to a 404 as well.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_hl_save" />
						<?php wp_nonce_field( 'dpt_hl_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'Login URL', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Login slug', 'digitizer-pro-tools' ); ?></th>
									<td>
										<code><?php echo esc_html( untrailingslashit( home_url( '/' ) ) ); ?>/</code>
										<input type="text" name="dpt_hl[slug]" value="<?php echo esc_attr( $o['slug'] ); ?>" class="regular-text" style="width:160px;" />
										<p class="description"><?php esc_html_e( 'Lowercase letters, digits, dashes and underscores only. WordPress core slugs are rejected.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Current login URL', 'digitizer-pro-tools' ); ?></th>
									<td>
										<a href="<?php echo esc_url( $login_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $login_url ); ?></code></a>
										<p class="description"><strong><?php esc_html_e( 'Bookmark this URL - wp-login.php will stop working while this module is enabled.', 'digitizer-pro-tools' ); ?></strong></p>
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
