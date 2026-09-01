<?php
/**
 * Content Control module - admin for global restrictions: list, editor,
 * reorder, toggle, delete. Plain PHP forms; conditions are repeatable rule
 * rows plus an optional one-level group, posted as parallel arrays.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Restrictions_Admin {

	const NONCE = 'dpt_cc_restrictions';

	public function init() {
		add_action( 'admin_post_dpt_cc_restriction_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dpt_cc_restriction_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_dpt_cc_restriction_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_dpt_cc_restriction_move', array( $this, 'handle_move' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( self::NONCE );
	}

	private static function back( $args = array() ) {
		$url = admin_url( 'admin.php?page=' . DPT_CC_Admin::PAGE_SLUG . '&tab=restrictions' );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private static function purge_caches() {
		// Whole classes of pages just changed their audience.
		if ( class_exists( 'DPT_CB_Settings' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
	}

	public function handle_save() {
		self::guard();
		// Raw pass-through: DPT_CC_Restrictions::sanitize_row() allowlists
		// every field, enum and id on the way into the option.
		$raw = isset( $_POST['restriction'] ) && is_array( $_POST['restriction'] ) ? wp_unslash( $_POST['restriction'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw['conditions'] = self::conditions_from_post();

		$rows = DPT_CC_Restrictions::all();
		$id   = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		$done = false;
		foreach ( $rows as $i => $row ) {
			if ( '' !== $id && $row['id'] === $id ) {
				$raw['id']  = $id;
				$rows[ $i ] = $raw;
				$done       = true;
				break;
			}
		}
		if ( ! $done ) {
			unset( $raw['id'] ); // a fresh id is generated on sanitize
			$rows[] = $raw;
		}
		DPT_CC_Restrictions::save_all( $rows );
		self::purge_caches();
		self::back( array( 'dpt_saved' => 1 ) );
	}

	/**
	 * Rebuild conditions from indexed POST arrays: cond_rule[i]/cond_not[i]/
	 * cond_value[i] for the root, gcond_* for the one optional group, plus
	 * cond_op / gcond_op operators. Every field carries its row index in the
	 * form markup because browsers omit unchecked checkboxes - a bare
	 * cond_not[] would compact and negate the WRONG rule (Codex round-1 P1).
	 * The same posted value feeds both the 'ids' and 'template' options -
	 * each rule callback reads only the one it declares.
	 *
	 * Public for the test harness; reads $_POST directly.
	 */
	public static function conditions_from_post() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- every element passes through DPT_CC_Restrictions::sanitize_conditions().
		$read = static function ( $key ) {
			return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		};
		$op  = ( isset( $_POST['cond_op'] ) && 'or' === $_POST['cond_op'] ) ? 'or' : 'and';
		$gop = ( isset( $_POST['gcond_op'] ) && 'and' === $_POST['gcond_op'] ) ? 'and' : 'or';
		// phpcs:enable

		$collect = static function ( $names, $nots, $values ) {
			$items = array();
			foreach ( (array) $names as $i => $name ) {
				if ( '' === $name || ! is_scalar( $name ) ) {
					continue;
				}
				$value   = ( isset( $values[ $i ] ) && is_scalar( $values[ $i ] ) ) ? (string) $values[ $i ] : '';
				$items[] = array(
					'type'    => 'rule',
					'name'    => $name,
					'not'     => ! empty( $nots[ $i ] ),
					'options' => array(
						'ids'      => $value,
						'template' => $value,
					),
				);
			}
			return $items;
		};

		$items   = $collect( $read( 'cond_rule' ), $read( 'cond_not' ), $read( 'cond_value' ) );
		$g_items = $collect( $read( 'gcond_rule' ), $read( 'gcond_not' ), $read( 'gcond_value' ) );
		if ( $g_items ) {
			$items[] = array( 'type' => 'group', 'operator' => $gop, 'items' => $g_items );
		}
		return array( 'operator' => $op, 'items' => $items );
	}

	public function handle_delete() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$rows = array_values(
			array_filter(
				DPT_CC_Restrictions::all(),
				static function ( $r ) use ( $id ) {
					return $r['id'] !== $id;
				}
			)
		);
		DPT_CC_Restrictions::save_all( $rows );
		self::purge_caches();
		self::back( array( 'dpt_saved' => 1 ) );
	}

	public function handle_toggle() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$rows = DPT_CC_Restrictions::all();
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] === $id ) {
				$rows[ $i ]['enabled'] = ! $row['enabled'];
			}
		}
		DPT_CC_Restrictions::save_all( $rows );
		self::purge_caches();
		self::back();
	}

	public function handle_move() {
		self::guard();
		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$dir  = ( isset( $_GET['dir'] ) && 'up' === $_GET['dir'] ) ? -1 : 1;
		$rows = DPT_CC_Restrictions::all();
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] !== $id ) {
				continue;
			}
			$j = $i + $dir;
			if ( $j >= 0 && $j < count( $rows ) ) {
				$tmp        = $rows[ $j ];
				$rows[ $j ] = $rows[ $i ];
				$rows[ $i ] = $tmp;
			}
			break;
		}
		DPT_CC_Restrictions::save_all( $rows );
		self::purge_caches();
		self::back();
	}

	/* --------------------------------------------------------------------- */
	/* Rendering - called from DPT_CC_Admin::render_page() on its tab        */
	/* --------------------------------------------------------------------- */

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display routing only.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$is_new  = isset( $_GET['new'] );
		// phpcs:enable
		if ( '' !== $edit_id || $is_new ) {
			self::render_editor( '' !== $edit_id ? DPT_CC_Restrictions::get( $edit_id ) : null );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$rows = DPT_CC_Restrictions::all();
		$base = admin_url( 'admin.php?page=' . DPT_CC_Admin::PAGE_SLUG . '&tab=restrictions' );
		$act  = static function ( $action, $id, $extra = array() ) {
			return wp_nonce_url(
				add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ),
				self::NONCE
			);
		};
		echo '<p><a class="button button-primary" href="' . esc_url( $base . '&new=1' ) . '">' . esc_html__( 'Add restriction', 'digitizer-pro-tools' ) . '</a> ';
		echo esc_html__( 'Order is priority - the first matching restriction wins.', 'digitizer-pro-tools' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		$heads = array(
			__( 'Priority', 'digitizer-pro-tools' ),
			__( 'Title', 'digitizer-pro-tools' ),
			__( 'Who may view', 'digitizer-pro-tools' ),
			__( 'Protection', 'digitizer-pro-tools' ),
			__( 'Enabled', 'digitizer-pro-tools' ),
			__( 'Actions', 'digitizer-pro-tools' ),
		);
		foreach ( $heads as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No restrictions yet.', 'digitizer-pro-tools' ) . '</td></tr>';
		}
		foreach ( $rows as $i => $row ) {
			$who = 'logged_out' === $row['who']['status'] ? __( 'Logged-out visitors', 'digitizer-pro-tools' ) : __( 'Logged-in users', 'digitizer-pro-tools' );
			if ( $row['who']['roles'] && 'any' !== $row['who']['role_match'] ) {
				$prefix = 'exclude' === $row['who']['role_match'] ? __( 'except: ', 'digitizer-pro-tools' ) : '';
				$who   .= ' (' . $prefix . implode( ', ', $row['who']['roles'] ) . ')';
			}
			$prot = 'redirect' === $row['protection']['method'] ? __( 'Redirect', 'digitizer-pro-tools' ) : __( 'Replace content', 'digitizer-pro-tools' );
			echo '<tr>';
			echo '<td>' . (int) ( $i + 1 );
			echo ' <a href="' . esc_url( $act( 'dpt_cc_restriction_move', $row['id'], array( 'dir' => 'up' ) ) ) . '" aria-label="' . esc_attr__( 'Move up', 'digitizer-pro-tools' ) . '">&uarr;</a>';
			echo ' <a href="' . esc_url( $act( 'dpt_cc_restriction_move', $row['id'], array( 'dir' => 'down' ) ) ) . '" aria-label="' . esc_attr__( 'Move down', 'digitizer-pro-tools' ) . '">&darr;</a></td>';
			echo '<td><a href="' . esc_url( $base . '&edit=' . $row['id'] ) . '"><strong>' . esc_html( '' !== $row['title'] ? $row['title'] : $row['id'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( $who ) . '</td>';
			echo '<td>' . esc_html( $prot ) . '</td>';
			echo '<td>' . ( $row['enabled'] ? '&#10004;' : '&mdash;' );
			echo ' <a href="' . esc_url( $act( 'dpt_cc_restriction_toggle', $row['id'] ) ) . '">' . esc_html( $row['enabled'] ? __( 'Disable', 'digitizer-pro-tools' ) : __( 'Enable', 'digitizer-pro-tools' ) ) . '</a></td>';
			echo '<td><a href="' . esc_url( $base . '&edit=' . $row['id'] ) . '">' . esc_html__( 'Edit', 'digitizer-pro-tools' ) . '</a> | ';
			echo '<a href="' . esc_url( $act( 'dpt_cc_restriction_delete', $row['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this restriction?', 'digitizer-pro-tools' ) ) . '\');">' . esc_html__( 'Delete', 'digitizer-pro-tools' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
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

	private static function render_rule_rows( $prefix, $items ) {
		$defs   = DPT_CC_Rules::definitions();
		$by_cat = array();
		foreach ( $defs as $name => $def ) {
			$by_cat[ $def['category'] ][ $name ] = $def['label'];
		}
		$items   = $items ? array_values( $items ) : array();
		$items[] = array( 'name' => '', 'not' => false, 'options' => array() ); // one spare blank row

		foreach ( $items as $idx => $item ) {
			$name  = isset( $item['name'] ) ? $item['name'] : '';
			$value = '';
			if ( isset( $item['options']['ids'] ) && '' !== $item['options']['ids'] ) {
				$value = $item['options']['ids'];
			} elseif ( isset( $item['options']['template'] ) ) {
				$value = $item['options']['template'];
			}
			?>
			<div class="dpt-cc-rule-row" style="margin:4px 0;">
				<label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>_not[<?php echo (int) $idx; ?>]" value="1" <?php checked( ! empty( $item['not'] ) ); ?> /> <?php esc_html_e( 'NOT', 'digitizer-pro-tools' ); ?></label>
				<select name="<?php echo esc_attr( $prefix ); ?>_rule[<?php echo (int) $idx; ?>]">
					<option value=""><?php esc_html_e( '— rule —', 'digitizer-pro-tools' ); ?></option>
					<?php foreach ( $by_cat as $cat => $rules ) : ?>
						<optgroup label="<?php echo esc_attr( $cat ); ?>">
							<?php foreach ( $rules as $rname => $rlabel ) : ?>
								<option value="<?php echo esc_attr( $rname ); ?>" <?php selected( $name, $rname ); ?>><?php echo esc_html( $rlabel ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<input type="text" name="<?php echo esc_attr( $prefix ); ?>_value[<?php echo (int) $idx; ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'IDs / template (when the rule needs one)', 'digitizer-pro-tools' ); ?>" />
			</div>
			<?php
		}
	}

	private static function render_editor( $row ) {
		$is_new = null === $row;
		$row    = $row ? $row : DPT_CC_Restrictions::sanitize_row( array( 'id' => 'template', 'enabled' => true ) );

		$root_rules = array();
		$group      = null;
		foreach ( $row['conditions']['items'] as $item ) {
			if ( 'group' === $item['type'] && null === $group ) {
				$group = $item;
			} elseif ( 'rule' === $item['type'] ) {
				$root_rules[] = $item;
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dpt_cc_restriction_save" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php if ( ! $is_new ) : ?>
				<input type="hidden" name="restriction[id]" value="<?php echo esc_attr( $row['id'] ); ?>" />
			<?php endif; ?>

			<div class="dpt-panel">
				<h2><?php esc_html_e( 'General', 'digitizer-pro-tools' ); ?></h2>
				<table class="form-table dpt-form">
					<tr>
						<th><?php esc_html_e( 'Title', 'digitizer-pro-tools' ); ?></th>
						<td><input type="text" class="regular-text" name="restriction[title]" value="<?php echo esc_attr( $row['title'] ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Enabled', 'digitizer-pro-tools' ); ?></th>
						<td><label><input type="checkbox" name="restriction[enabled]" value="1" <?php checked( $row['enabled'] ); ?> /> <?php esc_html_e( 'Active', 'digitizer-pro-tools' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Who may view', 'digitizer-pro-tools' ); ?></th>
						<td>
							<label style="display:block;"><input type="radio" name="restriction[who][status]" value="logged_in" <?php checked( $row['who']['status'], 'logged_in' ); ?> /> <?php esc_html_e( 'Logged-in users', 'digitizer-pro-tools' ); ?></label>
							<label style="display:block;"><input type="radio" name="restriction[who][status]" value="logged_out" <?php checked( $row['who']['status'], 'logged_out' ); ?> /> <?php esc_html_e( 'Logged-out visitors only', 'digitizer-pro-tools' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Role match', 'digitizer-pro-tools' ); ?></th>
						<td>
							<select name="restriction[who][role_match]">
								<option value="any" <?php selected( $row['who']['role_match'], 'any' ); ?>><?php esc_html_e( 'Any role - being logged in is enough', 'digitizer-pro-tools' ); ?></option>
								<option value="match" <?php selected( $row['who']['role_match'], 'match' ); ?>><?php esc_html_e( 'One of the selected roles', 'digitizer-pro-tools' ); ?></option>
								<option value="exclude" <?php selected( $row['who']['role_match'], 'exclude' ); ?>><?php esc_html_e( 'Any role EXCEPT the selected', 'digitizer-pro-tools' ); ?></option>
							</select><br>
							<?php foreach ( self::roles() as $key => $rname ) : ?>
								<label style="display:inline-block;margin-right:8px;"><input type="checkbox" name="restriction[who][roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $row['who']['roles'], true ) ); ?> /> <?php echo esc_html( $rname ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
			</div>

			<div class="dpt-panel">
				<h2><?php esc_html_e( 'Protection', 'digitizer-pro-tools' ); ?></h2>
				<table class="form-table dpt-form">
					<tr>
						<th><?php esc_html_e( 'When access is denied', 'digitizer-pro-tools' ); ?></th>
						<td>
							<label><input type="radio" name="restriction[protection][method]" value="redirect" <?php checked( $row['protection']['method'], 'redirect' ); ?> /> <?php esc_html_e( 'Redirect', 'digitizer-pro-tools' ); ?></label>
							<select name="restriction[protection][redirect_type]">
								<option value="login" <?php selected( $row['protection']['redirect_type'], 'login' ); ?>><?php esc_html_e( 'to the login form', 'digitizer-pro-tools' ); ?></option>
								<option value="home" <?php selected( $row['protection']['redirect_type'], 'home' ); ?>><?php esc_html_e( 'to the home page', 'digitizer-pro-tools' ); ?></option>
								<option value="custom" <?php selected( $row['protection']['redirect_type'], 'custom' ); ?>><?php esc_html_e( 'to this URL:', 'digitizer-pro-tools' ); ?></option>
							</select>
							<input type="url" name="restriction[protection][redirect_url]" value="<?php echo esc_attr( $row['protection']['redirect_url'] ); ?>" class="regular-text" placeholder="https://" />
							<br>
							<label><input type="radio" name="restriction[protection][method]" value="replace" <?php checked( $row['protection']['method'], 'replace' ); ?> /> <?php esc_html_e( 'Replace the content', 'digitizer-pro-tools' ); ?></label>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'restriction[protection][replacement_page]',
									'selected'          => (int) $row['protection']['replacement_page'],
									'show_option_none'  => esc_html__( '— show a message instead —', 'digitizer-pro-tools' ),
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Message', 'digitizer-pro-tools' ); ?></th>
						<td>
							<label><input type="checkbox" name="restriction[protection][override_message]" value="1" <?php checked( $row['protection']['override_message'] ); ?> /> <?php esc_html_e( 'Use a custom message for this restriction', 'digitizer-pro-tools' ); ?></label><br>
							<textarea name="restriction[protection][custom_message]" rows="3" class="large-text"><?php echo esc_textarea( $row['protection']['custom_message'] ); ?></textarea><br>
							<label><input type="checkbox" name="restriction[protection][show_excerpts]" value="1" <?php checked( $row['protection']['show_excerpts'] ); ?> /> <?php esc_html_e( 'Show the post excerpt above the message (teaser)', 'digitizer-pro-tools' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'In archives (main lists)', 'digitizer-pro-tools' ); ?></th>
						<td>
							<select name="restriction[archive_handling]">
								<option value="filter" <?php selected( $row['archive_handling'], 'filter' ); ?>><?php esc_html_e( 'Show the item with the restriction message', 'digitizer-pro-tools' ); ?></option>
								<option value="hide" <?php selected( $row['archive_handling'], 'hide' ); ?>><?php esc_html_e( 'Hide the item', 'digitizer-pro-tools' ); ?></option>
								<option value="replace_page" <?php selected( $row['archive_handling'], 'replace_page' ); ?>><?php esc_html_e( 'Replace the whole archive with a page', 'digitizer-pro-tools' ); ?></option>
								<option value="redirect" <?php selected( $row['archive_handling'], 'redirect' ); ?>><?php esc_html_e( 'Redirect away from the archive', 'digitizer-pro-tools' ); ?></option>
							</select>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'restriction[archive_page]',
									'selected'          => (int) $row['archive_page'],
									'show_option_none'  => esc_html__( '— page —', 'digitizer-pro-tools' ),
									'option_none_value' => 0,
								)
							);
							?>
							<select name="restriction[archive_redirect_type]">
								<option value="login" <?php selected( $row['archive_redirect_type'], 'login' ); ?>><?php esc_html_e( 'to the login form', 'digitizer-pro-tools' ); ?></option>
								<option value="home" <?php selected( $row['archive_redirect_type'], 'home' ); ?>><?php esc_html_e( 'to the home page', 'digitizer-pro-tools' ); ?></option>
								<option value="custom" <?php selected( $row['archive_redirect_type'], 'custom' ); ?>><?php esc_html_e( 'to this URL:', 'digitizer-pro-tools' ); ?></option>
							</select>
							<input type="url" name="restriction[archive_redirect_url]" value="<?php echo esc_attr( $row['archive_redirect_url'] ); ?>" class="regular-text" placeholder="https://" />
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'In other queries', 'digitizer-pro-tools' ); ?></th>
						<td>
							<select name="restriction[query_handling]">
								<option value="filter" <?php selected( $row['query_handling'], 'filter' ); ?>><?php esc_html_e( 'Show with the restriction message', 'digitizer-pro-tools' ); ?></option>
								<option value="hide" <?php selected( $row['query_handling'], 'hide' ); ?>><?php esc_html_e( 'Hide', 'digitizer-pro-tools' ); ?></option>
							</select>
							<label style="margin-left:12px;"><input type="checkbox" name="restriction[show_in_search]" value="1" <?php checked( $row['show_in_search'] ); ?> /> <?php esc_html_e( 'Allow in search results (otherwise always hidden there)', 'digitizer-pro-tools' ); ?></label>
						</td>
					</tr>
				</table>
			</div>

			<div class="dpt-panel">
				<h2><?php esc_html_e( 'Which content (conditions)', 'digitizer-pro-tools' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A restriction with no rules never applies. Rules that need a value take comma-separated IDs, or a template file name.', 'digitizer-pro-tools' ); ?></p>
				<p>
					<label><input type="radio" name="cond_op" value="and" <?php checked( $row['conditions']['operator'], 'and' ); ?> /> <?php esc_html_e( 'ALL rules must match (AND)', 'digitizer-pro-tools' ); ?></label>
					<label style="margin-left:12px;"><input type="radio" name="cond_op" value="or" <?php checked( $row['conditions']['operator'], 'or' ); ?> /> <?php esc_html_e( 'ANY rule may match (OR)', 'digitizer-pro-tools' ); ?></label>
				</p>
				<?php self::render_rule_rows( 'cond', $root_rules ); ?>
				<h3><?php esc_html_e( 'Group (optional, evaluated as one item of the list above)', 'digitizer-pro-tools' ); ?></h3>
				<p>
					<label><input type="radio" name="gcond_op" value="or" <?php checked( ! $group || 'or' === $group['operator'] ); ?> /> OR</label>
					<label style="margin-left:12px;"><input type="radio" name="gcond_op" value="and" <?php checked( (bool) ( $group && 'and' === $group['operator'] ) ); ?> /> AND</label>
				</p>
				<?php self::render_rule_rows( 'gcond', $group ? $group['items'] : array() ); ?>
			</div>

			<p class="dpt-actions"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save restriction', 'digitizer-pro-tools' ); ?></button></p>
		</form>
		<?php
	}
}
