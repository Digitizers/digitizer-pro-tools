<?php
/**
 * Abstract base for Digitizer Pro Tools feature modules.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class DPT_Module {

	/**
	 * Unique module id (lowercase, underscores). Used as the key in
	 * dpt_settings['modules'].
	 */
	abstract public function id();

	/**
	 * Human-readable module title (translated).
	 */
	abstract public function title();

	/**
	 * Short description shown on the Modules dashboard (translated).
	 */
	abstract public function description();

	/**
	 * Wire the module's hooks. Called only when the module is enabled.
	 */
	abstract public function init();

	/**
	 * Whether this module is enabled on fresh installs.
	 */
	public function enabled_by_default() {
		return false;
	}

	/**
	 * Seed / migrate the module's own options. Called on activation and
	 * on version upgrades, regardless of the enabled flag.
	 */
	public function install_defaults() {}

	/**
	 * Register the module's admin submenu under the main DPT menu.
	 * Called only when the module is enabled.
	 *
	 * @param string $parent_slug Slug of the top-level DPT menu.
	 */
	public function register_admin_menu( $parent_slug ) {}
}
