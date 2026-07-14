<?php
/**
 * Top-level admin menu + Modules dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_Admin {

	const MENU_SLUG = 'digitizer-pro-tools';

	/** @var DPT_Plugin */
	private $plugin;

	public function __construct( DPT_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_dpt_save_modules', array( $this, 'handle_save_modules' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Digitizer Pro Tools', 'digitizer-pro-tools' ),
			__( 'Digitizer Pro Tools', 'digitizer-pro-tools' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-tools',
			80
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Modules', 'digitizer-pro-tools' ),
			__( 'Modules', 'digitizer-pro-tools' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);
		foreach ( $this->plugin->modules() as $id => $module ) {
			if ( $this->plugin->is_module_enabled( $id ) ) {
				$module->register_admin_menu( self::MENU_SLUG );
			}
		}
	}

	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) && false === strpos( $hook, 'dpt-' ) ) {
			return;
		}
		wp_enqueue_style( 'dpt-admin', DPT_URL . 'assets/css/admin.css', array( 'wp-color-picker' ), DPT_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'dpt-admin', DPT_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), DPT_VERSION, true );
	}

	public function handle_save_modules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_save_modules' );

		$raw = isset( $_POST['dpt_modules'] ) && is_array( $_POST['dpt_modules'] ) ? wp_unslash( $_POST['dpt_modules'] ) : array();
		$this->plugin->save_enabled_map( $raw );

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = $this->plugin->enabled_map();
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Modules saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
		?>
		<div class="wrap dpt-wrap">
			<h1><?php esc_html_e( 'Digitizer Pro Tools', 'digitizer-pro-tools' ); ?></h1>
			<p class="dpt-intro"><?php esc_html_e( 'Enable the tools you need. Each module replaces a standalone plugin.', 'digitizer-pro-tools' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dpt_save_modules" />
				<?php wp_nonce_field( 'dpt_save_modules' ); ?>

				<div class="dpt-modules-grid">
					<?php foreach ( $this->plugin->modules() as $id => $module ) : ?>
						<div class="dpt-module-card">
							<div class="dpt-module-card-head">
								<h2><?php echo esc_html( $module->title() ); ?></h2>
								<label class="dpt-switch">
									<input type="hidden" name="dpt_modules[<?php echo esc_attr( $id ); ?>]" value="0" />
									<input type="checkbox" name="dpt_modules[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( '1', isset( $enabled[ $id ] ) ? $enabled[ $id ] : '0' ); ?> />
									<span class="dpt-switch-slider"></span>
								</label>
							</div>
							<p><?php echo esc_html( $module->description() ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

				<?php submit_button( __( 'Save Modules', 'digitizer-pro-tools' ) ); ?>
			</form>
		</div>
		<?php
	}
}
