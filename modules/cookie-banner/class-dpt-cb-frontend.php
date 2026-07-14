<?php
/**
 * Cookie Banner module - frontend rendering + consent handling.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CB_Frontend {

	const CONSENT_COOKIE = 'dpt_consent';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer',          array( $this, 'render_banner' ), 99 );
		add_action( 'wp_head',            array( $this, 'inject_consented_scripts' ), 99 );
		// High-priority inline check: runs before any deferred script can mess with the banner.
		add_action( 'wp_head',            array( $this, 'inject_inline_consent_check' ), 1 );
		// WP Rocket: don't optimize/defer/delay our JS files.
		add_filter( 'rocket_exclude_defer_js',            array( $this, 'rocket_exclude_files' ) );
		add_filter( 'rocket_delay_js_exclusions',         array( $this, 'rocket_exclude_patterns' ) );
		add_filter( 'rocket_minify_excluded_external_js', array( $this, 'rocket_exclude_files' ) );
		add_filter( 'rocket_excluded_inline_js_content',  array( $this, 'rocket_exclude_inline' ) );
	}

	public function rocket_exclude_files( $excluded ) {
		if ( ! is_array( $excluded ) ) { $excluded = array(); }
		$excluded[] = 'digitizer-pro-tools/modules/cookie-banner/assets/js/frontend.js';
		return $excluded;
	}

	public function rocket_exclude_patterns( $excluded ) {
		if ( ! is_array( $excluded ) ) { $excluded = array(); }
		$excluded[] = 'digitizer-pro-tools';
		$excluded[] = 'dpt_consent';
		$excluded[] = 'DPT_CB_CONFIG';
		$excluded[] = 'DPTCB';
		return $excluded;
	}

	public function rocket_exclude_inline( $excluded ) {
		if ( ! is_array( $excluded ) ) { $excluded = array(); }
		$excluded[] = 'DPT_CB_CONFIG';
		$excluded[] = 'dpt_consent';
		return $excluded;
	}

	/**
	 * Resolve the language for the current request from the page locale.
	 * WPML / Polylang / TranslatePress switch determine_locale() per page.
	 */
	public function resolve_lang( $o = null ) {
		if ( null === $o ) {
			$o = DPT_CB_Settings::all();
		}
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$langs  = $o['languages'];

		// Exact locale key (pt_BR style entries).
		$normalized = DPT_CB_Settings::normalize_lang_code( $locale );
		if ( $normalized && in_array( $normalized, $langs, true ) ) {
			return $normalized;
		}
		// Language part only (he_IL -> he).
		$short = strtolower( substr( $locale, 0, (int) strcspn( $locale, '_-' ) ) );
		if ( in_array( $short, $langs, true ) ) {
			return $short;
		}
		return $o['default_lang'];
	}

	/**
	 * Text direction for a language code.
	 */
	public function lang_dir( $lang ) {
		$rtl   = apply_filters( 'dpt_cb_rtl_langs', array( 'he', 'ar', 'fa', 'ur', 'yi' ) );
		$short = strtolower( substr( $lang, 0, (int) strcspn( $lang, '_-' ) ) );
		return in_array( $short, $rtl, true ) ? 'rtl' : 'ltr';
	}

	/**
	 * Inline script that runs in <head> with priority 1 - BEFORE any
	 * cache plugin can defer/delay it. This is the source of truth for
	 * "should the banner be visible right now". Also validates the
	 * consent version, so bumping it re-prompts even through page caches.
	 */
	public function inject_inline_consent_check() {
		if ( is_admin() ) {
			return;
		}
		$version = (string) DPT_CB_Settings::get( 'consent_version' );
		?>
		<script id="dpt-cb-precheck" data-no-defer data-cfasync="false">
		(function(){
			try {
				var EXPECTED_V = <?php echo wp_json_encode( $version ); ?>;
				var raw = null;
				// Cookie first
				var m = document.cookie.match(/(?:^|; )dpt_consent=([^;]*)/);
				if (m) { raw = decodeURIComponent(m[1]); }
				// Fall back to localStorage
				if (!raw) {
					try { raw = localStorage.getItem('dpt_consent_v1'); } catch(e){}
				}
				if (!raw) return;
				var c = JSON.parse(raw);
				if (!c || typeof c !== 'object') return;
				// Stale consent version - treat as no consent, banner shows again.
				if (String(c.v || '') !== EXPECTED_V) return;
				// Mark the document so CSS can hide the banner immediately when it streams in.
				document.documentElement.setAttribute('data-dpt-cb-resolved', '1');
			} catch(e) { /* swallow */ }
		})();
		</script>
		<style id="dpt-cb-precheck-css">
			html[data-dpt-cb-resolved="1"] #dpt-cb-banner,
			html[data-dpt-cb-resolved="1"] #dpt-cb-overlay { display: none !important; }
		</style>
		<?php
	}

	/**
	 * Banner should render?
	 */
	protected function is_active() {
		$o = DPT_CB_Settings::all();
		if ( '1' !== $o['enabled'] ) {
			return false;
		}
		if ( is_admin() ) {
			return false;
		}
		return true;
	}

	public function enqueue() {
		if ( ! $this->is_active() ) {
			return;
		}
		$base = DPT_URL . 'modules/cookie-banner/assets/';
		wp_enqueue_style(  'dpt-cb-frontend', $base . 'css/frontend.css', array(), DPT_VERSION );
		wp_enqueue_script( 'dpt-cb-frontend', $base . 'js/frontend.js',  array(), DPT_VERSION, true );

		$o    = DPT_CB_Settings::all();
		$lang = $this->resolve_lang( $o );

		// With script blocking on, the client is the ONLY injector of
		// consented snippets - server-rendered HTML can be cached and served
		// to other visitors, so it must never contain them (see
		// inject_consented_scripts()).
		$scripts = array();
		if ( '1' === $o['block_scripts'] ) {
			$scripts = array(
				'functional' => '1' === $o['cat_functional_enabled'] ? (string) $o['scripts_functional'] : '',
				'analytics'  => '1' === $o['cat_analytics_enabled'] ? (string) $o['scripts_analytics'] : '',
				'marketing'  => '1' === $o['cat_marketing_enabled'] ? (string) $o['scripts_marketing'] : '',
			);
		}

		wp_localize_script( 'dpt-cb-frontend', 'DPT_CB_CONFIG', array(
			'version'          => DPT_VERSION,
			'consentVersion'   => (string) $o['consent_version'],
			'consentDays'      => (int) $o['consent_days'],
			'showDelay'        => (int) $o['show_delay'],
			'autoAcceptScroll' => '1' === $o['auto_accept_scroll'],
			'categories'       => array(
				'functional' => '1' === $o['cat_functional_enabled'],
				'analytics'  => '1' === $o['cat_analytics_enabled'],
				'marketing'  => '1' === $o['cat_marketing_enabled'],
			),
			'position'         => $o['position'],
			'lang'             => $lang,
			'dir'              => $this->lang_dir( $lang ),
			'isMobile'         => wp_is_mobile(),
			'showOnMobile'     => '1' === $o['show_on_mobile'],
			'blockScripts'     => '1' === $o['block_scripts'],
			'scripts'          => $scripts,
		) );
	}

	/**
	 * Get current consent from cookie (server side).
	 */
	public function get_consent() {
		if ( empty( $_COOKIE[ self::CONSENT_COOKIE ] ) ) {
			return null;
		}
		$raw  = wp_unslash( $_COOKIE[ self::CONSENT_COOKIE ] );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		// Stale consent version counts as no consent.
		$expected = (string) DPT_CB_Settings::get( 'consent_version' );
		$stored   = isset( $data['v'] ) ? (string) $data['v'] : '';
		if ( $stored !== $expected ) {
			return null;
		}
		return $data;
	}

	/**
	 * Inject scripts to <head> when script blocking is OFF.
	 *
	 * With blocking ON, consented snippets are injected ONLY client-side
	 * (frontend.js) based on the visitor's own consent: server-rendered HTML
	 * may be stored by a full-page cache and served to OTHER visitors, so
	 * baking consented tags into it would run them for people who never
	 * consented.
	 */
	public function inject_consented_scripts() {
		if ( is_admin() ) {
			return;
		}
		$o = DPT_CB_Settings::all();
		if ( '1' !== $o['enabled'] ) {
			return;
		}
		if ( '1' !== $o['block_scripts'] ) {
			// Script blocking is off - load everything for everyone.
			$this->echo_script_block( $o['scripts_functional'] );
			$this->echo_script_block( $o['scripts_analytics'] );
			$this->echo_script_block( $o['scripts_marketing'] );
		}
	}

	/**
	 * Output a block of admin-provided scripts. Content was gated by the
	 * unfiltered_html capability at save time.
	 */
	private function echo_script_block( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return;
		}
		echo "\n<!-- DPT cookie consent script -->\n";
		echo $raw . "\n";
		echo "<!-- /DPT -->\n";
	}

	/**
	 * Render the banner HTML in footer.
	 */
	public function render_banner() {
		if ( ! $this->is_active() ) {
			return;
		}
		$o = DPT_CB_Settings::all();

		// Mobile visibility is decided in CSS (media query), never here:
		// wp_is_mobile() output baked into cached HTML would leak the wrong
		// variant to the other device type.

		$lang  = $this->resolve_lang( $o );
		$dir   = $this->lang_dir( $lang );
		$texts = DPT_CB_Settings::get_texts( $lang );

		$this->render_inline_css( $o );
		$this->render_banner_markup( $o, $texts, $lang, $dir );
		$this->render_float_button( $o, $texts );
	}

	/**
	 * Resolve the policy link for the current language.
	 * page_id wins over the manual URL; WPML/Polylang map it to the
	 * translated page when available.
	 */
	private function resolve_policy_link( $o ) {
		$page_id = (int) $o['policy_page_id'];
		if ( $page_id > 0 ) {
			// WPML (no-op when WPML absent).
			$page_id = (int) apply_filters( 'wpml_object_id', $page_id, 'page', true );
			// Polylang.
			if ( function_exists( 'pll_get_post' ) ) {
				$translated = pll_get_post( $page_id );
				if ( $translated ) {
					$page_id = (int) $translated;
				}
			}
			$link = get_permalink( $page_id );
			if ( $link ) {
				return $link;
			}
		}
		if ( ! empty( $o['policy_url'] ) ) {
			return $o['policy_url'];
		}
		return '';
	}

	/**
	 * Build the custom CSS for this banner instance.
	 */
	private function render_inline_css( $o ) {
		$text_color  = esc_attr( $o['text_color'] );
		$title_color = $o['title_color'] ? esc_attr( $o['title_color'] ) : $text_color;
		$title_size  = (int) $o['title_font_size'];
		$title_align = $this->logical_align( $o['title_align'] );
		$title_weight= esc_attr( $o['title_weight'] );
		$title_mb    = (int) $o['title_margin_bottom'];
		$title_shadow = '1' === $o['title_shadow_enabled']
			? sprintf( 'text-shadow:0 %dpx %dpx %s;', (int) $o['title_shadow_y'], (int) $o['title_shadow_blur'], esc_attr( $o['title_shadow_color'] ) )
			: '';

		$content_color = $o['content_color'] ? esc_attr( $o['content_color'] ) : $text_color;
		$content_size  = (int) $o['content_font_size'];
		$content_align = $this->logical_align( $o['content_align'] );
		$content_shadow = '1' === $o['content_shadow_enabled']
			? sprintf( 'text-shadow:0 %dpx %dpx %s;', (int) $o['content_shadow_y'], (int) $o['content_shadow_blur'], esc_attr( $o['content_shadow_color'] ) )
			: '';

		// Background image + overlay.
		$bg_image_css = '';
		if ( $o['bg_image_url'] ) {
			$ov_layer = '';
			$ov_o = (float) $o['bg_image_overlay_opacity'];
			if ( $ov_o > 0 ) {
				$rgba = $this->hex_to_rgba( $o['bg_image_overlay_color'], $ov_o );
				$ov_layer = "linear-gradient({$rgba},{$rgba}),";
			}
			$bg_image_css = sprintf(
				"background-image:%s url('%s');background-size:%s;background-position:%s;background-repeat:%s;",
				$ov_layer,
				esc_url( $o['bg_image_url'] ),
				esc_attr( $o['bg_image_size'] ),
				esc_attr( $o['bg_image_position'] ),
				esc_attr( $o['bg_image_repeat'] )
			);
		}

		$border_css = '';
		if ( (int) $o['border_width'] > 0 ) {
			$border_css = sprintf( 'border:%dpx %s %s;', (int) $o['border_width'], esc_attr( $o['border_style'] ), esc_attr( $o['border_color'] ) );
		}

		$box_shadow_css = '1' === $o['box_shadow'] ? 'box-shadow:0 10px 40px rgba(0,0,0,0.2);' : '';

		// Close button.
		$close_color  = $o['close_color'] ? esc_attr( $o['close_color'] ) : $text_color;
		$close_bg     = $o['close_bg_color'];
		$close_bg_css = $close_bg ? 'background:' . esc_attr( $close_bg ) . ';border-radius:50%;' : 'background:transparent;';
		$close_size   = (int) $o['close_size'];
		$close_box    = max( 20, $close_size + 12 );

		// Overlay.
		$overlay_rgba = $this->hex_to_rgba( $o['overlay_color'], $o['overlay_opacity'] );

		// Button styles.
		$btn_settings_border = ( isset( $o['btn_settings_border'] ) && '1' === $o['btn_settings_border'] ) ? 'border:1px solid currentColor;' : 'border:0;';

		// Float button - desktop + mobile position using offsets.
		$fb_corner     = $o['float_button_position'];
		$fb_size_d     = max( 30, (int) $o['float_button_size'] );
		$fb_size_m     = max( 30, (int) $o['float_button_size_mobile'] );
		$fb_offset_x_d = max( 0, (int) $o['float_offset_x'] );
		$fb_offset_y_d = max( 0, (int) $o['float_offset_y'] );
		$fb_offset_x_m = max( 0, (int) $o['float_offset_x_mobile'] );
		$fb_offset_y_m = max( 0, (int) $o['float_offset_y_mobile'] );
		$fb_pos_d      = $this->float_button_position_css( $fb_corner, $fb_offset_x_d, $fb_offset_y_d );
		$fb_pos_m      = $this->float_button_position_css( $fb_corner, $fb_offset_x_m, $fb_offset_y_m );
		?>
		<style id="dpt-cb-inline-css">
			#dpt-cb-overlay { background: <?php echo esc_attr( $overlay_rgba ); ?>; }
			#dpt-cb-banner .dpt-cb-box {
				background-color: <?php echo esc_attr( $o['bg_color'] ); ?>;
				color: <?php echo $text_color; ?>;
				max-width: <?php echo (int) $o['width']; ?>px;
				border-radius: <?php echo (int) $o['border_radius']; ?>px;
				padding: <?php echo (int) $o['padding']; ?>px;
				width: <?php echo (int) $o['max_width_pct']; ?>%;
				<?php echo $bg_image_css; ?>
				<?php echo $border_css; ?>
				<?php echo $box_shadow_css; ?>
			}
			#dpt-cb-banner .dpt-cb-title {
				color: <?php echo $title_color; ?>;
				font-size: <?php echo $title_size; ?>px;
				text-align: <?php echo $title_align; ?>;
				font-weight: <?php echo $title_weight; ?>;
				margin: 0 0 <?php echo $title_mb; ?>px;
				<?php echo $title_shadow; ?>
			}
			#dpt-cb-banner .dpt-cb-message {
				color: <?php echo $content_color; ?>;
				font-size: <?php echo $content_size; ?>px;
				text-align: <?php echo $content_align; ?>;
				<?php echo $content_shadow; ?>
			}
			#dpt-cb-banner .dpt-cb-close {
				color: <?php echo $close_color; ?>;
				font-size: <?php echo $close_size; ?>px;
				width: <?php echo $close_box; ?>px;
				height: <?php echo $close_box; ?>px;
				<?php echo $close_bg_css; ?>
			}
			#dpt-cb-banner .dpt-cb-btn-accept {
				background: <?php echo esc_attr( $o['btn_accept_bg'] ); ?>;
				color: <?php echo esc_attr( $o['btn_accept_color'] ); ?>;
				border-radius: <?php echo (int) $o['btn_accept_radius']; ?>px;
				border: 0;
			}
			#dpt-cb-banner .dpt-cb-btn-accept:hover { background: <?php echo esc_attr( $o['btn_accept_hover_bg'] ); ?>; }
			#dpt-cb-banner .dpt-cb-btn-reject {
				background: <?php echo esc_attr( $o['btn_reject_bg'] ); ?>;
				color: <?php echo esc_attr( $o['btn_reject_color'] ); ?>;
				border-radius: <?php echo (int) $o['btn_reject_radius']; ?>px;
				border: 0;
			}
			#dpt-cb-banner .dpt-cb-btn-reject:hover { background: <?php echo esc_attr( $o['btn_reject_hover_bg'] ); ?>; }
			#dpt-cb-banner .dpt-cb-btn-settings {
				background: <?php echo esc_attr( $o['btn_settings_bg'] ); ?>;
				color: <?php echo esc_attr( $o['btn_settings_color'] ); ?>;
				border-radius: <?php echo (int) $o['btn_settings_radius']; ?>px;
				<?php echo $btn_settings_border; ?>
			}
			#dpt-cb-banner .dpt-cb-btn-save {
				background: <?php echo esc_attr( $o['btn_save_bg'] ); ?>;
				color: <?php echo esc_attr( $o['btn_save_color'] ); ?>;
				border-radius: <?php echo (int) $o['btn_save_radius']; ?>px;
				border: 0;
			}
			#dpt-cb-float-button {
				background: <?php echo esc_attr( $o['float_button_bg'] ); ?>;
				color: <?php echo esc_attr( $o['float_button_color'] ); ?>;
				width: <?php echo $fb_size_d; ?>px;
				height: <?php echo $fb_size_d; ?>px;
				<?php echo $fb_pos_d; ?>
			}
			@media (max-width: 640px) {
				#dpt-cb-float-button {
					width: <?php echo $fb_size_m; ?>px !important;
					height: <?php echo $fb_size_m; ?>px !important;
					<?php echo $fb_pos_m; ?>
				}
			}
		</style>
		<?php
	}

	/**
	 * Map stored alignment to a CSS value. Legacy physical values pass
	 * through; logical start/end work in both RTL and LTR.
	 */
	private function logical_align( $align ) {
		$allowed = array( 'start', 'end', 'center', 'justify', 'left', 'right' );
		return in_array( $align, $allowed, true ) ? $align : 'start';
	}

	private function float_button_position_css( $pos, $offset_x = 20, $offset_y = 20 ) {
		$x = (int) $offset_x;
		$y = (int) $offset_y;
		$map = array(
			'bottom-right' => "bottom:{$y}px !important;right:{$x}px !important;top:auto !important;left:auto !important;",
			'bottom-left'  => "bottom:{$y}px !important;left:{$x}px !important;top:auto !important;right:auto !important;",
			'top-right'    => "top:{$y}px !important;right:{$x}px !important;bottom:auto !important;left:auto !important;",
			'top-left'     => "top:{$y}px !important;left:{$x}px !important;bottom:auto !important;right:auto !important;",
		);
		return isset( $map[ $pos ] ) ? $map[ $pos ] : $map['bottom-right'];
	}

	private function render_banner_markup( $o, $t, $lang, $dir ) {
		$position_class = 'dpt-cb-pos-' . esc_attr( $o['position'] );
		$anim_class     = ' dpt-cb-anim-' . esc_attr( $o['animation'] );
		$mobile_class   = $this->hide_mobile_class( $o );
		$preview_mode   = isset( $_GET['dpt_cb_preview'] ) && current_user_can( 'manage_options' );

		// CACHE-PROOF STRATEGY:
		// Always render the banner with `dpt-cb-hidden`. The JavaScript
		// removes it on load IF there is no valid consent, so cached HTML
		// never shows the banner to visitors who already answered.
		// Exception: preview mode for admins, which forces the banner open.
		$hidden_class = $preview_mode ? '' : ' dpt-cb-hidden';
		$force_open   = $preview_mode ? 'data-force-open="1"' : '';

		?>
		<?php if ( '1' === $o['overlay_enabled'] ) : ?>
			<div id="dpt-cb-overlay" class="<?php echo esc_attr( trim( $hidden_class . $mobile_class ) ); ?>"></div>
		<?php endif; ?>

		<div id="dpt-cb-banner"
		     class="<?php echo $position_class; ?><?php echo $anim_class; ?><?php echo $mobile_class; ?><?php echo $hidden_class; ?>"
		     dir="<?php echo esc_attr( $dir ); ?>"
		     lang="<?php echo esc_attr( str_replace( '_', '-', $lang ) ); ?>"
		     <?php echo $force_open; ?>
		     role="dialog" aria-labelledby="dpt-cb-title" aria-modal="true">
			<div class="dpt-cb-box">
				<?php if ( '1' === $o['show_close'] ) : ?>
					<button type="button" class="dpt-cb-close" data-dpt-cb-action="reject" aria-label="<?php echo esc_attr( $t['close_aria'] ); ?>">&times;</button>
				<?php endif; ?>

				<div class="dpt-cb-main-view">
					<?php if ( ! empty( $t['title'] ) ) : ?>
						<h2 id="dpt-cb-title" class="dpt-cb-title"><?php echo esc_html( $t['title'] ); ?></h2>
					<?php endif; ?>
					<div class="dpt-cb-message"><?php echo wp_kses_post( wpautop( $t['message'] ) ); ?></div>

					<?php
					$policy_link = $this->resolve_policy_link( $o );
					if ( $policy_link && ! empty( $t['policy_text'] ) ) :
					?>
						<p class="dpt-cb-policy"><a href="<?php echo esc_url( $policy_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $t['policy_text'] ); ?></a></p>
					<?php endif; ?>

					<div class="dpt-cb-buttons">
						<button type="button" class="dpt-cb-btn dpt-cb-btn-accept" data-dpt-cb-action="accept"><?php echo esc_html( $t['btn_accept_text'] ); ?></button>
						<?php if ( '1' === $o['btn_reject_show'] ) : ?>
							<button type="button" class="dpt-cb-btn dpt-cb-btn-reject" data-dpt-cb-action="reject"><?php echo esc_html( $t['btn_reject_text'] ); ?></button>
						<?php endif; ?>
						<?php if ( '1' === $o['btn_settings_show'] ) : ?>
							<button type="button" class="dpt-cb-btn dpt-cb-btn-settings" data-dpt-cb-action="show-settings"><?php echo esc_html( $t['btn_settings_text'] ); ?></button>
						<?php endif; ?>
					</div>
				</div>

				<div class="dpt-cb-settings-view" style="display:none;">
					<h3 class="dpt-cb-settings-title"><?php echo esc_html( $t['settings_view_title'] ); ?></h3>

					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name">🔒 <?php echo esc_html( $t['cat_essential_name'] ); ?></span>
							<span class="dpt-cb-always-on"><?php echo esc_html( $t['always_on_label'] ); ?></span>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_essential_desc'] ); ?></p>
					</div>

					<?php if ( '1' === $o['cat_functional_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name">🎯 <?php echo esc_html( $t['cat_functional_name'] ); ?></span>
							<label class="dpt-cb-toggle"><input type="checkbox" data-dpt-cb-cat="functional" /><span class="dpt-cb-toggle-slider"></span></label>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_functional_desc'] ); ?></p>
					</div>
					<?php endif; ?>

					<?php if ( '1' === $o['cat_analytics_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name">📊 <?php echo esc_html( $t['cat_analytics_name'] ); ?></span>
							<label class="dpt-cb-toggle"><input type="checkbox" data-dpt-cb-cat="analytics" /><span class="dpt-cb-toggle-slider"></span></label>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_analytics_desc'] ); ?></p>
					</div>
					<?php endif; ?>

					<?php if ( '1' === $o['cat_marketing_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name">📣 <?php echo esc_html( $t['cat_marketing_name'] ); ?></span>
							<label class="dpt-cb-toggle"><input type="checkbox" data-dpt-cb-cat="marketing" /><span class="dpt-cb-toggle-slider"></span></label>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_marketing_desc'] ); ?></p>
					</div>
					<?php endif; ?>

					<div class="dpt-cb-buttons">
						<button type="button" class="dpt-cb-btn dpt-cb-btn-save" data-dpt-cb-action="save-settings"><?php echo esc_html( $t['btn_save_text'] ); ?></button>
						<button type="button" class="dpt-cb-btn dpt-cb-btn-accept" data-dpt-cb-action="accept"><?php echo esc_html( $t['btn_accept_text'] ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Class that hides an element on small screens when "show on mobile"
	 * is off. Pure CSS so cached HTML behaves the same for every device.
	 */
	private function hide_mobile_class( $o ) {
		return '1' === $o['show_on_mobile'] ? '' : ' dpt-cb-hide-mobile';
	}

	private function render_float_button( $o, $t ) {
		if ( '1' !== $o['float_button_enabled'] ) {
			return;
		}
		$mobile_class = trim( $this->hide_mobile_class( $o ) );
		// Inline onclick is a defensive fallback: if a cache/optimizer plugin
		// delays our JS, the button still works on first click.
		$inline_open = "try{document.documentElement.removeAttribute('data-dpt-cb-resolved');"
		             . "var b=document.getElementById('dpt-cb-banner'),o=document.getElementById('dpt-cb-overlay');"
		             . "if(b){b.classList.remove('dpt-cb-hidden');b.classList.add('dpt-cb-entering');"
		             . "setTimeout(function(){b.classList.remove('dpt-cb-entering');},500);}"
		             . "if(o){o.classList.remove('dpt-cb-hidden');}"
		             . "var m=b&&b.querySelector('.dpt-cb-main-view'),s=b&&b.querySelector('.dpt-cb-settings-view');"
		             . "if(m)m.style.display='';if(s)s.style.display='none';"
		             . "}catch(e){}";
		?>
		<button type="button" id="dpt-cb-float-button" class="<?php echo esc_attr( $mobile_class ); ?>" aria-label="<?php echo esc_attr( $t['float_button_aria'] ); ?>" onclick="<?php echo esc_attr( $inline_open ); ?>"><?php
			echo esc_html( $t['float_button_text'] );
		?></button>
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
		$o = max( 0, min( 1, (float) $opacity ) );
		return "rgba({$r},{$g},{$b},{$o})";
	}
}
