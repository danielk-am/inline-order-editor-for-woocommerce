<?php
/**
 * Order changes: add an item that is not in the catalogue, add a delivery charge, change a line,
 * then settle taxes and totals.
 *
 * A change to an existing line is saved by wc_save_order_items(), the function behind the order
 * screen's own Save button, so stock, order notes and other extensions' hooks behave as they do there.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's order service. Other code calls these methods, never the screen.
 */
final class IOEFW_Orders {

	/**
	 * Hidden marker on a line item that was typed in, not picked from the catalogue.
	 */
	const CUSTOM_ITEM_META = '_ioefw_custom';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_marker_meta' ) );
	}

	/**
	 * Keeps the marker out of the item details shown on the order screen.
	 *
	 * @param array $keys Hidden item meta keys.
	 * @return array
	 */
	public static function hide_marker_meta( $keys ) {
		$keys   = (array) $keys;
		$keys[] = self::CUSTOM_ITEM_META;

		return $keys;
	}

	/**
	 * Whether this order's lines may be changed here.
	 *
	 * Follows WooCommerce: only orders it calls editable (pending payment, on hold, or not created
	 * yet). Orders with a refund are left alone as well.
	 *
	 * @param mixed $order Order, or anything else.
	 * @return true|WP_Error
	 */
	public static function can_edit( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'ioefw_no_order', __( 'This order could not be found.', 'inline-order-editor-for-woocommerce' ) );
		}

		$editable = $order->is_editable();
		$refunded = $editable && ( (float) $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0 );

		/**
		 * Filters whether the lines of an order may be changed by this plugin.
		 *
		 * @since 1.0.0
		 * @param bool     $allowed Whether the order may be changed.
		 * @param WC_Order $order   The order.
		 */
		$allowed = (bool) apply_filters( 'ioefw_can_edit_order', $editable && ! $refunded, $order );

		if ( $allowed ) {
			return true;
		}

		if ( $refunded ) {
			return new WP_Error( 'ioefw_locked', __( 'This order has a refund, so its lines can no longer be changed here.', 'inline-order-editor-for-woocommerce' ) );
		}

		return new WP_Error( 'ioefw_locked', __( 'This order can no longer be changed here. To change it, set its status back to Pending payment first.', 'inline-order-editor-for-woocommerce' ) );
	}

	/**
	 * Whether amounts typed for this order include tax.
	 *
	 * Follows the store's "prices entered with tax" setting, the way the shopkeeper thinks of prices.
	 * An order made on the order screen does not record that setting itself.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public static function amounts_include_tax( WC_Order $order ) {
		/**
		 * Filters whether amounts typed on an order include tax.
		 *
		 * @since 1.0.0
		 * @param bool     $inclusive Whether typed amounts include tax.
		 * @param WC_Order $order     The order.
		 */
		return (bool) apply_filters( 'ioefw_amounts_include_tax', wc_prices_include_tax(), $order );
	}

	/**
	 * A fingerprint of the order's lines. A change request carries the fingerprint the screen was
	 * drawn with, so a change made against lines that have moved on is refused.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	public static function items_hash( WC_Order $order ) {
		$parts = array();

		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			$parts[] = array(
				(int) $item_id,
				(string) $item->get_name(),
				(string) $item->get_quantity(),
				is_callable( array( $item, 'get_total' ) ) ? wc_format_decimal( $item->get_total() ) : '',
				is_callable( array( $item, 'get_subtotal' ) ) ? wc_format_decimal( $item->get_subtotal() ) : '',
			);
		}

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * What the order screen script needs to know about the order.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	public static function get_state( WC_Order $order ) {
		$can_edit  = self::can_edit( $order );
		$inclusive = self::amounts_include_tax( $order );
		$items     = array();

		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			$values = self::item_values( $item, $inclusive, $order );

			$items[ (int) $item_id ] = array(
				'type'     => $values['type'],
				'name'     => $values['name'],
				'quantity' => (string) $values['quantity'],
				'price'    => self::input_amount( $values['price'] ),
				'total'    => self::input_amount( $values['total'] ),
				'fields'   => $values['fields'],
			);
		}

		return array(
			'orderId'      => $order->get_id(),
			'editable'     => true === $can_edit,
			'lockedReason' => is_wp_error( $can_edit ) ? $can_edit->get_error_message() : '',
			'isNew'        => 'auto-draft' === $order->get_status(),
			'inclusive'    => $inclusive,
			'hash'         => self::items_hash( $order ),
			'total'        => self::plain_price( $order->get_total(), $order ),
			'items'        => $items,
		);
	}

	/**
	 * Adds a line for something that is not in the catalogue.
	 *
	 * @param WC_Order $order    The order.
	 * @param array    $args     name, quantity, price, note, tax_class. Amounts may be typed strings.
	 * @param array    $tax_args country, state, postcode and city to calculate tax for, as on the screen.
	 * @return WC_Order|WP_Error The order, read again after the change.
	 */
	public static function add_custom_item( WC_Order $order, array $args, array $tax_args = array() ) {
		$args     = wp_parse_args(
			$args,
			array(
				'name'      => '',
				'quantity'  => 1,
				'price'     => 0,
				'note'      => '',
				'tax_class' => '',
			)
		);
		$name     = self::clean_name( $args['name'] );
		$quantity = self::clean_quantity( $args['quantity'] );
		$price    = self::clean_amount( $args['price'], 0.0 );

		if ( '' === $name ) {
			return new WP_Error( 'ioefw_name', __( 'Type a name for the item.', 'inline-order-editor-for-woocommerce' ) );
		}

		if ( null === $quantity || $quantity <= 0 ) {
			return new WP_Error( 'ioefw_quantity', __( 'Type a quantity above zero.', 'inline-order-editor-for-woocommerce' ) );
		}

		if ( null === $price || $price < 0 ) {
			return new WP_Error( 'ioefw_price', __( 'Type a price of zero or more.', 'inline-order-editor-for-woocommerce' ) );
		}

		/**
		 * Filters a custom item before it is added to an order.
		 *
		 * @since 1.0.0
		 * @param array    $args  name, quantity, price, note and tax_class, already cleaned.
		 * @param WC_Order $order The order.
		 */
		$args = (array) apply_filters(
			'ioefw_custom_item_args',
			array(
				'name'      => $name,
				'quantity'  => $quantity,
				'price'     => $price,
				'note'      => self::clean_name( $args['note'] ),
				'tax_class' => self::clean_tax_class( $args['tax_class'] ),
			),
			$order
		);

		$line = (float) $args['price'] * (float) $args['quantity'];
		$item = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'name'      => $args['name'],
				'quantity'  => $args['quantity'],
				'tax_class' => $args['tax_class'],
				'subtotal'  => $line,
				'total'     => $line,
			)
		);
		$item->add_meta_data( self::CUSTOM_ITEM_META, 'yes', true );

		if ( '' !== $args['note'] ) {
			$item->add_meta_data( __( 'Note', 'inline-order-editor-for-woocommerce' ), $args['note'], true );
		}

		$order->add_item( $item );
		$order->save();

		$typed = array(
			'subtotal' => $line,
			'total'    => $line,
		);
		self::settle( $order, self::amounts_include_tax( $order ) ? array( $item->get_id() => $typed ) : array(), $tax_args );

		self::note(
			$order,
			sprintf(
				/* translators: 1: item name, 2: quantity, 3: price each. */
				__( 'Added item: %1$s, quantity %2$s, at %3$s each.', 'inline-order-editor-for-woocommerce' ),
				$args['name'],
				$args['quantity'],
				self::plain_price( $args['price'], $order )
			)
		);

		$order = wc_get_order( $order->get_id() );

		/**
		 * Fires after a custom item has been added and the order's totals have been settled.
		 *
		 * @since 1.0.0
		 * @param int      $item_id The new line's ID.
		 * @param WC_Order $order   The order, read again after the change.
		 */
		do_action( 'ioefw_custom_item_added', $item->get_id(), $order );

		return $order;
	}

	/**
	 * Adds a delivery charge as a shipping line.
	 *
	 * @param WC_Order $order    The order.
	 * @param array    $args     name and amount. The amount may be a typed string.
	 * @param array    $tax_args country, state, postcode and city to calculate tax for.
	 * @return WC_Order|WP_Error The order, read again after the change.
	 */
	public static function add_delivery( WC_Order $order, array $args, array $tax_args = array() ) {
		$args   = wp_parse_args(
			$args,
			array(
				'name'   => '',
				'amount' => 0,
			)
		);
		$name   = self::clean_name( $args['name'] );
		$amount = self::clean_amount( $args['amount'], 0.0 );

		if ( '' === $name ) {
			$name = __( 'Delivery', 'inline-order-editor-for-woocommerce' );
		}

		if ( null === $amount || $amount < 0 ) {
			return new WP_Error( 'ioefw_price', __( 'Type a delivery charge of zero or more.', 'inline-order-editor-for-woocommerce' ) );
		}

		$item = new WC_Order_Item_Shipping();
		$item->set_props(
			array(
				'method_title' => $name,
				'method_id'    => 'other',
				'total'        => $amount,
			)
		);
		$order->add_item( $item );
		$order->save();

		self::settle( $order, self::amounts_include_tax( $order ) ? array( $item->get_id() => array( 'total' => $amount ) ) : array(), $tax_args );

		self::note(
			$order,
			sprintf(
				/* translators: 1: name of the delivery charge, 2: amount. */
				__( 'Added delivery charge: %1$s, %2$s.', 'inline-order-editor-for-woocommerce' ),
				$name,
				self::plain_price( $amount, $order )
			)
		);

		$order = wc_get_order( $order->get_id() );

		/**
		 * Fires after a delivery charge has been added and the order's totals have been settled.
		 *
		 * @since 1.0.0
		 * @param int      $item_id The new shipping line's ID.
		 * @param WC_Order $order   The order, read again after the change.
		 */
		do_action( 'ioefw_delivery_added', $item->get_id(), $order );

		return $order;
	}

	/**
	 * Changes one part of one line: its name, quantity, price each, or total.
	 *
	 * @param WC_Order $order     The order.
	 * @param int      $item_id   The line's ID.
	 * @param string   $field     name, quantity, price or total.
	 * @param string   $raw_value The value as typed.
	 * @param array    $tax_args  country, state, postcode and city to calculate tax for.
	 * @return WC_Order|WP_Error The order, read again after the change.
	 */
	public static function update_item( WC_Order $order, $item_id, $field, $raw_value, array $tax_args = array() ) {
		$item_id = absint( $item_id );
		$item    = $item_id ? $order->get_item( $item_id, false ) : false;

		if ( ! self::is_line( $item ) ) {
			return new WP_Error( 'ioefw_no_item', __( 'That line is no longer on this order.', 'inline-order-editor-for-woocommerce' ) );
		}

		$inclusive = self::amounts_include_tax( $order );
		$before    = self::item_values( $item, $inclusive, $order );
		$type      = $before['type'];
		$changes   = array();
		$typed     = array();

		if ( ! in_array( $field, $before['fields'], true ) ) {
			return new WP_Error( 'ioefw_field', __( 'That part of the line cannot be changed here.', 'inline-order-editor-for-woocommerce' ) );
		}

		switch ( $field ) {
			case 'name':
				$name = self::clean_name( $raw_value );

				if ( '' === $name ) {
					return new WP_Error( 'ioefw_name', __( 'Type a name for the line.', 'inline-order-editor-for-woocommerce' ) );
				}

				$changes = array( 'name' => $name );
				break;

			case 'quantity':
				$quantity     = self::clean_quantity( $raw_value );
				$old_quantity = (float) $item->get_quantity();

				if ( null === $quantity || $quantity <= 0 ) {
					return new WP_Error( 'ioefw_quantity', __( 'Type a quantity above zero. To remove the line, use its delete button.', 'inline-order-editor-for-woocommerce' ) );
				}

				// Keep the price each, and any discount already on the line, as the order screen does.
				$ratio   = $old_quantity > 0 ? $quantity / $old_quantity : 1;
				$changes = array(
					'quantity' => $quantity,
					'subtotal' => (float) $item->get_subtotal() * $ratio,
					'total'    => (float) $item->get_total() * $ratio,
				);
				break;

			case 'price':
				$price = self::clean_amount( $raw_value );

				if ( null === $price || $price < 0 ) {
					return new WP_Error( 'ioefw_price', __( 'Type a price of zero or more.', 'inline-order-editor-for-woocommerce' ) );
				}

				// A discount already on the line keeps its share of the new price.
				$old_subtotal = (float) $item->get_subtotal();
				$subtotal     = $price * (float) $item->get_quantity();
				$changes      = array(
					'subtotal' => $subtotal,
					'total'    => $old_subtotal > 0 ? $subtotal * ( (float) $item->get_total() / $old_subtotal ) : $subtotal,
				);
				$typed        = $changes;
				break;

			case 'total':
				$total = self::clean_amount( $raw_value );

				if ( null === $total || ( $total < 0 && 'fee' !== $type ) ) {
					return new WP_Error( 'ioefw_price', __( 'Type a total of zero or more.', 'inline-order-editor-for-woocommerce' ) );
				}

				// On an item line the total is the price each times the quantity, with no hidden discount.
				$changes = array( 'total' => $total );

				if ( 'line_item' === $type ) {
					$changes['subtotal'] = $total;
				}

				$typed = $changes;
				break;
		}

		self::save_through_core( $order, $item, $changes );

		$order = wc_get_order( $order->get_id() );

		// A new name moves no money, so taxes and totals are left as they are.
		if ( 'name' !== $field ) {
			self::settle( $order, $inclusive && $typed ? array( $item_id => $typed ) : array(), $tax_args );
			$order = wc_get_order( $order->get_id() );
		}

		$item  = $order->get_item( $item_id, false );
		$after = self::is_line( $item ) ? self::item_values( $item, $inclusive, $order ) : $before;

		self::note( $order, self::change_note( $field, $before, $after, $order ) );

		/**
		 * Fires after a line has been changed and the order's totals have been settled.
		 *
		 * @since 1.0.0
		 * @param int      $item_id The line's ID.
		 * @param WC_Order $order   The order, read again after the change.
		 * @param array    $change  field, and the line's values before and after.
		 */
		do_action(
			'ioefw_item_updated',
			$item_id,
			$order,
			array(
				'field'  => $field,
				'before' => $before,
				'after'  => $after,
			)
		);

		return $order;
	}

	/**
	 * Calculates taxes and totals after a change, the way the order screen's Recalculate button does.
	 *
	 * @param WC_Order $order    The order.
	 * @param array    $typed    Per line ID, the subtotal and total as typed. They are read as including
	 *                           tax and converted, once WooCommerce has found the line's tax rates.
	 * @param array    $tax_args country, state, postcode and city to calculate tax for.
	 */
	public static function settle( WC_Order $order, array $typed = array(), array $tax_args = array() ) {
		// The order screen sends the address as it stands in the form. Without one, WooCommerce uses the saved address.
		$tax_args  = isset( $tax_args['country'] ) ? $tax_args : array();
		$converted = false;

		$order->calculate_taxes( $tax_args );

		foreach ( $typed as $item_id => $amounts ) {
			$item = $order->get_item( $item_id, false );

			if ( ! self::is_line( $item ) ) {
				continue;
			}

			$rates = self::rates_on( $item );

			if ( ! $rates ) {
				continue;
			}

			foreach ( $amounts as $prop => $amount ) {
				$setter = 'set_' . $prop;
				$amount = (float) $amount;

				if ( ! is_callable( array( $item, $setter ) ) ) {
					continue;
				}

				if ( 'fee' === $item->get_type() && $amount < 0 ) {
					// WooCommerce spreads the tax on a discount fee across the order's tax rates. Use the share it worked out.
					$tax = (float) $item->get_total_tax();
					$item->{$setter}( 0.0 !== $amount + $tax ? $amount * $amount / ( $amount + $tax ) : $amount );
				} else {
					$item->{$setter}( $amount - array_sum( WC_Tax::calc_tax( $amount, $rates, true ) ) );
				}
			}

			if ( $item instanceof WC_Order_Item_Fee ) {
				$item->set_amount( $item->get_total() );
			}

			$item->save();
			$converted = true;
		}

		// The converted lines need their tax before anything reads it.
		if ( $converted ) {
			$order->calculate_taxes( $tax_args );
		}

		// A coupon on the order follows the new prices, as it would at the checkout.
		if ( $order->get_items( 'coupon' ) && is_callable( array( $order, 'recalculate_coupons' ) ) ) {
			$order->recalculate_coupons();
			$order->calculate_taxes( $tax_args );
		}

		$order->calculate_totals( false );

		/**
		 * Fires after this plugin has recalculated an order's taxes and totals.
		 *
		 * @since 1.0.0
		 * @param WC_Order $order The order.
		 */
		do_action( 'ioefw_after_recalculate', $order );
	}

	/**
	 * An amount as text, with the order's currency.
	 *
	 * @param float|string $amount The amount.
	 * @param WC_Order     $order  The order.
	 * @return string
	 */
	public static function plain_price( $amount, WC_Order $order ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Whether a line is one this plugin changes: a product line, a fee or a shipping line.
	 *
	 * @param mixed $item The line, or false when the order has none with that ID.
	 * @return bool
	 *
	 * @phpstan-assert-if-true WC_Order_Item_Product|WC_Order_Item_Fee|WC_Order_Item_Shipping $item
	 */
	private static function is_line( $item ) {
		$kinds = $item instanceof WC_Order_Item_Product || $item instanceof WC_Order_Item_Fee || $item instanceof WC_Order_Item_Shipping;

		return $kinds && in_array( $item->get_type(), array( 'line_item', 'fee', 'shipping' ), true );
	}

	/**
	 * A line's values as the shopkeeper sees and types them: tax included when the store's prices are.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Fee|WC_Order_Item_Shipping $item      The line.
	 * @param bool     $inclusive Whether amounts include tax.
	 * @param WC_Order $order     The order.
	 * @return array
	 */
	private static function item_values( $item, $inclusive, WC_Order $order ) {
		$type   = $item->get_type();
		$total  = (float) $item->get_total() + ( $inclusive ? (float) $item->get_total_tax() : 0 );
		$values = array(
			'type'     => $type,
			'name'     => (string) $item->get_name(),
			'quantity' => 1,
			'price'    => $total,
			'total'    => $total,
			'fields'   => array( 'name', 'total' ),
		);

		if ( 'line_item' === $type ) {
			$quantity = (float) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal() + ( $inclusive ? (float) $item->get_subtotal_tax() : 0 );

			$values['quantity'] = $quantity;
			$values['price']    = $quantity > 0 ? $subtotal / $quantity : 0;
			// A line with a product behind it keeps the catalogue's name.
			$values['fields'] = $item->get_product() ? array( 'price', 'quantity', 'total' ) : array( 'name', 'price', 'quantity', 'total' );

			// With a coupon on the order a line's total is its price less the coupon, so it is not typed.
			if ( $order->get_items( 'coupon' ) ) {
				$values['fields'] = array_values( array_diff( $values['fields'], array( 'total' ) ) );
			}
		}

		/**
		 * Filters which parts of a line can be changed in place.
		 *
		 * @since 1.0.0
		 * @param string[]      $fields Any of name, price, quantity and total.
		 * @param WC_Order_Item $item   The line.
		 * @param WC_Order      $order  The order.
		 */
		$values['fields'] = array_values( array_intersect( array( 'name', 'price', 'quantity', 'total' ), (array) apply_filters( 'ioefw_editable_fields', $values['fields'], $item, $order ) ) );

		return $values;
	}

	/**
	 * Hands a line's new values to WooCommerce's own save, in the shape the order screen posts them.
	 *
	 * @param WC_Order $order   The order.
	 * @param WC_Order_Item_Product|WC_Order_Item_Fee|WC_Order_Item_Shipping $item    The line.
	 * @param array    $changes Any of name, quantity, subtotal and total.
	 */
	private static function save_through_core( WC_Order $order, $item, array $changes ) {
		$id    = $item->get_id();
		$taxes = $item->get_taxes();
		$total = self::decimal( isset( $changes['total'] ) ? $changes['total'] : $item->get_total() );
		$name  = isset( $changes['name'] ) ? $changes['name'] : $item->get_name();

		if ( 'shipping' === $item->get_type() ) {
			$form = array(
				'shipping_method_id'    => array( $id ),
				'shipping_method'       => array( $id => $item->get_method_id() ),
				'shipping_method_title' => array( $id => $name ),
				'shipping_cost'         => array( $id => $total ),
				'shipping_taxes'        => array( $id => isset( $taxes['total'] ) ? (array) $taxes['total'] : array() ),
			);
		} else {
			$form = array(
				'order_item_id'        => array( $id ),
				'order_item_name'      => array( $id => $name ),
				'order_item_tax_class' => array( $id => $item->get_tax_class() ),
				'line_total'           => array( $id => $total ),
				'line_tax'             => array( $id => isset( $taxes['total'] ) ? (array) $taxes['total'] : array() ),
				'line_subtotal_tax'    => array( $id => isset( $taxes['subtotal'] ) ? (array) $taxes['subtotal'] : array() ),
			);

			if ( 'line_item' === $item->get_type() ) {
				$form['order_item_qty'] = array( $id => (string) ( isset( $changes['quantity'] ) ? $changes['quantity'] : $item->get_quantity() ) );
				$form['line_subtotal']  = array( $id => self::decimal( isset( $changes['subtotal'] ) ? $changes['subtotal'] : $item->get_subtotal() ) );
			}
		}

		// wc_save_order_items() expects the values slashed, as they arrive from a form.
		wc_save_order_items( $order->get_id(), wp_slash( $form ) );
	}

	/**
	 * The tax rates WooCommerce applied to a line, in the shape WC_Tax::calc_tax() takes.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Fee|WC_Order_Item_Shipping $item The line, after taxes were calculated.
	 * @return array
	 */
	private static function rates_on( $item ) {
		$taxes = $item->get_taxes();
		$rates = array();

		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return $rates;
		}

		foreach ( array_keys( $taxes['total'] ) as $rate_id ) {
			$rates[ $rate_id ] = array(
				'rate'     => (float) WC_Tax::get_rate_percent_value( $rate_id ),
				'label'    => WC_Tax::get_rate_label( $rate_id ),
				'shipping' => 'yes',
				'compound' => WC_Tax::is_compound( $rate_id ) ? 'yes' : 'no',
			);
		}

		return $rates;
	}

	/**
	 * The order note for a changed line.
	 *
	 * @param string   $field  name, quantity, price or total.
	 * @param array    $before The line's values before.
	 * @param array    $after  The line's values after.
	 * @param WC_Order $order  The order.
	 * @return string
	 */
	private static function change_note( $field, array $before, array $after, WC_Order $order ) {
		switch ( $field ) {
			case 'name':
				/* translators: 1: old name, 2: new name. */
				return sprintf( __( 'Line renamed from %1$s to %2$s.', 'inline-order-editor-for-woocommerce' ), $before['name'], $after['name'] );

			case 'quantity':
				/* translators: 1: line name, 2: old quantity, 3: new quantity. */
				return sprintf( __( 'Quantity of %1$s changed from %2$s to %3$s.', 'inline-order-editor-for-woocommerce' ), $after['name'], $before['quantity'], $after['quantity'] );

			case 'price':
				/* translators: 1: line name, 2: old price, 3: new price. */
				return sprintf( __( 'Price of %1$s changed from %2$s to %3$s each.', 'inline-order-editor-for-woocommerce' ), $after['name'], self::plain_price( $before['price'], $order ), self::plain_price( $after['price'], $order ) );
		}

		/* translators: 1: line name, 2: old total, 3: new total. */
		return sprintf( __( 'Total of %1$s changed from %2$s to %3$s.', 'inline-order-editor-for-woocommerce' ), $after['name'], self::plain_price( $before['total'], $order ), self::plain_price( $after['total'], $order ) );
	}

	/**
	 * Adds a private order note under the name of the person making the change.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $text  The note.
	 */
	private static function note( WC_Order $order, $text ) {
		$order->add_order_note( $text, false, true, array( 'note_group' => 'order_update' ) );
	}

	/**
	 * A name or a note as plain text of a sensible length.
	 *
	 * @param mixed $raw The value as typed.
	 * @return string
	 */
	private static function clean_name( $raw ) {
		$name = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 );
	}

	/**
	 * A typed amount as a number, or null when it is not one. Reads the store's decimal separator.
	 *
	 * @param mixed      $raw   The value as typed.
	 * @param float|null $empty What an empty value means.
	 * @return float|null
	 */
	private static function clean_amount( $raw, $empty = null ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (float) $raw;
		}

		$raw = is_string( $raw ) ? ioefw_trim( $raw ) : '';

		if ( '' === $raw ) {
			return $empty;
		}

		$number = wc_format_decimal( $raw );

		return is_numeric( $number ) ? (float) $number : null;
	}

	/**
	 * A typed quantity in the store's stock units, or null when it is not a number.
	 *
	 * @param mixed $raw The value as typed.
	 * @return int|float|null
	 */
	private static function clean_quantity( $raw ) {
		$number = self::clean_amount( $raw );

		return null === $number ? null : wc_stock_amount( $number );
	}

	/**
	 * A tax class the store has, or the standard class.
	 *
	 * @param mixed $raw The tax class slug.
	 * @return string
	 */
	private static function clean_tax_class( $raw ) {
		$slug = is_string( $raw ) ? sanitize_title( $raw ) : '';

		return in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ? $slug : '';
	}

	/**
	 * A number as a plain decimal string for WooCommerce to store.
	 *
	 * @param mixed $number The number.
	 * @return string
	 */
	private static function decimal( $number ) {
		return (string) wc_format_decimal( is_string( $number ) ? $number : (float) $number );
	}

	/**
	 * An amount as it should appear in an input: the store's decimals and decimal separator.
	 *
	 * @param float $amount The amount.
	 * @return string
	 */
	private static function input_amount( $amount ) {
		return wc_format_localized_price( wc_format_decimal( (float) $amount, wc_get_price_decimals() ) );
	}
}
