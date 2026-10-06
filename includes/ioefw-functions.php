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

/**
 * Trims a value the same way on every PHP version.
 *
 * PHP 8.6 adds the form feed to the characters trim() removes by default. Naming them keeps one result everywhere.
 *
 * @since 1.0.2
 * @param mixed $text The value.
 * @return string
 */
function ioefw_trim( $text ) {
	return trim( (string) $text, " \n\r\t\v\x00" );
}
