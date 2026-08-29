<?php
/**
 * Agent Log module - the screen.
 *
 * The API is the product; this exists so that a person looking at a site can
 * answer the question without a terminal. Read-only apart from the one
 * button that empties the log.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Admin {

	const PAGE_SLUG = 'dpt-agent-log';

	public function __construct() {
		add_action( 'admin_post_dpt_al_clear', array( $this, 'handle_clear' ) );
	}

	public function register_menu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Agent Log', 'digitizer-pro-tools' ),
			__( 'Agent Log', 'digitizer-pro-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * A filter value from the query string, or '' when absent or malformed.
	 *
	 * sanitize_key() would hand an array-valued parameter to string functions
	 * and raise a TypeError on PHP 8, so the scalar check comes first - the
	 * same guard dpt_current_admin_page() uses for the same reason.
	 *
	 * @param string $key Parameter name.
	 * @return string
	 */
	private function filter_arg( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * A calendar date from the query string, or '' when absent or malformed.
	 *
	 * The same scalar guard as filter_arg() and for the same reason - an
	 * array-valued parameter must not reach a string function - and then a
	 * format check, because the store turns this into a strtotime() call and
	 * "2026-13-45" or "next tuesday" reaching that would silently produce a
	 * range the administrator did not ask for. createFromFormat() is lenient
	 * about overflow (it rolls 2026-13-45 into 2027-02-14), so the parsed
	 * date is formatted back and compared: only a string that survives that
	 * round trip is the date it claims to be.
	 *
	 * @param string $key Parameter name.
	 * @return string A Y-m-d date, or ''.
	 */
	private function date_arg( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of a read-only screen.
		$value = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
		if ( '' === $value ) {
			return '';
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return '';
		}
		return $value;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}

		$args = array( 'per_page' => 100 );
		foreach ( array( 'channel', 'object_type' ) as $key ) {
			$value = $this->filter_arg( $key );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		// The date range. The screen shows the newest hundred rows, so without
		// these an administrator looking into an incident cannot narrow to the
		// window it happened in at all. Whole days, and inclusive at both ends:
		// someone who types the same date in both boxes means that day, not an
		// empty range from its midnight to its midnight. The store bounds
		// logged_at, which is stored in UTC, so the day boundaries are UTC too.
		$after  = $this->date_arg( 'after' );
		$before = $this->date_arg( 'before' );
		if ( '' !== $after ) {
			$args['after'] = $after . ' 00:00:00';
		}
		if ( '' !== $before ) {
			$args['before'] = $before . ' 23:59:59';
		}

		// Values outside the enums contribute nothing to the query, so a
		// hand-edited URL narrows the list or does nothing - never widens it.
		$rows = DPT_AL_Store::query( $args );

		// The 'When' column is rendered with wp_date(), not date_i18n().
		// $stamp below is a true Unix timestamp - strtotime() on a UTC string -
		// and date_i18n() does not take one of those: its signature is
		// date_i18n( $format, $timestamp_with_offset ), and core's own docblock
		// calls that "a sum of Unix timestamp and timezone offset in seconds"
		// (wp-includes/functions.php:173). Given a real timestamp it takes the
		// legacy branch at line 203, gmdate()s the value and re-reads that
		// wall-clock string in the site timezone, which hands back the UTC time
		// wearing a local label: a row stored at 12:00 UTC would read 12:00 in
		// Jerusalem instead of 15:00. wp_date() was written for exactly this -
		// "unlike date_i18n(), this function accepts a true Unix timestamp, not
		// summed with timezone offset" (line 230).
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Log', 'digitizer-pro-tools' ); ?></h1>
			<p><?php esc_html_e( 'Changes that arrived from somewhere other than a browser: the REST API, WP-Cron, WP-CLI or XML-RPC. Work done by a person in the admin is not recorded here.', 'digitizer-pro-tools' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="channel">
					<option value=""><?php esc_html_e( 'Every channel', 'digitizer-pro-tools' ); ?></option>
					<?php foreach ( array( 'rest', 'cron', 'cli', 'xmlrpc' ) as $channel ) : ?>
						<option value="<?php echo esc_attr( $channel ); ?>" <?php selected( isset( $args['channel'] ) ? $args['channel'] : '', $channel ); ?>><?php echo esc_html( $channel ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="object_type">
					<option value=""><?php esc_html_e( 'Everything', 'digitizer-pro-tools' ); ?></option>
					<?php foreach ( array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' ) as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( isset( $args['object_type'] ) ? $args['object_type'] : '', $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="dpt-al-after" class="screen-reader-text"><?php esc_html_e( 'From', 'digitizer-pro-tools' ); ?></label>
				<input type="date" id="dpt-al-after" name="after" value="<?php echo esc_attr( $after ); ?>" placeholder="<?php esc_attr_e( 'From', 'digitizer-pro-tools' ); ?>" />
				<label for="dpt-al-before" class="screen-reader-text"><?php esc_html_e( 'To', 'digitizer-pro-tools' ); ?></label>
				<input type="date" id="dpt-al-before" name="before" value="<?php echo esc_attr( $before ); ?>" placeholder="<?php esc_attr_e( 'To', 'digitizer-pro-tools' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'digitizer-pro-tools' ); ?></button>
			</form>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Application', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'What', 'digitizer-pro-tools' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'digitizer-pro-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet. Either no automation has changed anything here, or the module was switched on after it did.', 'digitizer-pro-tools' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$fields = json_decode( isset( $row->fields ) ? (string) $row->fields : '[]', true );
						$stamp  = strtotime( ( isset( $row->logged_at ) ? $row->logged_at : '' ) . ' UTC' );
						?>
						<tr>
							<td><?php echo esc_html( $stamp ? wp_date( $format, $stamp ) : '' ); ?></td>
							<td><?php echo esc_html( isset( $row->channel ) ? $row->channel : '' ); ?></td>
							<td><?php echo esc_html( isset( $row->app ) && '' !== $row->app ? $row->app : '-' ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: action, 2: object type, 3: object name or id */
									esc_html__( '%1$s %2$s %3$s', 'digitizer-pro-tools' ),
									esc_html( isset( $row->action ) ? $row->action : '' ),
									esc_html( isset( $row->object_subtype ) && '' !== $row->object_subtype ? $row->object_subtype : ( isset( $row->object_type ) ? $row->object_type : '' ) ),
									esc_html( isset( $row->object_name ) && '' !== $row->object_name ? $row->object_name : '#' . ( isset( $row->object_id ) ? (int) $row->object_id : 0 ) )
								);
								?>
							</td>
							<td><?php echo esc_html( is_array( $fields ) ? implode( ', ', array_map( 'strval', $fields ) ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dpt_al_clear' ); ?>
				<input type="hidden" name="action" value="dpt_al_clear" />
				<p><button type="submit" class="button"><?php esc_html_e( 'Clear the log', 'digitizer-pro-tools' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'digitizer-pro-tools' ) );
		}
		check_admin_referer( 'dpt_al_clear' );

		DPT_AL_Store::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
