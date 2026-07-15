<?php
/**
 * Duplicate Post module - registration and wiring.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-dp-settings.php';
require_once __DIR__ . '/class-dpt-dp-duplicator.php';
require_once __DIR__ . '/class-dpt-dp-admin.php';

class DPT_Duplicate_Post_Module extends DPT_Module {

	/** @var DPT_DP_Admin */
	private $admin;

	public function id() {
		return 'duplicate_post';
	}

	public function title() {
		return __( 'Duplicate Post', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'One-click duplication of posts, pages and custom post types as drafts - including custom fields (Elementor data), taxonomies and the featured image.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return true;
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_DP_Admin();
		}
	}

	public function install_defaults() {
		DPT_DP_Settings::install_defaults();
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
