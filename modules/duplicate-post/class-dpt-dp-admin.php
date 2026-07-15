<?php
/**
 * Duplicate Post module - row/bulk actions, handlers and settings page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_DP_Admin {

	const PAGE_SLUG = 'dpt-duplicate-post';
	const NONCE     = 'dpt_dp_duplicate';

	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );
		add_action( 'admin_post_dpt_dp_duplicate', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_dpt_dp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Duplicate Post', 'digitizer-pro-tools' ),
			__( 'Duplicate Post', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Can the current user duplicate this post?
	 */
	private function can_duplicate( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, DPT_DP_Settings::enabled_post_types(), true ) ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type ) {
			return false;
		}
		return current_user_can( $type->cap->edit_posts ) && current_user_can( 'edit_post', $post->ID );
	}

	private function duplicate_url( $post_id, $redirect ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'dpt_dp_duplicate',
					'post'     => (int) $post_id,
					'redirect' => $redirect,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE . '_' . (int) $post_id
		);
	}

	/**
	 * "Duplicate" / "Duplicate & Edit" links in the posts list.
	 */
	public function row_actions( $actions, $post ) {
		if ( ! $this->can_duplicate( $post ) ) {
			return $actions;
		}
		$actions['dpt_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $this->duplicate_url( $post->ID, 'list' ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Duplicate "%s"', 'digitizer-pro-tools' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate', 'digitizer-pro-tools' )
		);
		$actions['dpt_duplicate_edit'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $this->duplicate_url( $post->ID, 'edit' ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Duplicate "%s" and edit the copy', 'digitizer-pro-tools' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate & Edit', 'digitizer-pro-tools' )
		);
		return $actions;
	}

	/**
	 * Bulk "Duplicate" on every enabled post type list screen.
	 */
	public function register_bulk_actions() {
		foreach ( DPT_DP_Settings::enabled_post_types() as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, array( $this, 'add_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	public function add_bulk_action( $actions ) {
		$actions['dpt_duplicate'] = __( 'Duplicate', 'digitizer-pro-tools' );
		return $actions;
	}

	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'dpt_duplicate' !== $action ) {
			return $redirect;
		}
		$done = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $this->can_duplicate( $post ) ) {
				continue;
			}
			$result = DPT_DP_Duplicator::duplicate( $post->ID );
			if ( ! is_wp_error( $result ) ) {
				$done++;
			}
		}
		return add_query_arg( 'dpt_dp_duplicated', $done, $redirect );
	}

	/**
	 * Row-action handler (admin-post.php).
	 */
	public function handle_duplicate() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		check_admin_referer( self::NONCE . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $this->can_duplicate( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this item.', 'digitizer-pro-tools' ) );
		}

		$new_id = DPT_DP_Duplicator::duplicate( $post_id );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$redirect = isset( $_GET['redirect'] ) && 'edit' === $_GET['redirect'] ? 'edit' : 'list';
		if ( 'edit' === $redirect ) {
			$url = get_edit_post_link( $new_id, 'raw' );
			if ( ! $url ) {
				$url = admin_url( 'post.php?action=edit&post=' . (int) $new_id );
			}
		} else {
			$url = add_query_arg(
				array(
					'post_type'         => $post->post_type,
					'dpt_dp_duplicated' => 1,
				),
				admin_url( 'edit.php' )
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function maybe_show_notices() {
		if ( isset( $_GET['dpt_dp_duplicated'] ) ) {
			$count = (int) $_GET['dpt_dp_duplicated'];
			if ( $count > 0 ) {
				$message = ( 1 === $count )
					? __( 'Item duplicated as a draft.', 'digitizer-pro-tools' )
					/* translators: %d: number of duplicated items */
					: sprintf( __( '%d items duplicated as drafts.', 'digitizer-pro-tools' ), $count );
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $message )
				);
			}
		}
		if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] && isset( $_GET['dpt_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'digitizer-pro-tools' ) . '</p></div>';
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_dp_settings' );

		$data = isset( $_POST['dpt_dp'] ) && is_array( $_POST['dpt_dp'] ) ? $_POST['dpt_dp'] : array();
		DPT_DP_Settings::save( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'dpt_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Settings page (single form, no tabs - the module is simple).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = DPT_DP_Settings::all();
		?>
		<div class="wrap dpt-wrap">
			<h1 class="dpt-title">
				<span class="dashicons dashicons-admin-page"></span>
				<?php esc_html_e( 'Duplicate Post', 'digitizer-pro-tools' ); ?>
				<span class="dpt-version">v<?php echo esc_html( DPT_VERSION ); ?></span>
			</h1>
			<p class="dpt-intro"><?php esc_html_e( 'Adds "Duplicate" and "Duplicate & Edit" links to the post lists, plus a bulk action. Copies are created as drafts, including custom fields (Elementor data), taxonomies and the featured image.', 'digitizer-pro-tools' ); ?></p>

			<div class="dpt-layout">
				<div class="dpt-main">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpt_dp_save" />
						<?php wp_nonce_field( 'dpt_dp_settings' ); ?>

						<div class="dpt-panel">
							<h2><span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Duplication Settings', 'digitizer-pro-tools' ); ?></h2>
							<table class="form-table dpt-form">
								<tr>
									<th><?php esc_html_e( 'Enabled post types', 'digitizer-pro-tools' ); ?></th>
									<td>
										<?php foreach ( DPT_DP_Settings::duplicable_post_types() as $type ) :
											$type_object = get_post_type_object( $type );
											if ( ! $type_object ) {
												continue;
											}
											?>
											<label style="display:block;margin-bottom:6px;">
												<input type="checkbox" name="dpt_dp[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $o['post_types'], true ) ); ?> />
												<?php echo esc_html( $type_object->labels->name ); ?> <code><?php echo esc_html( $type ); ?></code>
											</label>
										<?php endforeach; ?>
									</td>
								</tr>
								<tr>
									<th><label for="dpt_dp_title_suffix"><?php esc_html_e( 'Title suffix', 'digitizer-pro-tools' ); ?></label></th>
									<td>
										<input type="text" id="dpt_dp_title_suffix" name="dpt_dp[title_suffix]" value="<?php echo esc_attr( $o['title_suffix'] ); ?>" />
										<p class="description"><?php esc_html_e( 'Appended to the copy\'s title. Leave empty for an identical title.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Copy custom fields', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_dp[copy_meta]" value="0" />
											<input type="checkbox" name="dpt_dp[copy_meta]" value="1" <?php checked( $o['copy_meta'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Includes the featured image, page template and page-builder data (Elementor).', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Copy taxonomies', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_dp[copy_taxonomies]" value="0" />
											<input type="checkbox" name="dpt_dp[copy_taxonomies]" value="1" <?php checked( $o['copy_taxonomies'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Categories, tags and custom taxonomies.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Keep original date', 'digitizer-pro-tools' ); ?></th>
									<td>
										<label class="dpt-switch">
											<input type="hidden" name="dpt_dp[copy_date]" value="0" />
											<input type="checkbox" name="dpt_dp[copy_date]" value="1" <?php checked( $o['copy_date'], '1' ); ?> />
											<span class="dpt-switch-slider"></span>
										</label>
										<p class="description"><?php esc_html_e( 'Off = the copy gets the current date.', 'digitizer-pro-tools' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<p class="dpt-actions">
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'digitizer-pro-tools' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
