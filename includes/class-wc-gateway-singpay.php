<?php
/**
 * Singpay Payment Gateway
 *
 * @package WooCommerce Gateway Singpay
 */

/**
 * Singpay Payment Gateway
 *
 * Provides a Singpay Payment Gateway.
 *
 * @class  woocommerce_Singpay
 */
class WC_Gateway_SingPay extends WC_Payment_Gateway {


    /**
     * Version
     *
     * @var string
     */
    public $version;

    /**
     * Data to send to Singpay.
     *
     * @var array $data_to_send
     */
    protected $data_to_send = array();

    /**
     * Merchant ID.
     *
     * @var string $x_client_id
     */
    protected $x_client_id;

    /**
     * Merchant Key.
     *
     * @var string $x_client_secret
     */
    protected $x_client_secret;

    /**
     * Pass Phrase.
     *
     * @var string $x_wallet
     */
    protected $x_wallet;

    /**
     * Singpay URL.
     *
     * @var string $url
     */
    protected $url;

    /**
     * Singpay Validate URL.
     *
     * @var string $validate_url
     */
    protected $validate_url;

    /**
     * Response URL.
     *
     * @var string $response_url
     */
    protected $response_url;

    /**
     * Available countries.
     *
     * @var array $available_countries
     */
    protected $available_countries;

    /**
     * Available currencies.
     *
     * @var array $available_currencies
     */
    protected $available_currencies;

    /**
     * Logger instance.
     *
     * @var WC_Logger $logger
     */
    protected $logger;

    /**
     * Constructor
     */
    public function __construct() {

        $this->version      = WC_GATEWAY_SINGPAY_VERSION;
        $this->id           = 'singpay';
        $this->method_title = __( 'Singpay', 'woocommerce-gateway-singpay' );
        /* translators: 1: a href link 2: closing href */
        $this->method_description  = sprintf( __( 'Singpay works by sending the user to %1$sSingpay%2$s to enter their payment information.', 'woocommerce-gateway-singpay' ), '<a href="https://singpay.ga/">', '</a>' );
        $this->icon                = WP_PLUGIN_URL . '/' . plugin_basename( dirname( __DIR__ ) ) . '/assets/images/icon.png';
        $this->available_countries = array( 'GA' );

        /**
         * Filter available countries for Singpay Gateway.
         *
         * @since 1.0.0
         *
         * @param string[] $available_countries Array of available countries.
         */
        $this->available_currencies = (array) apply_filters( 'woocommerce_gateway_singpay_available_currencies', array( 'XAF' ) );

        // Supported functionality.
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change', // Subs 1.x support.
            'subscription_payment_method_change_customer', // Enabled for https://github.com/woocommerce/woocommerce-gateway-singpay/issues/32.
        );

        $this->init_form_fields();
        $this->init_settings();

        // Setup default merchant data.
        $this->x_client_id      = $this->get_option( 'x_client_id' );
        $this->x_client_secret     = $this->get_option( 'x_client_secret' );
        $this->x_wallet      = $this->get_option( 'x_wallet' );
        $this->url              = 'https://gateway.singpay.ga/v1/ext';
        $this->validate_url     = 'https://gateway.singpay.ga/v1';
        $this->title            = $this->get_option( 'title' );
        $this->response_url     = add_query_arg( 'wc-api', 'WC_Gateway_SingPay', home_url( '/' ) );
        $this->description      = $this->get_option( 'description' );
        $this->enabled          = 'yes' === $this->get_option( 'enabled' ) ? 'yes' : 'no';

        // Setup the test data, if in test mode.
        if ( 'yes' === $this->get_option( 'testmode' ) ) {
            $this->url          = 'https://gateway.singpay.ga/v1/ext';
            $this->validate_url = 'https://gateway.singpay.ga/v1';
            $this->add_testmode_admin_settings_notice();
        }

		add_action('rest_api_init', array($this, 'register_singpay_callback_endpoint'));
        add_action( 'woocommerce_api_wc_gateway_singpay', array( $this, 'check_itn_response' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_singpay', array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        // Add fees to order.
        add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_fee' ) );
        add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_net' ), 20 );

        // Change Payment Method actions.
        add_action( 'woocommerce_subscription_payment_method_updated_from_' . $this->id, array( $this, 'maybe_cancel_subscription_token' ), 10, 2 );

        // Add support for WooPayments multi-currency.
        add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ) );

        add_filter( 'nocache_headers', array( $this, 'no_store_cache_headers' ) );
		add_action( 'woocommerce_api_singpay-epg-notify-payment', array( $this, 'webhook' ) );

    }


	public function register_singpay_callback_endpoint() {
        register_rest_route('singpay/v1', '/callback', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_singpay_callback'),
            'permission_callback' => '__return_true', // Permettre un accès public au endpoint
        ));
    }

	public function handle_singpay_callback(WP_REST_Request $request) {
        // Récupérer le corps de la requête
        $body = json_decode($request->get_body(), true);

        // Journal de débogage
        error_log('SingPay Callback received: ' . print_r($body, true));

        // Vérifier et traiter les données de la transaction
        if (isset($body['transaction'])) {
            $transaction = $body['transaction'];

            // Extraire les données de la transaction
            $status = $transaction['status'];
            $result = $transaction['result'];
            $reference = $transaction['reference'];
            $amount = $transaction['amount'];

            // Journal de débogage
            error_log('Transaction details: Status: ' . $status . ', Result: ' . $result . ', Reference: ' . $reference . ', Amount: ' . $amount);

            // Traiter la transaction en fonction du statut et du résultat
            $order = wc_get_order($reference);
            if ($order) {
                if ($status === 'Terminate' && $result === 'Success') {
                    // Transaction réussie, mettre à jour la commande WooCommerce
                    $order->payment_complete();
                    $order->add_order_note('Payment completed successfully via SingPay.');
                } else {
                    // Gérer les autres statuts ou résultats de la transaction
                    $order->update_status('failed', 'Payment failed via SingPay. Status: ' . $status . ', Result: ' . $result);
                }

                // Retourner une réponse HTTP 200 pour confirmer la réception
                return new WP_REST_Response('Callback received', 200);
            } else {
                // Commande introuvable
                return new WP_REST_Response('Order not found', 404);
            }
        }

        // Retourner une réponse HTTP 400 en cas de données invalides
        return new WP_REST_Response('Invalid callback data', 400);
	}

	/**
     * Use the no-store, private cache directive on the order-pay endpoint.
     *
     * This prevents the browser caching the page even when the visitor has clicked
     * the back button. This is required to determine if a user has pressed back while
     * in the singpay gateway.
     *
     * @since 1.0.0
     *
     * @param string[] $headers Array of caching headers.
     * @return string[] Modified caching headers.
    */
    public function no_store_cache_headers( $headers ) {
        if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
            return $headers;
        }

        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';
        return $headers;
    }


	    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'          => array(
                'title'       => __( 'Enable/Disable', 'woocommerce-gateway-singpay' ),
                'label'       => __( 'Enable Singpay', 'woocommerce-gateway-singpay' ),
                'type'        => 'checkbox',
                'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-singpay' ),
                'default'     => 'no', // User should enter the required information before enabling the gateway.
                'desc_tip'    => true,
            ),
            'title'            => array(
                'title'       => __( 'Title', 'woocommerce-gateway-singpay' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-singpay' ),
                'default'     => __( 'Singpay', 'woocommerce-gateway-singpay' ),
                'desc_tip'    => true,
            ),
            'description'      => array(
                'title'       => __( 'Description', 'woocommerce-gateway-singpay' ),
                'type'        => 'text',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-singpay' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'testmode'         => array(
                'title'       => __( 'Singpay Sandbox', 'woocommerce-gateway-singpay' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in development mode.', 'woocommerce-gateway-singpay' ),
                'default'     => 'yes',
            ),
            'x_client_id'      => array(
                'title'       => __( 'Client ID', 'woocommerce-gateway-singpay' ),
                'type'        => 'text',
                'description' => __( '* Required. This is the Client ID, received from singpay.', 'woocommerce-gateway-singpay' ),
                'default'     => '',
            ),
            'x_client_secret'     => array(
                'title'       => __( 'Client Secret', 'woocommerce-gateway-singpay' ),
                'type'        => 'password',
                'description' => __( '* Required. This is the Client key, received from singpay.', 'woocommerce-gateway-singpay' ),
                'default'     => '',
            ),
            'x_wallet'      => array(
                'title'       => __( 'Wallet ID', 'woocommerce-gateway-singpay' ),
                'type'        => 'text',
                'description' => __( '* Required. Needed to ensure the data passed through is secure.', 'woocommerce-gateway-singpay' ),
                'default'     => '',
            ),
        );
    }

    /**
     * Get the required form field keys for setup.
     *
     * @return array
     */
    public function get_required_settings_keys() {
        return array(
            'x_client_id',
            'x_client_secret',
            'x_wallet',
        );
    }

/**
     * Determine if the gateway still requires setup.
     *
     * @return bool
     */
    public function needs_setup() {
        return ! $this->get_option( 'x_client_id' ) || ! $this->get_option( 'x_client_secret' ) || ! $this->get_option( 'x_wallet' );
    }

    /**
     * Add a notice to the x_client_secret and x_client_id fields when in test mode.
     *
     * @since 1.0.0
     */
    public function add_testmode_admin_settings_notice() {
        $this->form_fields['x_client_id']['description']  .= ' <strong>' . esc_html__( 'Sandbox Client ID currently in use', 'woocommerce-gateway-singpay' ) . ' ( ' . esc_html( $this->x_client_id ) . ' ).</strong>';
        $this->form_fields['x_client_secret']['description'] .= ' <strong>' . esc_html__( 'Sandbox Client Key currently in use', 'woocommerce-gateway-singpay' ) . ' ( ' . esc_html( $this->x_client_secret ) . ' ).</strong>';
    }

    /**
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @since 1.0.0
     * @return array
     */
    public function check_requirements() {

        $errors = array(
            // Check if the store currency is supported by Singpay.
            ! in_array( get_woocommerce_currency(), $this->available_currencies, true ) ? 'wc-gateway-singpay-error-invalid-currency' : null,
            // Check if user entered the client ID.
            'yes' !== $this->get_option( 'testmode' ) && empty( $this->get_option( 'x_client_id' ) ) ? 'wc-gateway-singpay-error-missing-client-id' : null,
            // Check if user entered the client key.
            'yes' !== $this->get_option( 'testmode' ) && empty( $this->get_option( 'x_client_secret' ) ) ? 'wc-gateway-singpay-error-missing-client-key' : null,
            // Check if user entered a pass phrase.
            'yes' !== $this->get_option( 'testmode' ) && empty( $this->get_option( 'x_wallet' ) ) ? 'wc-gateway-singpay-error-missing-wallet' : null,
        );

        return array_filter( $errors );
    }


	/**
     * Check if the gateway is available for use.
     *
     * @return bool
     */
    public function is_available() {
        if ( 'yes' === $this->enabled ) {
            $errors = $this->check_requirements();
            // Prevent using this gateway on frontend if there are any configuration errors.
            return 0 === count( $errors );
        }

        return parent::is_available();
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		if ( in_array( get_woocommerce_currency(), $this->available_currencies, true ) ) {
			parent::admin_options();
		} else {
			?>
			<h3><?php esc_html_e( 'Singpay', 'woocommerce-gateway-singpay' ); ?></h3>
			<div class="inline error">
				<p>
					<strong><?php esc_html_e( 'Gateway Disabled', 'woocommerce-gateway-singpay' ); ?></strong>
					<?php
					/* translators: 1: a href link 2: closing href */
					echo wp_kses_post( sprintf( __( 'Choose Gabon Rands as your store currency in %1$sGeneral Settings%2$s to enable the Singpay Gateway.', 'woocommerce-gateway-singpay' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">', '</a>' ) );
					?>
				</p>
			</div>
			<?php
		}
	}


	/**
	 * Process the payment and return the result.
	 *
	 * @since 1.0.0
	 *
	 * @throws Exception When there is an error processing the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return string[] Payment result {
	 *    @type string $result   Result of payment.
	 *    @type string $redirect Redirect URL.
	 * }
	 */

	public function process_payment( $order_id ) {
		$order    = wc_get_order( $order_id );
		$redirect = $order->get_checkout_payment_url( true );

		// Check if the payment is for changing payment method.
		if ( isset( $_GET['change_payment_method'] ) ) {
			$sub_id = absint( wp_unslash( $_GET['change_payment_method'] ) );
			if ( $this->is_subscription( $sub_id ) && floatval( 0 ) === floatval( $order->get_total() ) ) {
				$redirect = add_query_arg( 'change_pay_method', $sub_id, $redirect );
			}
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}
	
	public function receipt_page( $order_id ) {
		echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with Singpay.', 'woocommerce-gateway-singpay' ) . '</p>';
		echo $this->generate_singpay_link( $order_id );
	}

    /**
     * Generate the Singpay button link.
     *
     * @param int $order_id Order ID.
     * @return string
    */
	public function generate_singpay_link( $order_id ) {

		$order = wc_get_order( $order_id );
	
		// Préparer les données à envoyer.
		$data_to_send = array(
			'portefeuille'     => $this->x_wallet,
			'reference'        => $order_id,
			'redirect_success' => $this->get_return_url( $order ),
			'redirect_error'   => $order->get_cancel_order_url(),
			'amount'           => $order->get_total(),
			'disbursement'     => '',
			'logoURL'          => '', // Ajoutez votre URL de logo ici
			'isTransfer'       => false,
		);
	
		$headers = array(
			'x-client-id'     => $this->x_client_id,
			'x-client-secret' => $this->x_client_secret,
			'x-wallet'        => $this->x_wallet,
			'Content-Type'    => 'application/json',
		);
	
		$response = wp_remote_post( $this->url, array(
			'body'    => json_encode( $data_to_send ),
			'headers' => $headers,
		) );
	
		$cancel_button = '<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'woocommerce-gateway-singpay' ) . '</a>';
	
		if ( is_wp_error( $response ) ) {
			return '<p>' . __( 'There was an issue connecting to the payment gateway. Please try again later.', 'woocommerce-gateway-singpay' ) . '</p>' . $cancel_button ;
		}
	
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );
	
		if ( isset( $result['link'] ) ) {
			// Générer le bouton de paiement et le bouton d'annulation
			$payment_button = '<a class="button alt" href="' . esc_url( $result['link'] ) . '">' . esc_html__( 'Pay via Singpay', 'woocommerce-gateway-singpay' ) . '</a>';
			return $payment_button . $cancel_button;
		} else {
			return '<p>' . __( 'There was an issue processing your payment. Please try again later.', 'woocommerce-gateway-singpay' ) . '</p>' . $cancel_button;
		}
	}
	

	/**
	 * Check Singpay ITN response.
	 *
	 * @since 1.0.0
	 */
	public function check_itn_response() {
		// phpcs:ignore.WordPress.Security.NonceVerification.Missing
		$this->handle_itn_request( stripslashes_deep( $_POST ) );

		// Notify Singpay that information has been received.
		header( 'HTTP/1.0 200 OK' );
		flush();
	}

	/**
	 * Check Singpay ITN validity.
	 *
	 * @param array $data Data.
	 * @since 1.0.0
	 */
	public function handle_itn_request( $data ) {
		$this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'Singpay ITN call received'
			. PHP_EOL . '----------'
		);
		
		$this->log( 'Get posted data' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- debug info for logging.
		$this->log( 'Singpay Data: ' . print_r( $data, true ) );

		$singpay_error  = false;
		$singpay_done   = false;
		$debug_email    = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$session_id     = $data['custom_str1'];
		$vendor_name    = get_bloginfo( 'name', 'display' );
		$vendor_url     = home_url( '/' );
		$order_id       = absint( $data['custom_str3'] );
		$order_key      = wc_clean( $session_id );
		$order          = wc_get_order( $order_id );
		$original_order = $order;

		if ( false === $data ) {
			$singpay_error         = true;
			$singpay_error_message = PF_ERR_BAD_ACCESS;
		}

		// Verify security signature.
		if ( ! $singpay_error && ! $singpay_done ) {
			$this->log( 'Verify security signature' );
			$signature = md5( $this->_generate_parameter_string( $data, false, false ) ); // false not to sort data.
			// If signature different, log for debugging.
			if ( ! $this->validate_signature( $data, $signature ) ) {
				$singpay_error         = true;
				$singpay_error_message = PF_ERR_INVALID_SIGNATURE;
			}
		}

		// Verify source IP (If not in debug mode).
		if ( ! $singpay_error && ! $singpay_done
			&& $this->get_option( 'testmode' ) !== 'yes' ) {
			$this->log( 'Verify source IP' );

			if ( isset( $_SERVER['REMOTE_ADDR'] ) && ! $this->is_valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) ) {
				$singpay_error         = true;
				$singpay_error_message = PF_ERR_BAD_SOURCE_IP;
			}
		}

		// Verify data received.
		if ( ! $singpay_error ) {
			$this->log( 'Verify data received' );
			$validation_data = $data;
			unset( $validation_data['signature'] );
			$has_valid_response_data = $this->validate_response_data( $validation_data );

			if ( ! $has_valid_response_data ) {
				$singpay_error         = true;
				$singpay_error_message = PF_ERR_BAD_ACCESS;
			}
		}

		/**
		 * Handle Changing Payment Method.
		 *   - Save Singpay subscription token to handle future payment
		 *   - (for Singpay to Singpay payment method change) Cancel old token, as future payment will be handle with new token
		 *
		 * Note: The change payment method is handled before the amount mismatch check, as it doesn't involve an actual payment (0.00) and only token updates are handled here.
		 */
		if (
			! $singpay_error &&
			isset( $data['custom_str4'] ) && 
			'change_pay_method' === wc_clean( $data['custom_str4'] ) &&
			$this->is_subscription( $order_id ) &&
			floatval( 0 ) === floatval( $data['amount_gross'] )
		) {
			if ( self::get_order_prop( $order, 'order_key' ) !== $order_key ) {
				$this->log( 'Order key does not match' );
				exit;
			}

			$this->log( '- Change Payment Method' );
			$status = strtolower( $data['payment_status'] );
			if ( 'complete' === $status && isset( $data['token'] ) ) {
				$token        = sanitize_text_field( $data['token'] );
				$subscription = wcs_get_subscription( $order_id );
				if ( ! empty( $subscription ) && ! empty( $token ) ) {
					$old_token = $this->_get_subscription_token( $subscription );
					// Cancel old subscription token of subscription if we have it.
					if ( ! empty( $old_token ) ) {
						$this->cancel_subscription_listener( $subscription );
					}

					// Set new subscription token on subscription.
					$this->_set_subscription_token( $token, $subscription );
					$this->log( 'Singpay token updated on Subcription: ' . $order_id );
				}
			}
			return;
		}

		// Check data against internal order.
		if ( ! $singpay_error && ! $singpay_done ) {
			$this->log( 'Check data against internal order' );

			// alter order object to be the renewal order if
			// the ITN request comes as a result of a renewal submission request.
			$description = json_decode( $data['item_description'] );

			if ( ! empty( $description->renewal_order_id ) ) {
				$renewal_order = wc_get_order( $description->renewal_order_id );
				if ( ! empty( $renewal_order ) && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $renewal_order ) ) {
					$order = $renewal_order;
				}
			}

			// Check order amount.
			if ( ! $this->amounts_equal( $data['amount_gross'], self::get_order_prop( $order, 'order_total' ) ) ) {
				$singpay_error         = true;
				$singpay_error_message = PF_ERR_AMOUNT_MISMATCH;
			} elseif ( strcasecmp( $data['custom_str1'], self::get_order_prop( $original_order, 'order_key' ) ) !== 0 ) {
				// Check session ID.
				$singpay_error         = true;
				$singpay_error_message = PF_ERR_SESSIONID_MISMATCH;
			}
		}

		// Get internal order and verify it hasn't already been processed.
		if ( ! $singpay_error && ! $singpay_done ) {
			$this->log_order_details( $order );

			// Check if order has already been processed.
			if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
				$this->log( 'Order has already been processed' );
				$singpay_done = true;
			}
		}

		// If an error occurred.
		if ( $singpay_error ) {
			$this->log( 'Error occurred: ' . $singpay_error_message );

			if ( $this->send_debug_email ) {
				$this->log( 'Sending email notification' );

				// Send an email.
				$subject = 'Singpay ITN error: ' . $singpay_error_message;
				$body    =
					"Hi,\n\n" .
					"An invalid Singpay transaction on your website requires attention\n" .
					"------------------------------------------------------------\n" .
					'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n" .
					'Remote IP Address: ' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) . "\n" .
					'Remote host name: ' . gethostbyaddr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) . "\n" .
					'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
					'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n";
				if ( isset( $data['pf_payment_id'] ) ) {
					$body .= 'Singpay Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n";
				}
				if ( isset( $data['payment_status'] ) ) {
					$body .= 'Singpay Payment Status: ' . esc_html( $data['payment_status'] ) . "\n";
				}

				$body .= "\nError: " . $singpay_error_message . "\n";

				switch ( $singpay_error_message ) {
					case PF_ERR_AMOUNT_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['amount_gross'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'order_total' );
						break;

					case PF_ERR_ORDER_ID_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['custom_str3'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'id' );
						break;

					case PF_ERR_SESSIONID_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['custom_str1'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'id' );
						break;

					// For all other errors there is no need to add additional information.
					default:
						break;
				}

				wp_mail( $debug_email, $subject, $body );
			} // End if.
		} elseif ( ! $singpay_done ) {

			$this->log( 'Check status and update order' );

			if ( self::get_order_prop( $original_order, 'order_key' ) !== $order_key ) {
				$this->log( 'Order key does not match' );
				exit;
			}

			$status = strtolower( $data['payment_status'] );

			$subscriptions = array();
			if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$subscriptions = array_merge(
					wcs_get_subscriptions_for_renewal_order( $order_id ),
					wcs_get_subscriptions_for_order( $order_id )
				);
			}

			if ( 'complete' !== $status && 'cancelled' !== $status ) {
				foreach ( $subscriptions as $subscription ) {
					$this->_set_renewal_flag( $subscription );
				}
			}

			if ( 'complete' === $status ) {
				$this->handle_itn_payment_complete( $data, $order, $subscriptions );
			} elseif ( 'failed' === $status ) {
				$this->handle_itn_payment_failed( $data, $order );
			} elseif ( 'pending' === $status ) {
				$this->handle_itn_payment_pending( $data, $order );
			} elseif ( 'cancelled' === $status ) {
				$this->handle_itn_payment_cancelled( $data, $order, $subscriptions );
			}
		} // End if.

		$this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'End ITN call'
			. PHP_EOL . '----------'
		);
	}

	/**
	 * Handle logging the order details.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order object.
	 */
	public function log_order_details( $order ) {
		$customer_id = $order->get_user_id();

		$details = 'Order Details:'
		. PHP_EOL . 'customer id:' . $customer_id
		. PHP_EOL . 'order id:   ' . $order->get_id()
		. PHP_EOL . 'parent id:  ' . $order->get_parent_id()
		. PHP_EOL . 'status:     ' . $order->get_status()
		. PHP_EOL . 'total:      ' . $order->get_total()
		. PHP_EOL . 'currency:   ' . $order->get_currency()
		. PHP_EOL . 'key:        ' . $order->get_order_key()
		. '';

		$this->log( $details );
	}

	/**
	 * This function mainly responds to ITN cancel requests initiated on Singpay, but also acts
	 * just in case they are not cancelled.
	 *
	 * @version 1.0.0 Subscriptions flag
	 *
	 * @param array             $data          Should be from the Gateway ITN callback.
	 * @param WC_Order          $order         Order object.
	 * @param WC_Subscription[] $subscriptions Array of subscriptions.
	 */
	public function handle_itn_payment_cancelled( $data, $order, $subscriptions ) {

		remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
		foreach ( $subscriptions as $subscription ) {
			if ( 'cancelled' !== $subscription->get_status() ) {
				$subscription->update_status( 'cancelled', esc_html__( 'Merchant cancelled subscription on singpay.', 'woocommerce-gateway-singpay' ) );
				$this->_delete_subscription_token( $subscription );
			}
		}
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
	}

	/**
	 * This function handles payment complete request by singpay.
	 *
	 * @version 1.4.3 Subscriptions flag
	 *
	 * @param array             $data          Should be from the Gateway ITN callback.
	 * @param WC_Order          $order         Order object.
	 * @param WC_Subscription[] $subscriptions Array of subscriptions.
	 */
	public function handle_itn_payment_complete( $data, $order, $subscriptions ) {
		$this->log( '- Complete' );
		$order->add_order_note( esc_html__( 'ITN payment completed', 'woocommerce-gateway-singpay' ) );
		$order->update_meta_data( 'singpay_amount_fee', $data['amount_fee'] );
		$order->update_meta_data( 'singpay_amount_net', $data['amount_net'] );
		$order_id = self::get_order_prop( $order, 'id' );

		// Store token for future subscription deductions.
		if ( count( $subscriptions ) > 0 && isset( $data['token'] ) ) {
			if ( $this->_has_renewal_flag( reset( $subscriptions ) ) ) {
				// Renewal flag is set to true, so we need to cancel previous token since we will create a new one.
				$this->log( 'Cancel previous subscriptions with token ' . $this->_get_subscription_token( reset( $subscriptions ) ) );

				// Only request API cancel token for the first subscription since all of them are using the same token.
				$this->cancel_subscription_listener( reset( $subscriptions ) );
			}

			$token = sanitize_text_field( $data['token'] );
			foreach ( $subscriptions as $subscription ) {
				$this->_delete_renewal_flag( $subscription );
				$this->_set_subscription_token( $token, $subscription );
			}
		}

		// Mark payment as complete.
		$order->payment_complete( $data['pf_payment_id'] );

		$debug_email = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name = get_bloginfo( 'name', 'display' );
		$vendor_url  = home_url( '/' );
		if ( $this->send_debug_email ) {
			$subject = 'Singpay ITN on your site';
			$body    =
				"Hi,\n\n"
				. "A Singpay transaction has been completed on your website\n"
				. "------------------------------------------------------------\n"
				. 'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n"
				. 'Purchase ID: ' . esc_html( $data['m_payment_id'] ) . "\n"
				. 'Singpay Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n"
				. 'Singpay Payment Status: ' . esc_html( $data['payment_status'] ) . "\n"
				. 'Order Status Code: ' . self::get_order_prop( $order, 'status' );
			wp_mail( $debug_email, $subject, $body );
		}

		/**
		 * Fires after handling the Payment Complete ITN from Singpay.
		 *
		 * @since 1.0.0
		 *
		 * @param array             $data          ITN Payload.
		 * @param WC_Order          $order         Order Object.
		 * @param WC_Subscription[] $subscriptions Subscription array.
		 */
		do_action( 'woocommerce_singpay_handle_itn_payment_complete', $data, $order, $subscriptions );
	}

	/**
	 * Handle payment failed request by Singpay.
	 *
	 * @param array    $data  Should be from the Gateway ITN callback.
	 * @param WC_Order $order Order object.
	 */
	public function handle_itn_payment_failed( $data, $order ) {
		$this->log( '- Failed' );
		/* translators: 1: payment status */
		$order->update_status( 'failed', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-singpay' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
		$debug_email = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name = get_bloginfo( 'name', 'display' );
		$vendor_url  = home_url( '/' );

		if ( $this->send_debug_email ) {
			$subject = 'Singpay ITN Transaction on your site';
			$body    =
				"Hi,\n\n" .
				"A failed Singpay transaction on your website requires attention\n" .
				"------------------------------------------------------------\n" .
				'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n" .
				'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
				'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n" .
				'Singpay Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n" .
				'Singpay Payment Status: ' . esc_html( $data['payment_status'] );
			wp_mail( $debug_email, $subject, $body );
		}
	}

	/**
	 * Handle payment pending request by Singpay.
	 *
	 * @since 1.4.0
	 *
	 * @param array    $data  Should be from the Gateway ITN callback.
	 * @param WC_Order $order Order object.
	 */
	public function handle_itn_payment_pending( $data, $order ) {
		$this->log( '- Pending' );
		// Need to wait for "Completed" before processing.
		/* translators: 1: payment status */
		$order->update_status( 'on-hold', sprintf( esc_html__( 'Payment %s via ITN.', 'woocommerce-gateway-singpay' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
	}

	/**
	 * Get the pre-order fee.
	 *
	 * @param string $order_id Order ID.
	 * @return double
	 */
	public function get_pre_order_fee( $order_id ) {
		foreach ( wc_get_order( $order_id )->get_fees() as $fee ) {
			if ( is_array( $fee ) && 'Pre-Order Fee' === $fee['name'] ) {
				return doubleval( $fee['line_total'] ) + doubleval( $fee['line_tax'] );
			}
		}
	}

	/**
	 * Whether order contains a pre-order.
	 *
	 * @param string $order_id Order ID.
	 * @return bool Whether order contains a pre-order.
	 */
	public function order_contains_pre_order( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
		}
		return false;
	}

	/**
	 * Whether the order requires payment tokenization.
	 *
	 * @param string $order_id Order ID.
	 * @return bool Whether the order requires payment tokenization.
	 */
	public function order_requires_payment_tokenization( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id );
		}
		return false;
	}

	/**
	 * Whether order contains a pre-order fee.
	 *
	 * @return bool Whether order contains a pre-order fee.
	 */
	public function cart_contains_pre_order_fee() {
		if ( class_exists( 'WC_Pre_Orders_Cart' ) ) {
			return WC_Pre_Orders_Cart::cart_contains_pre_order_fee();
		}
		return false;
	}
	/**
	 * Store the Singpay subscription token
	 *
	 * @param string          $token        singpay subscription token.
	 * @param WC_Subscription $subscription The subscription object.
	 */
	protected function _set_subscription_token( $token, $subscription ) {
		$subscription->update_meta_data( '_singpay_subscription_token', $token );
		$subscription->save_meta_data();
	}

	/**
	 * Retrieve the singpay subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @return mixed singpay subscription token.
	 */
	protected function _get_subscription_token( $subscription ) {
		return $subscription->get_meta( '_singpay_subscription_token', true );
	}

	/**
	 * Retrieve the singpay subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @return mixed
	 */
	protected function _delete_subscription_token( $subscription ) {
		return $subscription->delete_meta_data( '_singpay_subscription_token' );
	}

	/**
	 * Store the Singpay renewal flag
	 *
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	protected function _set_renewal_flag( $subscription ) {
		$subscription->update_meta_data( '_singpay_renewal_flag', 'true' );
		$subscription->save_meta_data();
	}

	/**
	 * Retrieve the Singpay renewal flag for a given order id.
	 *
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @return bool
	 */
	protected function _has_renewal_flag( $subscription ) {
		return 'true' === $subscription->get_meta( '_singpay_renewal_flag', true );
	}

	/**
	 * Retrieve the Singpay renewal flag for a given order id.
	 *
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	protected function _delete_renewal_flag( $subscription ) {
		$subscription->delete_meta_data( '_singpay_renewal_flag' );
		$subscription->save_meta_data();
	}

	/**
	 * Wrapper for WooCommerce subscription function wc_is_subscription.
	 *
	 * @param WC_Order|int $order The order.
	 * @return bool
	 */
	public function is_subscription( $order ) {
		if ( ! function_exists( 'wcs_is_subscription' ) ) {
			return false;
		}
		return wcs_is_subscription( $order );
	}

	/**
	 * Cancel Singpay Tokenization(ad-hoc) token if subscription changed to other payment method.
	 *
	 * @param WC_Subscription $subscription       The subscription for which the payment method changed.
	 * @param string          $new_payment_method New payment method name.
	 */
	public function maybe_cancel_subscription_token( $subscription, $new_payment_method ) {
		$token = $this->_get_subscription_token( $subscription );
		if ( empty( $token ) || $this->id === $new_payment_method ) {
			return;
		}
		$this->cancel_subscription_listener( $subscription );
		$this->_delete_subscription_token( $subscription );

		$this->log( 'Singpay subscription token Cancelled.' );
	}

	/**
	 * Whether order contains a subscription.
	 *
	 * Wrapper function for wcs_order_contains_subscription
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Whether order contains a subscription.
	 */
	public function order_contains_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}
		return wcs_order_contains_subscription( $order );
	}

	/**
	 * Process scheduled subscription payment and update the subscription status accordingly.
	 *
	 * @param float    $amount_to_charge Subscription cost.
	 * @param WC_Order $renewal_order    Renewal order object.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$subscription = wcs_get_subscription( $renewal_order->get_meta( '_subscription_renewal', true ) );
		$this->log( 'Attempting to renew subscription from renewal order ' . self::get_order_prop( $renewal_order, 'id' ) );

		if ( empty( $subscription ) ) {
			$this->log( 'Subscription from renewal order was not found.' );
			return;
		}

		$response = $this->submit_subscription_payment( $subscription, $amount_to_charge );

		if ( is_wp_error( $response ) ) {
			/* translators: 1: error code 2: error message */
			$renewal_order->update_status( 'failed', sprintf( esc_html__( 'Singpay Subscription renewal transaction failed (%1$s:%2$s)', 'woocommerce-gateway-singpay' ), $response->get_error_code(), $response->get_error_message() ) );
		}
		// Payment will be completion will be capture only when the ITN callback is sent to $this->handle_itn_request().
		$renewal_order->add_order_note( esc_html__( 'Singpay Subscription renewal transaction submitted.', 'woocommerce-gateway-singpay' ) );
	}

	/**
	 * Attempt to process a subscription payment on the Singpay gateway.
	 *
	 * @param WC_Subscription $subscription     The subscription object.
	 * @param float           $amount_to_charge The amount to charge.
	 * @return mixed WP_Error on failure, bool true on success
	 */
	public function submit_subscription_payment( $subscription, $amount_to_charge ) {
		$token     = $this->_get_subscription_token( $subscription );
		$item_name = $this->get_subscription_name( $subscription );

		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
			$statuses_to_charge = array( 'on-hold', 'failed', 'pending' );
			if ( in_array( $order->get_status(), $statuses_to_charge, true ) ) {
				$latest_order_to_renew = $order;
				break;
			}
		}
		$item_description = wp_json_encode( array( 'renewal_order_id' => self::get_order_prop( $latest_order_to_renew, 'id' ) ) );

		return $this->submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description );
	}

	/**
	 * Get a name for the subscription item. For multiple
	 * item only Subscription $date will be returned.
	 *
	 * For subscriptions with no items Site/Blog name will be returned.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @return string
	 */
	public function get_subscription_name( $subscription ) {

		if ( $subscription->get_item_count() > 1 ) {
			return $subscription->get_date_to_display( 'start' );
		} else {
			$items = $subscription->get_items();

			if ( empty( $items ) ) {
				return get_bloginfo( 'name' );
			}

			$item = array_shift( $items );
			return $item['name'];
		}
	}

	/**
	 * Setup api data for the the adhoc payment.
	 *
	 * @since 1.4.0 introduced.
	 *
	 * @param string $token            Singpay subscription token.
	 * @param float  $amount_to_charge Amount to charge.
	 * @param string $item_name        Item name.
	 * @param string $item_description Item description.
	 *
	 * @return bool|WP_Error WP_Error on failure, bool true on success
	 */
	public function submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description ) {
		$args = array(
			'body' => array(
				'amount'           => $amount_to_charge * 100, // Convert to cents.
				'item_name'        => $item_name,
				'item_description' => $item_description,
			),
		);
		return $this->api_request( 'adhoc', $token, $args );
	}

	/**
	 * Send off API request.
	 *
	 * @since 1.0.0 introduced.
	 *
	 * @param string $command  API command.
	 * @param string $token    Singpay subscription token.
	 * @param array  $api_args Arguments for the API request. See WP documentation for wp_remote_request.
	 * @param string $method   GET | PUT | POST | DELETE.
	 *
	 * @return bool|WP_Error WP_Error on failure, bool true on success
	 */
	public function api_request( $command, $token, $api_args, $method = 'POST' ) {

		if ( empty( $token ) ) {
			$this->log( 'Error posting API request: No token supplied', true );
			return new WP_Error( '404', esc_html__( 'Can not submit Singpay request with an empty token', 'woocommerce-gateway-singpay' ), $results );
		}

		$api_endpoint  = "https://api.singpay.za/subscriptions/$token/$command";
		$api_endpoint .= 'yes' === $this->get_option( 'testmode' ) ? '?testing=true' : '';

		$timestamp           = current_time( rtrim( DateTime::ATOM, 'P' ) ) . '+02:00';
		$api_args['timeout'] = 45;
		$api_args['headers'] = array(
			'merchant-id' => $this->x_client_id,
			'timestamp'   => $timestamp,
			'version'     => 'v1',
		);

		// Set content length to fix "411: requests require a Content-length header" error.
		if ( 'cancel' === $command && ! isset( $api_args['body'] ) ) {
			$api_args['headers']['content-length'] = 0;
		}

		// Generate signature.
		$all_api_variables                = array_merge( $api_args['headers'], (array) $api_args['body'] );
		$api_args['headers']['signature'] = md5( $this->_generate_parameter_string( $all_api_variables ) );
		$api_args['method']               = strtoupper( $method );

		$results = wp_remote_request( $api_endpoint, $api_args );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		// Check Singpay server response.
		if ( 200 !== $results['response']['code'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
			$this->log( "Error posting API request:\n" . print_r( $results['response'], true ) );
			return new WP_Error( $results['response']['code'], json_decode( $results['body'] )->data->response, $results );
		}

		// Check adhoc bank charge response.
		$results_data = json_decode( $results['body'], true )['data'];

		// Sandbox ENV returns true(boolean) in response, while Production ENV "true"(string) in response.
		if ( 'adhoc' === $command && ! ( 'true' === $results_data['response'] || true === $results_data['response'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
			$this->log( "Error posting API request:\n" . print_r( $results_data, true ) );

			$code    = is_array( $results_data['response'] ) ? $results_data['response']['code'] : $results_data['response'];
			$message = is_array( $results_data['response'] ) ? $results_data['response']['reason'] : $results_data['message'];
			// Use trim here to display it properly e.g. on an order note, since Singpay can include CRLF in a message.
			return new WP_Error( $code, trim( $message ), $results );
		}

		$maybe_json = json_decode( $results['body'], true );

		if ( ! is_null( $maybe_json ) && isset( $maybe_json['status'] ) && 'failed' === $maybe_json['status'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
			$this->log( "Error posting API request:\n" . print_r( $results['body'], true ) );

			// Use trim here to display it properly e.g. on an order note, since Singpay can include CRLF in a message.
			return new WP_Error( $maybe_json['code'], trim( $maybe_json['data']['message'] ), $results['body'] );
		}

		return true;
	}

	/**
	 * Responds to Subscriptions extension cancellation event.
	 *
	 * @since 1.0.0 introduced.
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public function cancel_subscription_listener( $subscription ) {
		$token = $this->_get_subscription_token( $subscription );
		if ( empty( $token ) ) {
			return;
		}
		$this->api_request( 'cancel', $token, array(), 'PUT' );
	}

	/**
	 * Cancel a pre-order subscription.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Singpay subscription token.
	 *
	 * @return bool|WP_Error WP_Error on failure, bool true on success.
	 */
	public function cancel_pre_order_subscription( $token ) {
		return $this->api_request( 'cancel', $token, array(), 'PUT' );
	}

	/**
	 * Generate the parameter string to send to Singpay.
	 *
	 * @since 1.4.0 introduced.
	 *
	 * @param array $api_data               Data to send to the Singpay API.
	 * @param bool  $sort_data_before_merge Whether to sort before merge. Default true.
	 * @param bool  $skip_empty_values      Should key value pairs be ignored when generating signature? Default true.
	 *
	 * @return string
	 */
	protected function _generate_parameter_string( $api_data, $sort_data_before_merge = true, $skip_empty_values = true ) {

		// if sorting is required the passphrase should be added in before sort.
		if ( ! empty( $this->x_wallet ) && $sort_data_before_merge ) {
			$api_data['passphrase'] = $this->x_wallet;
		}

		if ( $sort_data_before_merge ) {
			ksort( $api_data );
		}

		// concatenate the array key value pairs.
		$parameter_string = '';
		foreach ( $api_data as $key => $val ) {

			if ( $skip_empty_values && empty( $val ) ) {
				continue;
			}

			if ( 'signature' !== $key ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- legacy code, validation required prior to switching to rawurlencode.
				$val               = urlencode( $val );
				$parameter_string .= "$key=$val&";
			}
		}
		// When not sorting passphrase should be added to the end before md5.
		if ( $sort_data_before_merge ) {
			$parameter_string = rtrim( $parameter_string, '&' );
		} elseif ( ! empty( $this->x_wallet ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- legacy code, validation required prior to switching to rawurlencode.
			$parameter_string .= 'passphrase=' . urlencode( $this->x_wallet );
		} else {
			$parameter_string = rtrim( $parameter_string, '&' );
		}

		return $parameter_string;
	}

	/**
	 * Process pre-order payment.
	 *
	 * @since 1.0.0 introduced.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function process_pre_order_payments( $order ) {
		wc_deprecated_function( 'process_pre_order_payments', 'x.x.x' );
	}

	/**
	 * Log system processes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		if ( 'yes' === $this->get_option( 'testmode' ) || $this->enable_logging ) {
			if ( empty( $this->logger ) ) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add( 'singpay', $message );
		}
	}

	/**
	 * Validate the signature against the returned data.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      Returned data.
	 * @param string $signature Signature to check.
	 * @return bool Whether the signature is valid.
	 */
	public function validate_signature( $data, $signature ) {
		$result = $data['signature'] === $signature;
		$this->log( 'Signature = ' . ( $result ? 'valid' : 'invalid' ) );
		return $result;
	}

	/**
	 * Validate the IP address to make sure it's coming from singpay.
	 *
	 * @param string $source_ip Source IP.
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_valid_ip( $source_ip ) {
		// Variable initialization.
		$valid_hosts = array(
			'www.singpay.ga',
			'sandbox.singpay.ga',
			'w1w.singpay.ga',
			'w2w.singpay.ga',
		);

		$valid_ips = array();

		foreach ( $valid_hosts as $pf_hostname ) {
			$ips = gethostbynamel( $pf_hostname );

			if ( false !== $ips ) {
				$valid_ips = array_merge( $valid_ips, $ips );
			}
		}

		// Remove duplicates.
		$valid_ips = array_unique( $valid_ips );

		// Adds support for X_Forwarded_For.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$x_forwarded_http_header = trim( current( preg_split( '/[,:]/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
			$source_ip               = rest_is_ip_address( $x_forwarded_http_header ) ? rest_is_ip_address( $x_forwarded_http_header ) : $source_ip;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
		$this->log( "Valid IPs:\n" . print_r( $valid_ips, true ) );
		$is_valid_ip = in_array( $source_ip, $valid_ips, true );

		/**
		 * Filter whether SingPay Gateway IP address is valid.
		 *
		 * @since 1.4.13
		 *
		 * @param bool $is_valid_ip Whether IP address is valid.
		 * @param bool $source_ip   Source IP.
		 */
		return apply_filters( 'woocommerce_gateway_singpay_is_valid_ip', $is_valid_ip, $source_ip );
	}

	/**
	 * Validate response data.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $post_data POST data for original request.
	 * @param string $proxy     Address of proxy to use or NULL if no proxy.
	 * @return bool
	 */
	public function validate_response_data( $post_data, $proxy = null ) {
		$this->log( 'Host = ' . $this->validate_url );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
		$this->log( 'Params = ' . print_r( $post_data, true ) );

		if ( ! is_array( $post_data ) ) {
			return false;
		}

		$response = wp_remote_post(
			$this->validate_url,
			array(
				'body'       => $post_data,
				'timeout'    => 70,
				'user-agent' => PF_USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
			$this->log( "Response error:\n" . print_r( $response, true ) );
			return false;
		}

		parse_str( $response['body'], $parsed_response );

		$response = $parsed_response;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- used for logging.
		$this->log( "Response:\n" . print_r( $response, true ) );

		// Interpret Response.
		if ( is_array( $response ) && in_array( 'VALID', array_keys( $response ), true ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check the given amounts are equal.
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @since 1.0.0
	 *
	 * @param float $amount1 1st amount for comparison.
	 * @param float $amount2 2nd amount for comparison.
	 *
	 * @return bool
	 */
	public function amounts_equal( $amount1, $amount2 ) {
		return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > PF_EPSILON );
	}

	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 *
	 * @since 1.4.1
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop( $order, $prop ) {
		switch ( $prop ) {
			case 'order_total':
				$getter = array( $order, 'get_total' );
				break;
			default:
				$getter = array( $order, 'get_' . $prop );
				break;
		}

		return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
	}

	/**
	 * Gets user-friendly error message strings from keys
	 *
	 * @param   string $key  The key representing an error.
	 *
	 * @return  string        The user-friendly error message for display
	 */
	public function get_error_message( $key ) {
		switch ( $key ) {
			case 'wc-gateway-singpay-error-invalid-currency':
				return esc_html__( 'Your store uses a currency that Singpay doesn\'t support yet.', 'woocommerce-gateway-singpay' );
			case 'wc-gateway-singpay-error-missing-client-id':
				return esc_html__( 'You forgot to fill your merchant ID.', 'woocommerce-gateway-singpay' );
			case 'wc-gateway-singpay-error-missing-client-key':
				return esc_html__( 'You forgot to fill your merchant key.', 'woocommerce-gateway-singpay' );
			case 'wc-gateway-singpay-error-missing-wallet':
				return esc_html__( 'Singpay requires a passphrase to work.', 'woocommerce-gateway-singpay' );
			default:
				return '';
		}
	}

	/**
	 * Show possible admin notices
	 */
	public function admin_notices() {

		// Get requirement errors.
		$errors_to_show = $this->check_requirements();

		// If everything is in place, don't display it.
		if ( ! count( $errors_to_show ) ) {
			return;
		}

		// If the gateway isn't enabled, don't show it.
		if ( 'no' === $this->enabled ) {
			return;
		}

		// Use transients to display the admin notice once after saving values.
		if ( ! get_transient( 'wc-gateway-singpay-admin-notice-transient' ) ) {
			set_transient( 'wc-gateway-singpay-admin-notice-transient', 1, 1 );

			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'To use Singpay as a payment provider, you need to fix the problems below:', 'woocommerce-gateway-singpay' ) . '</p>'
				. '<ul style="list-style-type: disc; list-style-position: inside; padding-left: 2em;">'
				. wp_kses_post(
					array_reduce(
						$errors_to_show,
						function ( $errors_list, $error_item ) {
							$errors_list = $errors_list . PHP_EOL . ( '<li>' . $this->get_error_message( $error_item ) . '</li>' );
							return $errors_list;
						},
						''
					)
				)
				. '</ul></p></div>';
		}
	}

	/**
	 * Displays the amount_fee as returned by Singpay.
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_fee( $order_id ) {

		$order = wc_get_order( $order_id );
		$fee   = $order->get_meta( 'singpay_amount_fee', true );

		if ( ! $fee ) {
			return;
		}
		?>

		<tr>
			<td class="label singpay-fee">
				<?php echo wc_help_tip( __( 'This represents the fee Singpay collects for the transaction.', 'woocommerce-gateway-singpay' ) ); ?>
				<?php esc_html_e( 'Singpay Fee:', 'woocommerce-gateway-singpay' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wp_kses_post( wc_price( $fee, array( 'decimals' => 2 ) ) ); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Displays the amount_net as returned by Singpay.
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_net( $order_id ) {

		$order = wc_get_order( $order_id );
		$net   = $order->get_meta( 'singpay_amount_net', true );

		if ( ! $net ) {
			return;
		}

		?>

		<tr>
			<td class="label singpay-net">
				<?php echo wc_help_tip( __( 'This represents the net total that was credited to your Singpay account.', 'woocommerce-gateway-singpay' ) ); ?>
				<?php esc_html_e( 'Amount Net:', 'woocommerce-gateway-singpay' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wp_kses_post( wc_price( $net, array( 'decimals' => 2 ) ) ); ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Filters the currency to 'XAF' if set via WooPayments multi-currency feature.
	 *
	 * @param string $currency The currency code.
	 * @return string
	 */
	public function filter_currency( $currency ) {
		// Do nothing if WooPayments is not activated.
		if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
			return $currency;
		}

		// Do nothing if the page is admin screen.
		if ( is_admin() ) {
			return $currency;
		}

		$user_id = get_current_user_id();

		// Check if the currency is set in the URL.
		if ( isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$currency_code = sanitize_text_field(
				wp_unslash( $_GET['currency'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
			// Check if the currency is set in the session (for logged-out users).
		} elseif ( 0 === $user_id && WC()->session ) {
			$currency_code = WC()->session->get( \WCPay\MultiCurrency\MultiCurrency::CURRENCY_SESSION_KEY );
			// Check if the currency is set in the user meta (for logged-in users).
		} elseif ( $user_id ) {
			$currency_code = get_user_meta( $user_id, \WCPay\MultiCurrency\MultiCurrency::CURRENCY_META_KEY, true );
		}

		if ( is_string( $currency_code ) && 'XAF' === $currency_code ) {
			return 'XAF';
		}

		return $currency;
	}
}
