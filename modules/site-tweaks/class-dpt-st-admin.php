<?php
/**
 * Site Tweaks module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ST_Admin {

	const PAGE_SLUG = 'dpt-site-tweaks';

	public function __construct() {
		add_action( 'admin_post_dpt_st_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Site Tweaks', 'digitizer-pro-tools' ),
			__( 'Site Tweaks', 'digitizer-pro-tools' ),
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
		check_admin_referer( 'dpt_st_settings' );

		$data = isset( $_POST['dpt_st'] ) && is_array( $_POST['dpt_st'] ) ? wp_unslash( $_POST['dpt_st'] ) : array();
		DPT_ST_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render one on/off switch row.
	 */
	private function switch_row( $key, $label, $description, $value, $extra = '' ) {
		?>
		<tr>
			<th><?php echo esc_html( $label ); ?></th>
			<td>
				<label class="dpt-switch">
					<input type="hidden" name="dpt_st[<?php echo esc_attr( $key ); ?>]" value="0" />
					<input type="checkbox" name="dpt_st[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value, '1' ); ?> />
					<span class="dpt-switch-slider"></span>
				</label>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo wp_kses( $description, array( 'code' => array(), 'em' => array(), 'strong' => array() ) ); ?></p>
				<?php endif; ?>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller passes pre-escaped markup. ?>
			</td>
		</tr>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o                = DPT_ST_Settings::all();
		$elementor_active = did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
		$svg_cap          = DPT_ST_Settings::svg_capability();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-admin-tools"></span>
				<?php esc_html_e( 'Site Tweaks', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Small site-wide tweaks, each an independent toggle. Replaces the assorted functions.php snippets.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_st_save" />
						<?php wp_nonce_field( 'dpt_st_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'HTTP security headers', 'digitizer-pro-tools' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Sent on front-end responses. Verify with your browser dev tools after enabling.', 'digitizer-pro-tools' ); ?></p>
							<table class="form-table dpt-form">
								<?php
								$this->switch_row( 'x_frame_options', __( 'X-Frame-Options: SAMEORIGIN', 'digitizer-pro-tools' ), __( 'Stops other sites from framing yours (clickjacking protection).', 'digitizer-pro-tools' ), $o['x_frame_options'] );
								$this->switch_row( 'x_content_type_options', __( 'X-Content-Type-Options: nosniff', 'digitizer-pro-tools' ), __( 'Stops browsers from MIME-sniffing responses.', 'digitizer-pro-tools' ), $o['x_content_type_options'] );
								$this->switch_row( 'x_xss_protection', __( 'X-XSS-Protection', 'digitizer-pro-tools' ), __( '<em>Legacy.</em> Deprecated and ignored by modern browsers; included only for parity with old snippets.', 'digitizer-pro-tools' ), $o['x_xss_protection'] );
								$this->switch_row(
									'hsts',
									__( 'Strict-Transport-Security (HSTS)', 'digitizer-pro-tools' ),
									__( 'Forces HTTPS for a year. <strong>Only enable once HTTPS is solid</strong> - browsers cache this and it is hard to undo. Sent over HTTPS only.', 'digitizer-pro-tools' ),
									$o['hsts']
								);
								$this->switch_row( 'hsts_preload', __( 'HSTS: includeSubDomains; preload', 'digitizer-pro-tools' ), __( 'Extends HSTS to <strong>every subdomain</strong> and marks the site for browser preload lists (near-permanent). Only with HSTS on, and only if every subdomain is HTTPS.', 'digitizer-pro-tools' ), $o['hsts_preload'] );
								?>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'SVG uploads', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<?php
								$this->switch_row(
									'svg_upload',
									__( 'Allow SVG uploads', 'digitizer-pro-tools' ),
									sprintf(
										/* translators: %s: capability name */
										__( 'Every SVG is sanitised on upload (scripts, event handlers and external references are stripped). Allowed only for users with the <code>%s</code> capability.', 'digitizer-pro-tools' ),
										$svg_cap
									),
									$o['svg_upload']
								);
								?>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-hidden"></span> <?php esc_html_e( 'Hide WordPress version', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<?php
								$this->switch_row( 'remove_generator', __( 'Remove version info', 'digitizer-pro-tools' ), __( 'Removes the generator meta tag/RSS marker and the <code>?ver=</code> WordPress version from core asset URLs. Plugin and theme asset versions are kept, so cache-busting still works.', 'digitizer-pro-tools' ), $o['remove_generator'] );
								?>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Elementor', 'digitizer-pro-tools' ); ?></h2>
							<?php if ( ! $elementor_active ) : ?>
								<p class="description"><em><?php esc_html_e( 'Elementor is not active on this site right now - these tweaks apply only when it is.', 'digitizer-pro-tools' ); ?></em></p>
							<?php endif; ?>
							<table class="form-table dpt-form">
								<?php
								$this->switch_row( 'elementor_google_fonts', __( 'Disable Elementor Google Fonts', 'digitizer-pro-tools' ), __( 'Stops Elementor loading fonts from Google (privacy/performance). Use only if your fonts are hosted locally.', 'digitizer-pro-tools' ), $o['elementor_google_fonts'] );
								$this->switch_row( 'elementor_tel_validate', __( 'Validate phone fields (Elementor Pro)', 'digitizer-pro-tools' ), __( 'Rejects malformed numbers in Elementor Pro form <code>tel</code> fields (9-14 digits, optional leading +).', 'digitizer-pro-tools' ), $o['elementor_tel_validate'] );
								?>
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
