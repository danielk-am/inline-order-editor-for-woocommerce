<?php
/**
 * Prints the order details email WooCommerce would send the customer for one order, as a page.
 * Used for the listing screenshot. Add &order=123. Not shipped in the release ZIP.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

$ioefw_email_order = wc_get_order( isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A dev script on a test site.

if ( ! $ioefw_email_order ) {
	wp_die( 'No such order.' );
}

$ioefw_email         = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
$ioefw_email->object = $ioefw_email_order;

echo $ioefw_email->style_inline( $ioefw_email->get_content_html() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own email markup.
