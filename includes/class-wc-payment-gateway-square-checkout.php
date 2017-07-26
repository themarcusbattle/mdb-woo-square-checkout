<?php

/**
 * Square Checkout Payment Gateway
 *
 * @since       0.1.0
 * @author 		Marcus B.
 */
class WC_Payment_Gateway_Square_Checkout extends WC_Payment_Gateway {


	public function __construct() {

		$this->id 					= 'square-checkout';
		$this->icon 				= '';
		$this->has_fields 			= false;
		$this->method_title			= __( 'Square Checkout', 'mdb-square-checkout' );
		$this->method_description 	= __( 'Enables Square Checkout support and does\'t require SSL', 'mdb-woo-square-checkout' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );

		// Save form field settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {

	    $this->form_fields = array(

	        'enabled' => array(
	            'title'   => __( 'Enable/Disable', 'mdb-woo-square-checkout' ),
	            'type'    => 'checkbox',
	            'label'   => __( 'Enable Square Checkout', 'mdb-woo-square-checkout' ),
	        ),

			'override_checkout' => array(
	            'title'   => __( 'Override Checkout', 'mdb-woo-square-checkout' ),
	            'type'    => 'checkbox',
				'description' => __( 'Override the checkout page and redirect straight to sqaure checkout', 'mdb-woo-square-checkout' ),
	            'label'   => __( 'Override Checkout', 'mdb-woo-square-checkout' ),
	        ),

	        'title' => array(
	            'title'       => __( 'Title', 'mdb-woo-square-checkout' ),
	            'type'        => 'text',
	            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'mdb-woo-square-checkout' ),
	            'default'     => __( 'Square', 'mdb-woo-square-checkout' ),
	            'desc_tip'    => true,
	        ),

	        'description' => array(
	            'title'       => __( 'Description', 'mdb-woo-square-checkout' ),
	            'type'        => 'textarea',
	            'description' => __( 'Payment method description that the customer will see on your checkout.', 'mdb-woo-square-checkout' ),
	            'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', 'mdb-woo-square-checkout' ),
	            'desc_tip'    => true,
	        ),

	        'instructions' => array(
	            'title'       => __( 'Instructions', 'mdb-woo-square-checkout' ),
	            'type'        => 'textarea',
	            'description' => __( 'Instructions that will be added to the thank you page and emails.', 'mdb-woo-square-checkout' ),
	            'default'     => '',
	            'desc_tip'    => true,
	        ),

	    );

	}

}
