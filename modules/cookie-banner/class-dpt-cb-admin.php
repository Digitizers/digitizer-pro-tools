<?php
/**
 * Cookie Banner module - admin settings page (8 tabs + language switcher).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CB_Admin {

	const PAGE_SLUG = 'dpt-cookie-banner';
	const NONCE     = 'dpt_cb_settings_nonce';

	public function __construct() {
		add_action( 'admin_post_dpt_cb_save',        array( $this, 'handle_save' ) );
		add_action( 'admin_post_dpt_cb_add_lang',    array( $this, 'handle_add_lang' ) );
		add_action( 'admin_post_dpt_cb_remove_lang', array( $this, 'handle_remove_lang' ) );
		add_action( 'admin_notices',                 array( $this, 'maybe_show_notices' ) );
	}

	/**
	 * Called by DPT_Admin on admin_menu.
	 */
	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Cookie Banner', 'digitizer-pro-tools' ),
			__( 'Cookie Banner', 'digitizer-pro-tools' ),
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
		if ( isset( $_GET['dpt_lang_added'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Language added.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_lang_removed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Language removed.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		if ( isset( $_GET['dpt_lang_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid language code. Use formats like "en", "ru" or "pt_BR".', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	/* ============================================================
	 *  POST handlers
	 * ============================================================ */

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( self::NONCE );

		$data = isset( $_POST['dpt_cb'] ) && is_array( $_POST['dpt_cb'] ) ? $_POST['dpt_cb'] : array();
		DPT_CB_Settings::save( $data );

		$tab  = isset( $_POST['current_tab'] ) ? sanitize_key( $_POST['current_tab'] ) : 'general';
		$args = array(
			'page'      => self::PAGE_SLUG,
			'tab'       => $tab,
			'dpt_saved' => 1,
		);
		if ( isset( $_POST['current_lang'] ) ) {
			$lang = DPT_CB_Settings::normalize_lang_code( wp_unslash( $_POST['current_lang'] ) );
			if ( $lang ) {
				$args['lang'] = $lang;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_add_lang() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_cb_add_lang' );

		$code  = isset( $_POST['dpt_new_lang'] ) ? wp_unslash( $_POST['dpt_new_lang'] ) : '';
		$added = DPT_CB_Settings::add_language( $code );

		$args = array( 'page' => self::PAGE_SLUG, 'tab' => 'texts' );
		if ( $added ) {
			$args['lang']           = $added;
			$args['dpt_lang_added'] = 1;
		} else {
			$args['dpt_lang_error'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_remove_lang() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_cb_remove_lang' );

		$code = isset( $_POST['dpt_remove_lang'] ) ? DPT_CB_Settings::normalize_lang_code( wp_unslash( $_POST['dpt_remove_lang'] ) ) : '';
		$args = array( 'page' => self::PAGE_SLUG, 'tab' => 'texts' );
		if ( $code && DPT_CB_Settings::remove_language( $code ) ) {
			$args['dpt_lang_removed'] = 1;
		} else {
			$args['dpt_lang_error'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ============================================================
	 *  Page
	 * ============================================================ */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts        = DPT_CB_Settings::all();
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$tabs        = array(
			'general'    => __( 'General', 'digitizer-pro-tools' ),
			'texts'      => __( 'Texts', 'digitizer-pro-tools' ),
			'design'     => __( 'Design', 'digitizer-pro-tools' ),
			'typography' => __( 'Typography', 'digitizer-pro-tools' ),
			'buttons'    => __( 'Buttons', 'digitizer-pro-tools' ),
			'categories' => __( 'Categories', 'digitizer-pro-tools' ),
			'scripts'    => __( 'Scripts', 'digitizer-pro-tools' ),
			'floating'   => __( 'Floating Button', 'digitizer-pro-tools' ),
		);

		$current_lang = $this->current_lang( $opts );
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-privacy"></span>
				<?php esc_html_e( 'Cookie Banner', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>

			<h2 class="nav-tab-wrapper dpt-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_cb_save" />
						<input type="hidden" name="current_tab" value="<?php echo esc_attr( $current_tab ); ?>" />
						<?php if ( 'texts' === $current_tab ) : ?>
							<input type="hidden" name="current_lang" value="<?php echo esc_attr( $current_lang ); ?>" />
						<?php endif; ?>
						<?php wp_nonce_field( self::NONCE ); ?>

						<div class="dpt-panel">
							<?php $this->render_tab( $current_tab, $opts, $current_lang ); ?>
						</div>

						<p class="dpt-actions">
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
						</p>
					</form>

					<?php if ( 'texts' === $current_tab ) : ?>
						<?php $this->render_language_manager( $opts, $current_lang ); ?>
					<?php endif; ?>
				</div>

				<aside class="dpt-sidebar">
					<?php $this->render_preview( $opts, $current_lang ); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * The language being edited on the Texts tab.
	 */
	private function current_lang( $opts ) {
		$lang = isset( $_GET['lang'] ) ? DPT_CB_Settings::normalize_lang_code( wp_unslash( $_GET['lang'] ) ) : '';
		if ( $lang && in_array( $lang, $opts['languages'], true ) ) {
			return $lang;
		}
		return $opts['default_lang'];
	}

	/**
	 * Human-readable label for a language code.
	 */
	private function lang_label( $code ) {
		$names = array(
			'he' => __( 'Hebrew', 'digitizer-pro-tools' ),
			'en' => __( 'English', 'digitizer-pro-tools' ),
			'ar' => __( 'Arabic', 'digitizer-pro-tools' ),
			'ru' => __( 'Russian', 'digitizer-pro-tools' ),
			'fr' => __( 'French', 'digitizer-pro-tools' ),
			'es' => __( 'Spanish', 'digitizer-pro-tools' ),
			'de' => __( 'German', 'digitizer-pro-tools' ),
		);
		$short = strtolower( substr( $code, 0, (int) strcspn( $code, '_' ) ) );
		if ( isset( $names[ $code ] ) ) {
			return $names[ $code ] . ' (' . $code . ')';
		}
		if ( isset( $names[ $short ] ) ) {
			return $names[ $short ] . ' (' . $code . ')';
		}
		return $code;
	}

	private function render_tab( $tab, $opts, $current_lang ) {
		switch ( $tab ) {
			case 'texts':      $this->tab_texts( $opts, $current_lang ); break;
			case 'design':     $this->tab_design( $opts );     break;
			case 'typography': $this->tab_typography( $opts ); break;
			case 'buttons':    $this->tab_buttons( $opts );    break;
			case 'categories': $this->tab_categories( $opts ); break;
			case 'scripts':    $this->tab_scripts( $opts );    break;
			case 'floating':   $this->tab_floating( $opts );   break;
			case 'general':
			default:           $this->tab_general( $opts );    break;
		}
	}

	/**
	 * Toggle switch markup (hidden input keeps unchecked boxes submitted).
	 */
	private function switch_field( $name, $checked ) {
		?>
		<label class="dpt-switch">
			<input type="hidden" name="dpt_cb[<?php echo esc_attr( $name ); ?>]" value="0" />
			<input type="checkbox" name="dpt_cb[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $checked, '1' ); ?> />
			<span class="dpt-switch-slider"></span>
		</label>
		<?php
	}

	/* ============================================================
	 *  TABS
	 * ============================================================ */

	private function tab_general( $o ) {
		?>
		<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'General Settings', 'digitizer-pro-tools' ); ?></h2>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Enable banner', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'enabled', $o['enabled'] ); ?>
					<span class="description"><?php esc_html_e( 'When disabled, the banner is not shown at all.', 'digitizer-pro-tools' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_position"><?php esc_html_e( 'Banner position', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$positions = array(
						'bottom'       => __( 'Full-width bar at the bottom', 'digitizer-pro-tools' ),
						'top'          => __( 'Full-width bar at the top', 'digitizer-pro-tools' ),
						'center'       => __( 'Centered modal', 'digitizer-pro-tools' ),
						'bottom-left'  => __( 'Bottom-left corner', 'digitizer-pro-tools' ),
						'bottom-right' => __( 'Bottom-right corner', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_position" name="dpt_cb[position]">
						<?php foreach ( $positions as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['position'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_animation"><?php esc_html_e( 'Entry animation', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$anims = array(
						'slide-up'   => __( 'Slide up', 'digitizer-pro-tools' ),
						'slide-down' => __( 'Slide down', 'digitizer-pro-tools' ),
						'fade'       => __( 'Fade', 'digitizer-pro-tools' ),
						'zoom'       => __( 'Zoom', 'digitizer-pro-tools' ),
						'none'       => __( 'None', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_animation" name="dpt_cb[animation]">
						<?php foreach ( $anims as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['animation'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Show on mobile', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'show_on_mobile', $o['show_on_mobile'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Accept on scroll', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'auto_accept_scroll', $o['auto_accept_scroll'] ); ?>
					<p class="description"><?php esc_html_e( 'Not recommended for GDPR - EU law requires active consent.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Block scripts until consent', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'block_scripts', $o['block_scripts'] ); ?>
					<p class="description"><?php esc_html_e( 'Scripts from the "Scripts" tab load only after the visitor consents to the matching category.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_consent_days"><?php esc_html_e( 'Consent lifetime (days)', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="number" min="1" max="730" id="dpt_cb_consent_days" name="dpt_cb[consent_days]" value="<?php echo esc_attr( $o['consent_days'] ); ?>" />
					<p class="description"><?php esc_html_e( 'After this many days the visitor is asked again.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_consent_version"><?php esc_html_e( 'Consent version', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="number" min="1" step="1" id="dpt_cb_consent_version" name="dpt_cb[consent_version]" value="<?php echo esc_attr( $o['consent_version'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Increase this number to ask ALL visitors for consent again (for example after changing your cookie policy). Popular page caches (WP Rocket, LiteSpeed, Super Cache, W3TC, Fastest Cache, SiteGround) are purged automatically when it changes.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_show_delay"><?php esc_html_e( 'Show delay (seconds)', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="number" min="0" max="120" step="1" id="dpt_cb_show_delay" name="dpt_cb[show_delay]" value="<?php echo esc_attr( $o['show_delay'] ); ?>" />
					<p class="description"><?php esc_html_e( '0 = show immediately. 5 = show after 5 seconds, so the banner does not interrupt visitors right away.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Texts tab: language sub-tabs + per-language fields.
	 */
	private function tab_texts( $o, $lang ) {
		$texts    = isset( $o['texts'][ $lang ] ) ? $o['texts'][ $lang ] : DPT_CB_Settings::default_texts( $lang );
		$defaults = DPT_CB_Settings::default_texts( $lang );
		$n        = 'dpt_cb[texts][' . esc_attr( $lang ) . ']';
		?>
		<h2><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Texts', 'digitizer-pro-tools' ); ?></h2>
		<p class="description dpt-big-desc"><?php esc_html_e( 'The banner picks the language automatically from the current page locale (WPML / Polylang / TranslatePress compatible), falling back to the default language.', 'digitizer-pro-tools' ); ?></p>

		<div class="nav-tab-wrapper dpt-lang-tabs">
			<?php foreach ( $o['languages'] as $code ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'texts', 'lang' => $code ), admin_url( 'admin.php' ) ) ); ?>"
				   class="nav-tab <?php echo $lang === $code ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $this->lang_label( $code ) ); ?>
					<?php if ( $code === $o['default_lang'] ) : ?>
						<span class="dpt-default-badge"><?php esc_html_e( 'default', 'digitizer-pro-tools' ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_title"><?php esc_html_e( 'Banner title', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_title" name="<?php echo $n; ?>[title]" value="<?php echo esc_attr( $texts['title'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( $defaults['title'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_message"><?php esc_html_e( 'Banner message', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<textarea id="dpt_cb_message" name="<?php echo $n; ?>[message]" rows="5" class="large-text"><?php echo esc_textarea( $texts['message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Basic HTML is allowed: <strong>, <em>, <a>, <br>', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_btn_accept"><?php esc_html_e( '"Accept all" button', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_btn_accept" name="<?php echo $n; ?>[btn_accept_text]" value="<?php echo esc_attr( $texts['btn_accept_text'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_btn_reject"><?php esc_html_e( '"Reject all" button', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_btn_reject" name="<?php echo $n; ?>[btn_reject_text]" value="<?php echo esc_attr( $texts['btn_reject_text'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_btn_settings"><?php esc_html_e( '"Settings" button', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_btn_settings" name="<?php echo $n; ?>[btn_settings_text]" value="<?php echo esc_attr( $texts['btn_settings_text'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_btn_save"><?php esc_html_e( '"Save preferences" button', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_btn_save" name="<?php echo $n; ?>[btn_save_text]" value="<?php echo esc_attr( $texts['btn_save_text'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_settings_view_title"><?php esc_html_e( 'Preferences screen title', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_settings_view_title" name="<?php echo $n; ?>[settings_view_title]" value="<?php echo esc_attr( $texts['settings_view_title'] ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_always_on"><?php esc_html_e( '"Always active" label', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_always_on" name="<?php echo $n; ?>[always_on_label]" value="<?php echo esc_attr( $texts['always_on_label'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_close_aria"><?php esc_html_e( 'Close button label (accessibility)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_close_aria" name="<?php echo $n; ?>[close_aria]" value="<?php echo esc_attr( $texts['close_aria'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_policy_text"><?php esc_html_e( 'Privacy policy link text', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" id="dpt_cb_policy_text" name="<?php echo $n; ?>[policy_text]" value="<?php echo esc_attr( $texts['policy_text'] ); ?>" /></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Category names and descriptions', 'digitizer-pro-tools' ); ?> (<?php echo esc_html( $this->lang_label( $lang ) ); ?>)</h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label><?php esc_html_e( 'Essential - name', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" name="<?php echo $n; ?>[cat_essential_name]" value="<?php echo esc_attr( $texts['cat_essential_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Essential - description', 'digitizer-pro-tools' ); ?></label></th>
				<td><textarea name="<?php echo $n; ?>[cat_essential_desc]" rows="2" class="large-text"><?php echo esc_textarea( $texts['cat_essential_desc'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Functional - name', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" name="<?php echo $n; ?>[cat_functional_name]" value="<?php echo esc_attr( $texts['cat_functional_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Functional - description', 'digitizer-pro-tools' ); ?></label></th>
				<td><textarea name="<?php echo $n; ?>[cat_functional_desc]" rows="2" class="large-text"><?php echo esc_textarea( $texts['cat_functional_desc'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Analytics - name', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" name="<?php echo $n; ?>[cat_analytics_name]" value="<?php echo esc_attr( $texts['cat_analytics_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Analytics - description', 'digitizer-pro-tools' ); ?></label></th>
				<td><textarea name="<?php echo $n; ?>[cat_analytics_desc]" rows="2" class="large-text"><?php echo esc_textarea( $texts['cat_analytics_desc'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Marketing - name', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" name="<?php echo $n; ?>[cat_marketing_name]" value="<?php echo esc_attr( $texts['cat_marketing_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Marketing - description', 'digitizer-pro-tools' ); ?></label></th>
				<td><textarea name="<?php echo $n; ?>[cat_marketing_desc]" rows="2" class="large-text"><?php echo esc_textarea( $texts['cat_marketing_desc'] ); ?></textarea></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-sticky"></span> <?php esc_html_e( 'Floating button texts', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label><?php esc_html_e( 'Button content', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" name="<?php echo $n; ?>[float_button_text]" value="<?php echo esc_attr( $texts['float_button_text'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave empty for the built-in cookie icon, or type short text of your own.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Button label (accessibility)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" name="<?php echo $n; ?>[float_button_aria]" value="<?php echo esc_attr( $texts['float_button_aria'] ); ?>" /></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Privacy policy link (shared for all languages)', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_policy_page_id"><?php esc_html_e( 'Privacy policy page', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_pages( array(
						'name'              => 'dpt_cb[policy_page_id]',
						'id'                => 'dpt_cb_policy_page_id',
						'show_option_none'  => __( '— No link —', 'digitizer-pro-tools' ),
						'option_none_value' => '0',
						'selected'          => (int) $o['policy_page_id'],
					) );
					?>
					<p class="description"><?php esc_html_e( 'Pick the privacy policy page. With WPML or Polylang, the translated page is linked automatically per language.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_policy_url"><?php esc_html_e( 'Or an external URL (instead of a page)', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="url" id="dpt_cb_policy_url" name="dpt_cb[policy_url]" value="<?php echo esc_attr( $o['policy_url'] ); ?>" class="large-text" placeholder="https://..." />
					<p class="description"><?php esc_html_e( 'Only if the policy lives outside this site. Leave empty when a page is selected above.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_default_lang"><?php esc_html_e( 'Default language', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<select id="dpt_cb_default_lang" name="dpt_cb[default_lang]">
						<?php foreach ( $o['languages'] as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $o['default_lang'], $code ); ?>><?php echo esc_html( $this->lang_label( $code ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used when the page locale has no matching language.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add / remove language forms (outside the main save form).
	 */
	private function render_language_manager( $o, $current_lang ) {
		?>
		<div class="dpt-panel dpt-lang-manager">
			<h3><?php esc_html_e( 'Manage languages', 'digitizer-pro-tools' ); ?></h3>
			<div class="dpt-lang-manager-forms">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dpt-inline-form">
					<input type="hidden" name="action" value="dpt_cb_add_lang" />
					<?php wp_nonce_field( 'dpt_cb_add_lang' ); ?>
					<label for="dpt_new_lang"><?php esc_html_e( 'Add language (code):', 'digitizer-pro-tools' ); ?></label>
					<input type="text" id="dpt_new_lang" name="dpt_new_lang" placeholder="ru / fr / pt_BR" maxlength="6" />
					<button type="submit" class="button"><?php esc_html_e( 'Add', 'digitizer-pro-tools' ); ?></button>
				</form>

				<?php if ( $current_lang !== $o['default_lang'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dpt-inline-form"
					      onsubmit="return confirm('<?php echo esc_js( __( 'Remove this language and its texts?', 'digitizer-pro-tools' ) ); ?>');">
						<input type="hidden" name="action" value="dpt_cb_remove_lang" />
						<input type="hidden" name="dpt_remove_lang" value="<?php echo esc_attr( $current_lang ); ?>" />
						<?php wp_nonce_field( 'dpt_cb_remove_lang' ); ?>
						<button type="submit" class="button button-link-delete">
							<?php
							/* translators: %s: language label */
							printf( esc_html__( 'Remove "%s"', 'digitizer-pro-tools' ), esc_html( $this->lang_label( $current_lang ) ) );
							?>
						</button>
					</form>
				<?php endif; ?>
			</div>
			<p class="description"><?php esc_html_e( 'New languages start with the English texts. The default language cannot be removed.', 'digitizer-pro-tools' ); ?></p>
		</div>
		<?php
	}

	private function tab_design( $o ) {
		?>
		<h2><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Banner Design', 'digitizer-pro-tools' ); ?></h2>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Colors and background', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_bg_color"><?php esc_html_e( 'Background color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_bg_color" name="dpt_cb[bg_color]" value="<?php echo esc_attr( $o['bg_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_text_color"><?php esc_html_e( 'Base text color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_text_color" name="dpt_cb[text_color]" value="<?php echo esc_attr( $o['text_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Box shadow', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'box_shadow', $o['box_shadow'] ); ?></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-image-filter"></span> <?php esc_html_e( 'Page overlay (dim the site behind the banner)', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Enable overlay', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'overlay_enabled', $o['overlay_enabled'] ); ?>
					<p class="description"><?php esc_html_e( 'Dims the page behind the banner, prompting the visitor to choose.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_overlay_color"><?php esc_html_e( 'Overlay color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_overlay_color" name="dpt_cb[overlay_color]" value="<?php echo esc_attr( $o['overlay_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_overlay_opacity"><?php esc_html_e( 'Overlay opacity (0-1)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" step="0.05" min="0" max="1" id="dpt_cb_overlay_opacity" name="dpt_cb[overlay_opacity]" value="<?php echo esc_attr( $o['overlay_opacity'] ); ?>" /></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Background image', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Background image', 'digitizer-pro-tools' ); ?></th>
				<td>
					<div class="dpt-image-picker">
						<input type="hidden" name="dpt_cb[bg_image_url]" value="<?php echo esc_attr( $o['bg_image_url'] ); ?>" />
						<input type="hidden" name="dpt_cb[bg_image_id]"  value="<?php echo esc_attr( $o['bg_image_id'] ); ?>" />
						<div class="dpt-image-preview">
							<?php if ( $o['bg_image_url'] ) : ?>
								<img src="<?php echo esc_url( $o['bg_image_url'] ); ?>" alt="" />
							<?php endif; ?>
						</div>
						<p>
							<button type="button" class="button dpt-image-select"><?php esc_html_e( 'Choose image', 'digitizer-pro-tools' ); ?></button>
							<button type="button" class="button dpt-image-remove" <?php echo ! $o['bg_image_url'] ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove image', 'digitizer-pro-tools' ); ?></button>
						</p>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_bg_image_size"><?php esc_html_e( 'Image size', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php $sizes = array( 'cover' => 'Cover', 'contain' => 'Contain', 'auto' => 'Auto', '100% 100%' => 'Stretch' ); ?>
					<select id="dpt_cb_bg_image_size" name="dpt_cb[bg_image_size]">
						<?php foreach ( $sizes as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['bg_image_size'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_bg_image_position"><?php esc_html_e( 'Image position', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$poss = array(
						'center center' => __( 'Center', 'digitizer-pro-tools' ),
						'top center'    => __( 'Top (center)', 'digitizer-pro-tools' ),
						'bottom center' => __( 'Bottom (center)', 'digitizer-pro-tools' ),
						'top left'      => __( 'Top left', 'digitizer-pro-tools' ),
						'top right'     => __( 'Top right', 'digitizer-pro-tools' ),
						'bottom left'   => __( 'Bottom left', 'digitizer-pro-tools' ),
						'bottom right'  => __( 'Bottom right', 'digitizer-pro-tools' ),
						'center left'   => __( 'Left side', 'digitizer-pro-tools' ),
						'center right'  => __( 'Right side', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_bg_image_position" name="dpt_cb[bg_image_position]">
						<?php foreach ( $poss as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['bg_image_position'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_bg_image_repeat"><?php esc_html_e( 'Repeat', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$reps = array(
						'no-repeat' => __( 'No repeat', 'digitizer-pro-tools' ),
						'repeat'    => __( 'Repeat', 'digitizer-pro-tools' ),
						'repeat-x'  => __( 'Horizontal', 'digitizer-pro-tools' ),
						'repeat-y'  => __( 'Vertical', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_bg_image_repeat" name="dpt_cb[bg_image_repeat]">
						<?php foreach ( $reps as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['bg_image_repeat'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_bg_image_overlay_color"><?php esc_html_e( 'Image overlay color', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" class="dpt-color" id="dpt_cb_bg_image_overlay_color" name="dpt_cb[bg_image_overlay_color]" value="<?php echo esc_attr( $o['bg_image_overlay_color'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Darkens a bright image so the text stays readable.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_bg_image_overlay_opacity"><?php esc_html_e( 'Image overlay opacity (0-1)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" step="0.05" min="0" max="1" id="dpt_cb_bg_image_overlay_opacity" name="dpt_cb[bg_image_overlay_opacity]" value="<?php echo esc_attr( $o['bg_image_overlay_opacity'] ); ?>" /></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-grid-view"></span> <?php esc_html_e( 'Layout and border', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_width"><?php esc_html_e( 'Max width (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="number" min="200" id="dpt_cb_width" name="dpt_cb[width]" value="<?php echo esc_attr( $o['width'] ); ?>" /> px
					<p class="description"><?php esc_html_e( 'Relevant for the centered modal and corner positions - full-width bars size automatically.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_max_width_pct"><?php esc_html_e( 'Max width on small screens (%)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="10" max="100" id="dpt_cb_max_width_pct" name="dpt_cb[max_width_pct]" value="<?php echo esc_attr( $o['max_width_pct'] ); ?>" /> %</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_border_radius"><?php esc_html_e( 'Corner radius (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" id="dpt_cb_border_radius" name="dpt_cb[border_radius]" value="<?php echo esc_attr( $o['border_radius'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_padding"><?php esc_html_e( 'Inner padding (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" id="dpt_cb_padding" name="dpt_cb[padding]" value="<?php echo esc_attr( $o['padding'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_border_width"><?php esc_html_e( 'Border width (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" id="dpt_cb_border_width" name="dpt_cb[border_width]" value="<?php echo esc_attr( $o['border_width'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_border_color"><?php esc_html_e( 'Border color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_border_color" name="dpt_cb[border_color]" value="<?php echo esc_attr( $o['border_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_border_style"><?php esc_html_e( 'Border style', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$styles = array(
						'solid'  => __( 'Solid', 'digitizer-pro-tools' ),
						'dashed' => __( 'Dashed', 'digitizer-pro-tools' ),
						'dotted' => __( 'Dotted', 'digitizer-pro-tools' ),
						'double' => __( 'Double', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_border_style" name="dpt_cb[border_style]">
						<?php foreach ( $styles as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['border_style'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Close button (X)', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Show close button', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'show_close', $o['show_close'] ); ?>
					<p class="description"><?php esc_html_e( 'Closing with X counts as rejecting non-essential cookies.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_close_size"><?php esc_html_e( 'X size (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="12" max="60" id="dpt_cb_close_size" name="dpt_cb[close_size]" value="<?php echo esc_attr( $o['close_size'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_close_color"><?php esc_html_e( 'X color', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" class="dpt-color" id="dpt_cb_close_color" name="dpt_cb[close_color]" value="<?php echo esc_attr( $o['close_color'] ); ?>" data-default-color="" />
					<p class="description"><?php esc_html_e( 'Empty = use the base text color.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_close_bg_color"><?php esc_html_e( 'X background color', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" class="dpt-color" id="dpt_cb_close_bg_color" name="dpt_cb[close_bg_color]" value="<?php echo esc_attr( $o['close_bg_color'] ); ?>" data-default-color="" />
					<p class="description"><?php esc_html_e( 'Empty = transparent. With a color, the button becomes a circle.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function tab_typography( $o ) {
		$aligns = array(
			'start'   => __( 'Start (right in RTL, left in LTR)', 'digitizer-pro-tools' ),
			'center'  => __( 'Center', 'digitizer-pro-tools' ),
			'end'     => __( 'End (left in RTL, right in LTR)', 'digitizer-pro-tools' ),
			'justify' => __( 'Justify', 'digitizer-pro-tools' ),
		);
		$weights = array(
			'300' => __( '300 - Light', 'digitizer-pro-tools' ),
			'400' => __( '400 - Regular', 'digitizer-pro-tools' ),
			'500' => __( '500 - Medium', 'digitizer-pro-tools' ),
			'600' => __( '600 - Semi-bold', 'digitizer-pro-tools' ),
			'700' => __( '700 - Bold', 'digitizer-pro-tools' ),
			'800' => __( '800 - Extra-bold', 'digitizer-pro-tools' ),
			'900' => __( '900 - Black', 'digitizer-pro-tools' ),
		);
		?>
		<h2><span class="dashicons dashicons-editor-textcolor"></span> <?php esc_html_e( 'Typography', 'digitizer-pro-tools' ); ?></h2>
		<p class="description dpt-big-desc"><?php esc_html_e( 'Alignment uses logical values, so "Start" is right for Hebrew/Arabic pages and left for English pages - the same setting fits every language.', 'digitizer-pro-tools' ); ?></p>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-heading"></span> <?php esc_html_e( 'Title', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_title_color"><?php esc_html_e( 'Title color', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" class="dpt-color" id="dpt_cb_title_color" name="dpt_cb[title_color]" value="<?php echo esc_attr( $o['title_color'] ); ?>" data-default-color="" />
					<p class="description"><?php esc_html_e( 'Empty = use the base text color.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_font_size"><?php esc_html_e( 'Font size', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="10" max="80" id="dpt_cb_title_font_size" name="dpt_cb[title_font_size]" value="<?php echo esc_attr( $o['title_font_size'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_align"><?php esc_html_e( 'Alignment', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<select id="dpt_cb_title_align" name="dpt_cb[title_align]">
						<?php foreach ( $aligns as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['title_align'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_weight"><?php esc_html_e( 'Font weight', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<select id="dpt_cb_title_weight" name="dpt_cb[title_weight]">
						<?php foreach ( $weights as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['title_weight'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_margin_bottom"><?php esc_html_e( 'Space below (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" id="dpt_cb_title_margin_bottom" name="dpt_cb[title_margin_bottom]" value="<?php echo esc_attr( $o['title_margin_bottom'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Text shadow', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'title_shadow_enabled', $o['title_shadow_enabled'] ); ?></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_shadow_color"><?php esc_html_e( 'Shadow color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_title_shadow_color" name="dpt_cb[title_shadow_color]" value="<?php echo esc_attr( $o['title_shadow_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_shadow_blur"><?php esc_html_e( 'Blur (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" max="40" id="dpt_cb_title_shadow_blur" name="dpt_cb[title_shadow_blur]" value="<?php echo esc_attr( $o['title_shadow_blur'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_title_shadow_y"><?php esc_html_e( 'Vertical offset (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="-20" max="20" id="dpt_cb_title_shadow_y" name="dpt_cb[title_shadow_y]" value="<?php echo esc_attr( $o['title_shadow_y'] ); ?>" /> px</td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-text"></span> <?php esc_html_e( 'Content', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_content_color"><?php esc_html_e( 'Text color', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="text" class="dpt-color" id="dpt_cb_content_color" name="dpt_cb[content_color]" value="<?php echo esc_attr( $o['content_color'] ); ?>" data-default-color="" />
					<p class="description"><?php esc_html_e( 'Empty = use the base text color.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_content_font_size"><?php esc_html_e( 'Font size', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="10" max="40" id="dpt_cb_content_font_size" name="dpt_cb[content_font_size]" value="<?php echo esc_attr( $o['content_font_size'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_content_align"><?php esc_html_e( 'Alignment', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<select id="dpt_cb_content_align" name="dpt_cb[content_align]">
						<?php foreach ( $aligns as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['content_align'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Text shadow', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'content_shadow_enabled', $o['content_shadow_enabled'] ); ?></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_content_shadow_color"><?php esc_html_e( 'Shadow color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_content_shadow_color" name="dpt_cb[content_shadow_color]" value="<?php echo esc_attr( $o['content_shadow_color'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_content_shadow_blur"><?php esc_html_e( 'Blur (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" max="40" id="dpt_cb_content_shadow_blur" name="dpt_cb[content_shadow_blur]" value="<?php echo esc_attr( $o['content_shadow_blur'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_content_shadow_y"><?php esc_html_e( 'Vertical offset (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="-20" max="20" id="dpt_cb_content_shadow_y" name="dpt_cb[content_shadow_y]" value="<?php echo esc_attr( $o['content_shadow_y'] ); ?>" /> px</td>
			</tr>
		</table>
		<?php
	}

	private function tab_buttons( $o ) {
		?>
		<h2><span class="dashicons dashicons-button"></span> <?php esc_html_e( 'Button Design', 'digitizer-pro-tools' ); ?></h2>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( '"Accept all" button', 'digitizer-pro-tools' ); ?></h3>
		<?php $this->button_style_fields( 'accept', $o, false ); ?>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( '"Reject all" button', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Show button', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'btn_reject_show', $o['btn_reject_show'] ); ?></td>
			</tr>
		</table>
		<?php $this->button_style_fields( 'reject', $o, false ); ?>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( '"Settings" button', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Show button', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'btn_settings_show', $o['btn_settings_show'] ); ?></td>
			</tr>
		</table>
		<?php $this->button_style_fields( 'settings', $o, true ); ?>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-saved"></span> <?php esc_html_e( '"Save preferences" button', 'digitizer-pro-tools' ); ?></h3>
		<?php $this->button_style_fields( 'save', $o, false ); ?>
		<?php
	}

	private function button_style_fields( $prefix, $o, $with_border ) {
		$bg     = $o[ 'btn_' . $prefix . '_bg' ];
		$color  = $o[ 'btn_' . $prefix . '_color' ];
		$radius = $o[ 'btn_' . $prefix . '_radius' ];
		?>
		<table class="form-table dpt-form">
			<tr>
				<th><label><?php esc_html_e( 'Background color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" name="dpt_cb[btn_<?php echo esc_attr( $prefix ); ?>_bg]" value="<?php echo esc_attr( $bg ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Text color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" name="dpt_cb[btn_<?php echo esc_attr( $prefix ); ?>_color]" value="<?php echo esc_attr( $color ); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Corner radius (px)', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" name="dpt_cb[btn_<?php echo esc_attr( $prefix ); ?>_radius]" value="<?php echo esc_attr( $radius ); ?>" /> px</td>
			</tr>
			<?php if ( in_array( $prefix, array( 'accept', 'reject' ), true ) ) : ?>
			<tr>
				<th><label><?php esc_html_e( 'Hover background color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" name="dpt_cb[btn_<?php echo esc_attr( $prefix ); ?>_hover_bg]" value="<?php echo esc_attr( $o[ 'btn_' . $prefix . '_hover_bg' ] ); ?>" /></td>
			</tr>
			<?php endif; ?>
			<?php if ( $with_border ) : ?>
			<tr>
				<th><?php esc_html_e( 'Border', 'digitizer-pro-tools' ); ?></th>
				<td>
					<?php $this->switch_field( 'btn_' . $prefix . '_border', isset( $o[ 'btn_' . $prefix . '_border' ] ) ? $o[ 'btn_' . $prefix . '_border' ] : '0' ); ?>
					<p class="description"><?php esc_html_e( 'Adds a thin border in the text color.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private function tab_categories( $o ) {
		?>
		<h2><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Cookie Categories', 'digitizer-pro-tools' ); ?></h2>
		<p class="description dpt-big-desc"><?php esc_html_e( 'Each category shows a separate toggle on the advanced-preferences screen. Scripts from the "Scripts" tab load only after the visitor consents to the matching category. Category names and descriptions are edited per language on the "Texts" tab.', 'digitizer-pro-tools' ); ?></p>

		<table class="form-table dpt-form">
			<tr>
				<th><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Essential cookies', 'digitizer-pro-tools' ); ?></th>
				<td><span class="description"><?php esc_html_e( 'Always active - cannot be disabled.', 'digitizer-pro-tools' ); ?></span></td>
			</tr>
			<tr>
				<th><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Functional cookies', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'cat_functional_enabled', $o['cat_functional_enabled'] ); ?></td>
			</tr>
			<tr>
				<th><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Analytics cookies', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'cat_analytics_enabled', $o['cat_analytics_enabled'] ); ?></td>
			</tr>
			<tr>
				<th><span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Marketing cookies', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'cat_marketing_enabled', $o['cat_marketing_enabled'] ); ?></td>
			</tr>
		</table>
		<?php
	}

	private function tab_scripts( $o ) {
		?>
		<h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'Scripts loaded after consent', 'digitizer-pro-tools' ); ?></h2>
		<div class="dpt-info-box">
			<p><strong><?php esc_html_e( 'How does it work?', 'digitizer-pro-tools' ); ?></strong></p>
			<p><?php esc_html_e( 'Paste the full HTML snippets here (Google Analytics, Facebook Pixel, GTM...). They are injected into the site <head> only after the visitor consents to the matching category.', 'digitizer-pro-tools' ); ?></p>
			<p><?php esc_html_e( 'Paste the complete code including the <script> tags.', 'digitizer-pro-tools' ); ?></p>
		</div>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Functional scripts', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<td>
					<textarea name="dpt_cb[scripts_functional]" rows="6" class="large-text code" dir="ltr" placeholder="<script>/* Example */</script>"><?php echo esc_textarea( $o['scripts_functional'] ); ?></textarea>
				</td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Analytics scripts', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<td>
					<textarea name="dpt_cb[scripts_analytics]" rows="8" class="large-text code" dir="ltr" placeholder="<!-- Google Analytics / GTM -->"><?php echo esc_textarea( $o['scripts_analytics'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'For example: Google Analytics 4, Google Tag Manager, Hotjar, Clarity.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Marketing scripts', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<td>
					<textarea name="dpt_cb[scripts_marketing]" rows="8" class="large-text code" dir="ltr" placeholder="<!-- Facebook Pixel / Google Ads -->"><?php echo esc_textarea( $o['scripts_marketing'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'For example: Facebook Pixel, Google Ads, TikTok Pixel, LinkedIn Insight.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function tab_floating( $o ) {
		?>
		<h2><span class="dashicons dashicons-sticky"></span> <?php esc_html_e( 'Floating "Manage Cookies" Button', 'digitizer-pro-tools' ); ?></h2>
		<p class="description dpt-big-desc"><?php esc_html_e( 'A small button in the corner of the site that lets visitors reopen the banner and change their preferences at any time. Its text and accessibility label are edited per language on the "Texts" tab.', 'digitizer-pro-tools' ); ?></p>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Basics', 'digitizer-pro-tools' ); ?></h3>
		<table class="form-table dpt-form">
			<tr>
				<th><?php esc_html_e( 'Enable button', 'digitizer-pro-tools' ); ?></th>
				<td><?php $this->switch_field( 'float_button_enabled', $o['float_button_enabled'] ); ?></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_position"><?php esc_html_e( 'Anchor corner', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<?php
					$fp = array(
						'bottom-right' => __( 'Bottom-right corner', 'digitizer-pro-tools' ),
						'bottom-left'  => __( 'Bottom-left corner', 'digitizer-pro-tools' ),
						'top-right'    => __( 'Top-right corner', 'digitizer-pro-tools' ),
						'top-left'     => __( 'Top-left corner', 'digitizer-pro-tools' ),
					);
					?>
					<select id="dpt_cb_float_position" name="dpt_cb[float_button_position]">
						<?php foreach ( $fp as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['float_button_position'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The final position is the corner plus the offsets below.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_bg"><?php esc_html_e( 'Background color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_float_bg" name="dpt_cb[float_button_bg]" value="<?php echo esc_attr( $o['float_button_bg'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_color"><?php esc_html_e( 'Text color', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="text" class="dpt-color" id="dpt_cb_float_color" name="dpt_cb[float_button_color]" value="<?php echo esc_attr( $o['float_button_color'] ); ?>" /></td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-desktop"></span> <?php esc_html_e( 'Desktop position', 'digitizer-pro-tools' ); ?></h3>
		<p class="description dpt-big-desc"><?php esc_html_e( 'Offsets are measured from the chosen corner. For example, corner "bottom-right" with vertical offset 100 places the button 100px above the bottom - handy for avoiding WhatsApp/chat buttons.', 'digitizer-pro-tools' ); ?></p>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_float_offset_x"><?php esc_html_e( 'Horizontal offset', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" max="500" id="dpt_cb_float_offset_x" name="dpt_cb[float_offset_x]" value="<?php echo esc_attr( $o['float_offset_x'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_offset_y"><?php esc_html_e( 'Vertical offset', 'digitizer-pro-tools' ); ?></label></th>
				<td>
					<input type="number" min="0" max="500" id="dpt_cb_float_offset_y" name="dpt_cb[float_offset_y]" value="<?php echo esc_attr( $o['float_offset_y'] ); ?>" /> px
					<p class="description"><?php esc_html_e( 'Tip: WhatsApp buttons usually sit 20-80px from the bottom. Use 90-100 to avoid collisions.', 'digitizer-pro-tools' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_size"><?php esc_html_e( 'Button size', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="30" max="100" id="dpt_cb_float_size" name="dpt_cb[float_button_size]" value="<?php echo esc_attr( $o['float_button_size'] ); ?>" /> px</td>
			</tr>
		</table>

		<h3 class="dpt-section-heading"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Mobile position', 'digitizer-pro-tools' ); ?></h3>
		<p class="description dpt-big-desc"><?php esc_html_e( 'Separate values for screens up to 640px wide.', 'digitizer-pro-tools' ); ?></p>
		<table class="form-table dpt-form">
			<tr>
				<th><label for="dpt_cb_float_offset_x_mobile"><?php esc_html_e( 'Horizontal offset', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" max="500" id="dpt_cb_float_offset_x_mobile" name="dpt_cb[float_offset_x_mobile]" value="<?php echo esc_attr( $o['float_offset_x_mobile'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_offset_y_mobile"><?php esc_html_e( 'Vertical offset', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="0" max="500" id="dpt_cb_float_offset_y_mobile" name="dpt_cb[float_offset_y_mobile]" value="<?php echo esc_attr( $o['float_offset_y_mobile'] ); ?>" /> px</td>
			</tr>
			<tr>
				<th><label for="dpt_cb_float_size_mobile"><?php esc_html_e( 'Button size', 'digitizer-pro-tools' ); ?></label></th>
				<td><input type="number" min="30" max="100" id="dpt_cb_float_size_mobile" name="dpt_cb[float_button_size_mobile]" value="<?php echo esc_attr( $o['float_button_size_mobile'] ); ?>" /> px</td>
			</tr>
		</table>
		<?php
	}

	/* ============================================================
	 *  Sidebar preview
	 * ============================================================ */

	/**
	 * Static preview snapshot of the saved values, in the language
	 * currently being edited (or the default language).
	 */
	private function render_preview( $o, $lang ) {
		$texts = DPT_CB_Settings::get_texts( $lang );
		$rtl   = in_array( strtolower( substr( $lang, 0, (int) strcspn( $lang, '_' ) ) ), array( 'he', 'ar', 'fa', 'ur', 'yi' ), true );
		$dir   = $rtl ? 'rtl' : 'ltr';

		$bg      = $o['bg_color'];
		$text    = $o['text_color'];
		$radius  = (int) $o['border_radius'];
		$padding = (int) $o['padding'];

		$box_style_parts = array(
			'background-color:' . esc_attr( $bg ),
			'border-radius:' . $radius . 'px',
			'padding:' . $padding . 'px',
			'color:' . esc_attr( $text ),
		);
		if ( $o['bg_image_url'] ) {
			$img_layers = '';
			$ov_o = (float) $o['bg_image_overlay_opacity'];
			if ( $ov_o > 0 ) {
				$rgba = $this->hex_to_rgba( $o['bg_image_overlay_color'], $ov_o );
				$img_layers = "linear-gradient({$rgba},{$rgba}),";
			}
			$box_style_parts[] = sprintf( "background-image:%s url('%s')", $img_layers, esc_url( $o['bg_image_url'] ) );
			$box_style_parts[] = 'background-size:' . esc_attr( $o['bg_image_size'] );
			$box_style_parts[] = 'background-position:' . esc_attr( $o['bg_image_position'] );
			$box_style_parts[] = 'background-repeat:' . esc_attr( $o['bg_image_repeat'] );
		}
		if ( (int) $o['border_width'] > 0 ) {
			$box_style_parts[] = 'border:' . (int) $o['border_width'] . 'px ' . esc_attr( $o['border_style'] ) . ' ' . esc_attr( $o['border_color'] );
		}
		$box_style = implode( ';', $box_style_parts );

		$title_color = $o['title_color'] ? $o['title_color'] : $text;
		$title_style = sprintf(
			'color:%s;font-size:%dpx;text-align:%s;font-weight:%s;margin:0 0 %dpx;',
			esc_attr( $title_color ), (int) $o['title_font_size'], esc_attr( $o['title_align'] ),
			esc_attr( $o['title_weight'] ), (int) $o['title_margin_bottom']
		);
		if ( '1' === $o['title_shadow_enabled'] ) {
			$title_style .= sprintf( 'text-shadow:0 %dpx %dpx %s;', (int) $o['title_shadow_y'], (int) $o['title_shadow_blur'], esc_attr( $o['title_shadow_color'] ) );
		}

		$content_color = $o['content_color'] ? $o['content_color'] : $text;
		$content_style = sprintf(
			'color:%s;font-size:%dpx;text-align:%s;line-height:1.5;',
			esc_attr( $content_color ), (int) $o['content_font_size'], esc_attr( $o['content_align'] )
		);
		if ( '1' === $o['content_shadow_enabled'] ) {
			$content_style .= sprintf( 'text-shadow:0 %dpx %dpx %s;', (int) $o['content_shadow_y'], (int) $o['content_shadow_blur'], esc_attr( $o['content_shadow_color'] ) );
		}

		$btn_accept_style = sprintf( 'background:%s;color:%s;border-radius:%dpx;',
			esc_attr( $o['btn_accept_bg'] ), esc_attr( $o['btn_accept_color'] ), (int) $o['btn_accept_radius'] );
		$btn_reject_style = sprintf( 'background:%s;color:%s;border-radius:%dpx;',
			esc_attr( $o['btn_reject_bg'] ), esc_attr( $o['btn_reject_color'] ), (int) $o['btn_reject_radius'] );
		$settings_border  = isset( $o['btn_settings_border'] ) && '1' === $o['btn_settings_border'] ? 'border:1px solid currentColor;' : '';
		$btn_settings_style = sprintf( 'background:%s;color:%s;border-radius:%dpx;%s',
			esc_attr( $o['btn_settings_bg'] ), esc_attr( $o['btn_settings_color'] ), (int) $o['btn_settings_radius'], $settings_border );
		?>
		<div class="dpt-preview-wrap">
			<h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview', 'digitizer-pro-tools' ); ?> <span class="dpt-preview-lang">(<?php echo esc_html( $this->lang_label( $lang ) ); ?>)</span></h3>
			<p class="description"><?php esc_html_e( 'Updates after saving.', 'digitizer-pro-tools' ); ?></p>
			<div class="dpt-preview-box" dir="<?php echo esc_attr( $dir ); ?>" style="<?php echo $box_style; ?>">
				<?php if ( '1' === $o['show_close'] ) : ?>
					<span class="dpt-preview-close" style="color:<?php echo esc_attr( $o['close_color'] ?: $text ); ?>;font-size:<?php echo (int) $o['close_size']; ?>px;<?php echo $o['close_bg_color'] ? 'background:' . esc_attr( $o['close_bg_color'] ) . ';border-radius:50%;' : ''; ?>">&times;</span>
				<?php endif; ?>
				<h4 style="<?php echo $title_style; ?>"><?php echo esc_html( $texts['title'] ); ?></h4>
				<div style="<?php echo $content_style; ?>"><?php echo wp_kses_post( wpautop( $texts['message'] ) ); ?></div>
				<div class="dpt-preview-buttons">
					<span class="dpt-preview-btn" style="<?php echo $btn_accept_style; ?>"><?php echo esc_html( $texts['btn_accept_text'] ); ?></span>
					<?php if ( '1' === $o['btn_reject_show'] ) : ?>
						<span class="dpt-preview-btn" style="<?php echo $btn_reject_style; ?>"><?php echo esc_html( $texts['btn_reject_text'] ); ?></span>
					<?php endif; ?>
					<?php if ( '1' === $o['btn_settings_show'] ) : ?>
						<span class="dpt-preview-btn" style="<?php echo $btn_settings_style; ?>"><?php echo esc_html( $texts['btn_settings_text'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( home_url( '/?dpt_cb_preview=1' ) ); ?>" target="_blank" class="button button-primary" style="width:100%;text-align:center;">
					<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'View banner on the site', 'digitizer-pro-tools' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function hex_to_rgba( $hex, $opacity ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) { $hex = '000000'; }
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$op = max( 0, min( 1, (float) $opacity ) );
		return "rgba({$r},{$g},{$b},{$op})";
	}
}
