<?php
/**
 * Shabbat Keeper settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Admin {

	const PAGE_SLUG = 'dpt-shabbat-keeper';
	const NONCE     = 'dpt_sk_settings_nonce';

	public function __construct() {
		add_action( 'admin_post_dpt_sk_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Shabbat Keeper', 'digitizer-pro-tools' ),
			__( 'Shabbat Keeper', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag from our own redirect.
	public function maybe_show_notices() {
		if ( self::PAGE_SLUG !== dpt_current_admin_page() ) {
			return;
		}
		if ( isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( self::NONCE );

		// Raw array on purpose: DPT_SK_Settings::sanitize() unslashes and
		// whitelists every field itself.
		$data = isset( $_POST['dpt_sk'] ) && is_array( $_POST['dpt_sk'] ) ? $_POST['dpt_sk'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized per field in DPT_SK_Settings::sanitize().
		DPT_SK_Settings::save( $data );
		DPT_SK_Enforce::on_settings_saved();

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Human name for a window reason such as "shabbat+rosh_hashana_2". */
	public static function reason_label( $reason ) {
		$names = array(
			'shabbat'        => __( 'Shabbat', 'digitizer-pro-tools' ),
			'rosh_hashana_1' => __( 'Rosh Hashana I', 'digitizer-pro-tools' ),
			'rosh_hashana_2' => __( 'Rosh Hashana II', 'digitizer-pro-tools' ),
			'yom_kippur'     => __( 'Yom Kippur', 'digitizer-pro-tools' ),
			'sukkot_1'       => __( 'Sukkot', 'digitizer-pro-tools' ),
			'shmini_atzeret' => __( 'Shmini Atzeret', 'digitizer-pro-tools' ),
			'pesach_1'       => __( 'Pesach', 'digitizer-pro-tools' ),
			'pesach_7'       => __( 'Seventh day of Pesach', 'digitizer-pro-tools' ),
			'shavuot'        => __( 'Shavuot', 'digitizer-pro-tools' ),
		);
		$out = array();
		foreach ( explode( '+', (string) $reason ) as $key ) {
			$out[] = isset( $names[ $key ] ) ? $names[ $key ] : $key;
		}
		return implode( ' + ', $out );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = DPT_SK_Settings::all();
		$tz     = new DateTimeZone( DPT_SK_Zmanim::TIMEZONE );
		$now    = DPT_SK_Enforce::now();
		$zmanim = DPT_SK_Enforce::zmanim();
		$next   = array_slice( $zmanim->windows( $now, $now + 70 * DAY_IN_SECONDS ), 0, 7 );
		$closed = DPT_SK_Enforce::is_closed();
		/* translators: PHP date format for a closure start or end: weekday, day.month, time. */
		$fmt = __( 'D j.n H:i', 'digitizer-pro-tools' );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Shabbat Keeper', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>

			<div class="dpt-panel">
				<h2><?php esc_html_e( 'Status', 'digitizer-pro-tools' ); ?></h2>
				<p>
					<strong><?php echo $closed ? esc_html__( 'Now: closed', 'digitizer-pro-tools' ) : esc_html__( 'Now: open', 'digitizer-pro-tools' ); ?></strong>
					<?php if ( $closed ) : ?>
						&middot; <?php echo esc_html( sprintf( __( 'Reopens %s', 'digitizer-pro-tools' ), DPT_SK_Enforce::reopens_text() ) ); ?>
					<?php elseif ( ! empty( $next ) ) : ?>
						&middot; <?php echo esc_html( sprintf( __( 'Next closing: %s', 'digitizer-pro-tools' ), wp_date( $fmt, $next[0]['start'], $tz ) ) ); ?>
					<?php endif; ?>
					&middot; <?php echo esc_html( DPT_SK_Settings::location()['name'] ); ?>
				</p>
				<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'dpt_shabbat', 'preview', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Preview the closed site', 'digitizer-pro-tools' ); ?></a></p>
				<h3><?php esc_html_e( 'Upcoming closures', 'digitizer-pro-tools' ); ?></h3>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'From', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Until', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'digitizer-pro-tools' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $next as $w ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $fmt, $w['start'], $tz ) ); ?></td>
							<td><?php echo esc_html( wp_date( $fmt, $w['end'], $tz ) ); ?></td>
							<td><?php echo esc_html( self::reason_label( $w['reason'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Nothing leaves the site: the times are computed here from the city you pick.', 'digitizer-pro-tools' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_sk_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Mode', 'digitizer-pro-tools' ); ?></h2>
					<p><label><input type="radio" name="dpt_sk[mode]" value="closed" <?php checked( $o['mode'], 'closed' ); ?> /> <?php esc_html_e( 'Closed - every visitor sees a "closed for Shabbat" page', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[mode]" value="business" <?php checked( $o['mode'], 'business' ); ?> /> <?php esc_html_e( 'Open to read, closed for business - pages stay readable; purchases and forms are refused', 'digitizer-pro-tools' ); ?></label></p>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Location', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><label for="dpt_sk_city"><?php esc_html_e( 'City', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<select id="dpt_sk_city" name="dpt_sk[city]">
									<?php foreach ( DPT_SK_Cities::all() as $slug => $city ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['city'], $slug ); ?>><?php echo esc_html( $city['name'] ); ?> (<?php echo esc_html( $city['candle'] ); ?>)</option>
									<?php endforeach; ?>
									<option value="custom" <?php selected( $o['city'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'digitizer-pro-tools' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Custom', 'digitizer-pro-tools' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Latitude', 'digitizer-pro-tools' ); ?> <input type="number" step="0.001" min="-65" max="65" name="dpt_sk[custom_lat]" value="<?php echo esc_attr( $o['custom_lat'] ); ?>" class="small-text" /></label>
								<label><?php esc_html_e( 'Longitude', 'digitizer-pro-tools' ); ?> <input type="number" step="0.001" min="-180" max="180" name="dpt_sk[custom_lon]" value="<?php echo esc_attr( $o['custom_lon'] ); ?>" class="small-text" /></label>
								<label><?php esc_html_e( 'Candle lighting, minutes before sunset', 'digitizer-pro-tools' ); ?> <input type="number" min="0" max="60" name="dpt_sk[custom_candle]" value="<?php echo esc_attr( $o['custom_candle'] ); ?>" class="small-text" /></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Havdalah', 'digitizer-pro-tools' ); ?></th>
							<td>
								<p><label><input type="radio" name="dpt_sk[havdalah]" value="42" <?php checked( $o['havdalah'], '42' ); ?> /> <?php esc_html_e( '42 minutes after sunset', 'digitizer-pro-tools' ); ?></label></p>
								<p><label><input type="radio" name="dpt_sk[havdalah]" value="72" <?php checked( $o['havdalah'], '72' ); ?> /> <?php esc_html_e( '72 minutes after sunset (Rabbeinu Tam)', 'digitizer-pro-tools' ); ?></label></p>
							</td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Closed page', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><label for="dpt_sk_closed_title"><?php esc_html_e( 'Title', 'digitizer-pro-tools' ); ?></label></th>
							<td><input type="text" id="dpt_sk_closed_title" name="dpt_sk[closed_title]" value="<?php echo esc_attr( $o['closed_title'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( DPT_SK_Settings::text( 'closed_title' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="dpt_sk_closed_message"><?php esc_html_e( 'Message', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<?php wp_editor( $o['closed_message'], 'dpt_sk_closed_message', array( 'textarea_name' => 'dpt_sk[closed_message]', 'textarea_rows' => 5, 'teeny' => true, 'media_buttons' => false ) ); ?>
								<p class="description"><?php echo esc_html( DPT_SK_Settings::text( 'closed_message' ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show the reopening time', 'digitizer-pro-tools' ); ?></th>
							<td><?php $this->switch_field( 'closed_show_times', $o['closed_show_times'] ); ?></td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Business mode', 'digitizer-pro-tools' ); ?></h2>
					<table class="form-table"><tbody>
						<tr><th scope="row"><?php esc_html_e( 'Refuse WooCommerce purchases', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'block_woo', $o['block_woo'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Refuse form submissions (Elementor, Contact Form 7, WPForms, Gravity Forms)', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'block_forms', $o['block_forms'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Hide phone and WhatsApp links', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'hide_contact', $o['hide_contact'] ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Show a banner at the top of every page', 'digitizer-pro-tools' ); ?></th><td><?php $this->switch_field( 'banner_on', $o['banner_on'] ); ?></td></tr>
						<tr>
							<th scope="row"><label for="dpt_sk_banner_text"><?php esc_html_e( 'Banner text', 'digitizer-pro-tools' ); ?></label></th>
							<td>
								<input type="text" id="dpt_sk_banner_text" name="dpt_sk[banner_text]" value="<?php echo esc_attr( $o['banner_text'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( DPT_SK_Settings::text( 'banner_text' ) ); ?>" />
								<p class="description"><?php esc_html_e( '%s is replaced by the reopening time.', 'digitizer-pro-tools' ); ?></p>
							</td>
						</tr>
					</tbody></table>
				</div>

				<div class="dpt-panel">
					<h2><?php esc_html_e( 'Override', 'digitizer-pro-tools' ); ?></h2>
					<p><label><input type="radio" name="dpt_sk[override]" value="auto" <?php checked( $o['override'], 'auto' ); ?> /> <?php esc_html_e( 'Automatic (by the calendar)', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[override]" value="force_open" <?php checked( $o['override'], 'force_open' ); ?> /> <?php esc_html_e( 'Force open', 'digitizer-pro-tools' ); ?></label></p>
					<p><label><input type="radio" name="dpt_sk[override]" value="force_closed" <?php checked( $o['override'], 'force_closed' ); ?> /> <?php esc_html_e( 'Force closed', 'digitizer-pro-tools' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'A forced state ignores the calendar until you switch back to automatic.', 'digitizer-pro-tools' ); ?></p>
				</div>

				<p class="dpt-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/** Hidden 0 before the checkbox so an unticked switch still posts, as Cookie Banner does. */
	private function switch_field( $name, $checked ) {
		?>
		<label class="dpt-switch">
			<input type="hidden" name="dpt_sk[<?php echo esc_attr( $name ); ?>]" value="0" />
			<input type="checkbox" name="dpt_sk[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked, '1' ); ?> />
			<span class="dpt-switch-slider"></span>
		</label>
		<?php
	}
}
