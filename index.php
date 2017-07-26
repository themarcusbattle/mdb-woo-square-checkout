<?php

/**
 * Plugin Name: Square Checkout for WooCommerce
 * Version: 0.1.0
 * Author: Marcus Battle
 * Author URI: http://marcusbattle.com
 */

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

require 'vendor/autoload.php';

// Autloader for MDB_Woo_Square_Checkout
spl_autoload_register( function ($name) {

	if ( 'WC_Payment_Gateway_Square_Checkout' == $name ) {

		$class_name = 'class-' . str_ireplace( '_', '-', strtolower( $name ) ) . '.php';
		$class_path = dirname( __FILE__ ) . '/includes/' . $class_name;

		if ( file_exists( $class_path ) ) {
			include( $class_path );
		}

	}

} );

class MDB_Woo_Square_Checkout {

	/**
	 * The single instance of the class.
	 *
	 * @var MDB_Woo_Square_Checkout
	 * @since 0.1.0
	 */
	protected static $instance = null;

	/**
	 * The single instance of the payment gateway.
	 *
	 * @var WC_Payment_Gateway_Square_Checkout
	 * @since 0.1.0
	 */
	public $square_checkout_gateway = null;

	public $order_id = null;

	public function init() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

	/**
	 * Adds all of the WP actions and filters required to run this plugin.
	 *
	 * @since 0.1.0
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'load_classes' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_square_checkout_gateway' ) );
		add_action( 'template_redirect', array( $this, 'redirect_woo_to_square' ) );
		add_action( 'template_redirect', array( $this, 'verify_transaction_from_square' ) );
	}

	/**
	 * Loads all required classes for the plugin.
	 *
	 * @since 0.1.0
	 */
	public function load_classes() {
		$this->square_checkout_gateway = new WC_Payment_Gateway_Square_Checkout();
	}

	/**
	 * Adds the Square Checkout gateway to the list of available payment gateways
	 *
	 * @since 0.1.0
	 * @param $gateways available payment gateways
	 * @return $gateways modified available payment gateways
	 */
	public function add_square_checkout_gateway( $gateways ) {
		$gateways[] = 'WC_Payment_Gateway_Square_Checkout';
		return $gateways;
	}

	/**
	 * Forces the WooCommerce checkout page to redirect to Square checkout
	 * @since 0.1.0
	 */
	public function redirect_woo_to_square() {

		global $wp;

		// Redirect all traffic except everything to /checkout
		if ( ! is_page( 'checkout' ) || 'checkout' != $wp->request ) {
			return false;
		}

		$override_checkout = $this->square_checkout_gateway->get_option('override_checkout');

		if ( 'no' == $override_checkout ) {
			return false;
		}

		// Create a new WooCommerce Checkout
		$checkout = new WC_Checkout();
		$this->order_id = $checkout->create_order( array() );

		// Create a new WooCommerce Order
		$order = new WC_Order( $this->order_id );

		$this->load_square_checkout_api_credentials();
		$this->init_square_checkout_api();

		// Build the square checkout array
		$items = $this->get_items_from_cart();
		
		// Add Shipping
		if ( $shipping = $this->get_shipping_from_cart() ) {
			$items[] = $shipping;
		}

		$this->process_square_checkout_order( $items );

		exit;
	}

	/**
	 * Forces the WooCommerce checkout page to redirect to Square checkout
	 * @since 0.1.0
	 */
	public function verify_transaction_from_square() {

		// Redirect all traffic except everything to /checkout/order-received
		if ( ! is_page( 'checkout' ) || ! isset( $_REQUEST['transactionId'] ) ) {
			return false;
		}

		$woo_order_key			= $_REQUEST['key'];
		$square_transaction_id 	= $_REQUEST['transactionId'];

		$this->load_square_checkout_api_credentials();
		$this->init_square_checkout_api();

		// Create a new API object to verify the transaction
		$transactionClient = new \SquareConnect\Api\TransactionsApi( $GLOBALS['API_CLIENT'] );
		$customerClient = new \SquareConnect\Api\CustomersApi( $GLOBALS['API_CLIENT'] );

		// Ping the Transactions API endpoint for transaction details
		try {

		  // Get transaction details for this order from the Transactions API endpoint
		  $api_response = $transactionClient->retrieveTransaction( $GLOBALS['LOCATION_ID'], $square_transaction_id );

		  // Get the customer_id from the transaction
		  $transaction 	= $api_response->getTransaction();
		  $tenders		= $transaction->getTenders();
		  $customer_id	= isset( $tenders[0] ) ? $tenders[0]->getCustomerId() : '';

		  // Extract the customer details
		  $api_response_customer = $customerClient->retrieveCustomer( $customer_id );
		  $customer 		= $api_response_customer->getCustomer();
		  $customer_address	= $customer->getAddress();

		  $customer_details = array(
			  'first_name'	=> $customer->getGivenName(),
			  'last_name'	=> $customer->getFamilyName(),
			  'email'		=> $customer->getEmailAddress(),
		      'address_1'  	=> $customer_address->getAddressLine1(),
		      'address_2'  	=> $customer_address->getAddressLine2(),
		      'city'       	=> $customer_address->getLocality(),
		      'state'      	=> $customer_address->getAdministrativeDistrictLevel1(),
		      'postcode'   	=> $customer_address->getPostalCode(),
		      'country'    	=> $customer_address->getCountry(),
		  );

		  $order = new WC_order( wc_get_order_id_by_order_key( $woo_order_key ) );

		  $order->set_address( $customer_details, 'billing' );
		  $order->set_address( $customer_details, 'shipping' );
		  $order->update_status( 'completed', 'Verified by Square Checkout', TRUE );

		} catch (Exception $e) {
		  echo "The SquareConnect\Configuration object threw an exception while " .
		       "calling TransactionsApi->retrieveTransaction: ",
		       $e->getMessage(), PHP_EOL;
		  exit;
		}

	}

	public function load_square_checkout_api_credentials() {

		// Include the Square Connect API resources
		require_once 'vendor/square/connect/autoload.php';

		// API initialization - Configure your online store information
		$GLOBALS['ACCESS_TOKEN'] = "sandbox-sq0atb-yAy3cXrfLxEp38oxAaYcrQ";
		$GLOBALS['STORE_NAME'] = "Coffee & Toffee NYC";
		$GLOBALS['LOCATION_ID'] = ""; // We'll set this in a moment
		$GLOBALS['API_CLIENT_SET'] = false;

		// Sanity check that all the needed configuration elements are set
		if ( $GLOBALS['STORE_NAME'] == null ) {
		  print(
		    "[ERROR] STORE NAME NOT SET. " .
		    "Please set a valid store name to use Square Checkout."
		  );
		  exit;
		} else if ( $GLOBALS['ACCESS_TOKEN'] == null ) {
		  print(
		    "[ERROR] ACCESS TOKEN NOT SET. Please set a valid authorization token " .
		    "(Personal Access Token or OAuth Token) to use Square Checkout."
		  );
		  exit;
		}

	}

	/**
	 * Return all of the produts from the shopping cart and prepare them for Square Checkout
	 *
	 * @since 0.1.0
	 * @return array $items Contects from the Woo Cart
	 */
	public function get_items_from_cart() {

		$items = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $items;
		}

		global $woocommerce;

		$cart_items = $woocommerce->cart->get_cart();

		if ( ! $cart_items ) {
			return $items;
		}

		foreach ( $cart_items as $index => $cart_item ) {

			$product_id	= ( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
			$total 		= $cart_item['line_total'];
			$sub_total 	= $cart_item['line_subtotal'];
			$discount_total	= $sub_total - $total;

			$price 		= $cart_item['line_subtotal'] / $cart_item['quantity'];

			$items[ $index ] = array(
				'name' => html_entity_decode( get_the_title( $product_id ) ),
				'quantity' => "{$cart_item['quantity']}",
				'base_price_money' => array(
					'amount' => $this->format_amount( $price ),
					'currency' => 'USD'
				)
			);

			// Apply line discount
			if ( $discount_total ) {

				$items[ $index ]['discounts'] = array( array(
					'name' => 'Coupon',
					'amount_money' => array(
						'amount' => $this->format_amount( $discount_total ),
						'currency' => 'USD'
					)
				) );

			}

		}

		return array_values( $items );

	}

	public function get_shipping_from_cart() {

		global $woocommerce;

		$shipping = array();
		$shipping_total = $woocommerce->cart->shipping_total;

		if ( empty( $shipping_total ) ) {
			return $shipping;
		}

		$shipping = array(
			'name' => 'Shipping',
			'quantity'	=> '1',
			'base_price_money' => array(
				'amount' => $this->format_amount( $shipping_total ),
				'currency' => 'USD'
			)
		);

		return $shipping;

	}

	/**
	 * Return all of the discounts currently applied to the cart
	 */
	public function get_discounts_from_woo_cart() {

		$discounts = array();

		global $woocommerce;

		$applied_coupons = $woocommerce->cart->applied_coupons;

		if ( empty( $applied_coupons ) ) {
			return $discounts;
		}

		foreach ( $applied_coupons as $coupon_code ) {

			$discount		= array();
			$coupon 		= new WC_Coupon( $coupon_code );
			$discount_type 	= $coupon->get_discount_type();

			if ( 'fixed_product' == $discount_type ) {
				continue;
			}

			$discount['name'] = strtoupper( $coupon_code );

			if ( 'percent' == $discount_type ) {
				$discount['percentage'] = $coupon->get_amount();
			} else {
				$discount['amount_money'] = array(
					'amount'	=> $this->format_amount( $coupon->get_amount() ),
					'currency'	=> 'USD',
				);
			}

			$discounts[] = $discount;

		}

		return $discounts;
	}

	/**
	 * Formats the product cost to two decimal places
	 *
	 * @since 0.1.0
	 * @param integer $amount Cost of product
	 * @param integer $amount Modified cost of product w/ 2 decimal places
	 */
	public function format_amount( $amount = 0 ) {
		return intval( strval( $amount * 100 ) );
	}

	/*******************************************************************************
	 * @name: initApiClient
	 * @param: none - uses the GLOBAL array settings to initialize the client
	 * @return: none - adds the ApiClient object to the GLOBAL array
	 *
	 * @desc:
	 * Initializes a Square Connect API client, loads the appropriate
	 * location ID and returns an Api object ready for communicating with Square's
	 * various API endpoints
	 ******************************************************************************/
	public function init_square_checkout_api() {

		// If we've already set the API client, we don't need to do it again
	    if ($GLOBALS['API_CLIENT_SET']) { return; }

	    // Create and configure a new Configuration object
	    $configuration = new \SquareConnect\Configuration();

		\SquareConnect\Configuration::getDefaultConfiguration()->setAccessToken($GLOBALS['ACCESS_TOKEN']);

	    // Create a LocationsApi client to load the location ID
	    $locationsApi = new \SquareConnect\Api\LocationsApi();

	    // Grab the location key for the configured store
	    try {

	      $apiResponse = $locationsApi->listLocations()->getLocations();

	      // There may be more than one location assocaited with the account (e.g,. a
	      // brick-and-mortar store and an online store), so we need to run through
	      // the response and pull the right location ID
	      foreach ($apiResponse as $location) {

	        if ($GLOBALS['STORE_NAME'] == $location->getName()) {

	          $GLOBALS['LOCATION_ID'] = $location['id'];
	          if (!in_array('CREDIT_CARD_PROCESSING', $location->getCapabilities())) {
	            print(
	              "[ERROR] LOCATION  " . $GLOBALS['STORE_NAME'] .
	              " can't processs payments"
	            );
	            exit();
	          }
	        }
	      }

	      if ($GLOBALS['LOCATION_ID'] == null) {
	        print(
	          "[ERROR] LOCATION ID NOT SET. A location ID for " .
	          $GLOBALS['STORE_NAME'] . " could not be found"
	        );
	        exit;
	      }

	      $GLOBALS['API_CLIENT_SET'] = true;

	    } catch (Exception $e) {

	      // Display the exception details, clear out the client since it couldn't
	      // be properly initialized, and exit
	      echo "The SquareConnect\Configuration object threw an exception while " .
	           "calling LocationApi->listLocations: ", $e->getMessage(), PHP_EOL;
	      $GLOBALS['API_CLIENT'] = null;
	      exit;
	    }

	}

	/**
	 *
	 *
	 * @return boolean
	 */
	public function process_square_checkout_order( $items = array() ) {

		// Check to see if we received any orders
		if ( empty( $items ) ) {
			return false;
		}

		$checkoutClient = new \SquareConnect\Api\CheckoutApi();

		$order 				= new WC_Order( $this->order_id );
		$order_return_url 	= $order->get_checkout_order_received_url();
		$order_key 			= $order->get_order_key();

		$orderArray = array(
			'redirect_url' 		=> $order_return_url,
			'idempotency_key' 	=> md5( date('Y-m-d H:i:s') ),
			'order' => array(
		    	'reference_id' => $order_key,
		    	'line_items' => $items,
			),
			'ask_for_shipping_address' => true,
			'merchant_support_email' => "info@battlebranding.com",
		);

		try {
			// Send the order array to Square Checkout
			$apiResponse = $checkoutClient->createCheckout(
				$GLOBALS['LOCATION_ID'],
				$orderArray
		  	);

		  	$checkout_response = $apiResponse->getCheckout();

			// Grab the redirect url and checkout ID sent back
			$checkoutUrl	= $checkout_response->getCheckoutPageUrl();
			$checkoutID		= $checkout_response->getId();

			// HELPER FUNCTION: save the checkoutID so it can be used to confirm the
			// transaction after payment processing
		  	$this->saveCheckoutId( $orderArray['order']['reference_id'], $checkoutID );

		} catch ( Exception $e ) {
			echo "The SquareConnect\Configuration object threw an exception while " .
				"calling CheckoutApi->createCheckout: ", $e->getMessage(), PHP_EOL;
		  	exit();
		}

		// Redirect the customer to Square Checkout
		header( "Location: $checkoutUrl" );

	}

	/*******************************************************************************
	 * @name: saveCheckoutId
	 * @param:
	 *   $currOrder - a string or object referencing the current order
	 *   $checkoutId - string; the checkout ID returned by Square Checkout
	 * @return: $success - boolean; success/failure of the save operation
	 *
	 * @desc:
	 * Takes in an order reference or number from your shopping cart software and
	 * a Square Checkout ID and adds the checkoutID to the order metadata
	 ******************************************************************************/
	public function saveCheckoutId($currOrder, $checkoutId) {
	  // add code to update the order metadata with the provided checkoutId
	  return $success;
	}

}

add_action( 'plugins_loaded', array( MDB_Woo_Square_Checkout::init(), 'hooks' ), 10 );
