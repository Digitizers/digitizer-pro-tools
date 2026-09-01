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
require_once __DIR__ . '/class-dpt-cc-restrictions.php';
require_once __DIR__ . '/class-dpt-cc-rules.php';
require_once __DIR__ . '/class-dpt-cc-enforce.php';
require_once __DIR__ . '/class-dpt-cc-widgets.php';
require_once __DIR__ . '/class-dpt-cc-restrictions-admin.php';

class DPT_Content_Control_Module extends DPT_Module {

	/** @var DPT_CC_Admin */
	private $admin;

	/** @var DPT_CC_Metabox */
	private $metabox;

	/** @var DPT_CC_Menu */
	private $menu;

	/** @var DPT_CC_Enforce */
	private $enforce;

	public function id() {
		return 'content_control';
	}

	public function title() {
		return __( 'Content Control', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Restrict pages and posts by role, define global restriction rules with redirect/replace protection, hide menu items and widgets, gate content with a shortcode, and protect the whole site behind login. Replaces the Content Control plugin.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_CC_Settings::install_defaults();
		if ( false === get_option( DPT_CC_Restrictions::OPTION ) ) {
			add_option( DPT_CC_Restrictions::OPTION, array() );
		}
	}

	public function init() {
		$this->admin   = new DPT_CC_Admin();
		$this->metabox = new DPT_CC_Metabox();
		$this->menu    = new DPT_CC_Menu();
		$this->enforce = new DPT_CC_Enforce();
		$this->enforce->init();
		$widgets = new DPT_CC_Widgets();
		$widgets->init();
		$restrictions_admin = new DPT_CC_Restrictions_Admin();
		$restrictions_admin->init();

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
		// REQUEST_URI already contains the full path including any subdirectory
		// the site lives in, so combine it with the trusted scheme+host from
		// home_url() rather than home_url( $req ), which would double the
		// subdirectory prefix (e.g. /site/site/page/).
		// Not passed through a text sanitizer: the value is re-assembled into a
		// URL below and only ever compared, never printed.
		$req    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared, never output, see above.
		$home   = wp_parse_url( home_url() );
		$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = ! empty( $home['host'] ) ? $home['host'] : '';
		if ( ! empty( $home['port'] ) ) {
			$host .= ':' . $home['port'];
		}
		return $scheme . '://' . $host . $req;
	}

	/* --------------------------------------------------------------------- */
	/* Per-post content replacement                                          */
	/* --------------------------------------------------------------------- */

	private function should_hide( $post_id ) {
		return $post_id
			&& DPT_CC_Access::post_is_restricted( $post_id )
			&& ! DPT_CC_Access::can_view_post( $post_id );
	}

	/**
	 * What the viewer gets in place of restricted content, or null when the
	 * content is theirs to see. Per-post meta is consulted first and wins;
	 * global restrictions come second, adding a per-row message override and
	 * an optional teaser excerpt above the notice.
	 *
	 * @param int  $post_id Post being rendered.
	 * @param bool $strip   Return plain text (feeds, REST, excerpt lists).
	 * @return string|null
	 */
	private function restricted_output( $post_id, $strip = false ) {
		if ( ! $post_id ) {
			return null;
		}
		if ( $this->should_hide( $post_id ) ) {
			$html = DPT_CC_Access::restriction_message( $post_id );
			return $strip ? wp_strip_all_tags( $html ) : $html;
		}
		$row = $this->enforce ? $this->enforce->restriction_for_post( get_post( $post_id ) ) : null;
		if ( ! $row ) {
			return null;
		}
		$custom = $this->enforce->denial_message( $row );
		$html   = '' !== $custom
			? '<div class="dpt-cc-restricted">' . wp_kses_post( wpautop( $custom ) ) . '</div>'
			: DPT_CC_Access::restriction_message( 0 );
		if ( ! empty( $row['protection']['show_excerpts'] ) ) {
			$teaser = get_post_field( 'post_excerpt', $post_id );
			if ( '' !== trim( (string) $teaser ) ) {
				$allowed = apply_filters(
					'dpt_cc_teaser_allowed_tags',
					array(
						'a'          => array( 'href' => array() ),
						'em'         => array(),
						'strong'     => array(),
						'p'          => array(),
						'ul'         => array(),
						'ol'         => array(),
						'li'         => array(),
						'blockquote' => array(),
					)
				);
				$html = '<div class="dpt-cc-excerpt">' . wp_kses( wpautop( $teaser ), $allowed ) . '</div>' . $html;
			}
		}
		return $strip ? wp_strip_all_tags( $html ) : $html;
	}

	public function filter_the_content( $content ) {
		$out = $this->restricted_output( get_the_ID() );
		return null === $out ? $content : $out;
	}

	public function filter_the_excerpt( $excerpt ) {
		$out = $this->restricted_output( get_the_ID() );
		return null === $out ? $excerpt : $out;
	}

	public function filter_get_the_excerpt( $excerpt, $post = null ) {
		$id  = $post ? ( is_object( $post ) ? $post->ID : (int) $post ) : get_the_ID();
		$out = $this->restricted_output( $id, true );
		return null === $out ? $excerpt : $out;
	}

	public function filter_feed_content( $content ) {
		$out = $this->restricted_output( get_the_ID(), true );
		return null === $out ? $content : $out;
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
		if ( ! $post ) {
			return $response;
		}
		$message = $this->restricted_output( $post->ID, true );
		if ( null === $message ) {
			return $response;
		}
		$data = $response->get_data();
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
				'role'           => '',
				'excluded_roles' => '',
				'show'           => 'logged_in', // logged_in | logged_out | roles
				'message'        => '',
				'inline'         => '',
				'class'          => '',
			),
			$atts,
			'dpt_restrict'
		);

		$split = static function ( $csv ) {
			return array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $csv ) ) ) );
		};
		$roles    = $split( $atts['role'] );
		$excluded = $split( $atts['excluded_roles'] );

		if ( $excluded ) {
			// Any logged-in user EXCEPT these roles. Wins over `role` - an
			// exclusion someone bothered to write is the stricter intent.
			$allowed = DPT_CC_Access::can_view( 'roles', $excluded, null, 'exclude' );
		} elseif ( $roles ) {
			$allowed = DPT_CC_Access::can_view( 'roles', $roles );
		} else {
			$allowed = DPT_CC_Access::can_view( DPT_CC_Access::sanitize_visibility( $atts['show'] ) );
		}

		if ( $allowed ) {
			return do_shortcode( $content );
		}

		$message = (string) $atts['message'];
		if ( '' === trim( $message ) ) {
			return '';
		}
		$tag     = in_array( strtolower( (string) $atts['inline'] ), array( '1', 'true', 'yes' ), true ) ? 'span' : 'div';
		$classes = array( 'dpt-cc-restricted' );
		foreach ( preg_split( '/\s+/', (string) $atts['class'] ) as $c ) {
			$c = sanitize_html_class( $c );
			if ( '' !== $c ) {
				$classes[] = $c;
			}
		}
		return '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . wp_kses_post( $message ) . '</' . $tag . '>';
	}
}
