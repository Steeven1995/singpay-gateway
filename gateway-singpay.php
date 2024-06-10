<?php
/**
 * Backwards compat.
 *
 * @since 1.0.0
 * @package WooCommerce Gateway Singpay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_plugins = get_option( 'active_plugins', array() );

foreach ( $active_plugins as $key => $active_plugin ) {
	if ( strstr( $active_plugin, '/gateway-singpay.php' ) ) {
		$active_plugins[ $key ] = str_replace( '/gateway-singpay.php', '/woocommerce-gateway-singpay.php', $active_plugin );
	}
}

update_option( 'active_plugins', $active_plugins );
