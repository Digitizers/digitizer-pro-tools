<?php
/**
 * Checkout Field Editor module - customise standard checkout fields and add a
 * few custom fields, saved to the order and shown in the admin and emails.
 *
 * Targets the classic (shortcode) checkout via woocommerce_checkout_fields.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-dpt-wcf-settings.php';
require_once __DIR__ . '/class-dpt-wcf-admin.php';

class DPT_Woo_Checkout_Fields_Module extends DPT_Module {

	/** @var DPT_WCF_Admin */
	private $admin;

	// Per-order snapshot of the custom-field definitions present at checkout.
	const DEFS_META = '_dpt_wcf_defs';

	public function id() {
		return 'woo_checkout_fields';
	}

	public function title() {
		return __( 'Checkout Field Editor', 'digitizer-pro-tools' );
	}

	public function description() {
		return __( 'Show, hide, require and reorder standard WooCommerce checkout fields, and add custom fields (text, select or checkbox) saved to the order and shown in the admin and order emails.', 'digitizer-pro-tools' );
	}

	public function install_defaults() {
		DPT_WCF_Settings::install_defaults();
	}

	public function init() {
		if ( is_admin() ) {
			$this->admin = new DPT_WCF_Admin();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Classic checkout only. The block checkout uses a separate field API
		// (register_additional_checkout_field), which is intentionally not in
		// scope for this module.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'customize_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_custom_fields' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_custom_fields' ), 10, 2 );

		// Display on the order.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_fields' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_order_meta_fields' ), 10, 3 );
	}

	// --- Checkout form -----------------------------------------------------

	/**
	 * Apply the standard-field overrides and inject the custom fields.
	 *
	 * @param array $fields WooCommerce checkout fields, by section.
	 * @return array
	 */
	public function customize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		$standard        = DPT_WCF_Settings::standard();
		$order_available = $this->order_section_available();

		foreach ( DPT_WCF_Settings::standard_defs() as $key => $def ) {
			$section = $def['section'];
			$fkey    = $def['field'];
			// Never touch a field whose section the classic template will skip.
			// Forcing the standard order-notes field required while its fieldset
			// is hidden would let WooCommerce validate a field that can never be
			// submitted, blocking every checkout.
			if ( 'order' === $section && ! $order_available ) {
				continue;
			}
			if ( ! isset( $fields[ $section ][ $fkey ] ) ) {
				continue;
			}
			$cfg = isset( $standard[ $key ] ) ? $standard[ $key ] : null;
			if ( null === $cfg ) {
				continue;
			}
			if ( '1' !== $cfg['enabled'] ) {
				unset( $fields[ $section ][ $fkey ] );
				continue;
			}
			$fields[ $section ][ $fkey ]['required'] = ( '1' === $cfg['required'] );
			if ( '' !== $cfg['priority'] ) {
				$fields[ $section ][ $fkey ]['priority'] = (int) $cfg['priority'];
			}
		}

		foreach ( DPT_WCF_Settings::custom_fields() as $cf ) {
			$section = $cf['section'];
			// The classic checkout only renders the "order" (additional-info)
			// fieldset when order notes are enabled. WooCommerce still validates
			// that fieldset, so a required custom field placed there when the
			// section is hidden could never be submitted and would block every
			// checkout (and an optional one would just vanish). Relocate such
			// fields to the always-rendered billing section instead.
			if ( 'order' === $section && ! $order_available ) {
				$section = 'billing';
			}
			if ( ! isset( $fields[ $section ] ) || ! is_array( $fields[ $section ] ) ) {
				$fields[ $section ] = array();
			}
			$fields[ $section ][ DPT_WCF_Settings::input_name( $cf['key'] ) ] = $this->build_field_args( $cf );
		}

		return $fields;
	}

	/**
	 * Whether the classic checkout will render the "order" (additional info)
	 * fieldset. Mirrors WooCommerce's own gate so we never place a field in a
	 * section the template skips.
	 *
	 * @return bool
	 */
	private function order_section_available() {
		$enabled = 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' );
		return (bool) apply_filters( 'woocommerce_enable_order_notes_field', $enabled );
	}

	/**
	 * Build a WooCommerce field-args array from a custom-field definition.
	 *
	 * @param array $cf Custom field.
	 * @return array
	 */
	private function build_field_args( $cf ) {
		$required = ( '1' === $cf['required'] );
		$args     = array(
			'type'     => $cf['type'],
			'label'    => $cf['label'],
			'required' => $required,
			'class'    => array( 'form-row-wide', 'dpt-wcf-field' ),
		);
		if ( '' !== $cf['priority'] ) {
			$args['priority'] = (int) $cf['priority'];
		}
		if ( 'select' === $cf['type'] ) {
			// Lead with a blank choice so an unpicked required select fails the
			// "required" check and an optional one can be left empty.
			$options = array( '' => __( 'Choose an option', 'digitizer-pro-tools' ) );
			foreach ( $cf['options'] as $opt ) {
				$options[ $opt ] = $opt;
			}
			$args['options'] = $options;
		}
		return $args;
	}

	// --- Order persistence -------------------------------------------------

	/**
	 * Persist submitted custom-field values on the order being created.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 */
	public function save_custom_fields( $order, $data ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$data = is_array( $data ) ? $data : array();
		$defs = array();
		foreach ( DPT_WCF_Settings::custom_fields() as $cf ) {
			$name = DPT_WCF_Settings::input_name( $cf['key'] );
			$meta = DPT_WCF_Settings::meta_key( $cf['key'] );
			$raw  = isset( $data[ $name ] ) ? $data[ $name ] : null;

			// Snapshot the field's identity on the order, so it still renders in
			// the admin and in (re-sent) emails after the definition is later
			// edited or removed from the settings.
			$defs[] = array(
				'key'   => $cf['key'],
				'label' => $cf['label'],
				'type'  => $cf['type'],
			);

			if ( 'checkbox' === $cf['type'] ) {
				$order->update_meta_data( $meta, empty( $raw ) ? 'no' : 'yes' );
				continue;
			}

			$val = is_array( $raw ) ? '' : sanitize_text_field( (string) $raw );
			// Never trust a select value that is not one of the configured
			// options - drop it rather than persist arbitrary posted text.
			if ( 'select' === $cf['type'] && '' !== $val && ! in_array( $val, $cf['options'], true ) ) {
				$val = '';
			}
			if ( '' !== $val ) {
				$order->update_meta_data( $meta, $val );
			}
		}
		if ( ! empty( $defs ) ) {
			$order->update_meta_data( self::DEFS_META, $defs );
		}
	}

	/**
	 * The custom-field definitions to render for a given order: the snapshot
	 * taken when the order was placed, falling back to the current settings for
	 * orders created before snapshots existed.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	private function order_field_defs( $order ) {
		$defs = $order->get_meta( self::DEFS_META );
		if ( is_array( $defs ) && ! empty( $defs ) ) {
			return $defs;
		}
		return DPT_WCF_Settings::custom_fields();
	}

	/**
	 * Server-side validation beyond WooCommerce's own required-field check:
	 * reject a select value that is not one of the configured options.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Error collector.
	 */
	public function validate_custom_fields( $data, $errors ) {
		if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
			return;
		}
		$data = is_array( $data ) ? $data : array();
		foreach ( DPT_WCF_Settings::custom_fields() as $cf ) {
			if ( 'select' !== $cf['type'] ) {
				continue;
			}
			$name = DPT_WCF_Settings::input_name( $cf['key'] );
			$val  = isset( $data[ $name ] ) ? $data[ $name ] : '';
			if ( '' !== $val && ! in_array( $val, $cf['options'], true ) ) {
				$errors->add(
					'dpt_wcf_invalid_select',
					sprintf(
						/* translators: %s: field label */
						__( '"%s" is not a valid selection.', 'digitizer-pro-tools' ),
						$cf['label']
					)
				);
			}
		}
	}

	// --- Order display -----------------------------------------------------

	/**
	 * Human-readable value of a custom field stored on an order, or '' when
	 * unset.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $cf    Custom field definition.
	 * @return string
	 */
	private function display_value( $order, $cf ) {
		$val = $order->get_meta( DPT_WCF_Settings::meta_key( $cf['key'] ) );
		if ( 'checkbox' === $cf['type'] ) {
			// Only surface a positive tick; an unticked optional box is noise.
			return ( 'yes' === $val ) ? __( 'Yes', 'digitizer-pro-tools' ) : '';
		}
		return ( null === $val ) ? '' : (string) $val;
	}

	/**
	 * Render the custom fields on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_admin_order_fields( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$rows = '';
		foreach ( $this->order_field_defs( $order ) as $cf ) {
			$val = $this->display_value( $order, $cf );
			if ( '' === $val ) {
				continue;
			}
			$rows .= '<p><strong>' . esc_html( $cf['label'] ) . ':</strong> ' . esc_html( $val ) . '</p>';
		}
		if ( '' !== $rows ) {
			echo '<div class="dpt-wcf-admin-fields"><h3>' . esc_html__( 'Additional checkout fields', 'digitizer-pro-tools' ) . '</h3>' . wp_kses_post( $rows ) . '</div>';
		}
	}

	/**
	 * Add the custom fields to the order-email meta table.
	 *
	 * @param array    $fields        Existing email meta fields.
	 * @param bool     $sent_to_admin Whether the email goes to the admin.
	 * @param WC_Order $order         Order.
	 * @return array
	 */
	public function email_order_meta_fields( $fields, $sent_to_admin, $order ) {
		if ( ! is_array( $fields ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return $fields;
		}
		foreach ( $this->order_field_defs( $order ) as $cf ) {
			$val = $this->display_value( $order, $cf );
			if ( '' === $val ) {
				continue;
			}
			$fields[ DPT_WCF_Settings::meta_key( $cf['key'] ) ] = array(
				'label' => $cf['label'],
				'value' => $val,
			);
		}
		return $fields;
	}

	public function register_admin_menu( $parent_slug ) {
		if ( $this->admin ) {
			$this->admin->register_menu( $parent_slug );
		}
	}
}
