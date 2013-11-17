<?php
/**
 * WooCommerce Payment Gateway Framework
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the plugin to newer
 * versions in the future. If you wish to customize the plugin for your
 * needs please refer to http://www.skyverge.com
 *
 * @package   SkyVerge/WooCommerce/Payment-Gateway/Classes
 * @author    SkyVerge
 * @copyright Copyright (c) 2013, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'SV_WC_Payment_Gateway_Direct' ) ) :

/**
 * # WooCommerce Payment Gateway Framework Direct Gateway
 *
 * @since 1.0
 */
abstract class SV_WC_Payment_Gateway_Direct extends SV_WC_Payment_Gateway {

	/** Subscriptions feature */
	const FEATURE_SUBSCRIPTIONS = 'subscriptions';

	/** Subscription payment method change feature */
	const FEATURE_SUBSCRIPTION_PAYMENT_METHOD_CHANGE = 'subscription_payment_method_change';

	/** Pre-orders feature */
	const FEATURE_PRE_ORDERS = 'pre-orders';

	/** @var array array of cached user id to array of SV_WC_Payment_Gateway_Payment_Token token objects */
	protected $tokens;


	/**
	 * Initialize the gateway
	 *
	 * @since 1.0
	 * @param string $id the gateway id
	 * @param SV_WC_Payment_Gateway_Plugin $plugin the parent plugin class
	 * @param string $text_domain the plugin text domain
	 * @param array $args gateway arguments
	 */
	public function __construct( $id, $plugin, $text_domain, $args ) {

		// parent constructor
		parent::__construct( $id, $plugin, $text_domain, $args );

		// watch for subscriptions support
		if ( $this->get_plugin()->is_subscriptions_active() ) {

			$subscription_support_hook               = 'wc_payment_gateway_' . $this->get_id() . '_supports_' . self::FEATURE_SUBSCRIPTIONS;
			$subscription_payment_method_change_hook = 'wc_payment_gateway_' . $this->get_id() . '_supports_' . self::FEATURE_SUBSCRIPTION_PAYMENT_METHOD_CHANGE;

			if ( ! has_action( $subscription_support_hook ) )
				add_action( $subscription_support_hook, array( $this, 'add_subscriptions_support' ) );

			if ( ! has_action( $subscription_payment_method_change_hook ) )
				add_action( $subscription_payment_method_change_hook, array( $this, 'add_subscription_payment_method_change_support' ) );
		}

		// watch for pre-orders support
		if ( $this->get_plugin()->is_pre_orders_active() ) {

			$pre_orders_support_hook = 'wc_payment_gateway_' . $this->get_id() . '_supports_' . str_replace( '-', '_', self::FEATURE_PRE_ORDERS );

			if ( ! has_action( $pre_orders_support_hook ) )
				add_action( $pre_orders_support_hook, array( $this, 'add_pre_orders_support' ) );
		}

	}


	/**
	 * Validate the payment fields when processing the checkout
	 *
	 * NOTE: if we want to bring billing field validation (ie length) into the
	 * fold, see the Elavon VM Payment Gateway for a sample implementation
	 *
	 * @since 1.0
	 * @see WC_Payment_Gateway::validate_fields()
	 * @return bool true if fields are valid, false otherwise
	 */
	public function validate_fields() {

		global $woocommerce;

		$is_valid = parent::validate_fields();

		if ( $this->supports_tokenization() ) {

			// tokenized transaction?
			if ( $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' ) ) {

				// unknown token?
				if ( ! $this->has_payment_token( get_current_user_id(), $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' ) ) ) {
					$woocommerce->add_error( __( 'Payment error, please try another payment method or contact us to complete your transaction.', $this->text_domain ) );
					$is_valid = false;
				}

				// no more validation to perform
				return $is_valid;
			}
		}

		// validate remaining payment fields
		if ( $this->is_credit_card_gateway() )
			return $this->validate_credit_card_fields( $is_valid );
		else
			return $this->validate_check_fields( $is_valid );
	}


	/**
	 * Returns true if the posted credit card fields are valid, false otherwise
	 *
	 * @since 1.0
	 * @param boolean $is_valid true if the fields are valid, false otherwise
	 * @return boolean true if the fields are valid, false otherwise
	 */
	protected function validate_credit_card_fields( $is_valid ) {

		global $woocommerce;

		$card_number      = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-account-number' );
		$expiration_month = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-exp-month' );
		$expiration_year  = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-exp-year' );
		$csc              = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-csc' );

		// validate card number
		$card_number = str_replace( array( ' ', '-' ), '', $card_number );

		if ( empty( $card_number ) ) {

			$woocommerce->add_error( __( 'Card number is missing', $this->text_domain ) );
			$is_valid = false;

		} else {

			if ( strlen( $card_number ) < 12 || strlen( $card_number ) > 19 ) {
				$woocommerce->add_error( __( 'Card number is invalid (wrong length)', $this->text_domain ) );
				$is_valid = false;
			}

			if ( ! ctype_digit( $card_number ) ) {
				$woocommerce->add_error( __( 'Card number is invalid (only digits allowed)', $this->text_domain ) );
				$is_valid = false;
			}

			if ( ! $this->luhn_check( $card_number ) ) {
				$woocommerce->add_error( __( 'Card number is invalid', $this->text_domain ) );
				$is_valid = false;
			}

		}

		// validate expiration data
		$current_year  = date( 'Y' );
		$current_month = date( 'n' );

		if ( ! ctype_digit( $expiration_month ) || ! ctype_digit( $expiration_year ) ||
			$expiration_month > 12 ||
			$expiration_month < 1 ||
			$expiration_year < $current_year ||
			( $expiration_year == $current_year && $expiration_month < $current_month ) ||
			$expiration_year > $current_year + 20
		) {
			$woocommerce->add_error( __( 'Card expiration date is invalid', $this->text_domain ) );
			$is_valid = false;
		}

		// validate card security code
		if ( $this->csc_enabled() ) {
			$is_valid = $this->validate_csc( $csc ) && $is_valid;
		}

		return $is_valid;
	}


	/**
	 * Validates the provided Card Security Code, adding user error messages as
	 * needed
	 *
	 * @since 1.0
	 * @param string $csc the customer-provided card security code
	 * @return boolean true if the card security code is valid, false otherwise
	 */
	protected function validate_csc( $csc ) {

		global $woocommerce;

		$is_valid = true;

		// validate security code
		if ( empty( $csc ) ) {

			$woocommerce->add_error( __( 'Card security code is missing', $this->text_domain ) );
			$is_valid = false;

		} else {

			// digit validation
			if ( ! ctype_digit( $csc ) ) {
				$woocommerce->add_error( __( 'Card security code is invalid (only digits are allowed)', $this->text_domain ) );
				$is_valid = false;
			}

			// length validation
			if ( strlen( $csc ) < 3 || strlen( $csc ) > 4 ) {
				$woocommerce->add_error( __( 'Card security code is invalid (must be 3 or 4 digits)', $this->text_domain ) );
				$is_valid = false;
			}

		}

		return $is_valid;

	}


	/**
	 * Returns true if the posted echeck fields are valid, false otherwise
	 *
	 * @since 1.0
	 * @param bool $is_valid true if the fields are valid, false otherwise
	 * @return bool
	 */
	protected function validate_check_fields( $is_valid ) {

		global $woocommerce;

		$account_number         = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-account-number' );
		$routing_number         = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-routing-number' );

		// optional fields (excluding account type for now)
		$drivers_license_number = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-drivers-license-number' );
		$drivers_license_state  = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-drivers-license-state' );
		$check_number           = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-check-number' );

		// routing number exists?
		if ( empty( $routing_number ) ) {

			$woocommerce->add_error( __( 'Routing Number is missing', $this->text_domain ) );
			$is_valid = false;

		} else {

			// routing number digit validation
			if ( ! ctype_digit( $routing_number ) ) {
				$woocommerce->add_error( __( 'Routing Number is invalid (only digits are allowed)', $this->text_domain ) );
				$is_valid = false;
			}

			// routing number length validation
			if ( 9 != strlen( $routing_number ) ) {
				$woocommerce->add_error( __( 'Routing number is invalid (must be 9 digits)', $this->text_domain ) );
				$is_valid = false;
			}

		}

		// account number exists?
		if ( empty( $account_number ) ) {

			$woocommerce->add_error( __( 'Account Number is missing', $this->text_domain ) );
			$is_valid = false;

		} else {

			// account number digit validation
			if ( ! ctype_digit( $account_number ) ) {
				$woocommerce->add_error( __( 'Account Number is invalid (only digits are allowed)', $this->text_domain ) );
				$is_valid = false;
			}

			// account number length validation
			if ( strlen( $account_number ) < 5 || strlen( $account_number ) > 17 ) {
				$woocommerce->add_error( __( 'Account number is invalid (must be between 5 and 17 digits)', $this->text_domain ) );
				$is_valid = false;
			}
		}

		// optional drivers license number validation
		if ( ! empty( $drivers_license_number ) &&  preg_match( '/^[a-zA-Z0-9 -]+$/', $drivers_license_number ) ) {
			$woocommerce->add_error( __( 'Drivers license number is invalid', $this->text_domain ) );
			$is_valid = false;
		}

		// optional check number validation
		if ( ! empty( $check_number ) && ! ctype_digit( $check_number ) ) {
			$woocommerce->add_error( __( 'Check Number is invalid (only digits are allowed)', $this->text_domain ) );
			$is_valid = false;
		}

		return $is_valid;

	}


	/**
	 * Handles payment processing
	 *
	 * @since 1.0
	 */
	public function process_payment( $order_id ) {

		global $woocommerce;

		// give other actors an opportunity to intercept and implement the process_payment() call for this transaction
		if ( true !== ( $result = apply_filters( 'wc_payment_gateway_' . $this->get_id() . '_process_payment', true, $order_id, $this ) ) ) {
			return $result;
		}

		// add payment information to order
		$order = $this->get_order( $order_id );

		try {

			// registered customer checkout (already logged in or creating account at checkout)
			if ( $this->supports_tokenization() && 0 !== $order->user_id && $this->should_tokenize_payment_method() ) {
				$order = $this->create_payment_token( $order );
			}

			// payment failures are handled internally by do_transaction()
			// the order amount will be $0 if a WooCommerce Subscriptions free trial product is being processed
			// note that customer id & payment token are saved to order when create_payment_token() is called
			if ( 0 == $order->payment_total || $this->do_transaction( $order ) ) {

				if ( 'on-hold' == $order->status )
					$order->reduce_order_stock(); // reduce stock for held orders, but don't complete payment
				else
					$order->payment_complete(); // mark order as having received payment

				$woocommerce->cart->empty_cart();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

		} catch ( Exception $e ) {

			$this->mark_order_as_failed( $order, $e->getMessage() );

		}
	}


	/**
	 * Add payment and transaction information as class members of WC_Order
	 * instance.  The standard information that can be added includes:
	 *
	 * $order->payment_total           - the payment total
	 * $order->customer_id             - optional payment gateway customer id (useful for tokenized payments, etc)
	 * $order->payment->account_number - the credit card or checking account number
	 * $order->payment->routing_number - account routing number (check transactions only)
	 * $order->payment->account_type   - optional type of account one of 'checking' or 'savings' if type is 'check'
	 * $order->payment->card_type      - optional card type, ie one of 'visa', etc
	 * $order->payment->exp_month      - the credit card expiration month (for credit card gateways)
	 * $order->payment->exp_year       - the credit card expiration year (for credit card gateways)
	 * $order->payment->csc            - the card security code (for credit card gateways)
	 * $order->payment->check_number   - optional check number (check transactions only)
	 * $order->payment->drivers_license_number - optional driver license number (check transactions only)
	 * $order->payment->drivers_license_state  - optional driver license state code (check transactions only)
	 * $order->payment->token          - payment token (for tokenized transactions)
	 *
	 * Note that not all gateways will necessarily pass or require all of the
	 * above.  These represent the most common attributes used among a variety
	 * of gateways, it's up to the specific gateway implementation to make use
	 * of, or ignore them, or add custom ones by overridding this method.
	 *
	 * Note: we could consider adding birthday to the list here, but do any gateways besides NETBilling use this one?
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::get_order()
	 * @param int $order_id order ID being processed
	 * @return WC_Order object with payment and transaction information attached
	 */
	protected function get_order( $order_id ) {

		$order = parent::get_order( $order_id );

		// payment info
		if ( $this->get_post( 'wc-' . $this->get_id_dasherized() . '-account-number' ) && ! $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' ) ) {

			// common attributes
			$order->payment->account_number = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-account-number' );

			if ( $this->is_credit_card_gateway() ) {

				// credit card specific attributes
				$order->payment->card_type      = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-card-type' );
				$order->payment->exp_month      = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-exp-month' );
				$order->payment->exp_year       = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-exp-year' );

				if ( $this->csc_enabled() ) {
					$order->payment->csc        = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-csc' );
				}

			} else {

				// echeck specific attributes
				$order->payment->routing_number         = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-routing-number' );
				$order->payment->account_type           = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-account-type' );
				$order->payment->check_number           = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-check-number' );
				$order->payment->drivers_license_number = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-drivers-license-number' );
				$order->payment->drivers_license_state  = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-drivers-license-state' );

			}

		} elseif ( $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' ) ) {

			// paying with tokenized payment method (we've already verified that this token exists in the validate_fields method)
			$token = $this->get_payment_token( $order->user_id, $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' ) );

			$order->payment->token          = $token->get_token();
			$order->payment->account_number = $token->get_last_four();

			if ( $this->is_credit_card_gateway() ) {

				// credit card specific attributes
				$order->payment->card_type    = $token->get_card_type();
				$order->payment->exp_month    = $token->get_exp_month();
				$order->payment->exp_year     = $token->get_exp_year();

				if ( $this->csc_enabled() ) {
					$order->payment->csc      = $this->get_post( 'wc-' . $this->get_id_dasherized() . '-csc' );
				}

			} else {

				// echeck specific attributes
				$order->payment->account_type = $token->get_account_type();

			}

			// make this the new default payment token
			$this->set_default_payment_token( $order->user_id, $token );
		}

		// allow other actors to modify the order object
		return apply_filters( 'wc_payment_gateway_' . $this->get_id() . '_get_order', $order, $this );

	}


	/**
	 * Performs a check transaction for the given order and returns the
	 * result
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @return SV_WC_Payment_Gateway_API_Response the response
	 * @throws Exception network timeouts, etc
	 */
	protected function do_check_transaction( $order ) {

		$response = $this->get_api()->check_debit( $order );

		// success! update order record
		if ( $response->transaction_approved() ) {

			$last_four = substr( $order->payment->account_number, -4 );

			// check order note.  there may not be an account_type available, but that's fine
			$message = sprintf( __( '%s Check Transaction Approved: %s account ending in %s', $this->text_domain ), $this->get_method_title(), $order->payment->account_type, $last_four );

			// optional check number
			if ( ! empty( $order->payment->check_number ) ) {
				$message .= '. ' . sprintf( __( 'Check number %s', $this->text_domain ), $order->payment->check_number );
			}

			// adds the transaction id (if any) to the order note
			if ( $response->get_transaction_id() ) {
				$message .= ' ' . sprintf( __( '(Transaction ID %s)', $this->text_domain ), $response->get_transaction_id() );
			}

			$order->add_order_note( $message );

		}

		return $response;

	}


	/**
	 * Performs a credit card transaction for the given order and returns the
	 * result
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @return SV_WC_Payment_Gateway_API_Response the response
	 * @throws Exception network timeouts, etc
	 */
	protected function do_credit_card_transaction( $order ) {

		if ( $this->perform_credit_card_charge() ) {
			$response = $this->get_api()->credit_card_charge( $order );
		} else {
			$response = $this->get_api()->credit_card_authorization( $order );
		}

		// success! update order record
		if ( $response->transaction_approved() ) {

			$last_four = substr( $order->payment->account_number, -4 );

			// credit card order note
			$message = sprintf(
				__( '%s %s Approved: %s ending in %s (expires %s)', $this->text_domain ),
				$this->get_method_title(),
				$this->perform_credit_card_authorization() ? 'Authorization' : 'Charge',
				isset( $order->payment->card_type ) && $order->payment->card_type ? SV_WC_Payment_Gateway_Payment_Token::type_to_name( $order->payment->card_type ) : 'card',
				$last_four,
				$order->payment->exp_month . '/' . substr( $order->payment->exp_year, -2 )
			);

			// adds the transaction id (if any) to the order note
			if ( $response->get_transaction_id() ) {
				$message .= ' ' . sprintf( __( '(Transaction ID %s)', $this->text_domain ), $response->get_transaction_id() );
			}

			$order->add_order_note( $message );

		}

		return $response;

	}


	/**
	 * Create a transaction
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @return bool true if transaction was successful, false otherwise
	 * @throws Exception network timeouts, etc
	 */
	protected function do_transaction( $order ) {

		// perform the credit card or check transaction
		$response = $this->is_credit_card_gateway() ? $this->do_credit_card_transaction( $order ) : $this->do_check_transaction( $order );

		// handle the response
		if ( $response->transaction_approved() || $response->transaction_held() ) {

			// add the standard transaction data
			$this->add_transaction_data( $order, $response );

			// allow the concrete class to add any gateway-specific transaction data to the order
			$this->add_payment_gateway_transaction_data( $order, $response );

			// if the transaction was held (ie fraud validation failure) mark it as such
			if ( $response->transaction_held() ) {
				$this->mark_order_as_held( $order, $response->get_status_message() );
			}

			return true;

		} else { // failure

			return $this->do_transaction_failed_result( $order, $response );

		}
	}


	/**
	 * Called after an unsuccessful transaction attempt
	 *
	 * @since 1.0
	 * @param WC_Order $order the order
	 * @param SV_WC_Payment_Gateway_API_Response $response the transaction response
	 * @return boolean false
	 */
	protected function do_transaction_failed_result( WC_Order $order, SV_WC_Payment_Gateway_API_Response $response ) {

		$order_note = sprintf( '%s: "%s"', $response->get_status_code(), $response->get_status_message() );

		// add transaction id if there is one
		if ( $response->get_transaction_id() ) {
			$order_note .= '. ' . sprintf( __( 'Transaction id %s', $this->text_domain ), $response->get_transaction_id() );
		}

		$this->mark_order_as_failed( $order, $order_note );

		return false;
	}


	/**
	 * Adds the standard transaction data to the order
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @param SV_WC_Payment_Gateway_API_Response|null $response optional transaction response
	 */
	protected function add_transaction_data( $order, $response = null ) {

		// payment info
		if ( isset( $order->payment->token ) && $order->payment->token )
			update_post_meta( $order->id, '_wc_' . $this->get_id() . '_payment_token', $order->payment->token );

		// account number
		if ( isset( $order->payment->account_number ) && $order->payment->account_number )
			update_post_meta( $order->id, '_wc_' . $this->get_id() . '_account_four', substr( $order->payment->account_number, -4 ) );

		if ( $this->is_credit_card_gateway() ) {

			// credit card gateway data

			if ( isset( $order->payment->exp_year ) && $order->payment->exp_year && isset( $order->payment->exp_month ) && $order->payment->exp_month )
				update_post_meta( $order->id, '_wc_' . $this->get_id() . '_card_expiry_date', $order->payment->exp_year . '-' . $order->payment->exp_month );

			if ( isset( $order->payment->card_type ) && $order->payment->card_type )
				update_post_meta( $order->id, '_wc_' . $this->get_id() . '_card_type', $order->payment->card_type );

		} else {

			// checking gateway data

			// optional account type (checking/savings)
			if ( isset( $order->payment->account_type ) && $order->payment->account_type )
				update_post_meta( $order->id, '_wc_' . $this->get_id() . '_account_type', $order->payment->account_type );

			// optional check number
			if ( isset( $order->payment->check_number ) && $order->payment->check_number )
				update_post_meta( $order->id, '_wc_' . $this->get_id() . '_check_number', $order->payment->account_type );

		}

		// if there's more than one environment
		if ( count( $this->get_environments() ) > 1 )
			update_post_meta( $order->id, '_wc_' . $this->get_id() . '_environment', $this->get_environment() );

		// if there is a payment gateway customer id, set it to the order (we don't append the environment here like we do for the user meta, because it's available from the 'environment' order meta already)
		if ( isset( $order->customer_id ) && $order->customer_id )
			update_post_meta( $order->id, '_wc_' . $this->get_id() . '_customer_id', $order->customer_id );

	}


	/**
	 * Adds any gateway-specific transaction data to the order
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @param SV_WC_Payment_Gateway_API_Response $response the transaction response
	 */
	protected function add_payment_gateway_transaction_data( $order, $response ) {

		// Optional method

	}


	/** Subscriptions feature ******************************************************/


	/**
	 * Returns true if this gateway with its current configuration supports subscriptions
	 *
	 * @since 1.0
	 * @return boolean true if the gateway supports subscriptions
	 */
	public function supports_subscriptions() {

		return $this->supports( self::FEATURE_SUBSCRIPTIONS ) && $this->supports_tokenization() && $this->tokenization_enabled();

	}


	/**
	 * Adds support for subscriptions by hooking in some necessary actions
	 *
	 * @since 1.0
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if subscriptions are not supported by this gateway or its current configuration
	 */
	public function add_subscriptions_support() {

		if ( ! $this->supports_subscriptions() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Subscriptions not supported by gateway', $this->text_domain ) );

		// force tokenization when needed
		add_filter( 'wc_payment_gateway_' . $this->get_id() . '_tokenization_forced', array( $this, 'subscriptions_tokenization_forced' ) );

		// add subscriptions data to the order object
		add_filter( 'wc_payment_gateway_' . $this->get_id() . '_get_order', array( $this, 'subscriptions_get_order' ) );

		// process scheduled subscription payments
		add_action( 'scheduled_subscription_payment_' . $this->get_id(), array( $this, 'process_subscription_renewal_payment' ), 10, 3 );

		// prevent unnecessary order meta from polluting parent renewal orders
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'remove_subscription_renewal_order_meta' ), 10, 4 );

		// display the current payment method used for a subscription in the "My Subscriptions" table
		add_filter( 'woocommerce_my_subscriptions_recurring_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 3 );
	}


	/**
	 * Force tokenization for subscriptions, this can be forced either during checkout
	 * or when the payment method for a subscription is being changed
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::tokenization_forced()
	 * @param bool $force_tokenization whether tokenization should be forced
	 * @return bool true if tokenization should be forced, false otherwise
	 */
	public function subscriptions_tokenization_forced( $force_tokenization ) {

		if ( WC_Subscriptions_Cart::cart_contains_subscription() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment )
			$force_tokenization = true;

		return $force_tokenization;
	}


	/**
	 * Adds subscriptions data to the order object
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::get_order()
	 * @param WC_Order $order the order
	 * @return WC_Order the orders
	 */
	public function subscriptions_get_order( $order ) {

		// bail if the gateway doesn't support subscriptions or the order doesn't contain a subscription
		if ( ! $this->supports_subscriptions() || ! WC_Subscriptions_Order::order_contains_subscription( $order->id ) )
			return $order;

		// subscriptions total, ensuring that we have a decimal point, even if it's 1.00
		$order->payment_total = number_format( (double) WC_Subscriptions_Order::get_total_initial_payment( $order ), 2, '.', '' );

		// load any required members that we might not have
		if ( ! isset( $order->payment->token ) || ! $order->payment->token )
			$order->payment->token = get_post_meta( $order->id, '_wc_' . $this->get_id() . '_payment_token', true );

		if ( ! isset( $order->customer_id ) || ! $order->customer_id )
			$order->customer_id = get_post_meta( $order->id, '_wc_' . $this->get_id() . '_customer_id', true );

		// ensure the payment token is still valid
		if ( ! $this->has_payment_token( $order->user_id, $order->payment->token ) ) {
			$order->payment->token = null;
		} else {

			// get the token object
			$token = $this->get_payment_token( $order->user_id, $order->payment->token );

			if ( ! isset( $order->payment->account_number ) || ! $order->payment->account_number )
				$order->payment->account_number = $token->get_last_four();

			if ( $this->is_credit_card_gateway() ) {

				// credit card token

				if ( ! isset( $order->payment->card_type ) || ! $order->payment->card_type )
					$order->payment->card_type = $token->get_card_type();

				if ( ! isset( $order->payment->exp_month ) || ! $order->payment->exp_month )
					$order->payment->exp_month = $token->get_exp_month();

				if ( ! isset( $order->payment->exp_month ) || ! $order->payment->exp_month )
					$order->payment->exp_month = $token->get_exp_month();

			} else {

				// check token

				if ( ! isset( $order->payment->account_type ) || ! $order->payment->account_type )
					$order->payment->account_type = $token->get_account_type();

			}

		}

		return $order;
	}


	/**
	 * Process subscription renewal
	 *
	 * @since 1.0
	 * @param float $amount_to_charge subscription amount to charge, could include multiple renewals if they've previously failed and the admin has enabled it
	 * @param WC_Order $order original order containing the subscription
	 * @param int $product_id the subscription product id
	 */
	public function process_subscription_renewal_payment( $amount_to_charge, $order, $product_id ) {

		try {

			// set order defaults
			$order = $this->get_order( $order->id );

			// set the amount to charge, ensuring that we have a decimal point, even if it's 1.00
			$order->payment_total = number_format( (double) $amount_to_charge, 2, '.', '' );

			// required
			if ( ! $order->payment->token || ! $order->user_id )
				throw new Exception( __( 'Subscription Renewal: Payment Token or User ID is missing/invalid.', $this->text_domain ) );

			// get the token, we've already verified it's good
			$token = $this->get_payment_token( $order->user_id, $order->payment->token );

			// perform the transaction
			if ( $this->is_credit_card_gateway() ) {

				if ( $this->perform_credit_card_charge() ) {
					$response = $this->get_api()->credit_card_charge( $order );
				} else {
					$response = $this->get_api()->credit_card_authorization( $order );
				}

			} else {

				$response = $this->get_api()->check_debit( $order );

			}

			// check for success  TODO: handle transaction held
			if ( $response->transaction_approved() ) {

				// order note based on gateway type
				if ( $this->is_credit_card_gateway() ) {
					$message = sprintf(
						__( '%s %s Subscription Renewal Payment Approved: %s ending in %s (expires %s)', $this->text_domain ),
						$this->get_method_title(),
						$this->perform_credit_card_authorization() ? 'Authorization' : 'Charge',
						$token->get_card_type() ? $token->get_type_full() : 'card',
						$token->get_last_four(),
						$token->get_exp_month() . '/' . $token->get_exp_year()
					);
				} elseif( $this->is_check_gateway() ) {

					// there may or may not be an account type (checking/savings) available, which is fine
					$message = sprintf( __( '%s Check Subscription Renewal Payment Approved: %s account ending in %s', $this->text_domain ), $this->get_method_title(), $token->get_account_type(), $token->get_last_four() );

				}

				// add order note
				$order->add_order_note( $message );

				// update subscription
				WC_Subscriptions_Manager::process_subscription_payments_on_order( $order, $product_id );

			} else {

				// failure
				throw new Exception( sprintf( '%s: %s', $response->get_status_code(), $response->get_status_message() ) );
			}

		} catch ( Exception $e ) {

			$this->mark_order_as_failed( $order, $e->getMessage() );

			// update subscription
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
		}
	}


	/**
	 * Don't copy over gateway-specific order meta when creating a parent renewal order.
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::get_remove_subscription_renewal_order_meta_fragment()
	 * @param array $order_meta_query MySQL query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return string MySQL meta query for pulling the metadata, excluding data added by this gateway
	 */
	public function remove_subscription_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		// all required and optional
		if ( 'parent' == $new_order_role ) {
			$order_meta_query .= $this->get_remove_subscription_renewal_order_meta(
				array(
					'_wc_' . $this->get_id() . '_payment_token',
					'_wc_' . $this->get_id() . '_account_four',
					'_wc_' . $this->get_id() . '_card_expiry_date',
					'_wc_' . $this->get_id() . '_card_type',
					'_wc_' . $this->get_id() . '_account_type',
					'_wc_' . $this->get_id() . '_check_number',
					'_wc_' . $this->get_id() . '_environment',
					'_wc_' . $this->get_id() . '_customer_id',
				)
			);
		}

		return $order_meta_query;
	}


	/**
	 * Returns the query fragment to remove the given subscription renewal order meta
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::remove_subscription_renewal_order_meta()
	 * @param array $meta_names array of string meta names to remove
	 * @return string query fragment
	 */
	protected function get_remove_subscription_renewal_order_meta_fragment( $meta_names ) {

		return " AND `meta_key` NOT IN ( '" . join( "', '", $meta_names ) . "' )";
	}


	/**
	 * Adds support for subscriptions by hooking in some necessary actions
	 *
	 * @since 1.0
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if subscriptions or subscription payment method changes are not supported by this gateway or its current configuration
	 */
	public function add_subscription_payment_method_change_support() {

		if ( ! $this->supports_subscriptions() || ! $this->supports( self::FEATURE_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ) ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Subscription payment method change not supported by gateway', $this->text_domain ) );

		// update the customer/token ID on the original order when making payment for a failed automatic renewal order
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_' . $this->get_id(), array( $this, 'update_failing_payment_method' ), 10, 2 );
	}


	/**
	 * Update the customer id/payment token for a subscription after a customer
	 * uses this gateway to successfully complete the payment for an automatic
	 * renewal payment which had previously failed.
	 *
	 * @since 1.0
	 * @param WC_Order $original_order the original order in which the subscription was purchased.
	 * @param WC_Order $renewal_order the order which recorded the successful payment (to make up for the failed automatic payment).
	 */
	public function update_failing_payment_method( WC_Order $original_order, WC_Order $renewal_order ) {

		update_post_meta( $original_order->id, '_wc_' . $this->get_id() . '_customer_id',   get_post_meta( $renewal_order->id, '_wc_' . $this->get_id() . '_customer_id', true ) );
		update_post_meta( $original_order->id, '_wc_' . $this->get_id() . '_payment_token', get_post_meta( $renewal_order->id, '_wc_' . $this->get_id() . '_payment_token', true ) );
	}


	/**
	 * Render the payment method used for a subscription in the "My Subscriptions" table
	 *
	 * @since 1.0
	 * @param string $payment_method_to_display the default payment method text to display
	 * @param array $subscription_details the subscription details
	 * @param WC_Order $order the order containing the subscription
	 * @return string the subscription payment method
	 */
	public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription_details, WC_Order $order ) {

		// bail for other payment methods
		if ( $this->get_id() !== $order->recurring_payment_method )
			return $payment_method_to_display;

		$token = $this->get_payment_token( $order->user_id, get_post_meta( $order->id, '_wc_' . $this->get_id() . '_payment_token', true ) );

		if ( is_object( $token )  )
			$payment_method_to_display = sprintf( 'Via %s ending in %s', $token->get_type_full(), $token->get_last_four() );

		return $payment_method_to_display;
	}


	/** Pre-Orders feature ******************************************************/


	/**
	 * Returns true if this gateway with its current configuration supports pre-orders
	 *
	 * @since 1.0
	 * @return boolean true if the gateway supports pre-orders
	 */
	public function supports_pre_orders() {

		return $this->supports( self::FEATURE_PRE_ORDERS ) && $this->supports_tokenization() && $this->tokenization_enabled();

	}


	/**
	 * Adds support for pre-orders by hooking in some necessary actions
	 *
	 * @since 1.0
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if pre-orders are not supported by this gateway or its current configuration
	 */
	public function add_pre_orders_support() {

		if ( ! $this->supports_pre_orders() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Pre-Orders not supported by gateway', $this->text_domain ) );

		// force tokenization when needed
		add_filter( 'wc_payment_gateway_' . $this->get_id() . '_tokenization_forced', array( $this, 'pre_orders_tokenization_forced' ) );

		// add pre-orders data to the order object
		add_filter( 'wc_payment_gateway_' . $this->get_id() . '_get_order', array( $this, 'pre_orders_get_order' ) );

		// process pre-order initial payment as needed
		add_filter( 'wc_payment_gateway_' . $this->get_id() . '_process_payment', array( $this, 'process_pre_order_payment' ), 10, 2 );

		// process batch pre-order payments
		add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->get_id(), array( $this, 'process_pre_order_release_payment' ) );

	}

	/**
	 * Force tokenization for pre-orders
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::tokenization_forced()
	 * @param boolean $force_tokenization whether tokenization should be forced
	 * @return boolean true if tokenization should be forced, false otherwise
	 */
	public function pre_orders_tokenization_forced( $force_tokenization ) {

		if ( class_exists( 'WC_Pre_Orders_Cart' ) && WC_Pre_Orders_Cart::cart_contains_pre_order() &&
			class_exists( 'WC_Pre_Orders_Product' ) && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() ) ) {

			// always tokenize the card for pre-orders that are charged upon release
			$force_tokenization = true;

		}

		return $force_tokenization;

	}


	/**
	 * Adds pre-orders data to the order object.  Filtered onto SV_WC_Payment_Gateway::get_order()
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::get_order()
	 * @param WC_Order $order the order
	 * @return WC_Order the orders
	 */
	public function pre_orders_get_order( $order ) {

		// bail if order doesn't contain a pre-order
		if ( ! WC_Pre_Orders_Order::order_contains_pre_order( $order ) )
			return $order;

		if ( WC_Pre_Orders_Order::order_requires_payment_tokenization( $order ) ) {

			// normally a guest user wouldn't be assigned a customer id, but for a pre-order requiring tokenization, it will be
			if ( 0 == $order->user_id && false !== ( $customer_id = $this->get_guest_customer_id( $order ) ) )
				$order->customer_id = $customer_id;

		} elseif ( WC_Pre_Orders_Order::order_has_payment_token( $order ) ) {

			// if this is a pre-order release payment with a tokenized payment method, get the payment token to complete the order

			// retrieve the payment token
			$order->payment->token = get_post_meta( $order->id, '_wc_' . $this->get_id() . '_payment_token', true );

			// retrieve the customer id
			$order->customer_id = get_post_meta( $order->id, '_wc_' . $this->get_id() . '_customer_id', true );

			// verify that this customer still has the token tied to this order.  Pass in customer_id to support tokenized guest orders
			if ( ! $this->has_payment_token( $order->user_id, $order->payment->token, $order->customer_id ) ) {

				$order->payment->token = null;

			} else {
				// Push expected payment data into the order, from the payment token when possible,
				//  or from the order object otherwise.  The theory is that the token will have the
				//  most up-to-date data, while the meta attached to the order is a second best

				// for a guest transaction with a gateway that doesn't support "tokenization get" this will return null
				$token = $this->get_payment_token( $order->user_id, $order->payment->token, $order->customer_id );

				// account last four
				$order->payment->account_number = $token && $token->get_last_four() ? $token->get_last_four() : get_post_meta( $order->id, '_wc_' . $this->get_id() . '_account_four', true );

				if ( $this->is_credit_card_gateway() ) {

					$order->payment->card_type = $token && $token->get_card_type() ? $token->get_card_type() : get_post_meta( $order->id, '_wc_' . $this->get_id() . '_card_type', true );

					if ( $token && $token->get_exp_month() && $token->get_exp_year() ) {
						$order->payment->exp_month  = $token->get_exp_month();
						$order->payment->exp_year   = $token->get_exp_year();
					} else {
						list( $exp_year, $exp_month ) = explode( '-', get_post_meta( $order->id, '_wc_' . $this->get_id() . '_card_expiry_date', true ) );
						$order->payment->exp_month  = $exp_month;
						$order->payment->exp_year   = $exp_year;
					}

				} else {

					// set the account type if available (checking/savings)
					$order->payment->account_type = $token && $token->get_account_type ? $token->get_account_type() : get_post_meta( $order->id, '_wc_' . $this->get_id() . '_account_type', true );

				}

			}

		}

		return $order;
	}


	/**
	 * Handle the pre-order initial payment/tokenization, or defer back to the normal payment
	 * processing flow
	 *
	 * @since 1.0
	 * @see SV_WC_Payment_Gateway::process_payment()
	 * @param boolean $result the result of this pre-order payment process
	 * @param int $order_id the order identifier
	 * @return true|array true to process this payment as a regular transaction, otherwise
	 *         return an array containing keys 'result' and 'redirect'
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if pre-orders are not supported by this gateway or its current configuration
	 */
	public function process_pre_order_payment( $result, $order_id ) {

		global $woocommerce;

		if ( ! $this->supports_pre_orders() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Pre-Orders not supported by gateway', $this->text_domain ) );

		if ( $this->get_plugin()->is_pre_orders_active() && class_exists( 'WC_Pre_Orders_Order' ) &&
			WC_Pre_Orders_Order::order_contains_pre_order( $order_id ) &&
			WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id ) ) {

			$order = $this->get_order( $order_id );

			try {

				// using an existing tokenized payment method
				if ( isset( $order->payment->token ) && $order->payment->token ) {

					// save the tokenized card info for completing the pre-order in the future
					$this->add_transaction_data( $order );

				} else {

					// otherwise tokenize the payment method
					$order = $this->create_payment_token( $order );
				}

				$token = $this->get_payment_token( get_current_user_id(), $order->payment->token );

				// order note based on gateway type
				if ( $this->is_credit_card_gateway() ) {
					$message = sprintf( __( '%s Pre-Order Payment Token Saved: %s ending in %s (expires %s)', $this->text_domain ),
						$this->get_method_title(),
						$token->get_type_full(),
						$token->get_last_four(),
						$token->get_exp_date()
					);
				} else {
					// account type (checking/savings) may or may not be available, which is fine
					$message = sprintf( __( '%s Pre-Order eCheck Payment Token Saved: %s account ending in %s', $this->text_domain ),
						$this->get_method_title(),
						$token->get_account_type(),
						$token->get_last_four()
					);
				}

				$order->add_order_note( $message );

				// mark order as pre-ordered / reduce order stock
				WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );

				// empty cart
				$woocommerce->cart->empty_cart();

				// redirect to thank you page
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);

			} catch( Exception $e ) {

				$this->mark_order_as_failed( $order, sprintf( __( 'Pre-Order Tokenization attempt failed (%s)', $this->text_domain ), $this->get_method_title(), $e->getMessage() ) );

			}
		}

		// processing regular product
		return $result;
	}


	/**
	 * Process a pre-order payment when the pre-order is released
	 *
	 * @since 1.0
	 * @param WC_Order $order original order containing the pre-order
	 */
	public function process_pre_order_release_payment( $order ) {

		try {

			// set order defaults
			$order = $this->get_order( $order->id );

			// order description
			$order->description = sprintf( __( '%s - Pre-Order Release Payment for Order %s', $this->text_domain ), esc_html( get_bloginfo( 'name' ) ), $order->get_order_number() );

			// token is required
			if ( ! $order->payment->token )
				throw new Exception( __( 'Payment token missing/invalid.', $this->text_domain ) );

			// perform the transaction
			if ( $this->is_credit_card_gateway() ) {

				if ( $this->perform_credit_card_charge() ) {
					$response = $this->get_api()->credit_card_charge( $order );
				} else {
					$response = $this->get_api()->credit_card_authorization( $order );
				}

			} else {

				$response = $this->get_api()->check_debit( $order );

			}

			// success! update order record
			if ( $response->transaction_approved() ) {

				$last_four = substr( $order->payment->account_number, -4 );

				// order note based on gateway type
				if ( $this->is_credit_card_gateway() ) {

					$message = sprintf(
						__( '%s %s Pre-Order Release Payment Approved: %s ending in %s (expires %s)', $this->text_domain ),
						$this->get_method_title(),
						$this->perform_credit_card_authorization() ? 'Authorization' : 'Charge',
						isset( $order->payment->card_type ) && $order->payment->card_type ? SV_WC_Payment_Gateway_Payment_Token::type_to_name( $order->payment->card_type ) : 'card',
						$last_four,
						$order->payment->exp_month . '/' . substr( $order->payment->exp_year, -2 )
					);

				} elseif ( $this->is_check_gateway() ) {

					// account type (checking/savings) may or may not be available, which is fine
					$message = sprintf( __( '%s eCheck Pre-Order Release Payment Approved: %s account ending in %s', $this->text_domain ), $this->get_method_title(), $order->payment->account_type, $last_four );

				}

				// adds the transaction id (if any) to the order note
				if ( $response->get_transaction_id() ) {
					$message .= ' ' . sprintf( __( '(Transaction ID %s)', $this->text_domain ), $response->get_transaction_id() );
				}

				$order->add_order_note( $message );
			}

			if ( $response->transaction_approved() || $response->transaction_held() ) {

				// add the standard transaction data
				$this->add_transaction_data( $order, $response );

				// allow the concrete class to add any gateway-specific transaction data to the order
				$this->add_payment_gateway_transaction_data( $order, $response );

				// if the transaction was held (ie fraud validation failure) mark it as such
				if ( $response->transaction_held() ) {

					$this->mark_order_as_held( $order, $response->get_status_message() );
					$order->reduce_order_stock(); // reduce stock for held orders, but don't complete payment

				} else {
					// otherwise complete the order
					$order->payment_complete();
				}

			} else {

				// failure
				throw new Exception( sprintf( '%s: %s', $response->get_status_code(), $response->get_status_message() ) );

			}

		} catch ( Exception $e ) {

			// Mark order as failed
			$this->mark_order_as_failed( $order, sprintf( __( 'Pre-Order Release Payment Failed: %s', $this->text_domain ), $e->getMessage() ) );

		}
	}


	/** Tokenization feature ******************************************************/


	/**
	 * A factory method to build and return a payment token object for the
	 * gateway.  Concrete classes can override this method to return a custom
	 * payment token implementation.
	 *
	 * Payment token data can include:
	 *
	 * + `default`   - boolean optional indicates this is the default payment token
	 * + `type`      - string credit card type (visa, mc, amex, disc, diners, jcb) or echeck
	 * + `last_four` - string last four digits of account number
	 * + `exp_month` - string optional expiration month (credit card only)
	 * + `exp_year`  - string optional expiration year (credit card only)
	 *
	 * @since 1.0
	 * @param string $token payment token
	 * @param array $data payment token data
	 * @return SV_WC_Payment_Gateway_Payment_Token payment token
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	protected function build_payment_token( $token, $data ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		return new SV_WC_Payment_Gateway_Payment_Token( $token, $data );

	}


	/**
	 * Tokenizes the current payment method and adds the standard transaction
	 * data to the order post record.
	 *
	 * @since 1.0
	 * @param WC_Order $order the order object
	 * @return WC_Order the order object
	 * @throws Exception on network error or request error
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	protected function create_payment_token( $order ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// perform the API request to tokenize the payment method
		$response = $this->get_api()->tokenize_payment_method( $order );

		if ( $response->transaction_approved() ) {

			// add the token to the order object for processing
			$token                 = $response->get_payment_token();
			$order->payment->token = $token->get_token();

			// for credit card transactions add the card type, if known (some gateways return the credit card type as part of the response, others may require it as part of the request, and still others it may never be known)
			if ( $this->is_credit_card_gateway() && $token->get_card_type() )
				$order->payment->card_type = $token->get_card_type();

			// checking/savings, if known
			if ( $this->is_check_gateway() && $token->get_account_type() )
				$order->payment->account_type = $token->get_account_type();

			// set the token to the user account
			if ( $order->user_id )
				$this->add_payment_token( $order->user_id, $token );

			// add the standard transaction data
			$this->add_transaction_data( $order, $response );

		} else {
			throw new Exception( sprintf( '%s: %s', $response->get_status_code(), $response->get_status_message() ) );
		}

		return $order;
	}


	/**
	 * Returns true if tokenization should be forced on the checkout page,
	 * false otherwise.  This is most useful to force tokenization for a
	 * subscription or pre-orders initial transaction.
	 *
	 * @since 1.0
	 * @return boolean true if tokenization should be forced on the checkout page, false otherwise
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function tokenization_forced() {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// otherwise generally no need to force tokenization
		return apply_filters( 'wc_payment_gateway_' . $this->get_id() . '_tokenization_forced', false, $this );
	}


	/**
	 * Returns true if the current payment method should be tokenized: whether
	 * requested by customer or otherwise forced.  This parameter is passed from
	 * the checkout page/payment form.
	 *
	 * @since 1.0
	 * @return boolean true if the current payment method should be tokenized
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	protected function should_tokenize_payment_method() {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		return $this->get_post( 'wc-' . $this->get_id_dasherized() . '-tokenize-payment-method' ) && ! $this->get_post( 'wc-' . $this->get_id_dasherized() . '-payment-token' );

	}


	/**
	 * Returns the payment token user meta name for persisting the payment tokens.
	 * Defaults to _wc_{gateway id}_payment_tokens for the production environment,
	 * and _wc_{gateway id}_payment_tokens_{environment} for any other environment.
	 *
	 * NOTE: the gateway id, rather than plugin id, is used by default to create
	 * the meta key for this setting, because it's assumed that in the case of a
	 * plugin having multiple gateways (ie credit card and eCheck) the payment
	 * tokens will be distinct between them
	 *
	 * @since 1.0
	 * @param string $environment_id optional environment id, defaults to plugin current environment
	 * @return string payment token user meta name
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function get_payment_token_user_meta_name( $environment_id = null ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// default to current environment
		if ( is_null( $environment_id ) )
			$environment_id = $this->get_environment();

		// leading underscore since this will never be displayed to an admin user in its raw form
		return '_wc_' . $this->get_id() . '_payment_tokens' . ( ! $this->is_production_environment( $environment_id ) ? '_' . $environment_id : '' );

	}


	/**
	 * Get the available payment tokens for a user as an associative array of
	 * payment token to SV_WC_Payment_Gateway_Payment_Token
	 *
	 * @since 1.0
	 * @param int $user_id WordPress user identifier, or 0 for guest
	 * @param string $customer_id optional unique customer identifier, if not provided this will be looked up based on $user_id which cannot be 0 in that case
	 * @return array associative array of string token to SV_WC_Payment_Gateway_Payment_Token object
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function get_payment_tokens( $user_id, $customer_id = null ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		if ( is_null( $customer_id ) ) {
			$customer_id = $this->get_customer_id( $user_id );
		}

		// return tokens cached during a single request
		if ( isset( $this->tokens[ $customer_id ] ) )
			return $this->tokens[ $customer_id ];

		$this->tokens[ $customer_id ] = array();
		$tokens = array();

		// retrieve the datastore persisted tokens first, so we have a fallback, as well as the default token
		if ( $user_id ) {

			$_tokens = get_user_meta( $user_id, $this->get_payment_token_user_meta_name(), true );

			// from database format
			if ( is_array( $_tokens ) ) {
				foreach ( $_tokens as $token => $data ) {
					$tokens[ $token ] = $this->build_payment_token( $token, $data );
				}
			}

			$this->tokens[ $customer_id ] = $tokens;
		}

		// if the payment gateway API supports retrieving tokens directly, do so as it's easier to stay synchronized
		if ( $this->get_api()->supports_get_tokenized_payment_methods() ) {

			try {

				// retrieve the payment method tokes from the remote API
				$response = $this->get_api()->get_tokenized_payment_methods( $customer_id );
				$this->tokens[ $customer_id ] = $response->get_payment_tokens();

				// check for a default from the persisted set, if any
				$default_token = null;
				foreach ( $tokens as $default_token ) {
					if ( $default_token->is_default() )
						break;
				}

				// mark the corresponding token from the API as the default one
				if ( $default_token && $default_token->is_default() && isset( $this->tokens[ $customer_id ][ $default_token->get_token() ] ) ) {
					$this->tokens[ $customer_id ][ $default_token->get_token() ]->set_default( true );
				}

			} catch( Exception $e ) {
				// communication or other error, fallback to the locally stored tokens
				$this->tokens[ $customer_id ] = $tokens;
			}

		}

		// set the payment type image url, if any, for convenience
		foreach ( $this->tokens[ $customer_id ] as $key => $token ) {
			$this->tokens[ $customer_id ][ $key ]->set_image_url( $this->get_payment_method_image_url( $token->is_credit_card() ? $token->get_card_type() : 'echeck' ) );
		}

		return $this->tokens[ $customer_id ];
	}


	/**
	 * Updates the given payment tokens for the identified user, in the database.
	 *
	 * @since 1.0
	 * @param int $user_id wordpress user identifier
	 * @param array $tokens array of tokens
	 * @return string updated user meta id
	 */
	protected function update_payment_tokens( $user_id, $tokens ) {

		// update the local cache
		$this->tokens[ $this->get_customer_id( $user_id ) ] = $tokens;

		// persist the updated tokens to the user meta
		return update_user_meta( $user_id, $this->get_payment_token_user_meta_name(), $this->payment_tokens_to_database_format( $tokens ) );
	}


	/**
	 * Returns the payment token object identified by $token from the user
	 * identified by $user_id
	 *
	 * @since 1.0
	 * @param int $user_id WordPress user identifier, or 0 for guest
	 * @param string $token payment token
	 * @param string $customer_id optional unique customer identifier, if not provided this will be looked up based on $user_id which cannot be 0
	 * @return SV_WC_Payment_Gateway_Payment_Token payment token object or null
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function get_payment_token( $user_id, $token, $customer_id = null ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		$tokens = $this->get_payment_tokens( $user_id, $customer_id );

		if ( isset( $tokens[ $token ] ) ) return $tokens[ $token ];

		return null;
	}


	/**
	 * Returns true if the identified user has the given payment token
	 *
	 * @since 1.0
	 * @param int $user_id WordPress user identifier, or 0 for guest
	 * @param string|SV_WC_Payment_Gateway_Payment_Token $token payment token
	 * @param string $customer_id optional unique customer identifier, if not provided this will be looked up based on $user_id which cannot be 0
	 * @return boolean true if the user has the payment token, false otherwise
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function has_payment_token( $user_id, $token, $customer_id = null ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		if ( is_object( $token ) ) {
			$token = $token->get_token();
		}

		// this is sort of a weird edge case: verifying a token exists for a guest customer
		//  using an API that doesn't support a tokenized payment method query operation.
		//  We will neither have a user record in the db, nor can we query the API endpoint,
		//  so just return true
		// Sample case: Guest pre-order transaction using FirstData
		if ( ! $this->get_api()->supports_get_tokenized_payment_methods() && ! $user_id ) {
			return true;
		}

		// token exists?
		return ! is_null( $this->get_payment_token( $user_id, $token, $customer_id ) );
	}


	/**
	 * Add a payment method and token as user meta.
	 *
	 * @since 1.0
	 * @param int $user_id user identifier
	 * @param SV_WC_Payment_Gateway_Payment_Token $token the token
	 * @return bool|int false if token not added, user meta ID if added
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function add_payment_token( $user_id, $token ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// get existing tokens
		$tokens = $this->get_payment_tokens( $user_id );

		// if this token is set as active, mark all others as false
		if ( $token->is_default() ) {
			foreach ( array_keys( $tokens ) as $key ) {
				$tokens[ $key ]->set_default( false );
			}
		}

		// add the new token
		$tokens[ $token->get_token() ] = $token;

		// persist the updated tokens
		return $this->update_payment_tokens( $user_id, $tokens );
	}


	/**
	 * Delete a credit card token from user meta
	 *
	 * @since 1.0
	 * @param int $user_id user identifier
	 * @param SV_WC_Payment_Gateway_Payment_Token|string $token the payment token to delete
	 * @return bool|int false if not deleted, updated user meta ID if deleted
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function remove_payment_token( $user_id, $token ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// unknown token?
		if ( ! $this->has_payment_token( $user_id, $token ) )
			return false;

		// get the payment token object as needed
		if ( ! is_object( $token ) ) {
			$token = $this->get_payment_token( $user_id, $token );
		}

		// for direct gateways that allow it, attempt to delete the token from the endpoint
		if ( $this->get_api()->supports_remove_tokenized_payment_method() ) {

			try {

				$response = $this->get_api()->remove_tokenized_payment_method( $this->get_customer_id( $user_id ), $token->get_token() );

				if ( ! $response->transaction_approved() ) {
					return false;
				}

			} catch( Exception $e ) {
				return false;
			}
		}

		// get existing tokens
		$tokens = $this->get_payment_tokens( $user_id );

		unset( $tokens[ $token->get_token() ] );

		// if the deleted card was the default one, make another one the new default
		if ( $token->is_default() ) {
			foreach ( array_keys( $tokens ) as $key ) {
				$tokens[ $key ]->set_default( true );
				break;
			}
		}

		// persist the updated tokens
		return $this->update_payment_tokens( $user_id, $tokens );
	}


	/**
	 * Set the default token for a user. This is shown as "Default Card" in the
	 * frontend and will be auto-selected during checkout
	 *
	 * @since 1.0
	 * @param int $user_id user identifier
	 * @param SV_WC_Payment_Gateway_Payment_Token|string $token the token to make default
	 * @return string|bool false if not set, updated user meta ID if set
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function set_default_payment_token( $user_id, $token ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// unknown token?
		if ( ! $this->has_payment_token( $user_id, $token ) )
			return false;

		// get the payment token object as needed
		if ( ! is_object( $token ) ) {
			$token = $this->get_payment_token( $user_id, $token );
		}

		// get existing tokens
		$tokens = $this->get_payment_tokens( $user_id );

		// mark $token as the only active
		foreach ( $tokens as $key => $_token ) {

			if ( $token->get_token() == $_token->get_token() )
				$tokens[ $key ]->set_default( true );
			else
				$tokens[ $key ]->set_default( false );

		}

		// persist the updated tokens
		return $this->update_payment_tokens( $user_id, $tokens );

	}


	/**
	 * Returns $tokens in a format suitable for data storage
	 *
	 * @since 1.0
	 * @param int $user_id user identifier
	 * @param array $tokens array of SV_WC_Payment_Gateway_Payment_Token tokens
	 * @return array data storage version of $tokens
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	protected function payment_tokens_to_database_format( $tokens ) {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		$_tokens = array();

		// to database format
		foreach ( $tokens as $key => $token ) {
			$_tokens[ $key ] = $token->to_datastore_format();
		}

		return $_tokens;
	}


	/**
	 * Handle any actions from the 'My Payment Methods' section on the
	 * 'My Account' page
	 *
	 * @since 1.0
	 */
	public function handle_my_payment_methods_actions() {

		global $woocommerce;

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		if ( ! $this->is_available() || ! $this->tokenization_enabled() )
			return;

		$token  = isset( $_GET[ 'wc-' . $this->get_id_dasherized() . '-token' ] )  ? trim( $_GET[ 'wc-' . $this->get_id_dasherized() . '-token' ] ) : '';
		$action = isset( $_GET[ 'wc-' . $this->get_id_dasherized() . '-action' ] ) ? $_GET[ 'wc-' . $this->get_id_dasherized() . '-action' ] : '';

		// process payment method actions
		if ( $token && $action && ! empty( $_GET['_wpnonce'] ) ) {

			// security check
			if ( false === wp_verify_nonce( $_GET['_wpnonce'], 'wc-' . $this->get_id_dasherized() . '-token-action' ) ) {

				$woocommerce->add_error( __( "There was an error with your request, please try again.", $this->text_domain ) );
				$woocommerce->set_messages();
				wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
				exit;

			}

			// current logged in user
			$user_id = get_current_user_id();

			// handle deletion
			if ( 'delete' === $action ) {

				if ( ! $this->remove_payment_token( $user_id, $token ) ) {

					$woocommerce->add_error( __( "Error removing payment method", $this->text_domain ) );
					$woocommerce->set_messages();

				}

			}

			// handle default change
			if ( 'make-default' === $action ) {
				$this->set_default_payment_token( $user_id, $token );
			}

			// remove the query params
			wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
			exit;
		}
	}


	/**
	 * Display the 'My Payment Methods' section on the 'My Account'
	 *
	 * @since 1.0
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 */
	public function show_my_payment_methods() {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		if ( ! $this->is_available() || ! $this->tokenization_enabled() )
			return;

		// render the template
		$this->show_my_payment_methods_load_template();
	}


	/**
	 * Render the "My Payment Methods" template
	 *
	 * This is a stub method which must be overridden if this gateway supports
	 * tokenization
	 *
	 * @since 1.0
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if payment method tokenization is not supported
	 * @throws SV_WC_Payment_Gateway_Unimplemented_Method_Exception if this gateway supports direct communication but has not provided an implementation for this method
	 */
	protected function show_my_payment_methods_load_template() {

		if ( ! $this->supports_tokenization() ) throw new SV_WC_Payment_Gateway_Feature_Unsupported_Exception( __( 'Payment tokenization not supported by gateway', $this->text_domain ) );

		// concrete stub method
		throw new SV_WC_Payment_Gateway_Unimplemented_Method_Exception( get_class( $this ) . substr( __METHOD__, strpos( __METHOD__, '::' ) ) . "()" );
	}


	/** Direct Payment Gateway ******************************************************/


	/**
	 * Returns the API instance for this gateway if it is a direct communication
	 *
	 * This is a stub method which must be overridden if this gateway performs
	 * direct communication
	 *
	 * @since 1.0
	 * @return SV_WC_Payment_Gateway_API the payment gateway API instance
	 * @throws SV_WC_Payment_Gateway_Feature_Unsupported_Exception if this gateway does not support direct communication
	 * @throws SV_WC_Payment_Gateway_Unimplemented_Method_Exception if this gateway supports direct communication but has not provided an implementation for this method
	 */
	public function get_api() {

		// concrete stub method
		throw new SV_WC_Payment_Gateway_Unimplemented_Method_Exception( get_class( $this ) . substr( __METHOD__, strpos( __METHOD__, '::' ) ) . "()" );
	}


	/** Getters ******************************************************/


	/**
	 * Returns true if this is a direct type gateway
	 *
	 * @since 1.0
	 * @return boolean if this is a direct payment gateway
	 */
	public function is_direct_gateway() {
		return true;
	}

}

endif;  // class exists check