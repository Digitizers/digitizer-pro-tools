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
		// WooCommerce would treat it as non-purchasable and never render the
		// add-to-cart form (nor our input). Force purchasability and replace the
		// (empty or fixed) price display.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'is_purchasable' ), 10, 2 );
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

		foreach ( array( DPT_NYP_Settings::META_MIN, DPT_NYP_Settings::META_MAX, DPT_NYP_Settings::META_SUGGESTED, DPT_NYP_Settings::META_DEFAULT ) as $key ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$val = DPT_NYP_Settings::sanitize_price( $raw );
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
	public function is_purchasable( $purchasable, $product ) {
		if ( $purchasable ) {
			return true;
		}
		if ( ! is_a( $product, 'WC_Product' ) || ! DPT_NYP_Settings::is_nyp( $product->get_id() ) ) {
			return $purchasable;
		}
		// Only override the "no price set" reason. Every other gate WooCommerce
		// applies (draft/private status, out of stock) must still block the
		// purchase, so a visitor cannot buy a non-published product.
		$published = ( 'publish' === $product->get_status() ) || current_user_can( 'edit_post', $product->get_id() );
		$in_stock  = ! method_exists( $product, 'is_in_stock' ) || $product->is_in_stock();
		if ( $published && $in_stock && '' === (string) $product->get_price() ) {
			return true;
		}
		return $purchasable;
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
		$cart_item_data['dpt_nyp_price'] = DPT_NYP_Settings::sanitize_price( $raw );
		// Make each custom-priced line a distinct cart item.
		$cart_item_data['dpt_nyp_unique'] = md5( microtime() . wp_rand() );
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
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['dpt_nyp_price'] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			// If NYP was disabled for the product after this item entered the
			// cart (persistent carts), leave the current fixed price alone.
			if ( ! DPT_NYP_Settings::is_nyp( $product_id ) ) {
				continue;
			}
			// The stored value is already a canonical float - read it directly,
			// never back through the locale-aware input parser.
			$stored = $cart_item['dpt_nyp_price'];
			if ( ! is_numeric( $stored ) ) {
				continue;
			}
			$price = (float) $stored;
			if ( ! is_finite( $price ) || $price < 0 ) {
				continue;
			}

			// Re-enforce the product's current range at calculation time so a
			// tampered session value - or rules changed after the item entered
			// the cart - cannot apply an out-of-range price.
			$min = DPT_NYP_Settings::min_price( $product_id );
			$max = DPT_NYP_Settings::max_price( $product_id );
			if ( null === $min ) {
				// No minimum: a zero/negative price is no longer allowed (it was
				// only valid while an explicit minimum of 0 was set). Leave the
				// product's own price rather than applying an invalid 0.
				if ( $price <= 0 ) {
					continue;
				}
			} elseif ( $price < $min ) {
				$price = $min;
			}
			if ( null !== $max && $price > $max ) {
				$price = $max;
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
