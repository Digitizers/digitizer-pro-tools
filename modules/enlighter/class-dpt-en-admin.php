<?php
/**
 * Enlighter module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_EN_Admin {

	const PAGE_SLUG = 'dpt-enlighter';

	public function __construct() {
		add_action( 'admin_post_dpt_en_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Enlighter', 'digitizer-pro-tools' ),
			__( 'Enlighter', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_en_settings' );
		$data = isset( $_POST['dpt_en'] ) && is_array( $_POST['dpt_en'] ) ? $_POST['dpt_en'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		DPT_EN_Settings::save( $data );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_EN_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-editor-code"></span>
				<?php esc_html_e( 'Enlighter', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Syntax-highlight code on the front end. Insert code with the "Code (Enlighter)" block, the [dpt_code lang="php"]…[/dpt_code] shortcode, or the Elementor widget. Optionally highlight every existing pre/code block automatically.', 'digitizer-pro-tools' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_en_save" />
				<?php wp_nonce_field( 'dpt_en_settings' ); ?>

				<div class="dpt-panel">
					<h2><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Appearance', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table dpt-form">
						<tr>
							<th><?php esc_html_e( 'Theme', 'digitizer-pro-tools' ); ?></th>
							<td>
								<select name="dpt_en[theme]">
									<option value="auto" <?php selected( $o['theme'], 'auto' ); ?>><?php esc_html_e( 'Auto (follow visitor)', 'digitizer-pro-tools' ); ?></option>
									<option value="light" <?php selected( $o['theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'digitizer-pro-tools' ); ?></option>
									<option value="dark" <?php selected( $o['theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'digitizer-pro-tools' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default language', 'digitizer-pro-tools' ); ?></th>
							<td>
								<select name="dpt_en[default_lang]">
									<?php foreach ( DPT_EN_Settings::languages() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o['default_lang'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Line numbers', 'digitizer-pro-tools' ); ?></th>
							<td><label class="dpt-switch"><input type="hidden" name="dpt_en[line_numbers]" value="0" /><input type="checkbox" name="dpt_en[line_numbers]" value="1" <?php checked( $o['line_numbers'], '1' ); ?> /><span class="dpt-switch-slider"></span></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Copy button', 'digitizer-pro-tools' ); ?></th>
							<td><label class="dpt-switch"><input type="hidden" name="dpt_en[copy_button]" value="0" /><input type="checkbox" name="dpt_en[copy_button]" value="1" <?php checked( $o['copy_button'], '1' ); ?> /><span class="dpt-switch-slider"></span></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Auto-highlight pre/code', 'digitizer-pro-tools' ); ?></th>
							<td>
								<label class="dpt-switch"><input type="hidden" name="dpt_en[auto_highlight]" value="0" /><input type="checkbox" name="dpt_en[auto_highlight]" value="1" <?php checked( $o['auto_highlight'], '1' ); ?> /><span class="dpt-switch-slider"></span></label>
								<p class="description"><?php esc_html_e( 'Highlight every existing <pre><code> block, detecting the language from its class (e.g. language-php).', 'digitizer-pro-tools' ); ?></p>
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
