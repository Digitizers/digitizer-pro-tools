<?php
/**
 * Content Control module - per-post restriction metabox.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Metabox {

	const NONCE = 'dpt_cc_metabox';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function add() {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_meta_box( 'dpt-cc-restrict', __( 'Content Control', 'digitizer-pro-tools' ), array( $this, 'render' ), $type, 'side', 'default' );
		}
	}

	public function render( $post ) {
		$rule    = DPT_CC_Access::post_rule( $post->ID );
		$message = (string) get_post_meta( $post->ID, DPT_CC_Access::META_MESSAGE, true );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="dpt-cc-visibility"><strong><?php esc_html_e( 'Who can view this?', 'digitizer-pro-tools' ); ?></strong></label>
			<select name="dpt_cc_visibility" id="dpt-cc-visibility" style="width:100%;">
				<option value="public" <?php selected( $rule['visibility'], 'public' ); ?>><?php esc_html_e( 'Everyone', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_in" <?php selected( $rule['visibility'], 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></option>
				<option value="logged_out" <?php selected( $rule['visibility'], 'logged_out' ); ?>><?php esc_html_e( 'Logged-out visitors only', 'digitizer-pro-tools' ); ?></option>
				<option value="roles" <?php selected( $rule['visibility'], 'roles' ); ?>><?php esc_html_e( 'Specific roles', 'digitizer-pro-tools' ); ?></option>
			</select>
		</p>
		<div class="dpt-cc-roles" style="margin:6px 0 10px;">
			<em><?php esc_html_e( 'Roles (used with "Specific roles"):', 'digitizer-pro-tools' ); ?></em><br>
			<?php foreach ( self::roles() as $key => $name ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="dpt_cc_roles[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $rule['roles'], true ) ); ?> />
					<?php echo esc_html( $name ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p>
			<label for="dpt-cc-message"><strong><?php esc_html_e( 'Custom restriction message', 'digitizer-pro-tools' ); ?></strong></label>
			<textarea name="dpt_cc_message" id="dpt-cc-message" rows="3" style="width:100%;"><?php echo esc_textarea( $message ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Shown in place of the content. Leave blank to use the global default.', 'digitizer-pro-tools' ); ?></span>
		</p>
		<?php
	}

	private static function roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$out = array();
		foreach ( get_editable_roles() as $key => $role ) {
			$out[ $key ] = translate_user_role( $role['name'] );
		}
		return $out;
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! current_user_can( $type->cap->edit_post, $post_id ) ) {
			return;
		}

		$visibility = DPT_CC_Access::sanitize_visibility( isset( $_POST['dpt_cc_visibility'] ) ? wp_unslash( $_POST['dpt_cc_visibility'] ) : 'public' );

		$roles = array();
		if ( isset( $_POST['dpt_cc_roles'] ) && is_array( $_POST['dpt_cc_roles'] ) ) {
			$valid = array_keys( self::roles() );
			foreach ( $_POST['dpt_cc_roles'] as $r ) {
				$r = sanitize_key( is_array( $r ) ? '' : wp_unslash( $r ) );
				if ( '' !== $r && in_array( $r, $valid, true ) ) {
					$roles[] = $r;
				}
			}
			$roles = array_values( array_unique( $roles ) );
		}

		$message = isset( $_POST['dpt_cc_message'] ) ? wp_kses_post( wp_unslash( $_POST['dpt_cc_message'] ) ) : '';

		if ( 'public' === $visibility ) {
			delete_post_meta( $post_id, DPT_CC_Access::META_VISIBILITY );
			delete_post_meta( $post_id, DPT_CC_Access::META_ROLES );
		} else {
			update_post_meta( $post_id, DPT_CC_Access::META_VISIBILITY, $visibility );
			update_post_meta( $post_id, DPT_CC_Access::META_ROLES, $roles );
		}
		if ( '' === trim( $message ) ) {
			delete_post_meta( $post_id, DPT_CC_Access::META_MESSAGE );
		} else {
			update_post_meta( $post_id, DPT_CC_Access::META_MESSAGE, $message );
		}
	}
}
