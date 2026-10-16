<?php
/**
 * Cookie Banner module - frontend rendering + consent handling.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CB_Frontend {

	const CONSENT_COOKIE = 'dpt_consent';

	/** The address a link points at to reopen the banner: #dpt-cookie-settings. */
	const OPEN_HASH = 'dpt-cookie-settings';

	/**
	 * What reopens the banner when clicked: a link to the hash (on its own or
	 * at the end of a full URL, as menus write it), or anything carrying the
	 * class, for builders that let you set a class but not an address.
	 */
	const OPEN_SELECTOR = 'a[href="#dpt-cookie-settings"], a[href$="#dpt-cookie-settings"], .dpt-cb-open';

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
		add_shortcode( 'dpt_cookie_settings', array( $this, 'settings_link_shortcode' ) );

		// The WP Consent API reads two things from the consent plugin: that one
		// is registered, and which kind of consent it collects. Opt-in: nothing
		// that needs consent runs before the visitor says yes. Only with the
		// banner on - an opt-in manager with no banner would leave every
		// category denied for good, since nobody could ever say yes.
		if ( '1' === DPT_CB_Settings::get( 'wp_consent_api' ) && self::signals_possible( DPT_CB_Settings::all() ) && defined( 'DPT_BASENAME' ) ) {
			add_filter( 'wp_consent_api_registered_' . DPT_BASENAME, '__return_true' );
			add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ) );
			add_filter( 'wp_has_consent', array( $this, 'filter_wp_has_consent' ), 10, 3 );
		}
	}

	/**
	 * The banner's own cookie is the truth for wp_has_consent(), not the API's
	 * mirrored wp_consent_* cookies. Those keep whatever was last written, so
	 * after a consent version bump or an expired choice they could still say
	 * "allow" while the banner is asking again - and code that asks in PHP
	 * runs before frontend.js can correct them.
	 *
	 * @param bool   $has_consent  What the API decided.
	 * @param string $category     The API category asked about.
	 * @param string $requested_by Who asked.
	 * @return bool
	 */
	public function filter_wp_has_consent( $has_consent, $category = '', $requested_by = '' ) {
		$banner_category = '';
		foreach ( self::wp_consent_api_map() as $cat => $api_categories ) {
			if ( in_array( $category, $api_categories, true ) ) {
				$banner_category = $cat;
				break;
			}
		}
		if ( 'functional' === $category ) {
			// The API's functional is the essential kind, never asked about.
			// Under opt-in the API would deny it until frontend.js writes its
			// cookie, which is too late for PHP asking in this request.
			return true;
		}
		if ( '' === $banner_category ) {
			return $has_consent; // Not a category this banner decides.
		}
		$consent = $this->get_consent();
		$enabled = self::enabled_categories( DPT_CB_Settings::all() );
		return is_array( $consent ) && ! empty( $consent[ $banner_category ] ) && $enabled[ $banner_category ];
	}

	/**
	 * Whether every visitor can reach the banner, which Consent Mode and the
	 * WP Consent API both assume: they start from "denied" and wait for a
	 * choice. With the banner off - or hidden on phones - some visitors could
	 * never make one, and everything would stay denied for them for good. So
	 * the two are not sent at all then, as with no consent plugin.
	 *
	 * @param array $o Settings, as DPT_CB_Settings::all() returns them.
	 * @return bool
	 */
	public static function signals_possible( $o ) {
		return '1' === $o['enabled'] && '1' === $o['show_on_mobile'];
	}

	/**
	 * The banner categories switched on now. A choice stored while a category
	 * was on grants nothing once it is switched off: it is no longer offered,
	 * and its scripts no longer load either.
	 *
	 * @param array $o Settings.
	 * @return array<string,bool>
	 */
	public static function enabled_categories( $o ) {
		return array(
			'functional' => '1' === $o['cat_functional_enabled'],
			'analytics'  => '1' === $o['cat_analytics_enabled'],
			'marketing'  => '1' === $o['cat_marketing_enabled'],
		);
	}

	/**
	 * The kind of consent the banner collects, for the WP Consent API.
	 *
	 * @return string
	 */
	public static function consent_type() {
		return 'optin';
	}

	/**
	 * What each banner category grants in Google Consent Mode v2. Every signal
	 * listed here starts denied; security_storage is not a choice and stays
	 * granted.
	 *
	 * @return array<string,string[]>
	 */
	public static function consent_mode_map() {
		return array(
			'functional' => array( 'functionality_storage', 'personalization_storage' ),
			'analytics'  => array( 'analytics_storage' ),
			'marketing'  => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
		);
	}

	/**
	 * Consent Mode's default state, set before any Google tag runs: every
	 * signal a category grants is denied, and tags wait up to half a second
	 * for an update from a stored choice.
	 *
	 * @return array<string,string|int>
	 */
	public static function consent_mode_defaults() {
		$defaults = array();
		foreach ( self::consent_mode_map() as $signals ) {
			foreach ( $signals as $signal ) {
				$defaults[ $signal ] = 'denied';
			}
		}
		$defaults['security_storage'] = 'granted';
		$defaults['wait_for_update']  = 500;
		return $defaults;
	}

	/**
	 * What each banner category means in the WP Consent API. The API's own
	 * "functional" is the essential kind, always allowed.
	 *
	 * @return array<string,string[]>
	 */
	public static function wp_consent_api_map() {
		return array(
			'functional' => array( 'preferences' ),
			'analytics'  => array( 'statistics', 'statistics-anonymous' ),
			'marketing'  => array( 'marketing' ),
		);
	}

	/**
	 * Whether the WP Consent API plugin is running here.
	 *
	 * @return bool
	 */
	public static function wp_consent_api_present() {
		return function_exists( 'wp_has_consent' );
	}

	/**
	 * [dpt_cookie_settings text="..."] - a link that reopens the banner, for
	 * a footer or the privacy policy page. Without text it reads like the
	 * floating button's label, in the page's language.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function settings_link_shortcode( $atts ) {
		if ( ! $this->is_active() ) {
			return ''; // Nothing on the page for it to open.
		}
		$atts = shortcode_atts( array( 'text' => '' ), $atts, 'dpt_cookie_settings' );
		$text = trim( (string) $atts['text'] );
		if ( '' === $text ) {
			$texts = DPT_CB_Settings::get_texts( $this->resolve_lang( DPT_CB_Settings::all() ) );
			$text  = $texts['float_button_aria'];
		}
		return '<a href="#' . esc_attr( self::OPEN_HASH ) . '" class="dpt-cb-settings-link">' . esc_html( $text ) . '</a>';
	}

	/**
	 * What reopens the banner when clicked, besides the floating button.
	 *
	 * @return string A CSS selector; '' turns links off.
	 */
	public static function open_selector() {
		/**
		 * Filters the CSS selector of the elements that reopen the banner.
		 *
		 * @param string $selector Default DPT_CB_Frontend::OPEN_SELECTOR.
		 */
		return trim( (string) apply_filters( 'dpt_cb_open_selector', self::OPEN_SELECTOR ) );
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
		$days    = max( 1, (int) DPT_CB_Settings::get( 'consent_days' ) );
		// Only with the banner on: with no banner nobody can ever grant, and
		// every Google tag on the site would stay denied for good.
		$o            = DPT_CB_Settings::all();
		$consent_mode = '1' === $o['consent_mode'] && $this->is_active() && self::signals_possible( $o );
		?>
		<script id="dpt-cb-precheck" data-no-defer data-cfasync="false">
		(function(){
			<?php if ( $consent_mode ) : ?>
			// Google Consent Mode v2. First in <head>, so it is in the data
			// layer before any Google tag reads it.
			var CM = <?php echo wp_json_encode( self::consent_mode_map() ); ?>;
			var CM_ON = <?php echo wp_json_encode( self::enabled_categories( $o ) ); ?>;
			window.dataLayer = window.dataLayer || [];
			if (typeof window.gtag !== 'function') { window.gtag = function(){ window.dataLayer.push(arguments); }; }
			window.gtag('consent', 'default', <?php echo wp_json_encode( self::consent_mode_defaults() ); ?>);
			<?php else : ?>
			var CM = null;
			<?php endif; ?>
			try {
				var EXPECTED_V = <?php echo wp_json_encode( $version ); ?>;
				var MAX_AGE_MS = <?php echo (int) $days; ?> * 864e5;
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
				// Consent lifetime elapsed (localStorage outlives the cookie).
				if (!c.ts || (Date.now() - c.ts) > MAX_AGE_MS) return;
				// A stored choice, so the tags start from it - on a cached page
				// too, since this runs in the browser.
				if (CM) {
					var u = {};
					for (var cat in CM) {
						if (!CM.hasOwnProperty(cat)) continue;
						for (var i = 0; i < CM[cat].length; i++) { u[CM[cat][i]] = c[cat] && CM_ON[cat] ? 'granted' : 'denied'; }
					}
					window.gtag('consent', 'update', u);
				}
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
			'openSelector'     => self::open_selector(),
			'openHash'         => self::OPEN_HASH,
			'consentMode'      => '1' === $o['consent_mode'] && self::signals_possible( $o ) ? self::consent_mode_map() : null,
			'wpConsentApi'     => '1' === $o['wp_consent_api'] && self::signals_possible( $o ) ? self::wp_consent_api_map() : null,
		) );
	}

	/**
	 * Get current consent from cookie (server side).
	 */
	public function get_consent() {
		if ( empty( $_COOKIE[ self::CONSENT_COOKIE ] ) ) {
			return null;
		}
		// Not run through a sanitizer: the value is JSON and json_decode()
		// either yields an array - whose individual members are cast below -
		// or nothing. A string sanitizer would corrupt the JSON instead.
		$raw  = wp_unslash( $_COOKIE[ self::CONSENT_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by json_decode() below.
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
		// Enforce the consent lifetime here too: the cookie's own expiry was
		// set from the consent_days value at write time, so a later, shorter
		// setting must still win.
		$days = max( 1, (int) DPT_CB_Settings::get( 'consent_days' ) );
		$ts   = isset( $data['ts'] ) ? (float) $data['ts'] : 0; // JS epoch ms.
		if ( ! $ts || ( microtime( true ) * 1000 - $ts ) > $days * 86400000 ) {
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
			// Script blocking is off - load everything for everyone, but a
			// disabled category stays off: it is no longer disclosed in the
			// preferences UI, so its leftover snippet must not run.
			if ( '1' === $o['cat_functional_enabled'] ) {
				$this->echo_script_block( $o['scripts_functional'] );
			}
			if ( '1' === $o['cat_analytics_enabled'] ) {
				$this->echo_script_block( $o['scripts_analytics'] );
			}
			if ( '1' === $o['cat_marketing_enabled'] ) {
				$this->echo_script_block( $o['scripts_marketing'] );
			}
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
		echo $raw . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw tracking snippet; gated by unfiltered_html at save time and meaningless if escaped.
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
		$close_alpha  = self::close_opacity( $o['close_opacity'] );
		// frontend.css puts the button 8px in from the box's top and its
		// inline-end side. With no visible title above the message, whatever of
		// it reaches past the padding would sit on the message's first line.
		$close_clear  = max( 0, 8 + $close_box - max( 0, (int) $o['padding'] ) );

		// Overlay.
		$overlay_rgba = $this->hex_to_rgba( $o['overlay_color'], $o['overlay_opacity'] );

		// Button styles.
		$btn_border_color    = ! empty( $o['btn_settings_border_color'] ) ? esc_attr( $o['btn_settings_border_color'] ) : 'currentColor';
		$btn_settings_border = ( isset( $o['btn_settings_border'] ) && '1' === $o['btn_settings_border'] ) ? 'border:1px solid ' . $btn_border_color . ';' : 'border:0;';
		// Every banner button. A height of 0 leaves it to the padding, as before.
		$btn_height      = max( 0, (int) $o['btn_height'] );
		$btn_font_size   = max( 8, (int) $o['btn_font_size'] );
		$btn_font_weight = esc_attr( $o['btn_font_weight'] ); // Allowlisted in DPT_CB_Settings::all().
		$policy_link_color = $o['policy_link_color'] ? esc_attr( $o['policy_link_color'] ) : '';

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
		// '' when the banner should inherit the site font; already sanitized to
		// a bare font stack (no ; { } etc.), so it is safe inside <style>.
		$font_family   = DPT_CB_Settings::font_family_css();

		// Box sizing is emitted with the position class and !important so it
		// overrides the position-specific rules in frontend.css (which pin the
		// corner box to 380px and force bars/corners to 100% on mobile). Same
		// specificity as those rules + later source order = the setting wins.
		$pos_sel   = '#dpt-cb-banner.dpt-cb-pos-' . preg_replace( '/[^a-z-]/', '', (string) $o['position'] ) . ' .dpt-cb-box';
		$is_corner      = in_array( $o['position'], array( 'bottom-left', 'bottom-right' ), true );
		$mobile_margins = self::box_mobile_margins( $o['position'] );
		$banner_sel     = '#dpt-cb-banner.dpt-cb-pos-' . preg_replace( '/[^a-z-]/', '', (string) $o['position'] );
		$corner_x       = max( 0, (int) $o['corner_offset_x'] );
		$corner_y       = max( 0, (int) $o['corner_offset_y'] );
		$corner_side    = 'bottom-left' === $o['position'] ? 'left' : 'right';
		// A bar has nothing to be flush with but the edge it sits on; the
		// centered modal is a dialog, not a bar, and is left alone.
		$full_width_edge = '';
		if ( '1' === $o['mobile_full_width'] ) {
			if ( 'top' === $o['position'] ) {
				$full_width_edge = 'top';
			} elseif ( in_array( $o['position'], array( 'bottom', 'bottom-left', 'bottom-right' ), true ) ) {
				$full_width_edge = 'bottom';
			}
		}
		$full_width_radius = self::full_width_radius( $full_width_edge, (int) $o['border_radius'], '1' === $o['mobile_full_width_radius'] );
		?>
		<?php
		/*
		 * Everything printed between here and </style> is CSS, not HTML: each
		 * value is either int-cast, run through esc_attr() where it was
		 * assigned above, or built by a helper that only emits allowlisted
		 * keywords (see DPT_CB_Settings::css_value_allowlists() and
		 * sanitize_font_family()).
		 *
		 * HTML escaping at the echo site would be wrong here, not merely
		 * redundant: the content of a <style> element is raw text, so a
		 * browser never decodes entities inside it - esc_attr() would turn a
		 * font stack like "Open Sans", sans-serif into &quot;Open Sans&quot;
		 * and break the declaration.
		 *
		 * Anything added to this block must keep that contract.
		 */
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS context; values sanitized at assignment, see above.
		?>
		<style id="dpt-cb-inline-css">
			#dpt-cb-overlay { background: <?php echo esc_attr( $overlay_rgba ); ?>; }
			<?php if ( '' !== $font_family ) : ?>
			/* Explicit font stack (Elementor's primary font or a custom one).
			   When set to "inherit" nothing is emitted and the banner keeps
			   inheriting the site/theme font.
			   Applied to every descendant, not just the wrappers: a theme that
			   sets font-family directly on h2/p wins over inheritance no matter
			   how specific an ancestor rule is, which would leave the title and
			   message on the theme font. !important because the admin asked for
			   this exact font. */
			#dpt-cb-banner, #dpt-cb-banner *,
			#dpt-cb-float-button, #dpt-cb-float-button * {
				font-family: <?php echo $font_family; // Already sanitized to a bare font stack. ?> !important;
			}
			<?php endif; ?>
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
			/* Authoritative desktop sizing for the active position. Needed
			   because frontend.css pins the corner box to a fixed 380px with
			   !important, which would otherwise ignore the width setting. */
			<?php echo $pos_sel; ?> {
			<?php if ( $is_corner ) : ?>
				/* A corner banner is shrink-to-fit (only bottom/left|right are
				   set), so a percentage width has nothing to resolve against -
				   it takes its own explicit pixel width, capped to the viewport.
				   Kept separate from the width/percentage pair so switching
				   positions does not overwrite either setting. */
				width: <?php echo (int) $o['width_corner']; ?>px !important;
				max-width: 95vw !important;
			<?php else : ?>
				max-width: <?php echo (int) $o['width']; ?>px !important;
				width: <?php echo (int) $o['max_width_pct']; ?>% !important;
			<?php endif; ?>
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
				opacity: <?php echo $close_alpha; ?>;
				<?php echo $close_bg_css; ?>
			}
			<?php if ( $close_clear > 0 ) : ?>
			/* No title shown: the close button sits where the message begins.
			   A float the button's size, on its side, makes the first lines
			   wrap around it while the rest keep the full width. Matched on
			   the markup (a button before the content, then a hidden title or
			   none), so it also covers a language whose title is empty. The
			   side is physical, as the button's is in each direction. */
			#dpt-cb-banner .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-title.dpt-cb-sr-only + .dpt-cb-message::before,
			#dpt-cb-banner .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-message:first-child::before {
				content: "";
				float: right;
				width: <?php echo $close_clear + 4; ?>px;
				height: <?php echo $close_clear; ?>px;
			}
			#dpt-cb-banner[dir="rtl"] .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-title.dpt-cb-sr-only + .dpt-cb-message::before,
			#dpt-cb-banner[dir="rtl"] .dpt-cb-close + .dpt-cb-main-view > .dpt-cb-message:first-child::before {
				float: left;
			}
			<?php endif; ?>
			<?php if ( '' !== $policy_link_color ) : ?>
			#dpt-cb-banner .dpt-cb-policy a,
			#dpt-cb-banner .dpt-cb-policy-inline a {
				color: <?php echo $policy_link_color; ?>;
				opacity: 1;
			}
			<?php endif; ?>
			/* Same selector as frontend.css's base button rule, later in the
			   page, so these win without !important. */
			#dpt-cb-banner .dpt-cb-btn {
				font-size: <?php echo $btn_font_size; ?>px;
				font-weight: <?php echo $btn_font_weight; ?>;
				<?php if ( $btn_height > 0 ) : ?>
				min-height: <?php echo $btn_height; ?>px;
				<?php endif; ?>
			}
			<?php if ( '1' === $o['btn_stretch'] ) : ?>
			/* Desktop only: below 640px frontend.css already stacks them full
			   width. The main view only - the settings view has one button. */
			@media (min-width: 641px) {
				#dpt-cb-banner .dpt-cb-main-view .dpt-cb-buttons .dpt-cb-btn {
					flex: 1 1 0;
				}
			}
			<?php endif; ?>
			<?php if ( $is_corner ) : ?>
			/* Desktop only: below 640px frontend.css spreads the corner banner
			   across the screen, and that layout keeps its own spacing. */
			@media (min-width: 641px) {
				<?php echo $banner_sel; ?> {
					<?php echo $corner_side; ?>: <?php echo $corner_x; ?>px;
					bottom: <?php echo $corner_y; ?>px;
				}
			}
			<?php endif; ?>
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
				/* Same selector shape and !important as the position rules in
				   frontend.css (which force bars/corners to 100%), so the
				   configured mobile size wins on later source order. On mobile
				   the corner banner is stretched to left/right:10px, so it is no
				   longer shrink-to-fit and a percentage width resolves normally. */
				<?php echo $pos_sel; ?> {
					max-width: <?php echo (int) $o['width_mobile']; ?>px !important;
					width: <?php echo (int) $o['max_width_pct_mobile']; ?>% !important;
					/* frontend.css stretches the mobile container to
					   left:10px/right:10px, so a box narrower than 100% would
					   sit against its left edge - a bottom-right banner would
					   look bottom-left and bars would lose their centering.
					   Keep the box on the side its position names. Physical
					   margins on purpose: the position names are physical, so
					   they must not flip with the RTL/LTR text direction. */
					margin-left: <?php echo $mobile_margins[0]; ?> !important;
					margin-right: <?php echo $mobile_margins[1]; ?> !important;
				}
				<?php if ( '' !== $full_width_edge ) : ?>
				/* "Full-width bar on mobile": flush with the screen edge, no
				   margin, and rounded only on the edge facing the page if at
				   all. After the sizing rule above, with the same selector and
				   !important, so it wins. */
				<?php echo $banner_sel; ?> {
					left: 0 !important;
					right: 0 !important;
					<?php echo $full_width_edge; ?>: 0 !important;
				}
				<?php echo $pos_sel; ?> {
					width: 100% !important;
					max-width: 100% !important;
					margin-left: 0 !important;
					margin-right: 0 !important;
					border-radius: <?php echo $full_width_radius; ?> !important;
				}
				<?php endif; ?>
				#dpt-cb-float-button {
					width: <?php echo $fb_size_m; ?>px !important;
					height: <?php echo $fb_size_m; ?>px !important;
					<?php echo $fb_pos_m; ?>
				}
			}
		</style>
		<?php
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The message, and the privacy policy link either on a line of its own
	 * (as it always was) or at the end of the message's last paragraph.
	 *
	 * @param string $message     The message as saved (wpautop'd here).
	 * @param string $policy_link Resolved policy URL, or '' for none.
	 * @param string $policy_text The link text.
	 * @param bool   $inline      Whether the link goes inside the message.
	 * @return string HTML, escaped.
	 */
	public static function message_html( $message, $policy_link, $policy_text, $inline ) {
		$message_html = wp_kses_post( wpautop( (string) $message ) );
		if ( '' === (string) $policy_link || '' === trim( (string) $policy_text ) ) {
			return '<div class="dpt-cb-message">' . $message_html . '</div>';
		}
		$a = '<a href="' . esc_url( $policy_link ) . '" target="_blank" rel="noopener">' . esc_html( $policy_text ) . '</a>';
		if ( ! $inline ) {
			return '<div class="dpt-cb-message">' . $message_html . '</div>' . "\n" . '<p class="dpt-cb-policy">' . $a . '</p>';
		}
		$span    = '<span class="dpt-cb-policy-inline">' . $a . '</span>';
		$trimmed = rtrim( $message_html );
		if ( '</p>' === substr( $trimmed, -4 ) ) {
			$message_html = substr( $trimmed, 0, -4 ) . ' ' . $span . '</p>';
		} else {
			// No closing paragraph to join (an empty message, or markup that
			// does not end in one): the link becomes the message's last paragraph.
			$message_html = $trimmed . '<p>' . $span . '</p>';
		}
		return '<div class="dpt-cb-message">' . $message_html . '</div>';
	}

	/**
	 * What the close button shows: the × character, which takes the banner's
	 * font (light in many Hebrew fonts), or a drawn ×, the same in every font.
	 * The icon is 1em square, so the size setting is its width and height, and
	 * it is painted in currentColor, so the colour setting applies to it.
	 *
	 * @param string $icon 'text' or 'svg'.
	 * @return string Fixed markup, nothing from input.
	 */
	public static function close_glyph( $icon ) {
		if ( 'svg' !== $icon ) {
			return '&times;';
		}
		return '<svg class="dpt-cb-close-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 4L20 20M20 4L4 20"></path></svg>';
	}

	/**
	 * The close button's opacity as a CSS number. Never below 0.1: a button
	 * that still closes the banner must not become invisible.
	 *
	 * @param mixed $value The saved setting.
	 * @return string
	 */
	public static function close_opacity( $value ) {
		$v = is_numeric( $value ) ? (float) $value : 0.7;
		$v = max( 0.1, min( 1.0, $v ) );
		return rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * The corners of the full-width mobile bar. The edge it is flush with is
	 * always square; the edge facing the page keeps the box's radius when
	 * asked to. Physical corners, as the bar's edge is.
	 *
	 * @param string $edge   'top' or 'bottom' - where the bar sits; '' for none.
	 * @param int    $radius The box's corner radius in px.
	 * @param bool   $keep   Whether the inner edge keeps it.
	 * @return string A border-radius value.
	 */
	public static function full_width_radius( $edge, $radius, $keep ) {
		$r = max( 0, (int) $radius );
		if ( ! $keep || 0 === $r || '' === $edge ) {
			return '0';
		}
		// top-left top-right bottom-right bottom-left.
		return 'bottom' === $edge ? "{$r}px {$r}px 0 0" : "0 0 {$r}px {$r}px";
	}

	/**
	 * Horizontal margins that keep a narrower-than-full mobile box on the side
	 * its position names.
	 *
	 * On mobile frontend.css stretches the banner container to
	 * left:10px/right:10px. The box is a block-level child, so at a width below
	 * 100% it would sit against the container's left edge - a bottom-right
	 * banner would render bottom-left and the bars would lose their centering.
	 *
	 * Physical (not logical) margins on purpose: the position names are
	 * physical, so the box must not flip sides with the RTL/LTR text direction.
	 *
	 * @param string $position Stored banner position.
	 * @return array{0:string,1:string} [margin-left, margin-right].
	 */
	public static function box_mobile_margins( $position ) {
		if ( 'bottom-left' === $position ) {
			return array( '0', 'auto' );   // hug the left edge
		}
		if ( 'bottom-right' === $position ) {
			return array( 'auto', '0' );   // hug the right edge
		}
		return array( 'auto', 'auto' );    // bars and the centered modal stay centered
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

	/**
	 * Inline SVG icon (stroke follows the text color). Self-contained -
	 * no icon font or Elementor dependency.
	 */
	private function icon( $name ) {
		$attrs = 'class="dpt-cb-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
		$icons = array(
			'lock'      => '<svg ' . $attrs . '><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
			'sliders'   => '<svg ' . $attrs . '><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>',
			'chart'     => '<svg ' . $attrs . '><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>',
			'megaphone' => '<svg ' . $attrs . '><path d="m3 11 18-5v12L3 14v-3z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path></svg>',
			'cookie'    => '<svg ' . $attrs . '><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"></path><path d="M8.5 8.5v.01"></path><path d="M16 15.5v.01"></path><path d="M12 12v.01"></path><path d="M11 17v.01"></path><path d="M7 14v.01"></path></svg>',
		);
		return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
	}

	/**
	 * Print one of the built-in icons. The markup comes from the fixed map in
	 * icon() and contains no variable input, so there is nothing to escape -
	 * escaping it would print the SVG source as visible text.
	 *
	 * @param string $name Icon key.
	 */
	private function print_icon( $name ) {
		echo $this->icon( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded SVG from the map in icon().
	}

	private function render_banner_markup( $o, $t, $lang, $dir ) {
		$position_class = 'dpt-cb-pos-' . $o['position'];
		$anim_class     = ' dpt-cb-anim-' . $o['animation'];
		$mobile_class   = $this->hide_mobile_class( $o );
		// Presence-only check on a read-only display flag, paired with a
		// capability check - no state changes, so no nonce is involved.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview flag, gated by manage_options.
		$preview_mode   = isset( $_GET['dpt_cb_preview'] ) && current_user_can( 'manage_options' );

		// CACHE-PROOF STRATEGY:
		// Always render the banner with `dpt-cb-hidden`. The JavaScript
		// removes it on load IF there is no valid consent, so cached HTML
		// never shows the banner to visitors who already answered.
		// Exception: preview mode for admins, which forces the banner open.
		$hidden_class = $preview_mode ? '' : ' dpt-cb-hidden';
		$banner_class = $position_class . $anim_class . $mobile_class . $hidden_class;

		?>
		<?php if ( '1' === $o['overlay_enabled'] ) : ?>
			<div id="dpt-cb-overlay" class="<?php echo esc_attr( trim( $hidden_class . $mobile_class ) ); ?>"></div>
		<?php endif; ?>

		<div id="dpt-cb-banner"
		     class="<?php echo esc_attr( $banner_class ); ?>"
		     dir="<?php echo esc_attr( $dir ); ?>"
		     lang="<?php echo esc_attr( str_replace( '_', '-', $lang ) ); ?>"
		     <?php if ( $preview_mode ) : ?>data-force-open="1"<?php endif; ?>
		     role="dialog" aria-labelledby="dpt-cb-title" aria-modal="true">
			<div class="dpt-cb-box">
				<?php if ( '1' === $o['show_close'] ) : ?>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup from close_glyph(). ?>
					<button type="button" class="dpt-cb-close" data-dpt-cb-action="reject" aria-label="<?php echo esc_attr( $t['close_aria'] ); ?>"><?php echo self::close_glyph( $o['close_icon'] ); ?></button>
				<?php endif; ?>

				<div class="dpt-cb-main-view">
					<?php if ( ! empty( $t['title'] ) ) : ?>
						<?php // Hidden from sight only when switched off: it still names the dialog (aria-labelledby). ?>
						<h2 id="dpt-cb-title" class="dpt-cb-title<?php echo '1' === $o['title_show'] ? '' : ' dpt-cb-sr-only'; ?>"><?php echo esc_html( $t['title'] ); ?></h2>
					<?php endif; ?>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from wp_kses_post(), esc_url() and esc_html() in message_html().
					echo self::message_html( $t['message'], $this->resolve_policy_link( $o ), $t['policy_text'], '1' === $o['policy_inline'] );
					?>

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
							<span class="dpt-cb-category-name"><?php $this->print_icon( 'lock' ); ?> <?php echo esc_html( $t['cat_essential_name'] ); ?></span>
							<span class="dpt-cb-always-on"><?php echo esc_html( $t['always_on_label'] ); ?></span>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_essential_desc'] ); ?></p>
					</div>

					<?php if ( '1' === $o['cat_functional_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name"><?php $this->print_icon( 'sliders' ); ?> <?php echo esc_html( $t['cat_functional_name'] ); ?></span>
							<label class="dpt-cb-toggle"><input type="checkbox" data-dpt-cb-cat="functional" /><span class="dpt-cb-toggle-slider"></span></label>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_functional_desc'] ); ?></p>
					</div>
					<?php endif; ?>

					<?php if ( '1' === $o['cat_analytics_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name"><?php $this->print_icon( 'chart' ); ?> <?php echo esc_html( $t['cat_analytics_name'] ); ?></span>
							<label class="dpt-cb-toggle"><input type="checkbox" data-dpt-cb-cat="analytics" /><span class="dpt-cb-toggle-slider"></span></label>
						</div>
						<p class="dpt-cb-category-desc"><?php echo wp_kses_post( $t['cat_analytics_desc'] ); ?></p>
					</div>
					<?php endif; ?>

					<?php if ( '1' === $o['cat_marketing_enabled'] ) : ?>
					<div class="dpt-cb-category">
						<div class="dpt-cb-category-head">
							<span class="dpt-cb-category-name"><?php $this->print_icon( 'megaphone' ); ?> <?php echo esc_html( $t['cat_marketing_name'] ); ?></span>
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
		// Empty text (or the legacy 🍪 seed) renders the built-in SVG cookie
		// icon; any other text/emoji the admin typed is shown as-is.
		$float_text = trim( (string) $t['float_button_text'] );
		?>
		<button type="button" id="dpt-cb-float-button" class="<?php echo esc_attr( $mobile_class ); ?>" aria-label="<?php echo esc_attr( $t['float_button_aria'] ); ?>" onclick="<?php echo esc_attr( $inline_open ); ?>"><?php
			if ( '' === $float_text || '🍪' === $float_text ) {
				$this->print_icon( 'cookie' );
			} else {
				echo esc_html( $float_text );
			}
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
