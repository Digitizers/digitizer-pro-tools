<?php
/**
 * Update Policy module - settings screen and the notice that keeps a hold
 * from being invisible.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_UP_Admin {

	const PAGE_SLUG = 'dpt-update-policy';

	public function __construct() {
		add_action( 'admin_post_dpt_up_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dpt_up_release', array( $this, 'handle_release' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Update Policy', 'digitizer-pro-tools' ),
			__( 'Update Policy', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Say what is being held, wherever someone would look for it.
	 *
	 * A hold nobody can see is indistinguishable from a site whose updates are
	 * broken, so this runs on the Updates screen and the dashboard as well as
	 * on the module's own page.
	 */
	public function maybe_show_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own post-save redirect.
		if ( self::PAGE_SLUG === dpt_current_admin_page() && isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$where  = is_object( $screen ) ? (string) $screen->id : '';
		if ( ! in_array( $where, array( 'update-core', 'dashboard', 'update-core-network', 'dashboard-network' ), true ) ) {
			return;
		}
		if ( ! DPT_UP_Settings::may_decide() ) {
			return;
		}

		foreach ( DPT_UP_Policy::held_majors() as $branch => $held ) {
			$this->render_hold_notice( $branch, $held );
		}
	}

	/**
	 * One notice for one held branch.
	 *
	 * @param string $branch Branch string.
	 * @param array  $held   version, seen and until timestamps.
	 */
	private function render_hold_notice( $branch, $held ) {
		$format = get_option( 'date_format' );
		?>
		<div class="notice notice-info">
			<p>
				<strong>
					<?php
					printf(
						/* translators: %s: WordPress version */
						esc_html__( 'WordPress %s is available, and this site is holding it back.', 'digitizer-pro-tools' ),
						esc_html( $held['version'] )
					);
					?>
				</strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: date the update was first offered, 2: date the hold ends */
					esc_html__( 'It was first offered here on %1$s, and the hold ends on %2$s. Major releases are held so that the plugins and themes on this site have time to catch up with them; security and maintenance releases are installed as usual and are not affected.', 'digitizer-pro-tools' ),
					esc_html( date_i18n( $format, (int) $held['seen'] ) ),
					esc_html( date_i18n( $format, (int) $held['until'] ) )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dpt_up_release_' . $branch ); ?>
				<input type="hidden" name="action" value="dpt_up_release" />
				<input type="hidden" name="branch" value="<?php echo esc_attr( $branch ); ?>" />
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Offer it now anyway', 'digitizer-pro-tools' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Update policy settings', 'digitizer-pro-tools' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! DPT_UP_Settings::may_decide() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_up_settings' );

		$policy              = DPT_UP_Settings::all();
		$policy['hold_days'] = isset( $_POST['dpt_up_hold_days'] ) ? absint( wp_unslash( $_POST['dpt_up_hold_days'] ) ) : DPT_UP_Settings::DEFAULT_DAYS;
		DPT_UP_Settings::save( $policy );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_release() {
		$branch = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';
		if ( ! DPT_UP_Settings::may_decide() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_up_release_' . $branch );

		DPT_UP_Policy::release( $branch );

		wp_safe_redirect( admin_url( 'update-core.php' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$policy = DPT_UP_Settings::all();
		$held   = DPT_UP_Policy::held_majors();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Update Policy', 'digitizer-pro-tools' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A major WordPress release is held back for a while after this site is first offered it, so that the plugins and themes running here have time to catch up with it. Security and maintenance releases are never held: they are what keeps the site safe, and WordPress installs them on its own.', 'digitizer-pro-tools' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dpt_up_settings' ); ?>
				<input type="hidden" name="action" value="dpt_up_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dpt-up-hold-days"><?php esc_html_e( 'Hold major releases for', 'digitizer-pro-tools' ); ?></label></th>
						<td>
							<input name="dpt_up_hold_days" id="dpt-up-hold-days" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr( (string) $policy['hold_days'] ); ?>" />
							<?php esc_html_e( 'days', 'digitizer-pro-tools' ); ?>
							<p class="description"><?php esc_html_e( 'Counted from the day this site first saw the release, not from the day it was published. Set to 0 to hold nothing.', 'digitizer-pro-tools' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Currently held', 'digitizer-pro-tools' ); ?></h2>
			<?php if ( ! $held ) : ?>
				<p><?php esc_html_e( 'Nothing is being held back right now.', 'digitizer-pro-tools' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $held as $branch => $row ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: WordPress version, 2: date the hold ends */
								esc_html__( 'WordPress %1$s, until %2$s', 'digitizer-pro-tools' ),
								esc_html( $row['version'] ),
								esc_html( date_i18n( get_option( 'date_format' ), (int) $row['until'] ) )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
