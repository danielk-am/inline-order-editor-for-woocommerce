<?php
/**
 * Switches the disposable test store between HPOS and the older post-based order storage, so the
 * checks can be run on both. Add &to=posts or &to=hpos. Not shipped in the release ZIP.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

$ioefw_to = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A dev script on a test site.

if ( in_array( $ioefw_to, array( 'posts', 'hpos' ), true ) ) {
	update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $ioefw_to ? 'yes' : 'no' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	echo 'Order storage will be ' . esc_html( $ioefw_to ) . " from the next request.\n";
	return;
}

echo 'Order storage is ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts' ) . ".\n";
