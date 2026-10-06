<?php
/**
 * Functions for themes, PDF templates and other plugins.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the terms kept on an order, as plain text. Empty when the order has none.
 *
 * In a PDF or email template: echo nl2br( esc_html( ioefw_get_order_terms( $order ) ) );
 *
 * @since 1.0.0
 * @param WC_Order|int $order The order or its ID.
 * @return string
 */
function ioefw_get_order_terms( $order ) {
	return IOEFW_Terms::get( $order, 'view' );
}
