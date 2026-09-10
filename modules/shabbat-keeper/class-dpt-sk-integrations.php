<?php
/**
 * Refuses purchases and form submissions while closed. The server-side
 * refusal is the truth; replacing the markup is a courtesy so a visitor
 * is told before trying.
 *
 * Every hook checks DPT_SK_Enforce::applies() - closed and not exempt -
 * and nothing else, so the Store API and AJAX submissions are covered.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Integrations {

	const FORM_SHORTCODES = array( 'contact-form-7', 'wpforms', 'gravityform' );

	public static function register() {
		add_action( 'init', array( __CLASS__, 'hook_plugins' ), 20 );
	}

	/** Hook only the plugins that are actually loaded, and only the switches that are on. */
	public static function hook_plugins() {
		if ( '1' === DPT_SK_Settings::get( 'block_woo' ) && class_exists( 'WooCommerce' ) ) {
			add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable' ), 10, 2 );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_notice' ), 31 );
			add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 10, 2 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'refuse_add_to_cart' ), 10, 2 );
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'refuse_checkout' ) );
			add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
		}
		if ( '1' === DPT_SK_Settings::get( 'block_forms' ) ) {
			if ( class_exists( '\ElementorPro\Plugin' ) ) {
				add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor_refuse' ), 10, 2 );
				add_filter( 'elementor/widget/render_content', array( __CLASS__, 'elementor_markup' ), 10, 2 );
			}
			if ( function_exists( 'wpcf7' ) ) {
				add_filter( 'wpcf7_validate', array( __CLASS__, 'cf7_refuse' ), 10, 2 );
			}
			if ( function_exists( 'wpforms' ) ) {
				add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms_refuse' ), 10, 2 );
			}
			if ( class_exists( 'GFForms' ) ) {
				add_filter( 'gform_validation', array( __CLASS__, 'gf_refuse' ) );
				add_filter( 'gform_validation_message', array( __CLASS__, 'gf_message' ), 10, 2 );
			}
			add_filter( 'do_shortcode_tag', array( __CLASS__, 'shortcode_markup' ), 10, 2 );
		}
	}

	/** The one sentence every refusal shows. */
	public static function message() {
		return sprintf( DPT_SK_Settings::text( 'banner_text' ), DPT_SK_Enforce::reopens_text() );
	}

	public static function closed_markup() {
		return '<div class="dpt-sk-closed-form"><p>' . esc_html( self::message() ) . '</p></div>';
	}

	/* ---------------- WooCommerce ---------------- */

	public static function not_purchasable( $purchasable, $product = null ) {
		return DPT_SK_Enforce::applies() ? false : $purchasable;
	}

	public static function product_notice() {
		if ( DPT_SK_Enforce::applies() ) {
			echo '<p class="dpt-sk-closed-notice">' . esc_html( self::message() ) . '</p>';
		}
	}

	public static function loop_button( $link, $product = null ) {
		if ( ! DPT_SK_Enforce::applies() ) {
			return $link;
		}
		return '<span class="button dpt-sk-closed">' . esc_html( DPT_SK_Settings::text( 'closed_title' ) ) . '</span>';
	}

	public static function refuse_add_to_cart( $passed, $product_id = 0 ) {
		if ( ! DPT_SK_Enforce::applies() ) {
			return $passed;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
		return false;
	}

	public static function refuse_checkout() {
		if ( DPT_SK_Enforce::applies() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	public static function store_api_errors( $errors, $cart = null ) {
		if ( DPT_SK_Enforce::applies() && $errors instanceof WP_Error ) {
			$errors->add( 'dpt_shabbat_keeper', self::message() );
		}
		return $errors;
	}

	/* ---------------- forms ---------------- */

	public static function elementor_refuse( $record, $ajax_handler ) {
		if ( DPT_SK_Enforce::applies() && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( self::message() );
		}
	}

	public static function elementor_markup( $content, $widget = null ) {
		if ( DPT_SK_Enforce::applies() && is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'form' === $widget->get_name() ) {
			return self::closed_markup();
		}
		return $content;
	}

	public static function cf7_refuse( $result, $tags = array() ) {
		if ( DPT_SK_Enforce::applies() && is_object( $result ) && method_exists( $result, 'invalidate' ) && ! empty( $tags ) ) {
			$result->invalidate( reset( $tags ), self::message() );
		}
		return $result;
	}

	public static function wpforms_refuse( $entry, $form_data = array() ) {
		if ( ! DPT_SK_Enforce::applies() || ! function_exists( 'wpforms' ) ) {
			return;
		}
		$id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		$wp = wpforms();
		if ( is_object( $wp ) && isset( $wp->process ) && is_object( $wp->process ) ) {
			if ( ! isset( $wp->process->errors ) || ! is_array( $wp->process->errors ) ) {
				$wp->process->errors = array();
			}
			$wp->process->errors[ $id ]['header'] = self::message();
		}
	}

	public static function gf_refuse( $result ) {
		if ( DPT_SK_Enforce::applies() && is_array( $result ) ) {
			$result['is_valid'] = false;
		}
		return $result;
	}

	public static function gf_message( $message, $form = null ) {
		return DPT_SK_Enforce::applies() ? '<div class="validation_error">' . esc_html( self::message() ) . '</div>' : $message;
	}

	public static function shortcode_markup( $output, $tag = '' ) {
		if ( DPT_SK_Enforce::applies() && in_array( $tag, self::FORM_SHORTCODES, true ) ) {
			return self::closed_markup();
		}
		return $output;
	}
}
