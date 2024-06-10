<?php
/**
 * Plugin Name: WooCommerce Singpay Gateway
 * Requires Plugins: woocommerce
 * Plugin URI: https://singpay.ga/
 * Description: Receive payments via mobile money (Airtel Money, Moov Money), Paypal & Maviance.
 * Author: Gabin Steeven
 * Author URI: https://gabinsteeven.site/
 * Version: 1.0.0
 * Requires at least: 6.3
 * Tested up to: 6.5
 * WC requires at least: 8.6
 * WC tested up to: 8.8
 * Requires PHP: 7.4
 * PHP tested up to: 8.3
 *
 * @package WooCommerce Gateway Singpay
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Define the plugin constants
define( 'WC_GATEWAY_SINGPAY_VERSION', '1.0.0' ); // WRCS: DEFINED_VERSION.
define( 'WC_GATEWAY_SINGPAY_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_GATEWAY_SINGPAY_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define("WC_GATEWAY_SINGPAY_DOMAIN", "sing-pay");



/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */
function woocommerce_singpay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once plugin_basename( 'includes/class-wc-gateway-singpay.php' );
	require_once plugin_basename( 'includes/class-wc-gateway-singpay-privacy.php' );
	load_plugin_textdomain( 'woocommerce-gateway-singpay', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_singpay_add_gateway' );
}
add_action( 'plugins_loaded', 'woocommerce_singpay_init', 0 );

/**
 * Add links to the plugin action links.
 *
 * @since 1.4.13
 *
 * @param array $links Plugin action links.
 * @return array Modified plugin action links.
 */
function woocommerce_singpay_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'wc_gateway_singpay',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'woocommerce-gateway-singpay' ) . '</a>',
		'<a href="https://www.woocommerce.com/my-account/tickets/">' . esc_html__( 'Support', 'woocommerce-gateway-singpay' ) . '</a>',
		'<a href="https://docs.woocommerce.com/document/singpay-payment-gateway/">' . esc_html__( 'Docs', 'woocommerce-gateway-singpay' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_singpay_plugin_links' );

/**
 * Add the gateway to WooCommerce
 *
 * @since 1.0.0
 *
 * @param string[] $methods WooCommerce payment methods.
 * @return string[] Modified payment methods to include Singpay.
 */
function woocommerce_singpay_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_SingPay';
	return $methods;
}

add_action( 'woocommerce_blocks_loaded', 'woocommerce_singpay_woocommerce_blocks_support' );

/**
 * Add the gateway to WooCommerce Blocks.
 *
 * @since 1.4.19
 */
function woocommerce_singpay_woocommerce_blocks_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once WC_GATEWAY_SINGPAY_PATH . '/includes/class-wc-gateway-singpay-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new WC_SingPay_Blocks_Support() );
			}
		);
	}
}

/**
 * Declares compatibility with Woocommerce features.
 *
 * List of features:
 * - custom_order_tables
 * - product_block_editor
 *
 * @since 1.0.0 Rename function
 * @return void
 */
function woocommerce_singpay_declare_feature_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'product_block_editor',
			__FILE__
		);
	}
}
add_action( 'before_woocommerce_init', 'woocommerce_singpay_declare_feature_compatibility' );

/**
 * Display notice if WooCommerce is not installed.
 *
 * @since 1.5.8
 */
function woocommerce_singpay_missing_wc_notice() {
	if ( class_exists( 'WooCommerce' ) ) {
		// Display nothing if WooCommerce is installed and activated.
		return;
	}

	echo '<div class="error"><p><strong>';
	printf(
		/* translators: %s WooCommerce download URL link. */
		esc_html__( 'WooCommerce Singpay Gateway requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-gateway-singpay' ),
		'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
	);
	echo '</strong></p></div>';
}
add_action( 'admin_notices', 'woocommerce_singpay_missing_wc_notice' );