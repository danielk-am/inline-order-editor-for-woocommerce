<?php
/**
 * Plugin checks, run inside a WordPress admin request on a disposable site.
 * Plain assertions, so they run without the WordPress test suite. Not shipped in the release ZIP.
 *
 * Everything made here is removed again at the end, and every setting changed is put back.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

$ioefw_results = array();
$ioefw_made    = array(
	'orders'   => array(),
	'products' => array(),
	'rates'    => array(),
	'coupons'  => array(),
);
$ioefw_options = array();

$ioefw_check = static function ( string $label, bool $passed ) use ( &$ioefw_results ): void {
	$ioefw_results[] = array( $label, $passed );
};

$ioefw_group = static function ( string $name, callable $tests ) use ( $ioefw_check ): void {
	try {
		$tests( $ioefw_check );
	} catch ( Throwable $error ) {
		$ioefw_check( $name . ': ' . get_class( $error ) . ' - ' . $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')', false );
	}
};

$ioefw_option = static function ( string $name, $value ) use ( &$ioefw_options ): void {
	if ( ! array_key_exists( $name, $ioefw_options ) ) {
		$ioefw_options[ $name ] = get_option( $name, null );
	}
	update_option( $name, $value );
};

$ioefw_order = static function ( string $status = 'pending' ) use ( &$ioefw_made ): WC_Order {
	$order = wc_create_order( array( 'status' => $status ) );
	$order->set_billing_country( WC()->countries->get_base_country() );
	$order->set_billing_email( 'ioefw-test@example.com' );
	$order->save();
	$ioefw_made['orders'][] = $order->get_id();

	return $order;
};

$ioefw_product = static function ( string $price, ?int $stock = null ) use ( &$ioefw_made ): WC_Product {
	$product = new WC_Product_Simple();
	$product->set_name( 'IOEFW test product ' . wp_generate_password( 6, false ) );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );

	if ( null !== $stock ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
	}

	$product->save();
	$ioefw_made['products'][] = $product->get_id();

	return $product;
};

$ioefw_tax_rate = static function ( string $percent, string $tax_class = '' ) use ( &$ioefw_made ): int {
	$rate_id = WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => '',
			'tax_rate_state'    => '',
			'tax_rate'          => $percent,
			'tax_rate_name'     => 'Test tax',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => $tax_class,
		)
	);
	$ioefw_made['rates'][] = $rate_id;

	return (int) $rate_id;
};

$ioefw_near = static function ( $actual, $expected ): bool {
	return abs( (float) $actual - (float) $expected ) < 0.005;
};

$ioefw_first = static function ( WC_Order $order, string $type = 'line_item' ) {
	$items = $order->get_items( $type );

	return $items ? reset( $items ) : null;
};

$ioefw_fresh = static function ( WC_Order $order ): WC_Order {
	return wc_get_order( $order->get_id() );
};

$ioefw_notes = static function ( WC_Order $order ): string {
	return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
};

$ioefw_output = static function ( callable $callback ): string {
	ob_start();
	$callback();

	return (string) ob_get_clean();
};

// Taxes off unless a group turns them on.
$ioefw_option( 'woocommerce_calc_taxes', 'no' );
$ioefw_option( 'woocommerce_prices_include_tax', 'no' );
$ioefw_option( 'woocommerce_tax_round_at_subtotal', 'no' );
$ioefw_option( 'woocommerce_tax_based_on', 'billing' );
$ioefw_option( 'woocommerce_manage_stock', 'yes' );

$ioefw_group(
	'Environment',
	static function ( callable $check ) use ( $ioefw_order ): void {
		$check( 'An order made here is read back from the order storage in use.', wc_get_order( $ioefw_order()->get_id() ) instanceof WC_Order );
		$check( 'The plugin declares itself compatible with HPOS.', in_array( plugin_basename( IOEFW_PLUGIN_FILE ), \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' )['compatible'], true ) );
		$check( 'Emails stay inside the test store.', false !== has_filter( 'pre_wp_mail' ) );
		$check( 'The four request handlers are registered for logged-in users only.', has_action( 'wp_ajax_ioefw_add_item' ) && has_action( 'wp_ajax_ioefw_update_item' ) && has_action( 'wp_ajax_ioefw_recalculate' ) && has_action( 'wp_ajax_ioefw_search_products' ) && ! has_action( 'wp_ajax_nopriv_ioefw_add_item' ) && ! has_action( 'wp_ajax_nopriv_ioefw_update_item' ) );
	}
);

$ioefw_group(
	'Custom item',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_near, $ioefw_first, $ioefw_notes ): void {
		$order  = $ioefw_order();
		$result = IOEFW_Orders::add_custom_item(
			$order,
			array(
				'name'     => 'Gift basket <b>large</b>',
				'quantity' => '2',
				'price'    => '15.50',
				'note'     => 'Blue ribbon',
			)
		);

		$check( 'Adding a custom item returns the order.', $result instanceof WC_Order );

		$item = $ioefw_first( $result );

		$check( 'The line has no product behind it.', $item && 0 === $item->get_product_id() && ! $item->get_product() );
		$check( 'The name is kept as plain text.', $item && 'Gift basket large' === $item->get_name() );
		$check( 'Quantity, subtotal and total follow price times quantity.', $item && 2 === (int) $item->get_quantity() && $ioefw_near( $item->get_subtotal(), 31 ) && $ioefw_near( $item->get_total(), 31 ) );
		$check( 'The order total is updated without a Recalculate.', $ioefw_near( $result->get_total(), 31 ) );
		$check( 'The line carries the hidden custom marker.', $item && 'yes' === $item->get_meta( IOEFW_Orders::CUSTOM_ITEM_META ) );
		$check( 'The marker is hidden from the item details on the order screen.', in_array( IOEFW_Orders::CUSTOM_ITEM_META, apply_filters( 'woocommerce_hidden_order_itemmeta', array() ), true ) );
		$check( 'The note is kept as a visible item detail.', $item && 'Blue ribbon' === $item->get_meta( 'Note' ) );
		$check( 'An order note records what was added.', false !== strpos( $ioefw_notes( $result ), 'Added item: Gift basket large, quantity 2' ) );

		$check( 'An empty name is refused.', is_wp_error( IOEFW_Orders::add_custom_item( $result, array( 'name' => '   ', 'price' => '5' ) ) ) );
		$check( 'A negative price is refused.', is_wp_error( IOEFW_Orders::add_custom_item( $result, array( 'name' => 'Thing', 'price' => '-5' ) ) ) );
		$check( 'A price that is not a number is refused.', is_wp_error( IOEFW_Orders::add_custom_item( $result, array( 'name' => 'Thing', 'price' => 'abc' ) ) ) );
		$check( 'A zero quantity is refused.', is_wp_error( IOEFW_Orders::add_custom_item( $result, array( 'name' => 'Thing', 'price' => '5', 'quantity' => '0' ) ) ) );
		$check( 'Nothing was added by the refused requests.', 1 === count( wc_get_order( $result->get_id() )->get_items() ) );

		$free = IOEFW_Orders::add_custom_item( wc_get_order( $result->get_id() ), array( 'name' => 'Free sample' ) );
		$check( 'An item with no price is added at zero, quantity one.', $free instanceof WC_Order && 2 === count( $free->get_items() ) && $ioefw_near( $free->get_total(), 31 ) );
	}
);

$ioefw_group(
	'Changing a line',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_product, $ioefw_near, $ioefw_first, $ioefw_fresh, $ioefw_notes ): void {
		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Cake', 'quantity' => '2', 'price' => '10' ) );
		$id    = $ioefw_first( $order )->get_id();

		$order = IOEFW_Orders::update_item( $order, $id, 'quantity', '3' );
		$item  = $order->get_item( $id, false );
		$check( 'A new quantity keeps the price each: 3 at 10 is 30.', 3 === (int) $item->get_quantity() && $ioefw_near( $item->get_total(), 30 ) && $ioefw_near( $order->get_total(), 30 ) );

		$order = IOEFW_Orders::update_item( $order, $id, 'price', '12.50' );
		$item  = $order->get_item( $id, false );
		$check( 'A new price each updates the line: 3 at 12.50 is 37.50.', $ioefw_near( $item->get_subtotal(), 37.5 ) && $ioefw_near( $item->get_total(), 37.5 ) && $ioefw_near( $order->get_total(), 37.5 ) );

		$order = IOEFW_Orders::update_item( $order, $id, 'total', '30' );
		$item  = $order->get_item( $id, false );
		$check( 'A new total sets the line with no hidden discount.', $ioefw_near( $item->get_subtotal(), 30 ) && $ioefw_near( $item->get_total(), 30 ) && $ioefw_near( $order->get_total(), 30 ) );

		$order = IOEFW_Orders::update_item( $order, $id, 'name', 'Birthday cake' );
		$item  = $order->get_item( $id, false );
		$check( 'A custom line can be renamed, and its amounts stay.', 'Birthday cake' === $item->get_name() && $ioefw_near( $item->get_total(), 30 ) && 3 === (int) $item->get_quantity() );

		$notes = $ioefw_notes( $order );
		$check( 'Each change leaves an order note with the old and new value.', false !== strpos( $notes, 'Quantity of Cake changed from 2 to 3.' ) && false !== strpos( $notes, 'Line renamed from Cake to Birthday cake.' ) && false !== strpos( $notes, 'Price of Cake changed from' ) && false !== strpos( $notes, 'Total of Cake changed from' ) );

		$check( 'A quantity of zero is refused.', is_wp_error( IOEFW_Orders::update_item( $order, $id, 'quantity', '0' ) ) );
		$check( 'An empty price is refused.', is_wp_error( IOEFW_Orders::update_item( $order, $id, 'price', '' ) ) );
		$check( 'A negative total is refused.', is_wp_error( IOEFW_Orders::update_item( $order, $id, 'total', '-1' ) ) );
		$check( 'An empty name is refused.', is_wp_error( IOEFW_Orders::update_item( $order, $id, 'name', ' ' ) ) );
		$check( 'An unknown field is refused.', is_wp_error( IOEFW_Orders::update_item( $order, $id, 'tax_class', 'x' ) ) );

		$tax_line = new WC_Order_Item_Tax();
		$tax_line->set_rate_id( 0 );
		$tax_line->set_label( 'Test tax line' );
		$order->add_item( $tax_line );
		$order->save();
		$check( 'A tax or coupon line of the order is refused, not treated as an item.', is_wp_error( IOEFW_Orders::update_item( wc_get_order( $order->get_id() ), $tax_line->get_id(), 'total', '1' ) ) );
		$order = wc_get_order( $order->get_id() );
		$order->remove_item( $tax_line->get_id() );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$check( 'The refused changes left the line alone.', $ioefw_near( $ioefw_fresh( $order )->get_total(), 30 ) );

		$other    = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Other order line', 'price' => '5' ) );
		$other_id = $ioefw_first( $other )->get_id();
		$check( "A line from another order cannot be changed through this order.", is_wp_error( IOEFW_Orders::update_item( $order, $other_id, 'price', '1' ) ) && $ioefw_near( $ioefw_fresh( $other )->get_total(), 5 ) );

		// A catalogue product keeps its name.
		$product = $ioefw_product( '20' );
		$order   = $ioefw_fresh( $order );
		$line_id = $order->add_product( $product, 1 );
		$order->calculate_totals();
		$state = IOEFW_Orders::get_state( $ioefw_fresh( $order ) );
		$check( 'A line with a product behind it offers price, quantity and total, not its name.', array( 'price', 'quantity', 'total' ) === $state['items'][ $line_id ]['fields'] );
		$check( 'A custom line offers its name as well.', array( 'name', 'price', 'quantity', 'total' ) === $state['items'][ $id ]['fields'] );
		$check( 'Renaming a catalogue line is refused.', is_wp_error( IOEFW_Orders::update_item( $ioefw_fresh( $order ), $line_id, 'name', 'Renamed' ) ) );

		// A discount made with the pencil keeps its share when the quantity or price changes.
		$order = $ioefw_fresh( $order );
		$line  = $order->get_item( $line_id, false );
		$line->set_total( 15 );
		$line->save();
		$order = IOEFW_Orders::update_item( $ioefw_fresh( $order ), $line_id, 'quantity', '2' );
		$line  = $order->get_item( $line_id, false );
		$check( 'A line discount keeps its share when the quantity changes.', $ioefw_near( $line->get_subtotal(), 40 ) && $ioefw_near( $line->get_total(), 30 ) );
	}
);

$ioefw_group(
	'Guards',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_first, $ioefw_fresh ): void {
		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Vase', 'price' => '25' ) );

		$check( 'A pending order can be changed.', true === IOEFW_Orders::can_edit( $order ) );
		$check( 'Something that is not an order cannot.', is_wp_error( IOEFW_Orders::can_edit( false ) ) );

		$hash = IOEFW_Orders::items_hash( $order );
		$next = IOEFW_Orders::update_item( $order, $ioefw_first( $order )->get_id(), 'quantity', '2' );
		$check( 'The fingerprint of the lines changes when a line changes.', $hash !== IOEFW_Orders::items_hash( $next ) );
		$check( 'The fingerprint is the same for the same lines.', IOEFW_Orders::items_hash( $next ) === IOEFW_Orders::items_hash( $ioefw_fresh( $next ) ) );

		$state = IOEFW_Orders::get_state( $next );
		$check( 'The state carries the fingerprint, the total and each line.', $state['hash'] === IOEFW_Orders::items_hash( $next ) && true === $state['editable'] && 1 === count( $state['items'] ) && false !== strpos( $state['total'], '50' ) );

		$on_hold = $ioefw_fresh( $next );
		$on_hold->set_status( 'on-hold' );
		$on_hold->save();
		$check( 'An on-hold order can be changed, as WooCommerce allows.', true === IOEFW_Orders::can_edit( $ioefw_fresh( $on_hold ) ) );

		$paid = $ioefw_fresh( $on_hold );
		$paid->set_status( 'processing' );
		$paid->save();
		$paid = $ioefw_fresh( $paid );
		$can  = IOEFW_Orders::can_edit( $paid );
		$check( 'A processing order cannot be changed.', is_wp_error( $can ) && 'ioefw_locked' === $can->get_error_code() );
		$check( 'Its state says so.', false === IOEFW_Orders::get_state( $paid )['editable'] && '' !== IOEFW_Orders::get_state( $paid )['lockedReason'] );

		$draft = $ioefw_order( 'checkout-draft' );
		$check( 'A Draft order cannot be changed: WooCommerce does not count it as editable.', is_wp_error( IOEFW_Orders::can_edit( $draft ) ) );

		$refunded = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Lamp', 'price' => '40' ) );
		wc_create_refund(
			array(
				'order_id' => $refunded->get_id(),
				'amount'   => 5,
				'reason'   => 'Test refund',
			)
		);
		$refunded = $ioefw_fresh( $refunded );
		$check( 'An order with a refund cannot be changed, even while pending.', 'pending' === $refunded->get_status() && is_wp_error( IOEFW_Orders::can_edit( $refunded ) ) );

		add_filter( 'ioefw_can_edit_order', '__return_true' );
		$check( 'The ioefw_can_edit_order filter can allow it.', true === IOEFW_Orders::can_edit( $refunded ) );
		remove_filter( 'ioefw_can_edit_order', '__return_true' );
	}
);

$ioefw_group(
	'Tax added on top of prices',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_option, $ioefw_tax_rate, $ioefw_near, $ioefw_first ): void {
		$ioefw_option( 'woocommerce_calc_taxes', 'yes' );
		$ioefw_option( 'woocommerce_prices_include_tax', 'no' );
		$ioefw_tax_rate( '10.0000' );

		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Candle', 'quantity' => '2', 'price' => '10' ) );
		$item  = $ioefw_first( $order );

		$check( 'A custom item is taxed at the standard rate.', $ioefw_near( $item->get_total(), 20 ) && $ioefw_near( $item->get_total_tax(), 2 ) );
		$check( 'Tax is added on top: the customer pays 22.', $ioefw_near( $order->get_total(), 22 ) && $ioefw_near( $order->get_total_tax(), 2 ) );
		$check( 'The state says amounts do not include tax, and shows the price each as typed.', false === IOEFW_Orders::get_state( $order )['inclusive'] && '10.00' === IOEFW_Orders::get_state( $order )['items'][ $item->get_id() ]['price'] );

		$order = IOEFW_Orders::update_item( $order, $item->get_id(), 'price', '20' );
		$check( 'A new price recalculates tax: 2 at 20 plus 10% is 44.', $ioefw_near( $order->get_total(), 44 ) && $ioefw_near( $order->get_total_tax(), 4 ) );

		$order = IOEFW_Orders::add_delivery( $order, array( 'name' => 'Courier', 'amount' => '8' ) );
		$ship  = $ioefw_first( $order, 'shipping' );
		$check( 'A delivery charge is a shipping line named as typed.', $ship && 'Courier' === $ship->get_name() && 'other' === $ship->get_method_id() && $ioefw_near( $ship->get_total(), 8 ) );
		$check( 'Delivery is taxed too: the order is 44 plus 8.80.', $ioefw_near( $order->get_total(), 52.8 ) && $ioefw_near( $order->get_shipping_total(), 8 ) );

		$order = IOEFW_Orders::update_item( $order, $ship->get_id(), 'total', '10' );
		$order = IOEFW_Orders::update_item( $order, $ship->get_id(), 'name', 'Same-day courier' );
		$ship  = $ioefw_first( $order, 'shipping' );
		$check( 'A delivery charge can be changed and renamed in place.', 'Same-day courier' === $ship->get_name() && 'other' === $ship->get_method_id() && $ioefw_near( $ship->get_total(), 10 ) && $ioefw_near( $order->get_total(), 55 ) );
		$check( 'A shipping line offers its name and total only.', array( 'name', 'total' ) === IOEFW_Orders::get_state( $order )['items'][ $ship->get_id() ]['fields'] );
	}
);

$ioefw_group(
	'Prices entered with tax',
	static function ( callable $check ) use ( $ioefw_option, $ioefw_tax_rate, $ioefw_near, $ioefw_first, &$ioefw_made ): void {
		$ioefw_option( 'woocommerce_calc_taxes', 'yes' );
		$ioefw_option( 'woocommerce_prices_include_tax', 'yes' );

		if ( ! WC_Tax::find_rates( array( 'country' => WC()->countries->get_base_country(), 'tax_class' => '' ) ) ) {
			$ioefw_tax_rate( '10.0000' );
		}

		// Made the way the order screen makes a new order: WooCommerce does not record the setting on it.
		$order = new WC_Order();
		$order->set_status( 'auto-draft' );
		$order->set_created_via( 'admin' );
		$order->set_billing_country( WC()->countries->get_base_country() );
		$order->save();
		$ioefw_made['orders'][] = $order->get_id();
		$check( 'An order made on the order screen does not record that prices include tax.', false === wc_get_order( $order->get_id() )->get_prices_include_tax() );
		$check( 'Typed amounts are still read as including tax, from the store setting.', true === IOEFW_Orders::amounts_include_tax( $order ) );

		$order = IOEFW_Orders::add_custom_item( $order, array( 'name' => 'Hamper', 'quantity' => '3', 'price' => '3.33' ) );
		$item  = $ioefw_first( $order );

		$check( 'The customer pays what was typed: 3 at 3.33 is 9.99.', $ioefw_near( $order->get_total(), 9.99 ) );
		$check( 'The line is stored without tax, and the tax beside it.', $ioefw_near( $item->get_total(), 9.08 ) && $ioefw_near( $item->get_total_tax(), 0.91 ) );

		$state = IOEFW_Orders::get_state( $order );
		$check( 'The state shows the amounts with tax, as they were typed.', true === $state['inclusive'] && '3.33' === $state['items'][ $item->get_id() ]['price'] && '9.99' === $state['items'][ $item->get_id() ]['total'] );

		$order = IOEFW_Orders::update_item( $order, $item->get_id(), 'price', '12' );
		$check( 'A new price is read with tax: 3 at 12 is 36.', $ioefw_near( $order->get_total(), 36 ) );

		$order = IOEFW_Orders::update_item( $order, $item->get_id(), 'quantity', '5' );
		$check( 'A new quantity keeps the price with tax: 5 at 12 is 60.', $ioefw_near( $order->get_total(), 60 ) );

		$order = IOEFW_Orders::update_item( $order, $item->get_id(), 'total', '55' );
		$check( 'A new total is read with tax: the customer pays 55.', $ioefw_near( $order->get_total(), 55 ) && '11.00' === IOEFW_Orders::get_state( $order )['items'][ $item->get_id() ]['price'] );

		$order = IOEFW_Orders::add_delivery( $order, array( 'name' => 'Delivery', 'amount' => '8' ) );
		$check( 'A delivery charge is read with tax too: 55 plus 8 is 63.', $ioefw_near( $order->get_total(), 63 ) );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Rush fee' );
		$fee->set_amount( 10 );
		$fee->set_total( 10 );
		$order->add_item( $fee );
		$order->save();
		$order = IOEFW_Orders::update_item( wc_get_order( $order->get_id() ), $fee->get_id(), 'total', '11' );
		$fee   = $ioefw_first( $order, 'fee' );
		$check( 'A fee changed in place is read with tax: 63 plus 11 is 74.', $ioefw_near( $order->get_total(), 74 ) && $ioefw_near( $fee->get_total(), 10 ) && $ioefw_near( $fee->get_amount(), 10 ) );

		$order = IOEFW_Orders::update_item( $order, $fee->get_id(), 'name', 'Express fee' );
		$fee   = $ioefw_first( $order, 'fee' );
		$check( 'Renaming a fee keeps its amount.', 'Express fee' === $fee->get_name() && $ioefw_near( $fee->get_total(), 10 ) && $ioefw_near( $order->get_total(), 74 ) );

		$order = IOEFW_Orders::update_item( $order, $fee->get_id(), 'total', '-11' );
		$check( 'A fee typed below zero is a discount, read with tax: 63 less 11 is 52.', $ioefw_near( $order->get_total(), 52 ) );

		// An order from the checkout records that its prices include tax, and has a coupon.
		$coupon = new WC_Coupon();
		$coupon->set_code( 'ioefw-incl-' . strtolower( wp_generate_password( 6, false ) ) );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$ioefw_made['coupons'][] = $coupon->get_id();

		$product = new WC_Product_Simple();
		$product->set_name( 'IOEFW test product with tax' );
		$product->set_regular_price( '55' );
		$product->set_status( 'publish' );
		$product->save();
		$ioefw_made['products'][] = $product->get_id();

		$checkout = wc_create_order( array( 'status' => 'pending' ) );
		$checkout->set_billing_country( WC()->countries->get_base_country() );
		$ioefw_made['orders'][] = $checkout->get_id();
		$line_id = $checkout->add_product( $product, 1 );
		$checkout->calculate_totals();
		$checkout->apply_coupon( $coupon->get_code() );
		$checkout = wc_get_order( $checkout->get_id() );
		$check( 'An order from the checkout records that prices include tax. With 10% off, 55 becomes 49.50.', true === $checkout->get_prices_include_tax() && $ioefw_near( $checkout->get_total(), 49.5 ) );

		$checkout = IOEFW_Orders::update_item( $checkout, $line_id, 'price', '110' );
		$check( 'A new price with tax under a coupon: 110 less 10% is 99, to the cent.', $ioefw_near( $checkout->get_total(), 99 ) );
		$check( 'With a coupon on the order, a line offers its price and quantity, and its total follows.', array( 'price', 'quantity' ) === IOEFW_Orders::get_state( $checkout )['items'][ $line_id ]['fields'] );
		$check( 'Typing a total on that line is refused.', is_wp_error( IOEFW_Orders::update_item( $checkout, $line_id, 'total', '60' ) ) );
	}
);

$ioefw_group(
	'Coupons and stock',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_option, $ioefw_product, $ioefw_near, $ioefw_fresh, &$ioefw_made ): void {
		$ioefw_option( 'woocommerce_calc_taxes', 'no' );
		$ioefw_option( 'woocommerce_prices_include_tax', 'no' );

		$coupon = new WC_Coupon();
		$coupon->set_code( 'ioefw-test-' . strtolower( wp_generate_password( 6, false ) ) );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$ioefw_made['coupons'][] = $coupon->get_id();

		$product = $ioefw_product( '100' );
		$order   = $ioefw_order();
		$line_id = $order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->apply_coupon( $coupon->get_code() );
		$order = $ioefw_fresh( $order );
		$check( 'The coupon takes 10% off: 100 becomes 90.', $ioefw_near( $order->get_total(), 90 ) );

		$order = IOEFW_Orders::update_item( $order, $line_id, 'price', '200' );
		$check( 'The coupon follows a new price: 200 becomes 180.', $ioefw_near( $order->get_total(), 180 ) && $ioefw_near( $order->get_discount_total(), 20 ) );

		$order = IOEFW_Orders::add_custom_item( $order, array( 'name' => 'Extra', 'price' => '50' ) );
		$check( 'A percentage coupon is for catalogue products, so a typed item keeps its price: 180 plus 50.', $ioefw_near( $order->get_total(), 230 ) );

		$fixed = new WC_Coupon();
		$fixed->set_code( 'ioefw-cart-' . strtolower( wp_generate_password( 6, false ) ) );
		$fixed->set_discount_type( 'fixed_cart' );
		$fixed->set_amount( 30 );
		$fixed->save();
		$ioefw_made['coupons'][] = $fixed->get_id();

		$order   = $ioefw_order();
		$line_id = $order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->apply_coupon( $fixed->get_code() );
		$order = IOEFW_Orders::add_custom_item( $ioefw_fresh( $order ), array( 'name' => 'Extra', 'price' => '50' ) );
		$check( 'A fixed cart coupon is shared across every line, typed items included: 150 less 30.', $ioefw_near( $order->get_total(), 120 ) && $ioefw_near( $order->get_discount_total(), 30 ) );

		// Stock follows WooCommerce's own rules: an on-hold order that has reduced stock adjusts it.
		$stocked = $ioefw_product( '10', 10 );
		$order   = $ioefw_order( 'on-hold' );
		$line_id = $order->add_product( $stocked, 2 );
		$order->calculate_totals();
		wc_reduce_stock_levels( $order->get_id() );
		$check( 'The on-hold order holds 2 of 10 in stock.', 8 === (int) wc_get_product( $stocked->get_id() )->get_stock_quantity() );

		$order = IOEFW_Orders::update_item( $ioefw_fresh( $order ), $line_id, 'quantity', '5' );
		$check( 'Raising the quantity to 5 takes 3 more from stock.', $order instanceof WC_Order && 5 === (int) wc_get_product( $stocked->get_id() )->get_stock_quantity() );

		$order = IOEFW_Orders::update_item( $order, $line_id, 'quantity', '1' );
		$check( 'Lowering it to 1 puts 4 back.', $order instanceof WC_Order && 9 === (int) wc_get_product( $stocked->get_id() )->get_stock_quantity() );
	}
);

$ioefw_group(
	'Order terms',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_option, $ioefw_output, $ioefw_fresh ): void {
		$ioefw_option( 'ioefw_terms_in_emails', 'yes' );
		$ioefw_option( 'ioefw_terms_on_order_page', 'yes' );
		$ioefw_option( 'ioefw_terms_on_pdf', 'yes' );
		$ioefw_option( 'ioefw_terms_heading', '' );
		$ioefw_option( 'ioefw_default_terms', "Pay within 7 days.\nNo returns on cut flowers." );

		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Wreath', 'price' => '80' ) );
		$text  = "Pay by <b>Friday</b>.\nCollect at https://example.com/shop & bring ID.";

		$check( 'An order starts with no terms.', '' === ioefw_get_order_terms( $order ) );

		IOEFW_Terms::set( $order, $text );
		$order = $ioefw_fresh( $order );
		$check( 'Terms are kept on the order as plain text, line breaks included.', "Pay by Friday.\nCollect at https://example.com/shop & bring ID." === $order->get_meta( IOEFW_Terms::META_KEY ) );
		$check( 'ioefw_get_order_terms() returns them, by order or by ID.', ioefw_get_order_terms( $order ) === $order->get_meta( IOEFW_Terms::META_KEY ) && ioefw_get_order_terms( $order->get_id() ) === ioefw_get_order_terms( $order ) );

		$html = $ioefw_output(
			static function () use ( $order ) {
				IOEFW_Terms::email( $order, false, false, null );
			}
		);
		$check( 'An HTML email shows the heading and the terms.', false !== strpos( $html, '<h2>Terms</h2>' ) && false !== strpos( $html, 'Pay by Friday.' ) );
		$check( 'Web addresses become links, and the text is escaped.', false !== strpos( $html, '<a href="https://example.com/shop"' ) && false !== strpos( $html, '&amp; bring ID.' ) && false === strpos( $html, '<b>' ) );

		$plain = $ioefw_output(
			static function () use ( $order ) {
				IOEFW_Terms::email( $order, false, true, null );
			}
		);
		$check( 'A plain-text email shows them without markup.', false !== strpos( $plain, "TERMS\n\nPay by Friday." ) && false === strpos( $plain, '<' ) );

		IOEFW_Terms::set( $order, "Don't forget: pay by Friday." );
		$apostrophe = $ioefw_output(
			static function () use ( $order ) {
				IOEFW_Terms::email( wc_get_order( $order->get_id() ), false, true, null );
			}
		);
		$check( "An apostrophe is written the way WooCommerce's plain-text emails keep it.", false !== strpos( $apostrophe, 'Don&#8217;t forget' ) && false === strpos( $apostrophe, '&#039;' ) );
		IOEFW_Terms::set( wc_get_order( $order->get_id() ), $text );
		$order = wc_get_order( $order->get_id() );

		$mailer  = WC()->mailer();
		$invoice = $mailer->get_emails()['WC_Email_Customer_Invoice'];
		$invoice->object = $order;
		$content = $invoice->get_content_html();
		$check( "WooCommerce's own order details email carries the terms.", false !== strpos( $content, 'Pay by Friday.' ) && false !== strpos( $content, 'Wreath' ) );

		// A real status change sends WooCommerce's own emails. The test store catches them.
		$paid = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Corsage', 'price' => '18' ) );
		IOEFW_Terms::set( $paid, 'Collect on Saturday from 10am.' );
		delete_option( 'ioefw_test_mail' );
		$paid = $ioefw_fresh( $paid );
		$paid->set_status( 'processing' );
		$paid->save();
		$caught = (array) get_option( 'ioefw_test_mail', array() );
		$mail   = implode( "\n", wp_list_pluck( $caught, 'message' ) );
		$check( 'Marking an order as paid sends emails, and the test store catches them.', count( $caught ) >= 1 );
		$check( 'Those emails carry the terms and the typed item.', false !== strpos( $mail, 'Collect on Saturday from 10am.' ) && false !== strpos( $mail, 'Corsage' ) );

		$page = $ioefw_output(
			static function () use ( $order ) {
				do_action( 'woocommerce_order_details_after_order_table', $order );
			}
		);
		$check( "The customer's order page shows the terms.", false !== strpos( $page, 'woocommerce-order-terms' ) && false !== strpos( $page, 'Pay by Friday.' ) );

		$ioefw_option( 'ioefw_terms_heading', 'Payment & delivery' );
		$ioefw_option( 'ioefw_terms_in_emails', 'no' );
		$ioefw_option( 'ioefw_terms_on_order_page', 'no' );
		$check(
			'Each place can be switched off in the settings.',
			'' === $ioefw_output(
				static function () use ( $order ) {
					IOEFW_Terms::email( $order, false, false, null );
					do_action( 'woocommerce_order_details_after_order_table', $order );
				}
			)
		);
		$check( 'The heading can be changed in the settings.', 'Payment & delivery' === IOEFW_Terms::heading() );
		$ioefw_option( 'ioefw_terms_in_emails', 'yes' );
		$ioefw_option( 'ioefw_terms_on_order_page', 'yes' );
		$ioefw_option( 'ioefw_terms_heading', '' );

		$pdf = $ioefw_output(
			static function () use ( $order ) {
				do_action( 'wpo_wcpdf_after_order_details', 'invoice', $order );
				do_action( 'wpo_wcpdf_after_order_details', 'packing-slip', $order );
			}
		);
		$check( 'The PDF invoice hook prints the terms once, on invoices only.', 1 === substr_count( $pdf, 'Pay by Friday.' ) );

		// The box on the order screen.
		$box = $ioefw_output(
			static function () use ( $order ) {
				IOEFW_Terms::render_meta_box( $order );
			}
		);
		$check( 'The Order terms box holds the saved terms, escaped for the field.', false !== strpos( $box, 'name="ioefw_order_terms"' ) && false !== strpos( $box, 'Collect at https://example.com/shop &amp; bring ID.</textarea>' ) && false !== strpos( $box, 'name="ioefw_terms_nonce"' ) );

		$new = new WC_Order();
		$new->set_status( 'auto-draft' );
		$new->save();
		$new_id  = $new->get_id();
		$new_box = $ioefw_output(
			static function () use ( $new ) {
				IOEFW_Terms::render_meta_box( $new );
			}
		);
		$check( 'A new order starts with the default terms in the box.', false !== strpos( $new_box, 'Pay within 7 days.' ) );
		$check( 'The default is not saved until the order is.', '' === ioefw_get_order_terms( $new ) );

		$_POST['ioefw_order_terms'] = wp_slash( "Deposit of 50% on order.\nBalance on delivery." );
		$_POST['ioefw_terms_nonce'] = 'not-a-real-nonce';
		IOEFW_Terms::save( $new_id, $new );
		$check( 'Saving without a valid nonce changes nothing.', '' === ioefw_get_order_terms( wc_get_order( $new_id ) ) );

		$_POST['ioefw_terms_nonce'] = wp_create_nonce( 'ioefw_save_terms' );
		IOEFW_Terms::save( $new_id, $new );
		$check( 'Saving the order saves the terms from the box.', "Deposit of 50% on order.\nBalance on delivery." === ioefw_get_order_terms( wc_get_order( $new_id ) ) );
		$check( 'The terms are saved before WooCommerce saves the order data, so an email sent by that save has them.', 5 === has_action( 'woocommerce_process_shop_order_meta', array( 'IOEFW_Terms', 'save' ) ) && 40 === has_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save' ) );

		$_POST['ioefw_order_terms'] = '';
		IOEFW_Terms::save( $new_id, wc_get_order( $new_id ) );
		$check( 'Emptying the box removes the terms.', '' === ioefw_get_order_terms( wc_get_order( $new_id ) ) && ! wc_get_order( $new_id )->meta_exists( IOEFW_Terms::META_KEY ) );

		unset( $_POST['ioefw_order_terms'], $_POST['ioefw_terms_nonce'] );
		wc_get_order( $new_id )->delete( true );

		add_filter(
			'ioefw_order_terms',
			static function ( $terms, $filtered_order, $context ) {
				return 'email' === $context ? $terms . ' Filtered.' : $terms;
			},
			10,
			3
		);
		$check( 'The ioefw_order_terms filter can change the terms for one place.', false !== strpos( IOEFW_Terms::get( $order, 'email' ), 'Filtered.' ) && false === strpos( IOEFW_Terms::get( $order, 'view' ), 'Filtered.' ) );
		remove_all_filters( 'ioefw_order_terms' );
	}
);

$ioefw_group(
	'PDF Invoices & Packing Slips',
	static function ( callable $check ) use ( $ioefw_order ): void {
		if ( ! function_exists( 'wcpdf_get_document' ) ) {
			echo "SKIPPED  PDF Invoices & Packing Slips for WooCommerce is not active, so its invoice was not built.\n";
			return;
		}

		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Table arrangement', 'price' => '120' ) );
		IOEFW_Terms::set( $order, 'Invoice terms: pay within 14 days.' );

		$document = wcpdf_get_document( 'invoice', wc_get_order( $order->get_id() ), true );
		$html     = $document ? $document->get_html() : '';

		$check( 'Its invoice shows the custom item.', false !== strpos( $html, 'Table arrangement' ) );
		$check( 'Its invoice shows the terms.', false !== strpos( $html, 'Invoice terms: pay within 14 days.' ) );
	}
);

$ioefw_group(
	'Analytics',
	static function ( callable $check ) use ( $ioefw_order, $ioefw_fresh ): void {
		if ( ! class_exists( \Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::class ) ) {
			echo "SKIPPED  WooCommerce Analytics is not available, so its Products report was not read.\n";
			return;
		}

		$order = IOEFW_Orders::add_custom_item( $ioefw_order(), array( 'name' => 'Analytics probe item', 'quantity' => '2', 'price' => '7' ) );
		$order = $ioefw_fresh( $order );
		$order->set_status( 'completed' );
		$order->save();

		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order->get_id() );
		\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $order->get_id() );

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/products' );
		$request->set_query_params(
			array(
				'extended_info' => true,
				'per_page'      => 100,
				'after'         => gmdate( 'Y-m-d\T00:00:00', time() - DAY_IN_SECONDS ),
				'before'        => gmdate( 'Y-m-d\T23:59:59', time() + DAY_IN_SECONDS ),
			)
		);
		$rows  = rest_do_request( $request )->get_data();
		$typed = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row['product_id'] ) && 0 === (int) $row['product_id'] ) {
				$typed[] = $row;
			}
		}

		$check( 'Analytics > Products lists typed items together in one row, with no product ID.', 1 === count( $typed ) && (int) $typed[0]['items_sold'] >= 2 );
		$check( 'That row is labelled with an item name and "(Deleted)".', 1 === count( $typed ) && false !== strpos( (string) $typed[0]['extended_info']['name'], '(Deleted)' ) );
		echo 'NOTE     Analytics names the typed-items row: ' . ( $typed ? wp_strip_all_tags( (string) $typed[0]['extended_info']['name'] ) : 'none found' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/plain report on a test site.
	}
);

$ioefw_group(
	'Settings and uninstall',
	static function ( callable $check ): void {
		$settings = apply_filters( 'woocommerce_general_settings', array() );
		$ids      = wp_list_pluck( $settings, 'id' );

		$check( 'The settings are added to WooCommerce > Settings > General.', in_array( 'ioefw_default_terms', $ids, true ) && in_array( 'ioefw_terms_in_emails', $ids, true ) && in_array( 'ioefw_terms_on_order_page', $ids, true ) && in_array( 'ioefw_terms_on_pdf', $ids, true ) && in_array( 'ioefw_terms_heading', $ids, true ) );
		$check( 'The section opens and closes.', 'title' === $settings[0]['type'] && 'sectionend' === end( $settings )['type'] );

		$uninstall = (string) file_get_contents( dirname( IOEFW_PLUGIN_FILE ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$missing   = array();

		foreach ( $ids as $id ) {
			if ( 0 === strpos( (string) $id, 'ioefw_' ) && 'ioefw_terms_options' !== $id && false === strpos( $uninstall, "'" . $id . "'" ) ) {
				$missing[] = $id;
			}
		}
		$check( 'uninstall.php removes every setting the plugin adds.', array() === $missing );
	}
);

// Clean up, and put the settings back.
foreach ( $ioefw_made['orders'] as $ioefw_id ) {
	$ioefw_cleanup = wc_get_order( $ioefw_id );
	if ( $ioefw_cleanup ) {
		foreach ( $ioefw_cleanup->get_refunds() as $ioefw_refund ) {
			$ioefw_refund->delete( true );
		}
		$ioefw_cleanup->delete( true );
	}
}
foreach ( $ioefw_made['products'] as $ioefw_id ) {
	wp_delete_post( $ioefw_id, true );
}
foreach ( $ioefw_made['coupons'] as $ioefw_id ) {
	wp_delete_post( $ioefw_id, true );
}
foreach ( $ioefw_made['rates'] as $ioefw_id ) {
	WC_Tax::_delete_tax_rate( $ioefw_id );
}
foreach ( $ioefw_options as $ioefw_name => $ioefw_value ) {
	if ( null === $ioefw_value ) {
		delete_option( $ioefw_name );
	} else {
		update_option( $ioefw_name, $ioefw_value );
	}
}

$ioefw_failed = 0;

foreach ( $ioefw_results as $ioefw_result ) {
	echo ( $ioefw_result[1] ? 'PASS  ' : 'FAIL  ' ) . wp_strip_all_tags( $ioefw_result[0] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/plain report on a test site.
	$ioefw_failed += $ioefw_result[1] ? 0 : 1;
}

echo "\n" . count( $ioefw_results ) . ' checks, ' . (int) $ioefw_failed . " failed.\n";
echo 'WordPress ' . esc_html( get_bloginfo( 'version' ) ) . ', WooCommerce ' . esc_html( WC()->version ) . ', PHP ' . esc_html( PHP_VERSION ) . ', plugin ' . esc_html( IOEFW_VERSION ) . ', order storage ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts' ) . "\n";
