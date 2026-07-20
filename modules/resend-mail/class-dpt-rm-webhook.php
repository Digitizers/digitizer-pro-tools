<?php
/**
 * Resend Mail module - delivery-status webhook endpoint.
 *
 * Resend signs webhooks with Svix: the svix-signature header carries one or
 * more "v1,<base64 HMAC-SHA256>" candidates over "<id>.<timestamp>.<body>"
 * keyed with the base64-decoded part of the whsec_ secret.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RM_Webhook {

	const ROUTE_NAMESPACE = 'dpt/v1';
	const ROUTE           = '/resend-webhook';

	// Reject events signed more than 5 minutes away from now (replay guard).
	const TIMESTAMP_TOLERANCE = 300;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public static function endpoint_url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	public function register_route() {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			// Authentication is the Svix signature check inside handle() -
			// webhooks are machine-to-machine calls with no WP user.
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$secret = (string) DPT_RM_Settings::get( 'webhook_secret' );
		if ( '' === $secret ) {
			// No secret configured: refuse instead of trusting unsigned input.
			return new WP_REST_Response( array( 'error' => 'webhook secret not configured' ), 403 );
		}

		$body = $request->get_body();
		$ok   = self::verify_signature(
			$secret,
			(string) $request->get_header( 'svix-id' ),
			(string) $request->get_header( 'svix-timestamp' ),
			(string) $request->get_header( 'svix-signature' ),
			$body,
			time()
		);
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$event = json_decode( $body, true );
		$type  = is_array( $event ) && isset( $event['type'] ) ? (string) $event['type'] : '';
		$id    = is_array( $event ) && isset( $event['data']['email_id'] ) ? (string) $event['data']['email_id'] : '';

		$status = self::map_status( $type );
		if ( '' !== $status && '' !== $id ) {
			DPT_RM_Log::update_status( $id, $status );
		}

		// Always 200 on verified events, even unmapped types - otherwise
		// Resend keeps retrying events we simply do not track.
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Svix signature verification, side-effect free for testability.
	 */
	public static function verify_signature( $secret, $svix_id, $svix_timestamp, $svix_signature, $body, $now ) {
		if ( '' === $svix_id || '' === $svix_timestamp || '' === $svix_signature ) {
			return false;
		}
		if ( ! preg_match( '/^\d+$/', $svix_timestamp ) ) {
			return false;
		}
		if ( abs( $now - (int) $svix_timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return false;
		}

		$key = $secret;
		if ( 0 === strpos( $secret, 'whsec_' ) ) {
			$key = base64_decode( substr( $secret, 6 ), true );
			if ( false === $key ) {
				return false;
			}
		}

		$signed_content = $svix_id . '.' . $svix_timestamp . '.' . $body;
		$expected       = base64_encode( hash_hmac( 'sha256', $signed_content, $key, true ) );

		// Header format: space-separated candidates, each "v1,<signature>".
		foreach ( explode( ' ', $svix_signature ) as $candidate ) {
			$parts = explode( ',', $candidate, 2 );
			if ( 2 === count( $parts ) && 'v1' === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resend event type -> log status ('' = event we do not track).
	 */
	public static function map_status( $type ) {
		$map = array(
			'email.sent'             => 'sent',
			'email.delivered'        => 'delivered',
			'email.delivery_delayed' => 'delivery_delayed',
			'email.opened'           => 'opened',
			'email.clicked'          => 'clicked',
			'email.bounced'          => 'bounced',
			'email.complained'       => 'complained',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}
}
