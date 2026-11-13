<?php
/** Settings screen for alternate palettes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_TS_Admin {
	const PAGE_SLUG = 'dpt-theme-switch';
	public function __construct() { add_action( 'admin_post_dpt_ts_save', array( $this, 'handle_save' ) ); }
	public function register_menu( $parent_slug ) {
		add_submenu_page( $parent_slug, __( 'Theme Switch', 'digitizer-pro-tools' ), __( 'Theme Switch', 'digitizer-pro-tools' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_page' ) );
	}
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) ); }
		check_admin_referer( 'dpt_ts_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- All values are allowlisted in Settings::clean().
		$raw = isset( $_POST['dpt_ts'] ) && is_array( $_POST['dpt_ts'] ) ? wp_unslash( $_POST['dpt_ts'] ) : array();
		$colors = isset( $raw['colors'] ) && is_array( $raw['colors'] ) ? $raw['colors'] : array();
		if ( isset( $raw['custom'] ) && is_string( $raw['custom'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw['custom'] ) as $line ) {
				$pair = explode( '=', $line, 2 );
				if ( 2 === count( $pair ) ) { $colors[ trim( $pair[0] ) ] = trim( $pair[1] ); }
			}
		}
		$raw['colors'] = $colors;
		DPT_TS_Settings::save( $raw );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$o = DPT_TS_Settings::all();
		$kit = DPT_TS_Settings::elementor_colors();
		$custom = array();
		foreach ( $o['colors'] as $name => $color ) {
			if ( ! isset( $kit[ $name ] ) ) { $custom[] = $name . '=' . $color; }
		}
		?>
		<div class="wrap dpt-wrap">
			<h1><?php esc_html_e( 'Theme Switch', 'digitizer-pro-tools' ); ?></h1>
			<?php if ( isset( $_GET['dpt_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only. ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Keep your original design and choose the colors for its alternate mode. Place [dpt_theme_switch] in a page, header or Elementor Shortcode widget.', 'digitizer-pro-tools' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_ts_save" />
				<?php wp_nonce_field( 'dpt_ts_settings' ); ?>
				<table class="form-table">
					<tr><th><label for="dpt-ts-base"><?php esc_html_e( 'Original palette', 'digitizer-pro-tools' ); ?></label></th><td>
						<select id="dpt-ts-base" name="dpt_ts[base]">
							<option value="light" <?php selected( $o['base'], 'light' ); ?>><?php esc_html_e( 'Light', 'digitizer-pro-tools' ); ?></option>
							<option value="dark" <?php selected( $o['base'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'digitizer-pro-tools' ); ?></option>
						</select>
					</td></tr>
					<tr><th><label for="dpt-ts-default"><?php esc_html_e( 'First visit', 'digitizer-pro-tools' ); ?></label></th><td>
						<select id="dpt-ts-default" name="dpt_ts[default]">
							<option value="system" <?php selected( $o['default'], 'system' ); ?>><?php esc_html_e( 'System', 'digitizer-pro-tools' ); ?></option>
							<option value="light" <?php selected( $o['default'], 'light' ); ?>><?php esc_html_e( 'Light', 'digitizer-pro-tools' ); ?></option>
							<option value="dark" <?php selected( $o['default'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'digitizer-pro-tools' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'A saved visitor choice takes priority. System follows the device preference.', 'digitizer-pro-tools' ); ?></p>
					</td></tr>
				</table>
				<h2><?php esc_html_e( 'Alternate palette', 'digitizer-pro-tools' ); ?></h2>
				<p><?php esc_html_e( 'Enter hex colors, such as #18181b or #ffffff. Leave a color empty to keep the original. These values apply only in the mode opposite your original palette.', 'digitizer-pro-tools' ); ?></p>
				<?php if ( $kit ) : ?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Elementor color', 'digitizer-pro-tools' ); ?></th><th><?php esc_html_e( 'Original color', 'digitizer-pro-tools' ); ?></th><th><?php esc_html_e( 'Alternate color', 'digitizer-pro-tools' ); ?></th></tr></thead><tbody>
					<?php $i = 0; foreach ( $kit as $name => $entry ) : $i++; ?>
						<tr><td><label for="dpt-ts-color-<?php echo (int) $i; ?>"><?php echo esc_html( $entry['label'] ); ?></label></td><td><code dir="ltr"><?php echo esc_html( $entry['color'] ); ?></code></td><td>
							<input id="dpt-ts-color-<?php echo (int) $i; ?>" type="text" dir="ltr" pattern="#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})" name="dpt_ts[colors][<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( isset( $o['colors'][ $name ] ) ? $o['colors'][ $name ] : '' ); ?>" placeholder="#18181b" />
						</td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<p><label for="dpt-ts-custom"><?php esc_html_e( 'Additional color variables', 'digitizer-pro-tools' ); ?></label></p>
				<textarea id="dpt-ts-custom" class="large-text code" dir="ltr" rows="5" name="dpt_ts[custom]" placeholder="--surface=#18181b"><?php echo esc_textarea( implode( "\n", $custom ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One CSS variable and alternate hex color per line: --surface=#18181b. Fixed widget colors and locally overridden variables need changes in the site design. Images and logos keep their original appearance.', 'digitizer-pro-tools' ); ?></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
