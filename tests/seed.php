<?php
/**
 * A small made-up shop for manual and browser checks on a disposable site: a florist that takes
 * orders by phone. Not shipped in the release ZIP.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( wc_get_product_id_by_sku( 'ioefw-rose-bouquet' ) ) {
	echo "Already seeded.\n";
	echo 'SEED_ORDER=' . (int) get_option( 'ioefw_seed_order' ) . "\n";
	return;
}

update_option( 'woocommerce_currency', 'SGD' );
update_option( 'woocommerce_default_country', 'SG' );
update_option( 'woocommerce_store_address', '1 Example Road' );
update_option( 'woocommerce_store_city', 'Singapore' );
update_option( 'woocommerce_store_postcode', '049145' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'ioefw_default_terms', "Payment by bank transfer or cash on collection.\nOrders are confirmed once paid. Changes are welcome up to 2 days before delivery." );

$ioefw_products = array(
	array( 'Rose bouquet', 'ioefw-rose-bouquet', '45.00', 12 ),
	array( 'Tulip bunch', 'ioefw-tulip-bunch', '28.00', 20 ),
	array( 'Sunflower basket', 'ioefw-sunflower-basket', '62.00', 6 ),
	array( 'Orchid in a pot', 'ioefw-orchid-pot', '38.50', 9 ),
	array( 'Greeting card', 'ioefw-greeting-card', '4.50', null ),
);

foreach ( $ioefw_products as $ioefw_data ) {
	$ioefw_product = new WC_Product_Simple();
	$ioefw_product->set_name( $ioefw_data[0] );
	$ioefw_product->set_sku( $ioefw_data[1] );
	$ioefw_product->set_regular_price( $ioefw_data[2] );
	$ioefw_product->set_status( 'publish' );

	if ( null !== $ioefw_data[3] ) {
		$ioefw_product->set_manage_stock( true );
		$ioefw_product->set_stock_quantity( $ioefw_data[3] );
	}

	$ioefw_product->save();
	echo 'Product ' . esc_html( $ioefw_data[0] ) . ' #' . (int) $ioefw_product->get_id() . "\n";
}

// One order taken on the phone and parked as Pending payment, ready to be changed.
$ioefw_order = wc_create_order( array( 'status' => 'pending' ) );
$ioefw_order->set_billing_first_name( 'Mei' );
$ioefw_order->set_billing_last_name( 'Tan' );
$ioefw_order->set_billing_phone( '+65 6000 0000' );
$ioefw_order->set_billing_email( 'mei.tan@example.com' );
$ioefw_order->set_billing_address_1( '10 Sample Street' );
$ioefw_order->set_billing_city( 'Singapore' );
$ioefw_order->set_billing_postcode( '049999' );
$ioefw_order->set_billing_country( 'SG' );
$ioefw_order->add_product( wc_get_product( wc_get_product_id_by_sku( 'ioefw-rose-bouquet' ) ), 2 );
$ioefw_order->add_product( wc_get_product( wc_get_product_id_by_sku( 'ioefw-greeting-card' ) ), 1 );
$ioefw_order->calculate_totals();
$ioefw_order->save();
IOEFW_Orders::add_custom_item(
	$ioefw_order,
	array(
		'name'     => 'Hand-tied ribbon wrap',
		'quantity' => 2,
		'price'    => '6.50',
		'note'     => 'Gold ribbon',
	)
);
IOEFW_Terms::set( wc_get_order( $ioefw_order->get_id() ), (string) get_option( 'ioefw_default_terms' ) );

update_option( 'ioefw_seed_order', $ioefw_order->get_id(), false );

echo 'SEED_ORDER=' . (int) $ioefw_order->get_id() . "\n";
