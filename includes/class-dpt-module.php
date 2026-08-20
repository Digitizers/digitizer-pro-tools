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
	 * Seed / migrate the module's own options. Called on activation and
	 * on version upgrades, regardless of the enabled flag.
	 */
	public function install_defaults() {}

	/**
	 * Whether the current user may switch this module on or off.
	 *
	 * Almost always yes: reaching the Modules screen already requires
	 * manage_options. A module overrides this when the thing it governs is
	 * not the current user's to govern - a decision that reaches beyond this
	 * blog, say - so that the switch cannot hand someone authority the
	 * feature itself would refuse them.
	 *
	 * The Modules screen renders such a module's switch as read-only and says
	 * why; the save path enforces it regardless of what was posted.
	 *
	 * @return bool
	 */
	public function user_can_toggle() {
		return true;
	}

	/**
	 * Why this user may not switch the module, shown next to a locked switch.
	 *
	 * @return string Translated sentence, or '' when there is nothing to say.
	 */
	public function toggle_denied_reason() {
		return '';
	}

	/**
	 * Register the module's admin submenu under the main DPT menu.
	 * Called only when the module is enabled.
	 *
	 * @param string $parent_slug Slug of the top-level DPT menu.
	 */
	public function register_admin_menu( $parent_slug ) {}
}
