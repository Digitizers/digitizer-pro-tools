<?php
/**
 * Cookie Banner module - settings storage, defaults and multilingual texts.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CB_Settings {

	const OPTION = 'dpt_cookie_banner';

	/**
	 * Shared (language-independent) defaults. Texts live in the nested
	 * 'texts' array, keyed by language code.
	 */
	public static function defaults() {
		return array(
			// General.
			'enabled'            => '1',
			'position'           => 'bottom',       // bottom | top | center | bottom-left | bottom-right
			'animation'          => 'slide-up',     // fade | slide-up | slide-down | zoom
			'show_on_mobile'     => '1',
			'auto_accept_scroll' => '0',
			'block_scripts'      => '1',

			// Multilingual.
			'default_lang'       => 'he',
			'languages'          => array( 'he', 'en' ),
			'texts'              => array(
				'he' => self::default_texts( 'he' ),
				'en' => self::default_texts( 'en' ),
			),

			// Policy link (shared; WPML/Polylang translate the page per language).
			'policy_url'         => '',
			'policy_page_id'     => '0',

			// Design - box.
			'bg_color'           => '#ffffff',
			'text_color'         => '#333333',
			'width'              => '900',
			'max_width_pct'      => '95',
			'border_radius'      => '12',
			'padding'            => '24',
			'box_shadow'         => '1',

			// Design - overlay (dim background when banner open).
			'overlay_enabled'    => '0',
			'overlay_color'      => '#000000',
			'overlay_opacity'    => '0.5',

			// Background image.
			'bg_image_url'       => '',
			'bg_image_id'        => '',
			'bg_image_size'      => 'cover',
			'bg_image_position'  => 'center center',
			'bg_image_repeat'    => 'no-repeat',
			'bg_image_overlay_color'   => '#000000',
			'bg_image_overlay_opacity' => '0',

			// Border.
			'border_width'       => '0',
			'border_color'       => '#000000',
			'border_style'       => 'solid',

			// Typography - Title (logical alignment: start | center | end | justify).
			'title_color'         => '',
			'title_font_size'     => '20',
			'title_align'         => 'start',
			'title_weight'        => '700',
			'title_margin_bottom' => '10',
			'title_shadow_enabled' => '0',
			'title_shadow_color'   => '#000000',
			'title_shadow_blur'    => '4',
			'title_shadow_y'       => '2',

			// Typography - Content.
			'content_color'      => '',
			'content_font_size'  => '14',
			'content_align'      => 'start',
			'content_shadow_enabled' => '0',
			'content_shadow_color'   => '#000000',
			'content_shadow_blur'    => '3',
			'content_shadow_y'       => '1',

			// Close button (X).
			'show_close'         => '1',
			'close_size'         => '22',
			'close_color'        => '',
			'close_bg_color'     => '',

			// Button "Accept".
			'btn_accept_bg'       => '#16a34a',
			'btn_accept_color'    => '#ffffff',
			'btn_accept_radius'   => '6',
			'btn_accept_hover_bg' => '#15803d',

			// Button "Reject".
			'btn_reject_bg'       => '#e5e7eb',
			'btn_reject_color'    => '#111827',
			'btn_reject_radius'   => '6',
			'btn_reject_hover_bg' => '#d1d5db',
			'btn_reject_show'     => '1',

			// Button "Settings".
			'btn_settings_bg'     => '#ffffff',
			'btn_settings_color'  => '#2563eb',
			'btn_settings_radius' => '6',
			'btn_settings_show'   => '1',
			'btn_settings_border' => '1',

			// Button "Save preferences".
			'btn_save_bg'         => '#2563eb',
			'btn_save_color'      => '#ffffff',
			'btn_save_radius'     => '6',

			// Floating "Manage cookies" button.
			'float_button_enabled'  => '1',
			'float_button_position' => 'bottom-right',
			'float_button_bg'       => '#2563eb',
			'float_button_color'    => '#ffffff',
			'float_offset_x'        => '20',
			'float_offset_y'        => '20',
			'float_offset_x_mobile' => '15',
			'float_offset_y_mobile' => '15',
			'float_button_size'        => '50',
			'float_button_size_mobile' => '44',

			// Categories (enable flags are shared; names/descriptions are per language).
			'cat_functional_enabled' => '1',
			'cat_analytics_enabled'  => '1',
			'cat_marketing_enabled'  => '1',

			// Scripts per category (placed in <head> only after consent).
			'scripts_functional' => '',
			'scripts_analytics'  => '',
			'scripts_marketing'  => '',

			// Consent behavior.
			'consent_days'       => '180',
			'consent_version'    => '1',
			'show_delay'         => '0',
		);
	}

	/**
	 * The translatable text keys stored per language.
	 */
	public static function text_keys() {
		return array(
			'title', 'message',
			'btn_accept_text', 'btn_reject_text', 'btn_settings_text', 'btn_save_text',
			'policy_text',
			'settings_view_title', 'always_on_label',
			'float_button_text', 'float_button_aria', 'close_aria',
			'cat_essential_name', 'cat_essential_desc',
			'cat_functional_name', 'cat_functional_desc',
			'cat_analytics_name', 'cat_analytics_desc',
			'cat_marketing_name', 'cat_marketing_desc',
		);
	}

	/**
	 * Text keys that may contain limited HTML (sanitized with wp_kses_post).
	 */
	public static function html_text_keys() {
		return array( 'message', 'cat_essential_desc', 'cat_functional_desc', 'cat_analytics_desc', 'cat_marketing_desc' );
	}

	/**
	 * Seed texts for a language. Hebrew and English ship complete;
	 * any other language starts from the English seed.
	 */
	public static function default_texts( $lang ) {
		if ( 'he' === $lang ) {
			return array(
				'title'               => 'אנחנו משתמשים בעוגיות 🍪',
				'message'             => 'האתר שלנו משתמש בעוגיות (Cookies) כדי לשפר את חוויית הגלישה שלך, להתאים את התוכן אישית ולנתח את התנועה באתר. על ידי לחיצה על "קבל הכל" אתה מסכים לשימוש בעוגיות. ניתן לנהל את ההעדפות שלך בכל עת.',
				'btn_accept_text'     => 'קבל הכל',
				'btn_reject_text'     => 'דחה הכל',
				'btn_settings_text'   => 'הגדרות מתקדמות',
				'btn_save_text'       => 'שמור העדפות',
				'policy_text'         => 'מדיניות פרטיות',
				'settings_view_title' => 'ניהול העדפות עוגיות',
				'always_on_label'     => 'תמיד פעילות',
				'float_button_text'   => '🍪',
				'float_button_aria'   => 'ניהול עוגיות',
				'close_aria'          => 'סגור',
				'cat_essential_name'  => 'עוגיות חיוניות',
				'cat_essential_desc'  => 'עוגיות חיוניות הן הכרחיות לתפקוד בסיסי של האתר ולא ניתן לבטלן.',
				'cat_functional_name' => 'פונקציונליות',
				'cat_functional_desc' => 'עוגיות פונקציונליות מאפשרות שמירת העדפות כמו שפה ואזור.',
				'cat_analytics_name'  => 'ביצועים / אנליטיקה',
				'cat_analytics_desc'  => 'עוגיות ביצועים עוזרות להבין איך המבקרים משתמשים באתר ומאפשרות לנו לשפר אותו.',
				'cat_marketing_name'  => 'שיווק',
				'cat_marketing_desc'  => 'עוגיות שיווק משמשות להצגת פרסומות רלוונטיות על פי ההתנהגות שלך באתר.',
			);
		}
		// English seed (also the base for any new language).
		return array(
			'title'               => 'We use cookies 🍪',
			'message'             => 'Our website uses cookies to improve your browsing experience, personalize content and analyze our traffic. By clicking "Accept all" you consent to the use of cookies. You can manage your preferences at any time.',
			'btn_accept_text'     => 'Accept all',
			'btn_reject_text'     => 'Reject all',
			'btn_settings_text'   => 'Advanced settings',
			'btn_save_text'       => 'Save preferences',
			'policy_text'         => 'Privacy policy',
			'settings_view_title' => 'Manage cookie preferences',
			'always_on_label'     => 'Always active',
			'float_button_text'   => '🍪',
			'float_button_aria'   => 'Manage cookies',
			'close_aria'          => 'Close',
			'cat_essential_name'  => 'Essential cookies',
			'cat_essential_desc'  => 'Essential cookies are required for the basic functionality of the website and cannot be disabled.',
			'cat_functional_name' => 'Functional',
			'cat_functional_desc' => 'Functional cookies enable saving preferences such as language and region.',
			'cat_analytics_name'  => 'Performance / Analytics',
			'cat_analytics_desc'  => 'Performance cookies help us understand how visitors use the website and allow us to improve it.',
			'cat_marketing_name'  => 'Marketing',
			'cat_marketing_desc'  => 'Marketing cookies are used to show you relevant ads based on your activity on the website.',
		);
	}

	/**
	 * Normalize a language code: `he`, `en`, `pt_BR`. Returns '' when invalid.
	 */
	public static function normalize_lang_code( $code ) {
		$code = str_replace( '-', '_', trim( (string) $code ) );
		if ( ! preg_match( '/^([a-zA-Z]{2,3})(?:_([a-zA-Z]{2}))?$/', $code, $m ) ) {
			return '';
		}
		$normalized = strtolower( $m[1] );
		if ( ! empty( $m[2] ) ) {
			$normalized .= '_' . strtoupper( $m[2] );
		}
		return $normalized;
	}

	/**
	 * Ensure defaults exist in DB on activation / upgrade.
	 */
	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		$defaults = self::defaults();

		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, $defaults );
			return;
		}

		// Merge defaults for new keys, keep user's saved values.
		$merged = array_merge( $defaults, $existing );

		// Deep-merge texts: keep saved languages, fill in missing keys per language.
		$languages = ( isset( $existing['languages'] ) && is_array( $existing['languages'] ) ) ? $existing['languages'] : $defaults['languages'];
		$texts     = ( isset( $existing['texts'] ) && is_array( $existing['texts'] ) ) ? $existing['texts'] : array();
		$merged['languages'] = array_values( array_unique( $languages ) );
		$merged['texts']     = array();
		foreach ( $merged['languages'] as $lang ) {
			$saved = ( isset( $texts[ $lang ] ) && is_array( $texts[ $lang ] ) ) ? $texts[ $lang ] : array();
			$merged['texts'][ $lang ] = array_merge( self::default_texts( $lang ), $saved );
		}

		update_option( self::OPTION, $merged );
	}

	/**
	 * Get all settings (merged with defaults; texts deep-merged per language).
	 */
	public static function all() {
		$opts     = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$merged = array_merge( $defaults, $opts );

		if ( ! is_array( $merged['languages'] ) || empty( $merged['languages'] ) ) {
			$merged['languages'] = $defaults['languages'];
		}
		if ( ! in_array( $merged['default_lang'], $merged['languages'], true ) ) {
			$merged['default_lang'] = $merged['languages'][0];
		}

		$saved_texts     = ( isset( $opts['texts'] ) && is_array( $opts['texts'] ) ) ? $opts['texts'] : array();
		$merged['texts'] = array();
		foreach ( $merged['languages'] as $lang ) {
			$saved = ( isset( $saved_texts[ $lang ] ) && is_array( $saved_texts[ $lang ] ) ) ? $saved_texts[ $lang ] : array();
			$merged['texts'][ $lang ] = array_merge( self::default_texts( $lang ), $saved );
		}
		return $merged;
	}

	/**
	 * Get one shared setting.
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Resolved texts for a language with the fallback chain:
	 * en seed <- default-language seed <- saved default-language <- saved $lang.
	 * Empty saved values fall through to the previous layer.
	 */
	public static function get_texts( $lang ) {
		$all     = self::all();
		$default = $all['default_lang'];

		$layers = array(
			self::default_texts( 'en' ),
			self::default_texts( $default ),
			isset( $all['texts'][ $default ] ) ? $all['texts'][ $default ] : array(),
		);
		if ( $lang !== $default ) {
			$layers[] = self::default_texts( $lang );
			$layers[] = isset( $all['texts'][ $lang ] ) ? $all['texts'][ $lang ] : array();
		}

		$texts = array();
		foreach ( self::text_keys() as $key ) {
			$texts[ $key ] = '';
			foreach ( $layers as $layer ) {
				if ( isset( $layer[ $key ] ) && '' !== trim( (string) $layer[ $key ] ) ) {
					$texts[ $key ] = $layer[ $key ];
				}
			}
		}
		return $texts;
	}

	/**
	 * Add a language (seeded from defaults). Returns normalized code or false.
	 */
	public static function add_language( $code ) {
		$code = self::normalize_lang_code( $code );
		if ( '' === $code ) {
			return false;
		}
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = self::defaults();
		}
		$all = self::all();
		if ( in_array( $code, $all['languages'], true ) ) {
			return $code; // Already exists.
		}
		$opts['languages']       = array_merge( $all['languages'], array( $code ) );
		$opts['texts']           = isset( $opts['texts'] ) && is_array( $opts['texts'] ) ? $opts['texts'] : array();
		$opts['texts'][ $code ]  = self::default_texts( $code );
		update_option( self::OPTION, $opts );
		return $code;
	}

	/**
	 * Remove a language. The default language cannot be removed.
	 */
	public static function remove_language( $code ) {
		$all = self::all();
		if ( $code === $all['default_lang'] || ! in_array( $code, $all['languages'], true ) ) {
			return false;
		}
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			return false;
		}
		$opts['languages'] = array_values( array_diff( $all['languages'], array( $code ) ) );
		if ( isset( $opts['texts'][ $code ] ) ) {
			unset( $opts['texts'][ $code ] );
		}
		update_option( self::OPTION, $opts );
		return true;
	}

	/**
	 * Save settings with per-type sanitization.
	 *
	 * Only fields present in $raw are updated - critical for tabbed forms
	 * where each tab submits a subset. Checkboxes use the hidden-input
	 * trick so unchecked boxes on the current tab still submit '0'.
	 * Texts are nested as texts[<lang>][<key>]; only submitted languages
	 * are touched.
	 */
	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$defaults = self::defaults();
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$clean = array_merge( $defaults, $existing );
		if ( isset( $existing['texts'] ) && is_array( $existing['texts'] ) ) {
			$clean['texts'] = $existing['texts'];
		}
		if ( isset( $existing['languages'] ) && is_array( $existing['languages'] ) ) {
			$clean['languages'] = $existing['languages'];
		}

		// Script fields (allow full script tags for trusted admins).
		$script_fields = array( 'scripts_functional', 'scripts_analytics', 'scripts_marketing' );

		// URL fields.
		$url_fields = array( 'policy_url', 'bg_image_url' );

		// Hex color fields that can be empty.
		$optional_colors = array( 'title_color', 'content_color', 'close_color', 'close_bg_color' );

		// Hex color fields required.
		$color_fields = array(
			'bg_color', 'text_color', 'overlay_color', 'bg_image_overlay_color', 'border_color',
			'title_shadow_color', 'content_shadow_color',
			'btn_accept_bg', 'btn_accept_color', 'btn_accept_hover_bg',
			'btn_reject_bg', 'btn_reject_color', 'btn_reject_hover_bg',
			'btn_settings_bg', 'btn_settings_color',
			'btn_save_bg', 'btn_save_color',
			'float_button_bg', 'float_button_color',
		);

		// Checkbox fields.
		$checkbox_fields = array(
			'enabled', 'show_on_mobile', 'auto_accept_scroll', 'block_scripts',
			'box_shadow', 'overlay_enabled',
			'title_shadow_enabled', 'content_shadow_enabled',
			'show_close',
			'btn_reject_show', 'btn_settings_show', 'btn_settings_border',
			'float_button_enabled',
			'cat_functional_enabled', 'cat_analytics_enabled', 'cat_marketing_enabled',
		);

		foreach ( $raw as $key => $raw_value ) {
			if ( 'texts' === $key ) {
				$clean['texts'] = self::sanitize_texts( $raw_value, $clean );
				continue;
			}
			if ( 'default_lang' === $key ) {
				$code = self::normalize_lang_code( is_array( $raw_value ) ? '' : wp_unslash( $raw_value ) );
				if ( $code && in_array( $code, $clean['languages'], true ) ) {
					$clean['default_lang'] = $code;
				}
				continue;
			}
			if ( 'languages' === $key ) {
				continue; // Managed only via add_language()/remove_language().
			}
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue; // Unknown field - ignore.
			}
			$value = is_array( $raw_value ) ? '' : wp_unslash( $raw_value );

			if ( in_array( $key, $script_fields, true ) ) {
				if ( current_user_can( 'unfiltered_html' ) ) {
					$clean[ $key ] = $value;
				} else {
					$clean[ $key ] = wp_kses_post( $value );
				}
			} elseif ( in_array( $key, $url_fields, true ) ) {
				$clean[ $key ] = esc_url_raw( $value );
			} elseif ( in_array( $key, $optional_colors, true ) ) {
				$clean[ $key ] = '' === $value ? '' : ( sanitize_hex_color( $value ) ?: '' );
			} elseif ( in_array( $key, $color_fields, true ) ) {
				$clean[ $key ] = sanitize_hex_color( $value ) ?: $defaults[ $key ];
			} elseif ( in_array( $key, $checkbox_fields, true ) ) {
				$clean[ $key ] = '1' === $value ? '1' : '0';
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		update_option( self::OPTION, $clean );
		return true;
	}

	/**
	 * Sanitize the submitted texts[<lang>][<key>] structure, merging into
	 * the currently saved texts. Only submitted languages/keys change.
	 */
	private static function sanitize_texts( $raw_texts, $clean ) {
		$texts = ( isset( $clean['texts'] ) && is_array( $clean['texts'] ) ) ? $clean['texts'] : array();
		if ( ! is_array( $raw_texts ) ) {
			return $texts;
		}
		$known_langs = isset( $clean['languages'] ) && is_array( $clean['languages'] ) ? $clean['languages'] : array();
		$text_keys   = self::text_keys();
		$html_keys   = self::html_text_keys();

		foreach ( $raw_texts as $lang => $fields ) {
			$lang = self::normalize_lang_code( $lang );
			if ( '' === $lang || ! in_array( $lang, $known_langs, true ) || ! is_array( $fields ) ) {
				continue;
			}
			if ( ! isset( $texts[ $lang ] ) || ! is_array( $texts[ $lang ] ) ) {
				$texts[ $lang ] = array();
			}
			foreach ( $fields as $key => $value ) {
				if ( ! in_array( $key, $text_keys, true ) || is_array( $value ) ) {
					continue;
				}
				$value = wp_unslash( $value );
				if ( in_array( $key, $html_keys, true ) ) {
					$texts[ $lang ][ $key ] = wp_kses_post( $value );
				} else {
					$texts[ $lang ][ $key ] = sanitize_text_field( $value );
				}
			}
		}
		return $texts;
	}
}
