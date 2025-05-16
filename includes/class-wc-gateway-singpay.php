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
     * Disbursement ID.
     *
     * @var string $disbursement_id
     */
    protected $disbursement_id;

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
        $this->disbursement_id = $this->get_option('disbursement_id');
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
	
		// Vérifier si l'en-tête Origin est présent dans la requête
		$origin = $request->get_header('Origin');
	
		// Vérifier si l'origine de la requête est autorisée
		if ($origin !== 'https://gateway.singpay.ga') {
			// Si l'origine n'est pas autorisée, retourner une erreur 403 Forbidden
			return new WP_REST_Response('Unauthorized', 403);
		}
	
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
            'disbursement_id'      => array(
                'title'       => __( 'Disbursement_id ID', 'woocommerce-gateway-singpay' ),
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
            'disbursement_id'
        );
    }

/**
     * Determine if the gateway still requires setup.
     *
     * @return bool
     */
    public function needs_setup() {
        return ! $this->get_option( 'x_client_id' ) || ! $this->get_option( 'x_client_secret' ) || ! $this->get_option( 'x_wallet' ) ||  $this->get_option( 'disbursement_id' );
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
            // Check if user entered a pass phrase.
            'yes' !== $this->get_option( 'testmode' ) && empty( $this->get_option( 'disbursement_id' ) ) ? 'wc-gateway-singpay-error-missing-disbursement' : null,
        );

        return array_filter( $errors );
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
			if ( floatval( 0 ) === floatval( $order->get_total() ) ) {
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
			'disbursement'     => $this->disbursement_id,
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
	 * Log system processes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		if ( 'yes' === $this->get_option( 'testmode' ) ) {
			if ( empty( $this->logger ) ) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add( 'singpay', $message );
		}
	}

	/**
	 * Gets user-friendly error message strings from keys
	 *
	 * @param   string $key  The key representing an error.
	 *
	 * @return  string The user-friendly error message for display
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
