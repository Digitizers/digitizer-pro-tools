<?php
/**
 * Resend Mail module - settings storage.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RM_Settings {

	const OPTION = 'dpt_resend_mail';

	public static function defaults() {
		return array(
			'api_key'           => '',
			'from_email'        => '',
			'from_name'         => '',
			// When on, every email leaves from the configured address even if
			// a plugin sets its own From header - Resend rejects senders that
			// are not on a verified domain, so this is the safe default.
			'force_from'        => '1',
			'reply_to'          => '',
			// On API errors, hand the email back to the default WordPress
			// mailer instead of dropping it.
			'fallback_on_error' => '1',
			'log_enabled'       => '1',
			// Svix signing secret (whsec_...) of the Resend webhook endpoint.
			'webhook_secret'    => '',
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		update_option( self::OPTION, array_merge( self::defaults(), $existing ) );
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * The API key, with an optional wp-config.php constant override so keys
	 * can be kept out of the database.
	 */
	public static function api_key() {
		if ( defined( 'DPT_RESEND_API_KEY' ) && is_string( DPT_RESEND_API_KEY ) && '' !== DPT_RESEND_API_KEY ) {
			return DPT_RESEND_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function has_constant_key() {
		return defined( 'DPT_RESEND_API_KEY' ) && is_string( DPT_RESEND_API_KEY ) && '' !== DPT_RESEND_API_KEY;
	}

	/**
	 * Ready to intercept wp_mail: an API key plus a valid sender address.
	 */
	public static function is_configured() {
		return '' !== self::api_key() && is_email( self::get( 'from_email' ) );
	}

	/**
	 * Masked key for display: first 5 + last 4 characters.
	 */
	public static function masked_key() {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) <= 9 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 5 ) . str_repeat( '*', 6 ) . substr( $key, -4 );
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$clean = self::all();

		// An empty key field keeps the saved key (so re-saving the page does
		// not wipe it); the explicit forget checkbox clears it.
		if ( ! empty( $raw['forget_api_key'] ) ) {
			$clean['api_key'] = '';
		} elseif ( isset( $raw['api_key'] ) && ! is_array( $raw['api_key'] ) ) {
			$key = trim( wp_unslash( $raw['api_key'] ) );
			if ( '' !== $key ) {
				$clean['api_key'] = $key;
			}
		}

		if ( isset( $raw['from_email'] ) && ! is_array( $raw['from_email'] ) ) {
			$email = sanitize_email( wp_unslash( $raw['from_email'] ) );
			$clean['from_email'] = is_email( $email ) ? $email : '';
		}

		if ( isset( $raw['from_name'] ) && ! is_array( $raw['from_name'] ) ) {
			$clean['from_name'] = sanitize_text_field( wp_unslash( $raw['from_name'] ) );
		}

		if ( isset( $raw['reply_to'] ) && ! is_array( $raw['reply_to'] ) ) {
			$reply = sanitize_email( wp_unslash( $raw['reply_to'] ) );
			$clean['reply_to'] = is_email( $reply ) ? $reply : '';
		}

		if ( isset( $raw['webhook_secret'] ) && ! is_array( $raw['webhook_secret'] ) ) {
			$clean['webhook_secret'] = trim( sanitize_text_field( wp_unslash( $raw['webhook_secret'] ) ) );
		}

		foreach ( array( 'force_from', 'fallback_on_error', 'log_enabled' ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$clean[ $flag ] = '1' === $raw[ $flag ] ? '1' : '0';
			}
		}

		update_option( self::OPTION, $clean );
		return true;
	}
}
