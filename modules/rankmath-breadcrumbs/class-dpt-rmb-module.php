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
		$position = ( count( $crumbs ) >= 1 ) ? 1 : 0;
		array_splice( $crumbs, $position, 0, array( array( $label, $url ) ) );
		return $crumbs;
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
		if ( function_exists( 'wc_get_page_permalink' ) ) {
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
