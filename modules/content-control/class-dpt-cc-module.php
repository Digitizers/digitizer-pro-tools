<?php
/**
 * Content Control module - restrict content by role, hide menu items, gate
 * blocks/shortcodes and protect the whole site behind login. Replaces the
 * standalone "Content Control" plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-cc-access.php';
require_once __DIR__ . '/class-dpt-cc-settings.php';
require_once __DIR__ . '/class-dpt-cc-metabox.php';
require_once __DIR__ . '/class-dpt-cc-menu.php';
require_once __DIR__ . '/class-dpt-cc-admin.php';

class DPT_Content_Control_Module extends DPT_Module {

	/** @var DPT_CC_Admin */
	private $admin;

	/** @var DPT_CC_Metabox */
	private $metabox;

	/** @var DPT_CC_Menu */
	private $menu;

	public function id() {
		return 'content_control';
	}

	public function title() {
		return __( 'Content Control', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Restrict pages and posts by role, hide menu items, gate content with a shortcode, and protect the whole site behind login. Replaces the Content Control plugin.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_CC_Settings::install_defaults();
	}

	public function init() {
		$this->admin   = new DPT_CC_Admin();
		$this->metabox = new DPT_CC_Metabox();
		$this->menu    = new DPT_CC_Menu();

		// Whole-site protection - earliest front-end decision.
		add_action( 'template_redirect', array( $this, 'enforce_site_protection' ), 1 );

		// Per-post content replacement for listings, single views and feeds.
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 20 );
		add_filter( 'the_excerpt', array( $this, 'filter_the_excerpt' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_get_the_excerpt' ), 20, 2 );
		add_filter( 'the_content_feed', array( $this, 'filter_feed_content' ), 20 );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_feed_content' ), 20 );

		// REST: blank restricted content for readers who cannot view it, and
		// enforce whole-site protection on the whole API.
		add_action( 'rest_api_init', array( $this, 'register_rest_guards' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'enforce_site_protection_rest' ), 20 );

		// Shortcode gate (works inside Elementor and the block editor too).
		add_shortcode( 'dpt_restrict', array( $this, 'shortcode_restrict' ) );

		$this->menu->init();
	}

	public function register_admin_menu( $parent_slug ) {
		$this->admin->register_menu( $parent_slug );
	}

	/* --------------------------------------------------------------------- */
	/* Whole-site protection                                                 */
	/* --------------------------------------------------------------------- */

	public function enforce_site_protection() {
		if ( is_admin() || ! DPT_CC_Settings::site_protection_active() ) {
			return;
		}
		// Never touch REST, cron, ajax or feeds here.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$mode  = DPT_CC_Settings::get( 'site_mode' );
		$roles = DPT_CC_Settings::get( 'site_roles' );
		$allowed = ( 'roles' === $mode )
			? DPT_CC_Access::can_view( 'roles', $roles )
			: DPT_CC_Access::can_view( 'logged_in' );

		if ( $allowed || $this->is_site_exempt() ) {
			return;
		}

		$action = DPT_CC_Settings::get( 'site_action' );
		if ( 'page' === $action && DPT_CC_Settings::get( 'site_redirect' ) ) {
			wp_safe_redirect( get_permalink( DPT_CC_Settings::get( 'site_redirect' ) ) );
			exit;
		}
		if ( 'message' === $action ) {
			$msg = (string) DPT_CC_Settings::get( 'site_message' );
			if ( '' === trim( $msg ) ) {
				$msg = __( 'This site is private.', 'digitizer-pro-tools' );
			}
			wp_die( wp_kses_post( wpautop( $msg ) ), esc_html__( 'Private site', 'digitizer-pro-tools' ), array( 'response' => 403 ) );
		}
		// Default: send to the login form and back.
		wp_safe_redirect( wp_login_url( $this->current_url() ) );
		exit;
	}

	/**
	 * Apply whole-site protection to the REST API. Without this, a private
	 * site would still expose ordinary posts through /wp-json, since the
	 * per-post REST guard only covers posts carrying restriction meta.
	 *
	 * @param WP_Error|null|true $result Existing authentication result.
	 * @return WP_Error|null|true
	 */
	public function enforce_site_protection_rest( $result ) {
		// Preserve an authentication error already raised upstream.
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! DPT_CC_Settings::site_protection_active() ) {
			return $result;
		}
		$mode    = DPT_CC_Settings::get( 'site_mode' );
		$roles   = DPT_CC_Settings::get( 'site_roles' );
		$allowed = ( 'roles' === $mode )
			? DPT_CC_Access::can_view( 'roles', $roles )
			: DPT_CC_Access::can_view( 'logged_in' );
		if ( $allowed ) {
			return $result;
		}
		return new WP_Error(
			'dpt_cc_rest_forbidden',
			__( 'This site is private.', 'digitizer-pro-tools' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	private function is_site_exempt() {
		$exempt = (array) DPT_CC_Settings::get( 'exempt_ids' );
		$redirect = (int) DPT_CC_Settings::get( 'site_redirect' );
		if ( $redirect ) {
			$exempt[] = $redirect;
		}
		$object_id = (int) get_queried_object_id();
		if ( $object_id && in_array( $object_id, array_map( 'intval', $exempt ), true ) ) {
			return true;
		}
		return (bool) apply_filters( 'dpt_cc_site_exempt', false, $object_id );
	}

	private function current_url() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $req );
	}

	/* --------------------------------------------------------------------- */
	/* Per-post content replacement                                          */
	/* --------------------------------------------------------------------- */

	private function should_hide( $post_id ) {
		return $post_id
			&& DPT_CC_Access::post_is_restricted( $post_id )
			&& ! DPT_CC_Access::can_view_post( $post_id );
	}

	public function filter_the_content( $content ) {
		$id = get_the_ID();
		return $this->should_hide( $id ) ? DPT_CC_Access::restriction_message( $id ) : $content;
	}

	public function filter_the_excerpt( $excerpt ) {
		$id = get_the_ID();
		return $this->should_hide( $id ) ? DPT_CC_Access::restriction_message( $id ) : $excerpt;
	}

	public function filter_get_the_excerpt( $excerpt, $post = null ) {
		$id = $post ? ( is_object( $post ) ? $post->ID : (int) $post ) : get_the_ID();
		return $this->should_hide( $id ) ? wp_strip_all_tags( DPT_CC_Access::restriction_message( $id ) ) : $excerpt;
	}

	public function filter_feed_content( $content ) {
		$id = get_the_ID();
		return $this->should_hide( $id ) ? wp_strip_all_tags( DPT_CC_Access::restriction_message( $id ) ) : $content;
	}

	/* --------------------------------------------------------------------- */
	/* REST protection                                                       */
	/* --------------------------------------------------------------------- */

	public function register_rest_guards() {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			add_filter( "rest_prepare_{$type}", array( $this, 'filter_rest_prepare' ), 20, 3 );
		}
	}

	public function filter_rest_prepare( $response, $post, $request ) {
		if ( ! $post || ! $this->should_hide( $post->ID ) ) {
			return $response;
		}
		$data    = $response->get_data();
		$message = wp_strip_all_tags( DPT_CC_Access::restriction_message( $post->ID ) );
		if ( isset( $data['content'] ) ) {
			$data['content'] = array( 'rendered' => '<p>' . esc_html( $message ) . '</p>', 'protected' => true );
		}
		if ( isset( $data['excerpt'] ) ) {
			$data['excerpt'] = array( 'rendered' => '<p>' . esc_html( $message ) . '</p>', 'protected' => true );
		}
		$response->set_data( $data );
		return $response;
	}

	/* --------------------------------------------------------------------- */
	/* Shortcode gate                                                        */
	/* --------------------------------------------------------------------- */

	public function shortcode_restrict( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'role'    => '',
				'show'    => 'logged_in', // logged_in | logged_out | roles
				'message' => '',
			),
			$atts,
			'dpt_restrict'
		);

		$roles = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $atts['role'] ) ) ) );
		$visibility = $roles ? 'roles' : DPT_CC_Access::sanitize_visibility( $atts['show'] );

		if ( DPT_CC_Access::can_view( $visibility, $roles ) ) {
			return do_shortcode( $content );
		}
		$message = (string) $atts['message'];
		return '' === trim( $message ) ? '' : '<div class="dpt-cc-restricted">' . wp_kses_post( $message ) . '</div>';
	}
}
