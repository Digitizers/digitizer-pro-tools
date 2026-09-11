<?php
/**
 * Decides "closed now" for the current request and applies it: the 503
 * page in closed mode, the banner and contact CSS in business mode, a
 * cache lifetime bounded by the next transition, and a cron purge at
 * that transition. Truth is always the computation, never the cron.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Enforce {

	const CRON_HOOK = 'dpt_sk_transition';

	/** @var bool|null */
	private static $closed = null;
	/** @var DPT_SK_Zmanim|null */
	private static $zmanim = null;
	/** @var bool */
	private static $banner_printed = false;

	public static function register() {
		// Priority 0: Content Control's whole-site protection exits at priority 1
		// (redirect or 403) and would otherwise answer first; the Shabbat 503 is
		// the earlier decision.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'print_head_css' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'print_banner' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_banner_fallback' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_transition_script' ), 99 );
		add_action( 'wp', array( __CLASS__, 'ensure_transition_event' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_transition' ) );
	}

	/** Forget the per-request memo (after a settings save, in tests). */
	public static function reset() {
		self::$closed         = null;
		self::$zmanim         = null;
		self::$banner_printed = false;
	}

	public static function zmanim() {
		if ( null === self::$zmanim ) {
			$loc          = DPT_SK_Settings::location();
			self::$zmanim = new DPT_SK_Zmanim( $loc['lat'], $loc['lon'], $loc['candle'], (int) DPT_SK_Settings::get( 'havdalah' ) );
		}
		return self::$zmanim;
	}

	/** The clock, filterable so tests can pin it. */
	public static function now() {
		return (int) apply_filters( 'dpt_shabbat_keeper_now', time() );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview flag; it changes nothing and only an administrator can use it.
	public static function is_preview() {
		return isset( $_GET['dpt_shabbat'] ) && 'preview' === $_GET['dpt_shabbat'] && current_user_can( 'manage_options' );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public static function is_closed() {
		if ( null !== self::$closed ) {
			return self::$closed;
		}
		$override = DPT_SK_Settings::get( 'override' );
		if ( 'force_open' === $override ) {
			self::$closed = false;
		} elseif ( 'force_closed' === $override || self::is_preview() ) {
			self::$closed = true;
		} else {
			self::$closed = self::zmanim()->is_closed_at( self::now() );
		}
		return self::$closed;
	}

	public static function is_exempt() {
		if ( self::is_preview() ) {
			return false;
		}
		return (bool) apply_filters( 'dpt_shabbat_keeper_exempt', current_user_can( 'manage_options' ) );
	}

	/** Closed, and this user is not exempt. Request type is not considered here. */
	public static function applies() {
		return self::is_closed() && ! self::is_exempt();
	}

	/** Closed, not exempt, and a context where refusing a purchase or a form makes sense: not cron, not WP-CLI, not a wp-admin screen (admin-ajax still counts, WooCommerce's front-end add-to-cart can travel through it). */
	public static function refusals_apply() {
		if ( ! self::applies() ) {
			return false;
		}
		$context_ok = ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( is_admin() && ! wp_doing_ajax() );
		return (bool) apply_filters( 'dpt_shabbat_keeper_refuse', $context_ok );
	}

	/** A visitor-facing page request: not admin, ajax, cron, REST, CLI, XML-RPC or login. */
	public static function is_front_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}
		return true;
	}

	/* ---------------- closed mode ---------------- */

	public static function should_block() {
		return self::is_front_request() && 'closed' === DPT_SK_Settings::get( 'mode' ) && self::applies();
	}

	public static function maybe_block() {
		if ( ! self::should_block() ) {
			return;
		}
		status_header( 503 );
		nocache_headers();
		foreach ( self::closed_headers() as $header ) {
			header( $header );
		}
		echo self::render_closed_screen(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output, escaped in the view.
		exit;
	}

	/** Extra headers for the 503: Retry-After until Havdalah, or an hour under a manual override. */
	public static function closed_headers() {
		$end   = self::reopens_at();
		$delay = null === $end ? HOUR_IN_SECONDS : max( 60, $end - self::now() );
		return array( 'Retry-After: ' . $delay );
	}

	public static function render_closed_screen() {
		$title   = DPT_SK_Settings::text( 'closed_title' );
		$message = DPT_SK_Settings::text( 'closed_message' );
		$reopens = '1' === DPT_SK_Settings::get( 'closed_show_times' ) ? self::reopens_sentence() : '';
		$loc     = DPT_SK_Settings::location();
		$city    = $loc['name'];
		$css     = self::css();
		$icon    = get_site_icon_url( 128 );
		$site    = get_bloginfo( 'name' );
		$script  = self::transition_script();
		ob_start();
		include __DIR__ . '/views/closed-screen.php';
		return (string) ob_get_clean();
	}

	public static function css() {
		$css = file_get_contents( __DIR__ . '/assets/closed.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin.
		return is_string( $css ) ? $css : '';
	}

	/* ---------------- times for people ---------------- */

	/** End of the current real window, or null: open, preview outside a window, or force-closed (the calendar does not decide when that ends). */
	public static function reopens_at() {
		if ( 'force_closed' === DPT_SK_Settings::get( 'override' ) ) {
			return null; // The calendar's end is not this closure's end.
		}
		$w = self::zmanim()->current_window( self::now() );
		return $w ? (int) $w['end'] : null;
	}

	/**
	 * "on Saturday 19:32"; "after Havdalah" for a preview outside a window;
	 * "when the closure is lifted" under force-closed, whose end the calendar
	 * does not know. Each carries its own preposition so it reads after
	 * "reopens" in every language.
	 */
	public static function reopens_text() {
		if ( 'force_closed' === DPT_SK_Settings::get( 'override' ) ) {
			return __( 'when the closure is lifted', 'digitizer-pro-tools' );
		}
		$end = self::reopens_at();
		if ( null === $end ) {
			return __( 'after Havdalah', 'digitizer-pro-tools' );
		}
		/* translators: PHP date format for the reopening moment: weekday name and time. */
		$when = wp_date( __( 'l H:i', 'digitizer-pro-tools' ), $end, new DateTimeZone( DPT_SK_Zmanim::TIMEZONE ) );
		/* translators: %s: weekday name and time, e.g. "Saturday 19:32". */
		return sprintf( __( 'on %s', 'digitizer-pro-tools' ), $when );
	}

	private static function reopens_sentence() {
		/* translators: %s: "on Saturday 19:32", "after Havdalah" or "when the closure is lifted". */
		return sprintf( __( 'The site reopens %s.', 'digitizer-pro-tools' ), self::reopens_text() );
	}

	/* ---------------- business mode ---------------- */

	public static function banner_html() {
		if ( 'business' !== DPT_SK_Settings::get( 'mode' ) || '1' !== DPT_SK_Settings::get( 'banner_on' ) || ! self::applies() ) {
			return '';
		}
		$text = str_replace( '%s', self::reopens_text(), DPT_SK_Settings::text( 'banner_text' ) );
		return '<div class="dpt-sk-banner" role="status">' . esc_html( $text ) . '</div>';
	}

	public static function print_banner() {
		if ( self::$banner_printed || ! self::is_front_request() ) {
			return;
		}
		$html = self::banner_html();
		if ( '' === $html ) {
			return;
		}
		self::$banner_printed = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in banner_html().
	}

	/** Themes that never fire wp_body_open get the banner from the footer, moved to the top. */
	public static function print_banner_fallback() {
		if ( self::$banner_printed || ! self::is_front_request() ) {
			return;
		}
		$html = self::banner_html();
		if ( '' === $html ) {
			return;
		}
		self::$banner_printed = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in banner_html().
		echo '<script>(function(){var b=document.querySelector(".dpt-sk-banner");if(b&&document.body.firstChild!==b){document.body.insertBefore(b,document.body.firstChild);}})();</script>';
	}

	public static function print_head_css() {
		if ( ! self::is_front_request() || 'business' !== DPT_SK_Settings::get( 'mode' ) || ! self::applies() ) {
			return;
		}
		$css = self::css();
		if ( '1' === DPT_SK_Settings::get( 'hide_contact' ) ) {
			$css .= 'a[href^="tel:"],a[href*="wa.me"],a[href*="api.whatsapp.com"]{display:none !important}';
		}
		if ( '' !== $css ) {
			echo '<style id="dpt-sk-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS shipped with the plugin.
		}
	}

	/* ---------------- caches ---------------- */

	/**
	 * The browser's copy of the mechanism: a full-page cache or CDN that serves
	 * every anonymous hit never lets WordPress see a request, so request-driven
	 * WP-Cron may never fire the purge. This inline script knows the next
	 * transition and, when the page is still open past it (or was served stale
	 * after it), reloads with a cache-busting query so the origin answers with
	 * the current state. The timer is always set - a page left open from Sunday
	 * to Friday must still turn - capped at 2e9 ms (23 days) to stay inside
	 * setTimeout's 32-bit range; the next transition is never more than a
	 * week away. Not printed under a manual override, which has no
	 * transition, and idle once the reloaded page carries the matching query.
	 */
	public static function print_transition_script() {
		echo self::transition_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script with one integer.
	}

	/** The script above as a string, or '' when it does not apply; the closed screen embeds it too, since that response exits before wp_footer. */
	public static function transition_script() {
		if ( ! self::is_front_request() || 'auto' !== DPT_SK_Settings::get( 'override' ) ) {
			return '';
		}
		$t = (int) self::zmanim()->next_transition( self::now() );
		return '<script id="dpt-sk-transition">(function(){var t=' . $t . ',n=Math.floor(Date.now()/1000);function q(){try{return new URL(location.href).searchParams.get("dpt_sk");}catch(e){return null;}}function go(){var u;try{u=new URL(location.href);}catch(e){return;}u.searchParams.set("dpt_sk",String(t));location.replace(u.toString());}if(n>=t){if(q()!==String(t)){go();}return;}setTimeout(go,Math.min((t-n+2)*1000,2e9));})();</script>';
	}

	public static function ensure_transition_event() {
		if ( ! self::is_front_request() ) {
			return;
		}
		self::schedule_next_transition();
	}

	private static function schedule_next_transition() {
		if ( 'auto' !== DPT_SK_Settings::get( 'override' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( self::zmanim()->next_transition( self::now() ) + 30, self::CRON_HOOK );
	}

	/**
	 * After a settings save: forget the memo, drop the pending transition event
	 * (the location may have moved it) and purge page caches, because a mode or
	 * override change must reach visitors now, not when the cached copy expires.
	 */
	public static function on_settings_saved() {
		self::reset();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::purge_caches();
	}

	public static function on_transition() {
		self::reset();
		do_action( 'dpt_shabbat_keeper_transition', self::zmanim()->is_closed_at( self::now() ) );
		self::purge_caches();
		self::schedule_next_transition();
	}

	/**
	 * Best effort, every known page cache, then one action for the rest: a CDN
	 * or an unknown cache hooks dpt_shabbat_keeper_purge and is told on every
	 * path that changes what visitors should see - the transition and a
	 * settings save alike. The object cache is left alone: it is not a page
	 * cache, and flushing it would discard unrelated data for no benefit.
	 */
	public static function purge_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true ); // WP Fastest Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); // SiteGround Optimizer.
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.
		do_action( 'breeze_clear_all_cache' ); // Breeze (Cloudways).
		do_action( 'dpt_shabbat_keeper_purge' );
	}
}
