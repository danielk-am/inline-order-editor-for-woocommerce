<?php
/**
 * Removes the plugin's settings when it is deleted.
 *
 * Terms already saved on orders are part of those orders' records, so they stay.
 *
 * @package Inline_Order_Editor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ioefw_default_terms', 'ioefw_terms_heading', 'ioefw_terms_in_emails', 'ioefw_terms_on_order_page', 'ioefw_terms_on_pdf' ) as $ioefw_option ) {
	delete_option( $ioefw_option );
}
