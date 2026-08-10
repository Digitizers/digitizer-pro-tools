<?php
/**
 * Checkout Field Editor module - settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_WCF_Admin {

	const PAGE_SLUG = 'dpt-woo-checkout-fields';

	public function __construct() {
		add_action( 'admin_post_dpt_wcf_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Checkout Field Editor', 'digitizer-pro-tools' ),
			__( 'Checkout Fields', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_show_notices() {
		if ( isset( $_GET['page'], $_GET['dpt_saved'] ) && self::PAGE_SLUG === dpt_current_admin_page() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_wcf_settings' );

		$data = isset( $_POST['dpt_wcf'] ) && is_array( $_POST['dpt_wcf'] ) ? wp_unslash( $_POST['dpt_wcf'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		DPT_WCF_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Human labels for the managed standard fields.
	 */
	private function standard_labels() {
		return array(
			'billing_company'   => __( 'Company', 'digitizer-pro-tools' ),
			'billing_address_2' => __( 'Address line 2', 'digitizer-pro-tools' ),
			'billing_phone'     => __( 'Phone', 'digitizer-pro-tools' ),
			'order_comments'    => __( 'Order notes', 'digitizer-pro-tools' ),
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o          = DPT_WCF_Settings::all();
		$woo_active = class_exists( 'WooCommerce' );
		$labels     = $this->standard_labels();

		// Render the existing custom fields plus two spare rows for adding more.
		$custom    = $o['custom'];
		$max       = DPT_WCF_Settings::MAX_CUSTOM;
		$row_count = min( $max, count( $custom ) + 2 );
		$sections  = array(
			'billing'  => __( 'Billing', 'digitizer-pro-tools' ),
			'shipping' => __( 'Shipping', 'digitizer-pro-tools' ),
			'order'    => __( 'Additional info', 'digitizer-pro-tools' ),
		);
		$types = array(
			'text'     => __( 'Text', 'digitizer-pro-tools' ),
			'select'   => __( 'Dropdown', 'digitizer-pro-tools' ),
			'checkbox' => __( 'Checkbox', 'digitizer-pro-tools' ),
		);
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'Checkout Field Editor', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Show, hide, require and reorder standard checkout fields, and add your own. Applies to the classic (shortcode) checkout.', 'digitizer-pro-tools' ); ?></p>

			<?php if ( ! $woo_active ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active on this site right now - this module applies only when it is.', 'digitizer-pro-tools' ); ?></p></div>
			<?php endif; ?>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_wcf_save" />
						<?php wp_nonce_field( 'dpt_wcf_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-forms"></span> <?php esc_html_e( 'Standard fields', 'digitizer-pro-tools' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Leave "Order" blank to keep WooCommerce\'s default position. Lower numbers appear first.', 'digitizer-pro-tools' ); ?></p>
							<table class="form-table dpt-form">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Field', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'Show', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'Required', 'digitizer-pro-tools' ); ?></th>
										<th><?php esc_html_e( 'Order', 'digitizer-pro-tools' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $labels as $key => $label ) : ?>
									<?php $cfg = isset( $o['standard'][ $key ] ) ? $o['standard'][ $key ] : array( 'enabled' => '1', 'required' => '0', 'priority' => '' ); ?>
									<tr>
										<td><strong><?php echo esc_html( $label ); ?></strong></td>
										<td>
											<input type="hidden" name="dpt_wcf[standard][<?php echo esc_attr( $key ); ?>][enabled]" value="0" />
											<input type="checkbox" name="dpt_wcf[standard][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $cfg['enabled'], '1' ); ?> />
										</td>
										<td>
											<input type="hidden" name="dpt_wcf[standard][<?php echo esc_attr( $key ); ?>][required]" value="0" />
											<input type="checkbox" name="dpt_wcf[standard][<?php echo esc_attr( $key ); ?>][required]" value="1" <?php checked( $cfg['required'], '1' ); ?> />
										</td>
										<td>
											<input type="number" min="0" step="1" class="small-text" name="dpt_wcf[standard][<?php echo esc_attr( $key ); ?>][priority]" value="<?php echo esc_attr( $cfg['priority'] ); ?>" />
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Custom fields', 'digitizer-pro-tools' ); ?></h2>
							<p class="description">
								<?php
								printf(
									/* translators: %d: maximum number of custom fields */
									esc_html__( 'Up to %d custom fields. Clear the label to remove a field. For a dropdown, put one option per line.', 'digitizer-pro-tools' ),
									(int) $max
								);
								?>
							</p>
							<?php for ( $i = 0; $i < $row_count; $i++ ) : ?>
								<?php
								$cf = isset( $custom[ $i ] ) ? $custom[ $i ] : array( 'label' => '', 'key' => '', 'type' => 'text', 'section' => 'billing', 'required' => '0', 'options' => array(), 'priority' => '' );
								$opts_text = isset( $cf['options'] ) && is_array( $cf['options'] ) ? implode( "\n", $cf['options'] ) : '';
								?>
								<fieldset class="dpt-wcf-custom-row" style="border:1px solid #dcdcde;padding:12px 16px;margin:0 0 12px;border-radius:6px;">
									<input type="hidden" name="dpt_wcf[custom][<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( isset( $cf['key'] ) ? $cf['key'] : '' ); ?>" />
									<table class="form-table dpt-form">
										<tr>
											<th><?php esc_html_e( 'Label', 'digitizer-pro-tools' ); ?></th>
											<td><input type="text" class="regular-text" name="dpt_wcf[custom][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $cf['label'] ); ?>" /></td>
											<th><?php esc_html_e( 'Type', 'digitizer-pro-tools' ); ?></th>
											<td>
												<select name="dpt_wcf[custom][<?php echo (int) $i; ?>][type]">
													<?php foreach ( $types as $tval => $tlabel ) : ?>
														<option value="<?php echo esc_attr( $tval ); ?>" <?php selected( $cf['type'], $tval ); ?>><?php echo esc_html( $tlabel ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Section', 'digitizer-pro-tools' ); ?></th>
											<td>
												<select name="dpt_wcf[custom][<?php echo (int) $i; ?>][section]">
													<?php foreach ( $sections as $sval => $slabel ) : ?>
														<option value="<?php echo esc_attr( $sval ); ?>" <?php selected( $cf['section'], $sval ); ?>><?php echo esc_html( $slabel ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<th><?php esc_html_e( 'Required', 'digitizer-pro-tools' ); ?></th>
											<td>
												<input type="hidden" name="dpt_wcf[custom][<?php echo (int) $i; ?>][required]" value="0" />
												<label><input type="checkbox" name="dpt_wcf[custom][<?php echo (int) $i; ?>][required]" value="1" <?php checked( $cf['required'], '1' ); ?> /> <?php esc_html_e( 'Make this field required', 'digitizer-pro-tools' ); ?></label>
											</td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Order', 'digitizer-pro-tools' ); ?></th>
											<td><input type="number" min="0" step="1" class="small-text" name="dpt_wcf[custom][<?php echo (int) $i; ?>][priority]" value="<?php echo esc_attr( $cf['priority'] ); ?>" /></td>
											<th><?php esc_html_e( 'Dropdown options', 'digitizer-pro-tools' ); ?></th>
											<td><textarea rows="3" class="large-text code" name="dpt_wcf[custom][<?php echo (int) $i; ?>][options]" placeholder="<?php esc_attr_e( 'One option per line', 'digitizer-pro-tools' ); ?>"><?php echo esc_textarea( $opts_text ); ?></textarea></td>
										</tr>
									</table>
								</fieldset>
							<?php endfor; ?>
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
