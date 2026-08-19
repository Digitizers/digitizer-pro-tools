<?php
/**
 * Onboarding module - the wizard screen and its one-item endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Admin {

	const PAGE_SLUG = 'dpt-onboarding';
	const NONCE     = 'dpt_onb';

	public function __construct() {
		add_action( 'wp_ajax_dpt_onb_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Onboarding', 'digitizer-pro-tools' ),
			__( 'Onboarding', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue() {
		if ( self::PAGE_SLUG !== dpt_current_admin_page() ) {
			return;
		}
		$base = DPT_URL . 'modules/onboarding/assets/';
		wp_enqueue_style( 'dpt-onb-wizard', $base . 'css/wizard.css', array(), DPT_VERSION );
		wp_enqueue_script( 'dpt-onb-wizard', $base . 'js/wizard.js', array(), DPT_VERSION, true );
		wp_localize_script(
			'dpt-onb-wizard',
			'DPT_ONB',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'strings' => array(
					'working' => __( 'Working...', 'digitizer-pro-tools' ),
					'done'    => __( 'Done', 'digitizer-pro-tools' ),
					'failed'  => __( 'Failed', 'digitizer-pro-tools' ),
					'skipped' => __( 'Skipped', 'digitizer-pro-tools' ),
					'network' => __( 'The request did not complete. Check the site can reach WordPress.org and GitHub.', 'digitizer-pro-tools' ),
					/* translators: 1: number installed, 2: number activated, 3: number skipped, 4: number failed */
					'summary' => __( '%1$d installed, %2$d activated, %3$d skipped, %4$d failed.', 'digitizer-pro-tools' ),
				),
			)
		);
	}

	/**
	 * Apply exactly one item.
	 *
	 * One item per request on purpose: a single request that installs fourteen
	 * things exhausts max_execution_time on ordinary shared hosting, and when
	 * it does the operator has no way to tell how far it got.
	 */
	public function handle_apply() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'digitizer-pro-tools' ) ), 403 );
		}

		$id   = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$item = DPT_ONB_Manifest::get( $id );
		if ( null === $item ) {
			wp_send_json_error( array( 'message' => __( 'That item is not part of the baseline.', 'digitizer-pro-tools' ) ), 400 );
		}

		// Ask only for what this item's current state actually requires.
		// Core removes install_plugins/install_themes when DISALLOW_FILE_MODS
		// is set - the host saying it does not want new code from the
		// dashboard - but activating something already on disk is a different
		// permission, and demanding the install capability for it would refuse
		// work the operator is entitled to do.
		$needed = DPT_ONB_Installer::capability_for( $item, DPT_ONB_State::of( $item ) );
		if ( '' !== $needed && ! current_user_can( $needed ) ) {
			wp_send_json_error(
				array(
					'message' => 'install_plugins' === $needed || 'install_themes' === $needed
						? __( 'This site does not allow installing from the dashboard.', 'digitizer-pro-tools' )
						: __( 'You are not allowed to do that.', 'digitizer-pro-tools' ),
				),
				403
			);
		}

		wp_send_json_success( DPT_ONB_Installer::apply( $id ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$states = DPT_ONB_State::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Onboarding', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Installs and activates the Digitizer baseline on this site. Anything already active is left exactly as it is - nothing here updates, downgrades or reconfigures a plugin you already have. Safe to run more than once.', 'digitizer-pro-tools' ); ?></p>

			<table class="widefat dpt-onb-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="dpt-onb-all" checked /></td>
						<th><?php esc_html_e( 'Item', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Source', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Status', 'digitizer-pro-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( DPT_ONB_Manifest::items() as $item ) : ?>
					<?php $state = $states[ $item['id'] ]; ?>
					<tr data-item="<?php echo esc_attr( $item['id'] ); ?>">
						<th scope="row" class="check-column">
							<input type="checkbox" class="dpt-onb-pick" value="<?php echo esc_attr( $item['id'] ); ?>" checked />
						</th>
						<td>
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<code><?php echo esc_html( $item['slug'] ); ?></code>
							<?php if ( 'theme' === $item['type'] ) : ?>
								<span class="dpt-onb-tag"><?php esc_html_e( 'theme', 'digitizer-pro-tools' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'github' === $item['source'] ) : ?>
								<?php echo esc_html( $item['repo'] ); ?>
							<?php else : ?>
								<?php esc_html_e( 'WordPress.org', 'digitizer-pro-tools' ); ?>
							<?php endif; ?>
						</td>
						<td class="dpt-onb-status" data-state="<?php echo esc_attr( $state ); ?>">
							<?php
							if ( DPT_ONB_State::ACTIVE === $state ) {
								esc_html_e( 'Active', 'digitizer-pro-tools' );
							} elseif ( DPT_ONB_State::PRESENT === $state ) {
								// The parent theme: installed is all it needs,
								// and it is not the site's active theme.
								esc_html_e( 'Installed', 'digitizer-pro-tools' );
							} elseif ( DPT_ONB_State::INACTIVE === $state ) {
								esc_html_e( 'Installed, not active', 'digitizer-pro-tools' );
							} else {
								esc_html_e( 'Not installed', 'digitizer-pro-tools' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="dpt-actions">
				<button type="button" class="button button-primary button-hero" id="dpt-onb-run"><?php esc_html_e( 'Set up this site', 'digitizer-pro-tools' ); ?></button>
			</p>
			<p class="dpt-onb-summary" id="dpt-onb-summary" role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}
