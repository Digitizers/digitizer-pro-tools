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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_head_css' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'print_banner' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_banner_fallback' ) );
		add_action( 'send_headers', array( __CLASS__, 'send_cache_header' ) );
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
		ob_start();
		include __DIR__ . '/views/closed-screen.php';
		return (string) ob_get_clean();
	}

	public static function css() {
		$css = file_get_contents( __DIR__ . '/assets/closed.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin.
		return is_string( $css ) ? $css : '';
	}

	/* ---------------- times for people ---------------- */

	/** End of the current real window, or null (open, or closed only by override/preview). */
	public static function reopens_at() {
		$w = self::zmanim()->current_window( self::now() );
		return $w ? (int) $w['end'] : null;
	}

	/** "Saturday 19:32", or "after Havdalah" when there is no real window. */
	public static function reopens_text() {
		$end = self::reopens_at();
		if ( null === $end ) {
			return __( 'after Havdalah', 'digitizer-pro-tools' );
		}
		/* translators: PHP date format for the reopening moment: weekday name and time. */
		return wp_date( __( 'l H:i', 'digitizer-pro-tools' ), $end, new DateTimeZone( DPT_SK_Zmanim::TIMEZONE ) );
	}

	private static function reopens_sentence() {
		/* translators: %s: the day and time the site reopens, or "after Havdalah". */
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
		$css = '';
		if ( '1' === DPT_SK_Settings::get( 'banner_on' ) ) {
			$css .= self::css();
		}
		if ( '1' === DPT_SK_Settings::get( 'hide_contact' ) ) {
			$css .= 'a[href^="tel:"],a[href*="wa.me"],a[href*="api.whatsapp.com"]{display:none !important}';
		}
		if ( '' !== $css ) {
			echo '<style id="dpt-sk-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS shipped with the plugin.
		}
	}

	/* ---------------- caches ---------------- */

	/** Seconds until the next transition, or null when nothing should be bounded. */
	public static function cache_max_age() {
		if ( 'auto' !== DPT_SK_Settings::get( 'override' ) ) {
			return null;
		}
		$now = self::now();
		return max( 60, min( HOUR_IN_SECONDS, self::zmanim()->next_transition( $now ) - $now ) );
	}

	/** The Cache-Control header sent, or null when nothing was sent. */
	public static function send_cache_header() {
		if ( ! self::is_front_request() || is_user_logged_in() ) {
			return null;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return null;
		}
		$header = self::cache_header_for( headers_list() );
		if ( null !== $header ) {
			header( $header );
		}
		return $header;
	}

	/**
	 * The Cache-Control header we would send, or null when none should be
	 * sent: no bound applies, or something already sent asks for less than
	 * our bound (no-store, no-cache, private, or a shorter max-age).
	 *
	 * @param string[] $sent_headers Headers already queued for the response.
	 * @return string|null
	 */
	public static function cache_header_for( array $sent_headers ) {
		$max = self::cache_max_age();
		if ( null === $max ) {
			return null;
		}
		foreach ( $sent_headers as $sent ) {
			if ( ! preg_match( '/^cache-control:(.*)/i', $sent, $cc ) ) {
				continue;
			}
			if ( preg_match( '/no-store|no-cache|private/i', $cc[1] ) ) {
				return null; // Something already asked for less than any positive max-age.
			}
			if ( preg_match( '/max-age=(\d+)/i', $cc[1], $m ) && (int) $m[1] <= $max ) {
				return null; // Something already asked for a shorter life.
			}
		}
		return 'Cache-Control: public, max-age=' . (int) $max;
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

	public static function on_transition() {
		self::reset();
		do_action( 'dpt_shabbat_keeper_transition', self::zmanim()->is_closed_at( self::now() ) );
		self::purge_caches();
		self::schedule_next_transition();
	}

	/** Best effort, every known page cache; unknown ones can hook the transition action. */
	public static function purge_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
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
	}
}
