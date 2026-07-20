<?php
/**
 * Resend Mail module - settings page, test email and send log.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RM_Admin {

	const PAGE_SLUG = 'dpt-resend-mail';

	public function __construct() {
		add_action( 'admin_post_dpt_rm_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dpt_rm_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_dpt_rm_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Resend Mail', 'digitizer-pro-tools' ),
			__( 'Resend Mail', 'digitizer-pro-tools' ),
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
		if ( isset( $_GET['dpt_test_sent'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent - check the inbox and the log below.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_test_fallback'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The Resend API rejected the test email - it went out through the default WordPress mailer instead. Check the log below for the error.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_test_failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The test email failed to send. Check the log below for the error.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_test_invalid'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Enter a valid email address for the test.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_not_configured'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Add an API key and a valid sender address first - until then emails go through the default WordPress mailer.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_log_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Send log cleared.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_rm_settings' );

		$data = isset( $_POST['dpt_rm'] ) && is_array( $_POST['dpt_rm'] ) ? $_POST['dpt_rm'] : array();
		DPT_RM_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_rm_test' );

		$args = array( 'page' => self::PAGE_SLUG );

		if ( ! DPT_RM_Settings::is_configured() ) {
			$args['dpt_not_configured'] = 1;
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$to = isset( $_POST['dpt_rm_test_to'] ) && ! is_array( $_POST['dpt_rm_test_to'] ) ? sanitize_email( wp_unslash( $_POST['dpt_rm_test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$args['dpt_test_invalid'] = 1;
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Resend Mail test from %s', 'digitizer-pro-tools' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$message = __( 'This is a test email sent through the Resend API by the Digitizer Pro Tools plugin.', 'digitizer-pro-tools' )
			. "\n\n" . __( 'If you are reading it, delivery works.', 'digitizer-pro-tools' );

		$sent = wp_mail( $to, $subject, $message );

		// wp_mail() returning true is not enough: with fallback enabled a
		// Resend rejection still goes out via the default mailer. Report
		// what actually happened to the Resend call.
		if ( $sent && 'sent' === DPT_RM_Sender::$last_result ) {
			$args['dpt_test_sent'] = 1;
		} elseif ( $sent ) {
			$args['dpt_test_fallback'] = 1;
		} else {
			$args['dpt_test_failed'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_rm_clear_log' );

		DPT_RM_Log::clear();

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_log_cleared' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function status_label( $status ) {
		$labels = array(
			'sent'             => __( 'Sent', 'digitizer-pro-tools' ),
			'delivered'        => __( 'Delivered', 'digitizer-pro-tools' ),
			'delivery_delayed' => __( 'Delayed', 'digitizer-pro-tools' ),
			'opened'           => __( 'Opened', 'digitizer-pro-tools' ),
			'clicked'          => __( 'Clicked', 'digitizer-pro-tools' ),
			'bounced'          => __( 'Bounced', 'digitizer-pro-tools' ),
			'complained'       => __( 'Marked as spam', 'digitizer-pro-tools' ),
			'failed'           => __( 'Failed', 'digitizer-pro-tools' ),
			'fallback'         => __( 'Fell back to default mailer', 'digitizer-pro-tools' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private function status_class( $status ) {
		if ( in_array( $status, array( 'failed', 'bounced', 'complained' ), true ) ) {
			return 'dpt-rm-status-bad';
		}
		if ( in_array( $status, array( 'fallback', 'delivery_delayed' ), true ) ) {
			return 'dpt-rm-status-warn';
		}
		return 'dpt-rm-status-ok';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o          = DPT_RM_Settings::all();
		$configured = DPT_RM_Settings::is_configured();
		$entries    = DPT_RM_Log::entries();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Resend Mail', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Sends every email this site generates (wp_mail) through the Resend API instead of the server\'s PHP mailer. Deactivate any other SMTP plugin - two mailers will fight over the same emails.', 'digitizer-pro-tools' ); ?></p>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add an API key and a valid sender address first - until then emails go through the default WordPress mailer.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_rm_save" />
						<?php wp_nonce_field( 'dpt_rm_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'Resend API', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'API key', 'digitizer-pro-tools' ); ?></th>
									<td>
										<?php if ( DPT_RM_Settings::has_constant_key() ) : ?>
											<code><?php echo esc_html( DPT_RM_Settings::masked_key() ); ?></code>
											<p class="description"><?php esc_html_e( 'Defined by the DPT_RESEND_API_KEY constant in wp-config.php - it overrides anything entered here.', 'digitizer-pro-tools' ); ?></p>
										<?php else : ?>
											<input type="password" name="dpt_rm[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $o['api_key'] ? DPT_RM_Settings::masked_key() : 're_...' ); ?>" />
											<p class="description"><?php esc_html_e( 'Create a key in the Resend dashboard under API Keys. Leave empty to keep the saved key.', 'digitizer-pro-tools' ); ?></p>
											<?php if ( '' !== $o['api_key'] ) : ?>
												<label><input type="checkbox" name="dpt_rm[forget_api_key]" value="1" /> <?php esc_html_e( 'Forget the saved key', 'digitizer-pro-tools' ); ?></label>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'From email', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="email" name="dpt_rm[from_email]" value="<?php echo esc_attr( $o['from_email'] ); ?>" class="regular-text" placeholder="hello@yourdomain.com" />
										<p class="description"><?php esc_html_e( 'Must be an address on a domain you verified in Resend (Domains screen), otherwise the API rejects every email.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'From name', 'digitizer-pro-tools' ); ?></th>
									<td><input type="text" name="dpt_rm[from_name]" value="<?php echo esc_attr( $o['from_name'] ); ?>" class="regular-text" /></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Force sender', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_rm[force_from]" value="0" />
											<input type="checkbox" name="dpt_rm[force_from]" value="1" <?php checked( '1', $o['force_from'] ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Always send from the address above, even when another plugin sets its own From address. Recommended - Resend only accepts verified senders.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Default Reply-To', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="email" name="dpt_rm[reply_to]" value="<?php echo esc_attr( $o['reply_to'] ); ?>" class="regular-text" />
										<p class="description"><?php esc_html_e( 'Optional. Used when the email does not carry its own Reply-To header.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Behavior', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Fallback on errors', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_rm[fallback_on_error]" value="0" />
											<input type="checkbox" name="dpt_rm[fallback_on_error]" value="1" <?php checked( '1', $o['fallback_on_error'] ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'If the Resend API returns an error, hand the email to the default WordPress mailer instead of dropping it.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Send log', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_rm[log_enabled]" value="0" />
											<input type="checkbox" name="dpt_rm[log_enabled]" value="1" <?php checked( '1', $o['log_enabled'] ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description">
											<?php
											printf(
												/* translators: %d: number of log entries kept. */
												esc_html__( 'Keep the last %d sends with their delivery status.', 'digitizer-pro-tools' ),
												(int) DPT_RM_Log::MAX_ENTRIES
											);
											?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'Delivery webhook (optional)', 'digitizer-pro-tools' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Lets the send log show what happened after the send: delivered, bounced, opened, marked as spam. In the Resend dashboard add a webhook pointing at the URL below, pick the email events, then paste its signing secret here.', 'digitizer-pro-tools' ); ?></p>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Endpoint URL', 'digitizer-pro-tools' ); ?></th>
									<td><code><?php echo esc_html( DPT_RM_Webhook::endpoint_url() ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Signing secret', 'digitizer-pro-tools' ); ?></th>
									<td>
										<input type="text" name="dpt_rm[webhook_secret]" value="<?php echo esc_attr( $o['webhook_secret'] ); ?>" class="regular-text" placeholder="whsec_..." />
										<p class="description"><?php esc_html_e( 'Unsigned webhook calls are rejected while this is empty.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<p class="dpt-actions">
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
						</p>
					</form>

					<div class="dpt-panel">
						<h2><span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Send a test email', 'digitizer-pro-tools' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="dpt_rm_test" />
							<?php wp_nonce_field( 'dpt_rm_test' ); ?>
							<input type="email" name="dpt_rm_test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
							<button type="submit" class="button"><?php esc_html_e( 'Send test', 'digitizer-pro-tools' ); ?></button>
						</form>
					</div>

					<div class="dpt-panel">
						<h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Send log', 'digitizer-pro-tools' ); ?></h2>
						<?php if ( empty( $entries ) ) : ?>
							<p class="description"><?php esc_html_e( 'No emails logged yet.', 'digitizer-pro-tools' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Time', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'To', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'Subject', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'Status', 'digitizer-pro-tools' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $entries as $entry ) : ?>
										<tr>
											<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', (int) $entry['time'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?></td>
											<td><?php echo esc_html( $entry['to'] ); ?></td>
											<td><?php echo esc_html( $entry['subject'] ); ?></td>
											<td>
												<span class="dpt-rm-status <?php echo esc_attr( $this->status_class( $entry['status'] ) ); ?>"><?php echo esc_html( $this->status_label( $entry['status'] ) ); ?></span>
												<?php if ( '' !== $entry['error'] ) : ?>
													<p class="description"><?php echo esc_html( $entry['error'] ); ?></p>
												<?php endif; ?>
												<?php if ( '' !== $entry['note'] ) : ?>
													<p class="description"><?php echo esc_html( $entry['note'] ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
								<input type="hidden" name="action" value="dpt_rm_clear_log" />
								<?php wp_nonce_field( 'dpt_rm_clear_log' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'digitizer-pro-tools' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<style>
			.dpt-rm-status { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; }
			.dpt-rm-status-ok { background:#d5f5e3; color:#1e6e3e; }
			.dpt-rm-status-warn { background:#fdf3d7; color:#8a6d1a; }
			.dpt-rm-status-bad { background:#fadbd8; color:#a02a1e; }
		</style>
		<?php
	}
}
