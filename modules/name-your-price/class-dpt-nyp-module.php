<?php
/**
 * Name Your Price module - customer-set pricing on chosen WooCommerce products,
 * with server-enforced min/max.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-nyp-settings.php';
require_once __DIR__ . '/class-dpt-nyp-admin.php';

class DPT_Name_Your_Price_Module extends DPT_Module {

	/** @var DPT_NYP_Admin */
	private $admin;

	public function id() {
		return 'name_your_price';
	}

	public function title() {
		return __( 'Name Your Price', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Let customers set their own price on chosen WooCommerce products (donations / pay-what-you-want), with optional minimum, maximum and suggested prices enforced on the server.', 'digitizer-pro-tools' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function install_defaults() {
		DPT_NYP_Settings::install_defaults();
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_NYP_Admin();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Product edit screen: NYP fields on the General tab.
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );

		// A donation / pay-what-you-want product often has no regular price, so
		// WooCommerce would treat it as non-purchasable. Give it a base price
		// (its suggested/default/minimum, else 0) so WooCommerce's own
		// purchasability check passes - WITHOUT overriding the purchasability
		// filter, so a catalog-mode / membership plugin's veto still wins.
		add_filter( 'woocommerce_product_get_price', array( $this, 'product_price' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 10, 2 );
		// In archives, send NYP products to the single-product page instead of
		// an AJAX quick-add: the price input only exists on the product form, so
		// a direct quick-add has no price and would be rejected.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_add_to_cart_link' ), 10, 2 );

		// Front-end: price input + cart wiring.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_price_input' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'cart_item_from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_custom_prices' ), 20 );
	}

	// --- Admin product fields ---------------------------------------------

	public function render_product_fields() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$id = (int) $post->ID;
		echo '<div class="options_group dpt-nyp-fields show_if_simple">';

		woocommerce_wp_checkbox( array(
			'id'          => DPT_NYP_Settings::META_ENABLED,
			'label'       => __( 'Name Your Price', 'digitizer-pro-tools' ),
			'description' => __( 'Let the customer set the price for this product.', 'digitizer-pro-tools' ),
			'value'       => DPT_NYP_Settings::is_nyp( $id ) ? 'yes' : 'no',
		) );

		$fields = array(
			DPT_NYP_Settings::META_MIN       => __( 'Minimum price', 'digitizer-pro-tools' ),
			DPT_NYP_Settings::META_MAX       => __( 'Maximum price', 'digitizer-pro-tools' ),
			DPT_NYP_Settings::META_SUGGESTED => __( 'Suggested price', 'digitizer-pro-tools' ),
			DPT_NYP_Settings::META_DEFAULT   => __( 'Default (pre-filled) price', 'digitizer-pro-tools' ),
		);
		foreach ( $fields as $key => $label ) {
			$val = get_post_meta( $id, $key, true );
			woocommerce_wp_text_input( array(
				'id'                => $key,
				'label'             => $label . ( function_exists( 'get_woocommerce_currency_symbol' ) ? ' (' . get_woocommerce_currency_symbol() . ')' : '' ),
				'value'             => '' === $val ? '' : wc_format_localized_price( $val ),
				'data_type'         => 'price',
				'wrapper_class'     => 'dpt-nyp-price-field',
			) );
		}

		echo '</div>';
	}

	public function save_product_fields( $product_id ) {
		$product_id = (int) $product_id;

		// Capability + nonce are enforced by WooCommerce before this hook.
		$enabled = ( isset( $_POST[ DPT_NYP_Settings::META_ENABLED ] ) && 'yes' === $_POST[ DPT_NYP_Settings::META_ENABLED ] ) ? 'yes' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, DPT_NYP_Settings::META_ENABLED, $enabled );

		$prices = array();
		foreach ( array( DPT_NYP_Settings::META_MIN, DPT_NYP_Settings::META_MAX, DPT_NYP_Settings::META_SUGGESTED, DPT_NYP_Settings::META_DEFAULT ) as $key ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$prices[ $key ] = DPT_NYP_Settings::sanitize_price( $raw );
		}

		// Cross-field validation: an inverted range (min > max) would make every
		// price invalid, so drop the maximum and keep an open-ended minimum.
		if ( null !== $prices[ DPT_NYP_Settings::META_MIN ] && null !== $prices[ DPT_NYP_Settings::META_MAX ]
			&& $prices[ DPT_NYP_Settings::META_MIN ] > $prices[ DPT_NYP_Settings::META_MAX ] ) {
			$prices[ DPT_NYP_Settings::META_MAX ] = null;
		}

		// A maximum that rounds to zero (e.g. 0 or 0.001 in a two-decimal
		// currency) would reject every possible amount unless free is explicitly
		// allowed (an explicit minimum of 0). Otherwise drop it (open-ended).
		if ( null !== $prices[ DPT_NYP_Settings::META_MAX ]
			&& round( $prices[ DPT_NYP_Settings::META_MAX ], DPT_NYP_Settings::price_decimals() ) <= 0 ) {
			$explicit_zero_min = ( null !== $prices[ DPT_NYP_Settings::META_MIN ] && 0.0 === (float) $prices[ DPT_NYP_Settings::META_MIN ] );
			if ( ! $explicit_zero_min ) {
				$prices[ DPT_NYP_Settings::META_MAX ] = null;
			}
		}

		// Keep the suggested/default (pre-filled) prices inside the range, so the
		// storefront never pre-fills a value that would be rejected at add-to-cart.
		$min = $prices[ DPT_NYP_Settings::META_MIN ];
		$max = $prices[ DPT_NYP_Settings::META_MAX ];
		foreach ( array( DPT_NYP_Settings::META_SUGGESTED, DPT_NYP_Settings::META_DEFAULT ) as $key ) {
			if ( null === $prices[ $key ] ) {
				continue;
			}
			if ( null !== $min && $prices[ $key ] < $min ) {
				$prices[ $key ] = $min;
			}
			if ( null !== $max && $prices[ $key ] > $max ) {
				$prices[ $key ] = $max;
			}
		}

		foreach ( $prices as $key => $val ) {
			if ( null === $val ) {
				delete_post_meta( $product_id, $key );
			} else {
				update_post_meta( $product_id, $key, wc_format_decimal( $val ) );
			}
		}
	}

	// --- Front-end ---------------------------------------------------------

	/**
	 * Make NYP products purchasable even without a regular price.
	 *
	 * @param bool       $purchasable Current purchasable state.
	 * @param WC_Product $product     Product object.
	 * @return bool
	 */
	public function product_price( $price, $product ) {
		// Only fill in a base price when the product has no regular price of its
		// own - never clobber an explicitly-priced product.
		if ( '' !== (string) $price ) {
			return $price;
		}
		if ( ! is_a( $product, 'WC_Product' ) || ! DPT_NYP_Settings::is_nyp( $product->get_id() ) ) {
			return $price;
		}
		return DPT_NYP_Settings::base_price( $product->get_id() );
	}

	/**
	 * Replace the (empty or fixed) price display for NYP products so a stale
	 * regular price is not shown alongside the "name your price" input.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function price_html( $html, $product ) {
		if ( ! is_a( $product, 'WC_Product' ) || ! DPT_NYP_Settings::is_nyp( $product->get_id() ) ) {
			return $html;
		}
		$suggested = DPT_NYP_Settings::suggested_price( $product->get_id() );
		if ( null !== $suggested ) {
			return '<span class="dpt-nyp-price-html">' . esc_html(
				sprintf(
					/* translators: %s: suggested price */
					__( 'Suggested: %s', 'digitizer-pro-tools' ),
					DPT_NYP_Settings::format_price( $suggested )
				)
			) . '</span>';
		}
		return '<span class="dpt-nyp-price-html">' . esc_html( DPT_NYP_Settings::label() ) . '</span>';
	}

	/**
	 * Replace the archive add-to-cart button with a link to the product page for
	 * NYP products, so the price input is always used.
	 *
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function loop_add_to_cart_link( $html, $product ) {
		if ( ! is_a( $product, 'WC_Product' ) || ! DPT_NYP_Settings::is_nyp( $product->get_id() ) ) {
			return $html;
		}
		return sprintf(
			'<a href="%s" class="button dpt-nyp-select">%s</a>',
			esc_url( $product->get_permalink() ),
			esc_html__( 'Choose amount', 'digitizer-pro-tools' )
		);
	}

	public function render_price_input() {
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}
		$id = $product->get_id();
		if ( ! DPT_NYP_Settings::is_nyp( $id ) ) {
			return;
		}

		$default = DPT_NYP_Settings::default_price( $id );
		$min     = DPT_NYP_Settings::min_price( $id );
		$max     = DPT_NYP_Settings::max_price( $id );
		$label   = DPT_NYP_Settings::label();

		// Defensive clamp in case the range changed after the default was saved.
		if ( null !== $default ) {
			if ( null !== $min && $default < $min ) {
				$default = $min;
			}
			if ( null !== $max && $default > $max ) {
				$default = $max;
			}
		}

		echo '<div class="dpt-nyp-input">';
		echo '<label for="dpt_nyp_price">' . esc_html( $label ) . '</label> ';
		printf(
			'<input type="text" inputmode="decimal" id="dpt_nyp_price" name="dpt_nyp_price" value="%s" class="dpt-nyp-price" autocomplete="off" />',
			esc_attr( null !== $default ? wc_format_localized_price( $default ) : '' )
		);

		if ( DPT_NYP_Settings::is_on( 'show_range_hint' ) && ( null !== $min || null !== $max ) ) {
			$hint = '';
			if ( null !== $min && null !== $max ) {
				/* translators: 1: min price, 2: max price */
				$hint = sprintf( __( 'Between %1$s and %2$s', 'digitizer-pro-tools' ), DPT_NYP_Settings::format_price( $min ), DPT_NYP_Settings::format_price( $max ) );
			} elseif ( null !== $min ) {
				/* translators: %s: min price */
				$hint = sprintf( __( 'Minimum %s', 'digitizer-pro-tools' ), DPT_NYP_Settings::format_price( $min ) );
			} else {
				/* translators: %s: max price */
				$hint = sprintf( __( 'Maximum %s', 'digitizer-pro-tools' ), DPT_NYP_Settings::format_price( $max ) );
			}
			echo ' <span class="dpt-nyp-hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Reject an add-to-cart with an out-of-range or invalid custom price.
	 * Authoritative server-side gate.
	 *
	 * @param bool $passed     Current validation state.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! DPT_NYP_Settings::is_nyp( $product_id ) ) {
			return $passed;
		}
		$raw   = isset( $_POST['dpt_nyp_price'] ) ? wp_unslash( $_POST['dpt_nyp_price'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$error = DPT_NYP_Settings::validate_price( $product_id, $raw );
		if ( null !== $error ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $error, 'error' );
			}
			return false;
		}
		return $passed;
	}

	/**
	 * Store the validated custom price on the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product id.
	 * @param int   $variation_id   Variation id.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! DPT_NYP_Settings::is_nyp( $product_id ) ) {
			return $cart_item_data;
		}
		$raw = isset( $_POST['dpt_nyp_price'] ) ? wp_unslash( $_POST['dpt_nyp_price'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// Re-validate here too: add_cart_item_data can be reached directly
		// (e.g. programmatic add) without passing through the validation filter.
		if ( null !== DPT_NYP_Settings::validate_price( $product_id, $raw ) ) {
			return $cart_item_data;
		}
		// The price becomes part of WooCommerce's cart-item id hash, so lines
		// with different prices are already kept separate. We deliberately do
		// NOT add a random uniqueness key: it would give every addition a
		// different cart id and defeat "Sold individually" enforcement (and
		// prevent same-price lines from merging).
		$cart_item_data['dpt_nyp_price'] = DPT_NYP_Settings::sanitize_price( $raw );
		return $cart_item_data;
	}

	/**
	 * Restore the custom price from the session cart.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Stored session values.
	 * @return array
	 */
	public function cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['dpt_nyp_price'] ) ) {
			$cart_item['dpt_nyp_price'] = $values['dpt_nyp_price'];
		}
		return $cart_item;
	}

	/**
	 * Apply the stored custom prices to the cart totals. Re-clamps to the
	 * product's min/max so a tampered session value cannot bypass the range.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function apply_custom_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		$can_remove = method_exists( $cart, 'remove_cart_item' );
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( ! isset( $cart_item['data'] ) ) {
				continue;
			}
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			// Only touch NYP products. If NYP was disabled for the product after
			// the item entered the cart (persistent carts), leave its current
			// fixed price alone.
			if ( ! DPT_NYP_Settings::is_nyp( $product_id ) ) {
				continue;
			}
			// Every NYP line must carry a valid customer price. A missing or
			// non-numeric value means the item was added without going through
			// our flow (e.g. a direct WC_Cart::add_to_cart() that bypassed the
			// validation filter) - treat it as invalid so it is removed below
			// rather than sold at the product's base price of 0.
			$stored = isset( $cart_item['dpt_nyp_price'] ) ? $cart_item['dpt_nyp_price'] : null;
			$price  = is_numeric( $stored ) ? (float) $stored : -1.0;

			// Re-enforce the product's current range at calculation time. A
			// tampered session value that is merely out of range is clamped back
			// in; one that is genuinely invalid now (non-numeric, or rounds to a
			// disallowed zero because the rules changed) is removed from the cart
			// rather than sold at the product's base price, which for an unpriced
			// NYP product is 0 - i.e. free.
			$min = DPT_NYP_Settings::min_price( $product_id );
			$max = DPT_NYP_Settings::max_price( $product_id );
			if ( $price >= 0 ) {
				if ( null !== $min && $price < $min ) {
					$price = $min;
				}
				if ( null !== $max && $price > $max ) {
					$price = $max;
				}
			}

			if ( null !== DPT_NYP_Settings::check_price_range( $product_id, $price ) ) {
				if ( $can_remove ) {
					$cart->remove_cart_item( $key );
				}
				continue;
			}
			$cart_item['data']->set_price( $price );
		}
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
