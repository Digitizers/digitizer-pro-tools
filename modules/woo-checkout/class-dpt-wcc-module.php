<?php
/**
 * WooCommerce Checkout module - email-typo suggestions and Israeli phone-number
 * validation on the checkout billing fields.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-wcc-settings.php';
require_once __DIR__ . '/class-dpt-wcc-admin.php';

class DPT_Woo_Checkout_Module extends DPT_Module {

	/** @var DPT_WCC_Admin */
	private $admin;

	public function id() {
		return 'woo_checkout';
	}

	public function title() {
		return __( 'WooCommerce Checkout', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Checkout helpers for WooCommerce: suggest corrections for mistyped email domains, and validate Israeli phone numbers (client-side hint plus a server-side check).', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function install_defaults() {
		DPT_WCC_Settings::install_defaults();
	}

	public function init() {
		// Admin settings page loads regardless of WooCommerce.
		if ( is_admin() ) {
			$this->admin = new DPT_WCC_Admin();
		}

		// Front-end behaviour needs WooCommerce.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( DPT_WCC_Settings::is_on( 'phone_validation' ) ) {
			add_action( 'woocommerce_checkout_process', array( $this, 'validate_phone_server' ) );
		}
	}

	/**
	 * Enqueue the checkout script/styles, but only on the checkout page and
	 * only when at least one feature is enabled.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		$email_on = DPT_WCC_Settings::is_on( 'email_suggestion' );
		$phone_on = DPT_WCC_Settings::is_on( 'phone_validation' );
		if ( ! $email_on && ! $phone_on ) {
			return;
		}

		wp_enqueue_style(
			'dpt-wcc',
			DPT_URL . 'modules/woo-checkout/assets/css/checkout.css',
			array(),
			DPT_VERSION
		);
		wp_enqueue_script(
			'dpt-wcc',
			DPT_URL . 'modules/woo-checkout/assets/js/checkout.js',
			array( 'jquery' ),
			DPT_VERSION,
			true
		);

		wp_localize_script(
			'dpt-wcc',
			'DPTWooCheckout',
			array(
				'emailEnabled' => $email_on ? 1 : 0,
				'phoneEnabled' => $phone_on ? 1 : 0,
				'domains'      => array_values( DPT_WCC_Settings::email_domains() ),
				'i18n'         => array(
					/* translators: %s: suggested email address */
					'emailSuggest' => __( 'Did you mean %s?', 'digitizer-pro-tools' ),
					/* translators: 1: number of digits, 2: dialing prefix */
					'phoneMissing' => __( 'The number is %1$d digit(s) short for a number starting with %2$s.', 'digitizer-pro-tools' ),
					/* translators: 1: number of digits, 2: dialing prefix */
					'phoneExtra'   => __( 'The number has %1$d digit(s) too many for a number starting with %2$s.', 'digitizer-pro-tools' ),
					'phoneFormat'  => __( 'The phone number must start with 05, 972 or +972.', 'digitizer-pro-tools' ),
				),
			)
		);
	}

	/**
	 * Server-side (authoritative) Israeli phone-number validation. Adds a
	 * WooCommerce notice, which halts checkout, on a malformed number.
	 */
	public function validate_phone_server() {
		// Nonce is verified by WooCommerce core before this hook fires.
		$phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $phone ) {
			return; // "required" handling is WooCommerce's job.
		}

		$error = self::phone_error( $phone );
		if ( null !== $error && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * Validate an Israeli phone number. Returns an error message, or null when
	 * valid. Filterable so a site can replace the rule set.
	 *
	 * @param string $phone Raw phone input.
	 * @return string|null
	 */
	public static function phone_error( $phone ) {
		$cleaned = preg_replace( '/[^\d+]/', '', (string) $phone );

		$error = null;
		if ( 0 === strpos( $cleaned, '+972' ) ) {
			if ( 13 !== strlen( $cleaned ) ) {
				$error = __( 'A phone number starting with +972 must contain 13 digits.', 'digitizer-pro-tools' );
			}
		} elseif ( 0 === strpos( $cleaned, '972' ) ) {
			if ( 12 !== strlen( $cleaned ) ) {
				$error = __( 'A phone number starting with 972 must contain 12 digits.', 'digitizer-pro-tools' );
			}
		} elseif ( 0 === strpos( $cleaned, '05' ) ) {
			if ( 10 !== strlen( $cleaned ) ) {
				$error = __( 'A phone number starting with 05 must contain 10 digits.', 'digitizer-pro-tools' );
			}
		} else {
			$error = __( 'The phone number must start with 05, 972 or +972.', 'digitizer-pro-tools' );
		}

		return apply_filters( 'dpt_wcc_phone_error', $error, $cleaned, $phone );
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
