<?php
/**
 * Rank Math Breadcrumbs module - adds a "Blog" crumb on post contexts and a
 * "Shop" crumb on WooCommerce product pages, with auto-detected URLs/labels.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-rmb-settings.php';
require_once __DIR__ . '/class-dpt-rmb-admin.php';

class DPT_RankMath_Breadcrumbs_Module extends DPT_Module {

	/** @var DPT_RMB_Admin */
	private $admin;

	public function id() {
		return 'rankmath_breadcrumbs';
	}

	public function title() {
		return __( 'Rank Math Breadcrumbs', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Adds a Blog crumb on post pages and a Shop crumb on WooCommerce product pages to the Rank Math breadcrumb trail. URLs are detected automatically and can be overridden.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function install_defaults() {
		DPT_RMB_Settings::install_defaults();
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_RMB_Admin();
		}

		// Front-end behaviour needs Rank Math.
		if ( ! class_exists( 'RankMath' ) ) {
			return;
		}

		if ( DPT_RMB_Settings::is_on( 'blog_crumb' ) ) {
			add_filter( 'rank_math/frontend/breadcrumb/items', array( $this, 'add_blog_crumb' ), 10, 2 );
		}
		if ( DPT_RMB_Settings::is_on( 'shop_crumb' ) ) {
			add_filter( 'rank_math/frontend/breadcrumb/items', array( $this, 'add_shop_crumb' ), 10, 2 );
		}
	}

	/**
	 * Insert a "Blog" crumb (after Home) on post/blog-archive contexts.
	 *
	 * @param array $crumbs Rank Math crumb list.
	 * @param mixed $class  Breadcrumbs object (unused).
	 * @return array
	 */
	public function add_blog_crumb( $crumbs, $class = null ) {
		if ( is_front_page() || is_home() ) {
			return $crumbs; // the posts page itself needs no self-link.
		}
		if ( ! ( is_singular( 'post' ) || is_category() || is_tag() || is_author() || is_date() ) ) {
			return $crumbs;
		}
		return self::insert_crumb( $crumbs, $this->blog_label(), $this->blog_url() );
	}

	/**
	 * Insert a "Shop" crumb (after Home) on WooCommerce product pages.
	 *
	 * @param array $crumbs Rank Math crumb list.
	 * @param mixed $class  Breadcrumbs object (unused).
	 * @return array
	 */
	public function add_shop_crumb( $crumbs, $class = null ) {
		if ( ! is_singular( 'product' ) ) {
			return $crumbs;
		}
		return self::insert_crumb( $crumbs, $this->shop_label(), $this->shop_url() );
	}

	/**
	 * Insert a crumb after the Home entry, unless the label/URL is empty or the
	 * same URL is already present in the trail.
	 *
	 * @param array  $crumbs Existing crumbs.
	 * @param string $label  Crumb label.
	 * @param string $url    Crumb URL.
	 * @return array
	 */
	public static function insert_crumb( $crumbs, $label, $url ) {
		if ( ! is_array( $crumbs ) || '' === (string) $label || '' === (string) $url ) {
			return $crumbs;
		}
		// Avoid duplicating a crumb Rank Math (or the other filter) already added.
		foreach ( $crumbs as $crumb ) {
			if ( is_array( $crumb ) && isset( $crumb[1] ) && untrailingslashit( (string) $crumb[1] ) === untrailingslashit( $url ) ) {
				return $crumbs;
			}
		}
		// Insert after Home only when the first crumb actually is Home; if Rank
		// Math's Home crumb is disabled, $crumbs[0] is a real item and inserting
		// after it would make that item linkable (e.g. "Product > Shop").
		$position = self::first_is_home( $crumbs ) ? 1 : 0;
		array_splice( $crumbs, $position, 0, array( array( $label, $url ) ) );
		return $crumbs;
	}

	/**
	 * Whether the first crumb is the site Home entry (so a new crumb should go
	 * after it). Compared trailing-slash-insensitively against home_url('/').
	 *
	 * @param array $crumbs Crumb list.
	 * @return bool
	 */
	public static function first_is_home( $crumbs ) {
		if ( empty( $crumbs ) || ! is_array( $crumbs[0] ) || ! isset( $crumbs[0][1] ) ) {
			return false;
		}
		$first_url = untrailingslashit( (string) $crumbs[0][1] );

		// Candidate "home" URLs: the WordPress home, plus Rank Math's own
		// configured Home Link (which a site may point at a localized or other
		// canonical root, so a plain home_url() comparison would miss it).
		$candidates = array();
		if ( function_exists( 'home_url' ) ) {
			$candidates[] = untrailingslashit( (string) home_url( '/' ) );
		}
		$rm_home = self::rank_math_home_link();
		if ( '' !== $rm_home ) {
			$candidates[] = untrailingslashit( $rm_home );
		}
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && $first_url === $candidate ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rank Math's configured breadcrumb Home Link, or '' when unavailable.
	 *
	 * @return string
	 */
	public static function rank_math_home_link() {
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'get_settings' ) ) {
			$link = \RankMath\Helper::get_settings( 'general.breadcrumbs_home_link' );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		return '';
	}

	// --- Auto-detection ----------------------------------------------------

	public function blog_url() {
		$url = trim( (string) DPT_RMB_Settings::get( 'blog_url' ) );
		if ( '' !== $url ) {
			return $url;
		}
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$permalink = get_permalink( $posts_page );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return '';
	}

	public function blog_label() {
		$label = trim( (string) DPT_RMB_Settings::get( 'blog_label' ) );
		if ( '' !== $label ) {
			return $label;
		}
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$title = get_the_title( $posts_page );
			if ( '' !== trim( (string) $title ) ) {
				return $title;
			}
		}
		return __( 'Blog', 'digitizer-pro-tools' );
	}

	public function shop_url() {
		$url = trim( (string) DPT_RMB_Settings::get( 'shop_url' ) );
		if ( '' !== $url ) {
			return $url;
		}
		// wc_get_page_permalink('shop') falls back to the site home URL when the
		// shop page is unset/deleted, so confirm a real shop page exists first -
		// otherwise the Shop crumb would just link to Home.
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_get_page_permalink' )
			&& (int) wc_get_page_id( 'shop' ) > 0 ) {
			$permalink = wc_get_page_permalink( 'shop' );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return '';
	}

	public function shop_label() {
		$label = trim( (string) DPT_RMB_Settings::get( 'shop_label' ) );
		if ( '' !== $label ) {
			return $label;
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$title = get_the_title( $shop_id );
				if ( '' !== trim( (string) $title ) ) {
					return $title;
				}
			}
		}
		return __( 'Shop', 'digitizer-pro-tools' );
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
