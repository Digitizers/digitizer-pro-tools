<?php
/**
 * Core plugin singleton: module registry, loading and migrations.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Plugin {

	/** @var DPT_Plugin */
	private static $instance = null;

	/** @var DPT_Module[] Instantiated modules, keyed by id. */
	private $modules = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Module registry: id => array( file => main class file, class => class name ).
	 * Extendable via the dpt_modules filter.
	 */
	public function registry() {
		$modules = array(
			'cookie_banner' => array(
				'file'    => DPT_PATH . 'modules/cookie-banner/class-dpt-cb-module.php',
				'class'   => 'DPT_Cookie_Banner_Module',
				'default' => '1',
			),
			'duplicate_post' => array(
				'file'    => DPT_PATH . 'modules/duplicate-post/class-dpt-dp-module.php',
				'class'   => 'DPT_Duplicate_Post_Module',
				'default' => '1',
			),
			'update_emails' => array(
				'file'    => DPT_PATH . 'modules/update-emails/class-dpt-ue-module.php',
				'class'   => 'DPT_Update_Emails_Module',
				'default' => '1',
			),
			'disable_comments' => array(
				'file'    => DPT_PATH . 'modules/disable-comments/class-dpt-dc-module.php',
				'class'   => 'DPT_Disable_Comments_Module',
				'default' => '0',
			),
			'hide_login' => array(
				'file'    => DPT_PATH . 'modules/hide-login/class-dpt-hl-module.php',
				'class'   => 'DPT_Hide_Login_Module',
				'default' => '0',
			),
			'user_role_editor' => array(
				'file'    => DPT_PATH . 'modules/user-role-editor/class-dpt-ure-module.php',
				'class'   => 'DPT_User_Role_Editor_Module',
				'default' => '0',
			),
			'content_control' => array(
				'file'    => DPT_PATH . 'modules/content-control/class-dpt-cc-module.php',
				'class'   => 'DPT_Content_Control_Module',
				'default' => '0',
			),
			'enlighter' => array(
				'file'    => DPT_PATH . 'modules/enlighter/class-dpt-en-module.php',
				'class'   => 'DPT_Enlighter_Module',
				'default' => '0',
			),
			'site_tweaks' => array(
				'file'    => DPT_PATH . 'modules/site-tweaks/class-dpt-st-module.php',
				'class'   => 'DPT_Site_Tweaks_Module',
				'default' => '0',
			),
			'woo_checkout' => array(
				'file'    => DPT_PATH . 'modules/woo-checkout/class-dpt-wcc-module.php',
				'class'   => 'DPT_Woo_Checkout_Module',
				'default' => '0',
			),
		);
		return apply_filters( 'dpt_modules', $modules );
	}

	/**
	 * A module's on/off default from its registry spec.
	 */
	private function module_default( $spec ) {
		return ( isset( $spec['default'] ) && '1' === $spec['default'] ) ? '1' : '0';
	}

	/**
	 * Boot the plugin on plugins_loaded.
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->load_modules();
		$this->maybe_migrate();

		new DPT_Admin( $this );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'digitizer-pro-tools', false, dirname( DPT_BASENAME ) . '/languages' );
	}

	/**
	 * Instantiate every registered module; init() only the enabled ones.
	 */
	private function load_modules() {
		$enabled = $this->enabled_map();
		foreach ( $this->registry() as $id => $spec ) {
			if ( ! file_exists( $spec['file'] ) ) {
				continue;
			}
			require_once $spec['file'];
			if ( ! class_exists( $spec['class'] ) ) {
				continue;
			}
			$module = new $spec['class']();
			$this->modules[ $id ] = $module;
			if ( ! empty( $enabled[ $id ] ) && '1' === $enabled[ $id ] ) {
				$module->init();
			}
		}
	}

	/**
	 * All instantiated modules (enabled or not) for the dashboard.
	 *
	 * @return DPT_Module[]
	 */
	public function modules() {
		return $this->modules;
	}

	public function is_module_enabled( $id ) {
		$enabled = $this->enabled_map();
		return ! empty( $enabled[ $id ] ) && '1' === $enabled[ $id ];
	}

	/**
	 * The saved modules on/off map, with defaults for unknown ids.
	 */
	public function enabled_map() {
		$opts = get_option( DPT_OPTION, array() );
		$map  = ( is_array( $opts ) && isset( $opts['modules'] ) && is_array( $opts['modules'] ) ) ? $opts['modules'] : array();
		foreach ( $this->registry() as $id => $spec ) {
			if ( ! array_key_exists( $id, $map ) ) {
				// New module never saved before: fall back to its default flag.
				$map[ $id ] = $this->module_default( $spec );
			}
		}
		return $map;
	}

	/**
	 * Persist the modules on/off map.
	 */
	public function save_enabled_map( $map ) {
		$opts = get_option( DPT_OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$clean = array();
		foreach ( $this->registry() as $id => $spec ) {
			$clean[ $id ] = ( isset( $map[ $id ] ) && '1' === $map[ $id ] ) ? '1' : '0';
		}
		$changed         = ! isset( $opts['modules'] ) || $opts['modules'] !== $clean;
		$opts['modules'] = $clean;
		update_option( DPT_OPTION, $opts );

		// Toggling a module changes the rendered HTML - stale cached pages
		// would keep the old module output alive.
		if ( $changed && class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
	}

	/**
	 * Seed core + module defaults. Runs on activation and upgrades.
	 */
	public function install_defaults() {
		$opts = get_option( DPT_OPTION );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		if ( ! isset( $opts['modules'] ) || ! is_array( $opts['modules'] ) ) {
			$opts['modules'] = array();
		}
		foreach ( $this->registry() as $id => $spec ) {
			if ( ! array_key_exists( $id, $opts['modules'] ) ) {
				$opts['modules'][ $id ] = $this->module_default( $spec );
			}
			if ( file_exists( $spec['file'] ) ) {
				require_once $spec['file'];
				if ( class_exists( $spec['class'] ) ) {
					$module = new $spec['class']();
					$module->install_defaults();
				}
			}
		}
		update_option( DPT_OPTION, $opts );
		update_option( 'dpt_db_version', DPT_VERSION );
	}

	/**
	 * Run migrations when the plugin files were replaced without the
	 * activation hook firing (manual/FTP updates).
	 */
	private function maybe_migrate() {
		$current = get_option( 'dpt_db_version', '0' );
		if ( version_compare( $current, DPT_VERSION, '<' ) ) {
			$this->install_defaults();
		}
	}
}
