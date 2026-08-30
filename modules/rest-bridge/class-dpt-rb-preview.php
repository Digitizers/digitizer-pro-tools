<?php
/**
 * REST Bridge module - what it would put on the API, before it is switched on.
 *
 * The info endpoint answers this for a module that is already running. This
 * screen answers it for one that is not, which is the moment the answer
 * matters: fields are discovered from the site's own JetEngine definitions, so
 * until something reads them nobody knows what this module will expose - and
 * the module stands down while the plugin it replaces is active, so there is
 * no way to look without first deactivating a plugin that works.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RB_Preview {

	const PAGE_SLUG = 'dpt-rest-bridge-preview';

	/**
	 * @param string $parent_slug Menu to hang the page on.
	 * @return void
	 */
	public static function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'REST Bridge Preview', 'digitizer-pro-tools' ),
			__( 'REST Bridge Preview', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Discovery reads the site's JetEngine definitions out of the
		// database. Cheap, but not free, and nothing here is worth paying for
		// on every load of a page somebody opened by accident - so it runs
		// when it is asked to and not before.
		$run = isset( $_POST['dpt_rb_preview'] );
		if ( $run ) {
			check_admin_referer( 'dpt_rb_preview' );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'REST Bridge Preview', 'digitizer-pro-tools' ); ?></h1>
			<p><?php esc_html_e( 'Runs the module\'s discovery and registration without registering anything, and reports what it found. Nothing on this page changes the site.', 'digitizer-pro-tools' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'dpt_rb_preview' ); ?>
				<p><button type="submit" name="dpt_rb_preview" value="1" class="button button-primary"><?php esc_html_e( 'Run the preview', 'digitizer-pro-tools' ); ?></button></p>
			</form>
			<?php
			if ( $run ) {
				self::render_report( DPT_RB_Info::preview() );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array $report The info payload, rehearsed.
	 * @return void
	 */
	private static function render_report( $report ) {
		$fields  = isset( $report['fields'] ) && is_array( $report['fields'] ) ? $report['fields'] : array();
		$skipped = isset( $report['skipped'] ) && is_array( $report['skipped'] ) ? $report['skipped'] : array();
		$compat  = isset( $report['compat'] ) && is_array( $report['compat'] ) ? $report['compat'] : array();
		$seo     = isset( $report['rank_math_fields'] ) && is_array( $report['rank_math_fields'] ) ? $report['rank_math_fields'] : array();

		$total = 0;
		foreach ( $fields as $names ) {
			$total += is_array( $names ) ? count( $names ) : 0;
		}
		?>
		<h2><?php esc_html_e( 'Fields', 'digitizer-pro-tools' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: number of fields, 2: number of post types and taxonomies */
				esc_html__( '%1$d fields on %2$d post types and taxonomies.', 'digitizer-pro-tools' ),
				(int) $total,
				count( $fields )
			);
			?>
		</p>
		<?php if ( empty( $fields ) ) : ?>
			<p><?php esc_html_e( 'No JetEngine fields were found. If this site uses JetEngine, check that its field definitions are saved.', 'digitizer-pro-tools' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post type or taxonomy', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Field', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Type', 'digitizer-pro-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $fields as $target => $names ) : ?>
					<?php foreach ( (array) $names as $name => $schema ) : ?>
						<tr>
							<td><?php echo esc_html( $target ); ?></td>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td><?php echo esc_html( isset( $schema['type'] ) ? (string) $schema['type'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $compat ) ) : ?>
			<h2><?php esc_html_e( 'Compatibility names', 'digitizer-pro-tools' ); ?></h2>
			<p><?php esc_html_e( 'Names the module provides so callers written against the plugin it replaces keep working. A name here is not marked in the table above, because the list records names and not the targets they landed on - and the same name can be the site\'s own field on one post type and a compatibility name on another.', 'digitizer-pro-tools' ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( $compat as $dpt_compat_name ) : ?>
					<li><code><?php echo esc_html( (string) $dpt_compat_name ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Rank Math', 'digitizer-pro-tools' ); ?></h2>
		<?php if ( empty( $seo ) ) : ?>
			<p><?php esc_html_e( 'Rank Math is not active here, so none of its fields would be exposed.', 'digitizer-pro-tools' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of Rank Math meta keys */
					esc_html__( '%d Rank Math keys would be readable and writable on posts and pages, in the edit context only.', 'digitizer-pro-tools' ),
					count( $seo )
				);
				?>
			</p>
			<ul class="ul-disc">
				<?php foreach ( $seo as $dpt_key ) : ?>
					<li><code><?php echo esc_html( (string) $dpt_key ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Not exposed', 'digitizer-pro-tools' ); ?></h2>
		<?php if ( empty( $skipped ) ) : ?>
			<p><?php esc_html_e( 'Nothing was skipped.', 'digitizer-pro-tools' ); ?></p>
		<?php else : ?>
			<ul class="ul-disc">
				<?php foreach ( $skipped as $reason ) : ?>
					<li><?php echo esc_html( (string) $reason ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Routes', 'digitizer-pro-tools' ); ?></h2>
		<ul class="ul-disc">
			<?php foreach ( (array) ( isset( $report['routes'] ) ? $report['routes'] : array() ) as $route ) : ?>
				<li><code><?php echo esc_html( (string) $route ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
