<?php
/**
 * Resend Mail module - lightweight send log.
 *
 * Stored as a capped, non-autoloaded option (newest first). Webhook events
 * update entries in place by their Resend email id.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RM_Log {

	const OPTION = 'dpt_resend_mail_log';
	const MAX_ENTRIES = 100;

	/**
	 * Status progression rank: webhook events can arrive out of order, so an
	 * entry never moves backwards (a late "delivery_delayed" must not
	 * overwrite "delivered", a late "opened" must not overwrite "clicked").
	 * Every status has a distinct rank; "complained" outranks "bounced"
	 * because a spam complaint implies the email was delivered and seen.
	 */
	private static function status_rank( $status ) {
		$ranks = array(
			'failed'           => 0,
			'fallback'         => 0,
			'sent'             => 1,
			'delivery_delayed' => 2,
			'delivered'        => 3,
			'opened'           => 4,
			'clicked'          => 5,
			'bounced'          => 6,
			'complained'       => 7,
		);
		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 0;
	}

	public static function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Prepend a send attempt. $entry keys: to, subject, status, resend_id,
	 * error, note.
	 */
	public static function record( $entry ) {
		if ( '1' !== DPT_RM_Settings::get( 'log_enabled' ) ) {
			return;
		}
		$defaults = array(
			'time'      => time(),
			'to'        => '',
			'subject'   => '',
			'status'    => 'sent',
			'resend_id' => '',
			'error'     => '',
			'note'      => '',
		);
		$entry = array_merge( $defaults, is_array( $entry ) ? $entry : array() );

		$entry['to']      = self::truncate( $entry['to'], 200 );
		$entry['subject'] = self::truncate( $entry['subject'], 150 );
		$entry['error']   = self::truncate( $entry['error'], 300 );

		$entries = self::entries();
		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		self::persist( $entries );
	}

	/**
	 * Apply a webhook delivery event to the matching log entry.
	 *
	 * @return bool Whether a matching entry was found and updated.
	 */
	public static function update_status( $resend_id, $status ) {
		if ( '' === $resend_id || '' === $status ) {
			return false;
		}
		$entries = self::entries();
		$updated = false;
		foreach ( $entries as $i => $entry ) {
			$ids = isset( $entry['resend_id'] ) ? explode( ',', (string) $entry['resend_id'] ) : array();
			if ( ! in_array( $resend_id, $ids, true ) ) {
				continue;
			}
			$current = isset( $entry['status'] ) ? $entry['status'] : 'sent';
			if ( self::status_rank( $status ) >= self::status_rank( $current ) ) {
				$entries[ $i ]['status'] = $status;
				$updated = true;
			}
			break;
		}
		if ( $updated ) {
			self::persist( $entries );
		}
		return $updated;
	}

	public static function clear() {
		self::persist( array() );
	}

	private static function persist( $entries ) {
		if ( false === get_option( self::OPTION, false ) ) {
			// The log can grow to ~100 entries - keep it out of alloptions.
			add_option( self::OPTION, $entries, '', 'no' );
			return;
		}
		update_option( self::OPTION, $entries );
	}

	private static function truncate( $value, $length ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}
		return substr( $value, 0, $length );
	}
}
