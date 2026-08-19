<?php
/**
 * Onboarding module - the Digitizer baseline.
 *
 * Deliberately code and not a settings screen. An admin field that accepts a
 * ZIP URL or a slug is an arbitrary-code-execution surface, and this list is
 * fixed: adding to it means editing this file and shipping a release, which is
 * the right amount of friction for something that installs code on client
 * sites.
 *
 * Order matters and is part of the contract - the wizard applies items in this
 * order, so a parent theme precedes its child.
 *
 * Labels are product names, not translatable strings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Manifest {

	/**
	 * The baseline, in application order.
	 *
	 * Each item:
	 *   id     - stable key used by the AJAX endpoint and the checklist.
	 *   label  - product name shown in the wizard.
	 *   type   - 'plugin' or 'theme'.
	 *   source - 'wporg' or 'github'.
	 *   slug   - the directory the item must end up in. For wporg items this
	 *            is also the API slug; for github items it is the name we
	 *            rename the extracted directory to.
	 *   repo     - 'owner/name', github items only.
	 *   parent   - parent theme slug, child themes only.
	 *   activate - false for an item that only has to be present. The parent
	 *              theme is one: activating it would replace the child.
	 *
	 * @return array[]
	 */
	public static function items() {
		return array(
			array(
				'id'       => 'hello_elementor',
				'label'    => 'Hello Elementor',
				'type'     => 'theme',
				'source'   => 'wporg',
				'slug'     => 'hello-elementor',
				// Present is the goal state: it exists to be the child's
				// parent, and activating it would undo the child.
				'activate' => false,
			),
			array(
				'id'     => 'hello_digitizer',
				'label'  => 'Hello Digitizer',
				'type'   => 'theme',
				'source' => 'github',
				'repo'   => 'Digitizers/hello-digitizer',
				'slug'   => 'hello-digitizer',
				'parent' => 'hello-elementor',
			),
			array(
				'id'     => 'elementor',
				'label'  => 'Elementor',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'elementor',
			),
			array(
				'id'     => 'angie',
				'label'  => 'Angie - Agentic AI',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'angie',
			),
			array(
				'id'     => 'cloudflare',
				'label'  => 'Cloudflare',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'cloudflare',
			),
			array(
				'id'     => 'fluent_smtp',
				'label'  => 'FluentSMTP',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'fluent-smtp',
			),
			array(
				'id'     => 'imagify',
				'label'  => 'Imagify',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'imagify',
			),
			array(
				'id'     => 'maspik',
				'label'  => 'Maspik',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'contact-forms-anti-spam',
			),
			array(
				'id'     => 'rank_math',
				'label'  => 'Rank Math SEO',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'seo-by-rank-math',
			),
			array(
				'id'     => 'wordfence',
				'label'  => 'Wordfence Security',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'wordfence',
			),
			array(
				'id'     => 'wpcode',
				'label'  => 'WPCode',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'insert-headers-and-footers',
			),
			array(
				'id'     => 'siteagent',
				'label'  => 'SiteAgent for Aura',
				'type'   => 'plugin',
				'source' => 'wporg',
				'slug'   => 'digitizer-site-worker',
			),
			array(
				'id'     => 'elementor_mcp',
				'label'  => 'Elementor MCP',
				'type'   => 'plugin',
				'source' => 'github',
				'repo'   => 'Digitizers/elementor-mcp',
				'slug'   => 'elementor-mcp',
			),
			array(
				'id'     => 'mcp_adapter',
				'label'  => 'MCP Adapter',
				'type'   => 'plugin',
				'source' => 'github',
				'repo'   => 'WordPress/mcp-adapter',
				'slug'   => 'mcp-adapter',
			),
		);
	}

	/**
	 * One item by id.
	 *
	 * Every entry point looks the id up here, so an id that is not in the
	 * manifest can never reach the installer.
	 *
	 * @param string $id Item id.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}
		foreach ( self::items() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}
}
