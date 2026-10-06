<?php
/**
 * The order screen's requests: add a line, change a line, recalculate, and search the catalogue.
 *
 * Each handler checks the nonce and the capability, reads named fields only, and hands the work to
 * IOEFW_Orders. The response carries the Items box as WooCommerce itself draws it.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers for the order screen.
 */
final class IOEFW_Ajax {

	const NONCE = 'ioefw-order';

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'add_item', 'update_item', 'recalculate', 'search_products' ) as $handler ) {
			add_action( 'wp_ajax_ioefw_' . $handler, array( __CLASS__, $handler ) );
		}
	}

	/**
	 * Adds a custom item or a delivery charge.
	 */
	public static function add_item() {
		$request = self::request();
		$order   = self::editable_order( $request, true );
		$before  = IOEFW_Orders::plain_price( $order->get_total(), $order );

		if ( 'delivery' === $request['kind'] ) {
			$result = IOEFW_Orders::add_delivery(
				$order,
				array(
					'name'   => $request['name'],
					'amount' => $request['price'],
				),
				$request['tax']
			);
		} else {
			$result = IOEFW_Orders::add_custom_item(
				$order,
				array(
					'name'      => $request['name'],
					'quantity'  => $request['quantity'],
					'price'     => $request['price'],
					'note'      => $request['note'],
					'tax_class' => $request['tax_class'],
				),
				$request['tax']
			);
		}

		self::respond( $result, $before );
	}

	/**
	 * Changes one part of one line.
	 */
	public static function update_item() {
		$request = self::request();
		$order   = self::editable_order( $request, true );
		$before  = IOEFW_Orders::plain_price( $order->get_total(), $order );

		self::respond( IOEFW_Orders::update_item( $order, $request['item_id'], $request['field'], $request['value'], $request['tax'] ), $before );
	}

	/**
	 * Recalculates taxes and totals, after WooCommerce itself added a product from the catalogue.
	 */
	public static function recalculate() {
		$request = self::request();
		$order   = self::editable_order( $request, false );
		$before  = IOEFW_Orders::plain_price( $order->get_total(), $order );

		IOEFW_Orders::settle( $order, array(), $request['tax'] );

		self::respond( wc_get_order( $order->get_id() ), $before );
	}

	/**
	 * Finds catalogue products for the new line's suggestions.
	 */
	public static function search_products() {
		$request = self::request();
		$term    = $request['term'];
		$found   = array();

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'products' => $found ) );
		}

		/**
		 * Filters how many catalogue products the new line suggests.
		 *
		 * @since 1.0.0
		 * @param int $limit Number of suggestions.
		 */
		$limit = max( 1, (int) apply_filters( 'ioefw_product_search_limit', 8 ) );
		$ids   = WC_Data_Store::load( 'product' )->search_products( $term, '', true, false, $limit * 2 );

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			// A variable product is a container. Its variations are the things that can be ordered.
			if ( ! $product || ! wc_products_array_filter_readable( $product ) || $product->is_type( 'variable' ) ) {
				continue;
			}

			$stock = '';

			if ( $product->managing_stock() ) {
				/* translators: %s: number of items in stock. */
				$stock = sprintf( __( '%s in stock', 'inline-order-editor-for-woocommerce' ), wc_format_stock_quantity_for_display( $product->get_stock_quantity(), $product ) );
			} elseif ( ! $product->is_in_stock() ) {
				$stock = __( 'Out of stock', 'inline-order-editor-for-woocommerce' );
			}

			$found[] = array(
				'id'     => $product->get_id(),
				'name'   => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'sku'    => (string) $product->get_sku(),
				'price'  => '' === (string) $product->get_price() ? '' : html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
				'amount' => (float) $product->get_price(),
				'stock'  => $stock,
			);

			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		wp_send_json_success( array( 'products' => $found ) );
	}

	/**
	 * Verifies the request and returns its named fields, cleaned.
	 *
	 * @return array
	 */
	private static function request() {
		check_ajax_referer( self::NONCE, 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ioefw_forbidden',
					'message' => __( 'You are not allowed to change orders.', 'inline-order-editor-for-woocommerce' ),
				),
				403
			);
		}

		$text = array();

		foreach ( array( 'kind', 'field', 'name', 'value', 'quantity', 'price', 'note', 'tax_class', 'hash', 'term' ) as $key ) {
			$text[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		// The address in the form, read the way WooCommerce reads it for its own Recalculate button.
		$tax = array();

		foreach ( array( 'country', 'state', 'postcode', 'city' ) as $key ) {
			$tax[ $key ] = isset( $_POST[ $key ] ) ? wc_strtoupper( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
		}

		return array_merge(
			$text,
			array(
				'order_id' => isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0,
				'item_id'  => isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0,
				'tax'      => isset( $_POST['country'] ) ? $tax : array(),
			)
		);
	}

	/**
	 * The order a request is about, or an error response when it may not be changed.
	 *
	 * @param array $request    The cleaned request.
	 * @param bool  $check_hash Whether the lines must still be as the screen last drew them.
	 * @return WC_Order
	 */
	private static function editable_order( array $request, $check_hash ) {
		$order    = $request['order_id'] ? wc_get_order( $request['order_id'] ) : false;
		$can_edit = IOEFW_Orders::can_edit( $order );

		if ( is_wp_error( $can_edit ) ) {
			self::fail( $can_edit, $order instanceof WC_Order ? $order : null );
		}

		if ( $check_hash && ! hash_equals( IOEFW_Orders::items_hash( $order ), $request['hash'] ) ) {
			self::fail(
				new WP_Error( 'ioefw_stale', __( 'This order was changed somewhere else, so nothing was saved. The lines below are now up to date. Please make your change again.', 'inline-order-editor-for-woocommerce' ) ),
				$order
			);
		}

		return $order;
	}

	/**
	 * Sends the result of a change.
	 *
	 * @param WC_Order|WP_Error $result       The order after the change, or what went wrong.
	 * @param string            $total_before The order total before the change, as text.
	 */
	private static function respond( $result, $total_before ) {
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}

		if ( ! $result instanceof WC_Order ) {
			self::fail( new WP_Error( 'ioefw_no_order', __( 'This order could not be found.', 'inline-order-editor-for-woocommerce' ) ) );
		}

		wp_send_json_success(
			array_merge(
				self::fragments( $result ),
				array(
					'total'       => IOEFW_Orders::plain_price( $result->get_total(), $result ),
					'totalBefore' => $total_before,
				)
			)
		);
	}

	/**
	 * Sends an error. With an order, the response also carries its lines so the screen can catch up.
	 *
	 * @param WP_Error      $error What went wrong.
	 * @param WC_Order|null $order The order, when the screen should be redrawn.
	 */
	private static function fail( WP_Error $error, $order = null ) {
		$data = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);

		if ( $order instanceof WC_Order ) {
			$data = array_merge( $data, self::fragments( $order ) );
		}

		wp_send_json_error( $data );
	}

	/**
	 * The Items box and the notes list, drawn by WooCommerce's own templates.
	 *
	 * When a template is missing the HTML is left out, and the script asks WooCommerce to reload the box.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private static function fragments( WC_Order $order ) {
		$views     = WC()->plugin_path() . '/includes/admin/meta-boxes/views/';
		$fragments = array();

		if ( is_readable( $views . 'html-order-items.php' ) ) {
			ob_start();
			include $views . 'html-order-items.php';
			$fragments['html'] = ob_get_clean();
		}

		if ( is_readable( $views . 'html-order-notes.php' ) ) {
			$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

			ob_start();
			include $views . 'html-order-notes.php';
			$fragments['notes_html'] = ob_get_clean();
		}

		return $fragments;
	}
}
