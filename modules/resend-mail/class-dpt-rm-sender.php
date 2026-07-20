<?php
/**
 * Resend Mail module - wp_mail() interception and Resend API delivery.
 *
 * Uses the pre_wp_mail short-circuit filter (WP 5.7+) instead of redefining
 * the pluggable wp_mail(), so the module can be toggled per site and never
 * fatals against another mail plugin that already defines it.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_RM_Sender {

	const API_URL = 'https://api.resend.com/emails';

	// Resend caps 50 recipients per address field and 40MB per email total;
	// leave headroom for base64 (+33%) and the rest of the payload.
	const MAX_RECIPIENTS_PER_CALL = 50;
	const MAX_ATTACHMENT_BYTES    = 26214400; // 25MB of raw files

	/**
	 * Outcome of the most recent short_circuit() call: 'sent', 'fallback',
	 * 'failed', 'passthrough' (not configured / unbuildable payload) or null
	 * (no call yet). Lets the admin test tell a real Resend delivery apart
	 * from one the default mailer rescued.
	 *
	 * @var string|null
	 */
	public static $last_result = null;

	public function __construct() {
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit' ), 10, 2 );
	}

	/**
	 * pre_wp_mail handler. Returning null lets core wp_mail() run (used both
	 * when the module is not configured and as the error fallback); returning
	 * a boolean short-circuits wp_mail() with that result.
	 */
	public function short_circuit( $return, $atts ) {
		self::$last_result = 'passthrough';
		if ( null !== $return ) {
			// Another plugin already claimed this email.
			return $return;
		}
		if ( ! DPT_RM_Settings::is_configured() ) {
			return $return;
		}

		$payload = $this->build_payload( $atts );
		if ( null === $payload ) {
			// Unbuildable for the API (no recipients, invalid sender,
			// oversized attachments) - let the default mailer try.
			return $return;
		}

		$result = $this->deliver( $payload );

		$to_display = implode( ', ', $payload['to'] );
		$subject    = isset( $payload['subject'] ) ? $payload['subject'] : '';

		if ( is_wp_error( $result ) ) {
			if ( '1' === DPT_RM_Settings::get( 'fallback_on_error' ) ) {
				self::$last_result = 'fallback';
				DPT_RM_Log::record( array(
					'to'      => $to_display,
					'subject' => $subject,
					'status'  => 'fallback',
					'error'   => $result->get_error_message(),
				) );
				return null; // Core wp_mail() proceeds with the default mailer.
			}
			self::$last_result = 'failed';
			DPT_RM_Log::record( array(
				'to'      => $to_display,
				'subject' => $subject,
				'status'  => 'failed',
				'error'   => $result->get_error_message(),
			) );
			// Mirror core's failure contract so listeners (logging plugins,
			// form plugins showing "could not send") keep working.
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $result->get_error_message(), $atts ) );
			return false;
		}

		if ( '' !== $result['partial_error'] ) {
			// Part of the recipients never got the email - that is a failed
			// wp_mail() call, not a success. No fallback here either: it
			// would double-send to the batches Resend already accepted.
			self::$last_result = 'failed';
			DPT_RM_Log::record( array(
				'to'        => $to_display,
				'subject'   => $subject,
				'status'    => 'failed',
				'resend_id' => implode( ',', $result['ids'] ),
				'error'     => $result['partial_error'],
			) );
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $result['partial_error'], $atts ) );
			return false;
		}

		self::$last_result = 'sent';
		DPT_RM_Log::record( array(
			'to'        => $to_display,
			'subject'   => $subject,
			'status'    => 'sent',
			'resend_id' => implode( ',', $result['ids'] ),
		) );
		// Mirror core's success contract too - audit/metrics listeners on
		// wp_mail_succeeded must keep seeing short-circuited deliveries.
		do_action( 'wp_mail_succeeded', $atts );
		return true;
	}

	/**
	 * Translate wp_mail() arguments into a Resend /emails payload.
	 *
	 * @param array $atts wp_mail attributes: to, subject, message, headers, attachments.
	 * @return array|null Payload, or null when this email should go to the default mailer.
	 */
	public function build_payload( $atts ) {
		$to = isset( $atts['to'] ) ? $atts['to'] : array();
		if ( is_string( $to ) ) {
			$to = explode( ',', $to );
		}
		$to = array_values( array_filter( array_map( 'trim', (array) $to ) ) );
		if ( empty( $to ) ) {
			return null;
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : '' );

		// Sender resolution. force_from pins the verified address; otherwise
		// mirror core wp_mail(): an explicit From header replaces the
		// defaults first, then the wp_mail_from filters get the final say -
		// sites use those filters to enforce a verified sender domain.
		$from_email = (string) DPT_RM_Settings::get( 'from_email' );
		$from_name  = (string) DPT_RM_Settings::get( 'from_name' );
		if ( '1' !== DPT_RM_Settings::get( 'force_from' ) ) {
			if ( '' !== $headers['from_email'] ) {
				$from_email = $headers['from_email'];
				$from_name  = $headers['from_name'];
			}
			$from_email = apply_filters( 'wp_mail_from', $from_email );
			$from_name  = apply_filters( 'wp_mail_from_name', $from_name );
		}
		if ( ! is_email( $from_email ) ) {
			return null;
		}

		$payload = array(
			'from'    => '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email,
			'to'      => $to,
			'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
		);

		$content_type = '' !== $headers['content_type'] ? $headers['content_type'] : 'text/plain';
		$content_type = apply_filters( 'wp_mail_content_type', $content_type );
		$message      = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		if ( false !== strpos( (string) $content_type, 'text/html' ) ) {
			$payload['html'] = $message;
		} else {
			$payload['text'] = $message;
		}

		// Unlike To (which deliver() chunks), Cc/Bcc cannot be split without
		// changing what recipients see - over the API's per-field cap the
		// whole email goes to the default mailer, never dropping recipients.
		if ( count( $headers['cc'] ) > self::MAX_RECIPIENTS_PER_CALL || count( $headers['bcc'] ) > self::MAX_RECIPIENTS_PER_CALL ) {
			return null;
		}
		if ( ! empty( $headers['cc'] ) ) {
			$payload['cc'] = $headers['cc'];
		}
		if ( ! empty( $headers['bcc'] ) ) {
			$payload['bcc'] = $headers['bcc'];
		}

		$reply_to = $headers['reply_to'];
		if ( empty( $reply_to ) && is_email( DPT_RM_Settings::get( 'reply_to' ) ) ) {
			$reply_to = array( DPT_RM_Settings::get( 'reply_to' ) );
		}
		if ( ! empty( $reply_to ) ) {
			$payload['reply_to'] = $reply_to;
		}

		if ( ! empty( $headers['custom'] ) ) {
			$payload['headers'] = $headers['custom'];
		}

		$attachments = $this->build_attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );
		if ( null === $attachments ) {
			return null; // Oversized for the API - default mailer handles it.
		}
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		return $payload;
	}

	/**
	 * wp_mail-style headers (string or array) into the parts we map onto the
	 * API payload. Unknown headers ride along as custom headers.
	 */
	public function parse_headers( $headers ) {
		$parsed = array(
			'from_email'   => '',
			'from_name'    => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => '',
			'custom'       => array(),
		);
		if ( empty( $headers ) ) {
			return $parsed;
		}
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $content ) = explode( ':', trim( $header ), 2 );
			$key     = strtolower( trim( $name ) );
			$content = trim( $content );
			if ( '' === $content ) {
				continue;
			}
			switch ( $key ) {
				case 'from':
					if ( preg_match( '/(.*)<(.+)>/', $content, $m ) ) {
						$parsed['from_name']  = trim( trim( $m[1] ), " \t\"'" );
						$parsed['from_email'] = trim( $m[2] );
					} else {
						$parsed['from_email'] = $content;
					}
					break;
				case 'cc':
				case 'bcc':
					$parsed[ $key ] = array_merge( $parsed[ $key ], $this->split_addresses( $content ) );
					break;
				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], $this->split_addresses( $content ) );
					break;
				case 'content-type':
					$parts = explode( ';', $content );
					$parsed['content_type'] = strtolower( trim( $parts[0] ) );
					break;
				case 'mime-version':
					// Transport detail, meaningless on an API call.
					break;
				default:
					$parsed['custom'][ trim( $name ) ] = $content;
			}
		}
		return $parsed;
	}

	private function split_addresses( $content ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', $content ) ) ) );
	}

	/**
	 * wp_mail attachments (file paths; string keys are desired filenames
	 * since WP 6.2) into base64 API attachments. Unreadable files are
	 * skipped - same best-effort PHPMailer applies. Returns null when the
	 * total is too large for the API.
	 *
	 * @return array|null
	 */
	private function build_attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		$built = array();
		$total = 0;
		foreach ( $attachments as $name => $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}
			$path = trim( $path );
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$total += (int) filesize( $path );
			if ( $total > self::MAX_ATTACHMENT_BYTES ) {
				return null;
			}
			$contents = file_get_contents( $path );
			if ( false === $contents ) {
				continue;
			}
			$built[] = array(
				'filename' => is_string( $name ) && '' !== $name ? $name : wp_basename( $path ),
				'content'  => base64_encode( $contents ),
			);
		}
		return $built;
	}

	/**
	 * Send the payload, chunking the To list to the API's per-call limit.
	 * Cc/Bcc ride on the first chunk only, so copied recipients get exactly
	 * one copy.
	 *
	 * @return array|WP_Error array( 'ids' => string[], 'note' => string ) when
	 *                        at least one chunk was accepted.
	 */
	public function deliver( $payload ) {
		$chunks = array_chunk( $payload['to'], self::MAX_RECIPIENTS_PER_CALL );
		$ids    = array();
		$errors = array();
		foreach ( $chunks as $i => $chunk ) {
			$body = $payload;
			$body['to'] = $chunk;
			if ( $i > 0 ) {
				unset( $body['cc'], $body['bcc'] );
			}
			$result = $this->call_api( $body );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$ids[] = $result;
			}
		}
		if ( empty( $ids ) ) {
			return new WP_Error( 'dpt_rm_api_error', implode( ' | ', $errors ) );
		}
		$partial_error = '';
		if ( ! empty( $errors ) ) {
			// Some chunks were accepted, some rejected. Falling back would
			// double-send to the accepted recipients, so the caller reports
			// this as a failure instead of claiming everyone got the email.
			$partial_error = sprintf( '%d of %d recipient batches were rejected (the rest were delivered): %s', count( $errors ), count( $chunks ), implode( ' | ', $errors ) );
		}
		return array( 'ids' => $ids, 'partial_error' => $partial_error );
	}

	/**
	 * One POST /emails call.
	 *
	 * @return string|WP_Error The Resend email id.
	 */
	protected function call_api( $body ) {
		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . DPT_RM_Settings::api_key(),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $data ) && ! empty( $data['id'] ) ) {
			return (string) $data['id'];
		}
		$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : sprintf( 'Unexpected API response (HTTP %d)', $code );
		return new WP_Error( 'dpt_rm_api_error', $message );
	}
}
