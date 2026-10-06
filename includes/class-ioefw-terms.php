<?php
/**
 * Order terms: a text kept on the order, shown to the customer in order emails and on their order page.
 *
 * The text is copied onto each order when the order is saved, so changing the default later never
 * rewrites what an earlier customer was told.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Order terms box and everywhere the terms are shown.
 */
final class IOEFW_Terms {

	/**
	 * Order meta key that holds the terms. PDF and export templates can read it.
	 */
	const META_KEY = '_ioefw_order_terms';

	/**
	 * Hooks.
	 */
	public static function init() {
		// Before WooCommerce saves the order data at priority 40, so an email sent by that save has the terms.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 5, 2 );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_page' ), 20 );
		add_action( 'wpo_wcpdf_after_order_details', array( __CLASS__, 'pdf_invoice' ), 10, 2 );
	}

	/**
	 * Adds the Order terms box to the order screen.
	 *
	 * @param string $screen_id The order screen.
	 */
	public static function add_meta_box( $screen_id ) {
		add_meta_box( 'ioefw-order-terms', __( 'Order terms', 'inline-order-editor-for-woocommerce' ), array( __CLASS__, 'render_meta_box' ), $screen_id, 'normal', 'default' );
	}

	/**
	 * The box: one text area, filled with the default terms on a new order.
	 *
	 * @param WP_Post|WC_Order $post_or_order The order being edited.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$default = self::default_terms();
		$terms   = (string) $order->get_meta( self::META_KEY );

		if ( '' === $terms && 'auto-draft' === $order->get_status() ) {
			$terms = $default;
		}

		wp_nonce_field( 'ioefw_save_terms', 'ioefw_terms_nonce' );
		?>
		<label class="screen-reader-text" for="ioefw-order-terms-text"><?php esc_html_e( 'Order terms', 'inline-order-editor-for-woocommerce' ); ?></label>
		<textarea id="ioefw-order-terms-text" name="ioefw_order_terms" rows="4" class="widefat"><?php echo esc_textarea( $terms ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'For example payment, delivery or return terms. The customer sees them in order emails and on their order page. Saved when you create or update the order.', 'inline-order-editor-for-woocommerce' ); ?>
		</p>
		<p class="ioefw-terms-actions">
			<?php if ( '' !== $default ) : ?>
				<button type="button" class="button-link ioefw-use-default-terms" data-terms="<?php echo esc_attr( $default ); ?>"><?php esc_html_e( 'Use the default terms', 'inline-order-editor-for-woocommerce' ); ?></button>
				<span aria-hidden="true">|</span>
			<?php endif; ?>
			<a href="<?php echo esc_url( IOEFW_Settings::url() ); ?>"><?php esc_html_e( 'Set the default terms', 'inline-order-editor-for-woocommerce' ); ?></a>
			<?php
			/**
			 * Fires at the end of the links under the Order terms box. The text area is #ioefw-order-terms-text.
			 *
			 * @since 1.0.0
			 * @param WC_Order $order The order being edited.
			 */
			do_action( 'ioefw_terms_box_actions', $order );
			?>
		</p>
		<?php
	}

	/**
	 * Saves the box with the order.
	 *
	 * @param int              $order_id      The order ID.
	 * @param WP_Post|WC_Order $post_or_order The order being saved.
	 */
	public static function save( $order_id, $post_or_order = null ) {
		if ( ! isset( $_POST['ioefw_terms_nonce'], $_POST['ioefw_order_terms'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ioefw_terms_nonce'] ) ), 'ioefw_save_terms' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			self::set( $order, sanitize_textarea_field( wp_unslash( $_POST['ioefw_order_terms'] ) ) );
		}
	}

	/**
	 * Stores terms on an order. An empty text removes them.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $terms The terms, as plain text.
	 */
	public static function set( WC_Order $order, $terms ) {
		$terms = ioefw_trim( sanitize_textarea_field( (string) $terms ) );

		if ( (string) $order->get_meta( self::META_KEY ) === $terms ) {
			return;
		}

		if ( '' === $terms ) {
			$order->delete_meta_data( self::META_KEY );
		} else {
			$order->update_meta_data( self::META_KEY, $terms );
		}

		$order->save();

		/**
		 * Fires after an order's terms have been saved.
		 *
		 * @since 1.0.0
		 * @param WC_Order $order The order.
		 * @param string   $terms The terms. Empty when they were removed.
		 */
		do_action( 'ioefw_terms_saved', $order, $terms );
	}

	/**
	 * An order's terms, as plain text.
	 *
	 * @param WC_Order|int $order   The order or its ID.
	 * @param string       $context Where the terms are about to be shown: email, order_page, pdf or view.
	 * @return string
	 */
	public static function get( $order, $context = 'view' ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		/**
		 * Filters an order's terms before they are shown.
		 *
		 * @since 1.0.0
		 * @param string   $terms   The terms, as plain text.
		 * @param WC_Order $order   The order.
		 * @param string   $context email, order_page, pdf or view.
		 */
		return ioefw_trim( apply_filters( 'ioefw_order_terms', (string) $order->get_meta( self::META_KEY ), $order, $context ) );
	}

	/**
	 * The store's default terms, as plain text.
	 *
	 * @return string
	 */
	public static function default_terms() {
		return ioefw_trim( sanitize_textarea_field( (string) get_option( 'ioefw_default_terms', '' ) ) );
	}

	/**
	 * The heading shown above the terms.
	 *
	 * @return string
	 */
	public static function heading() {
		$heading = ioefw_trim( get_option( 'ioefw_terms_heading', '' ) );

		return '' === $heading ? __( 'Terms', 'inline-order-editor-for-woocommerce' ) : $heading;
	}

	/**
	 * Shows the terms in an order email, below the order details.
	 *
	 * @param mixed $order         The order.
	 * @param bool  $sent_to_admin Whether the email goes to the store.
	 * @param bool  $plain_text    Whether the email is plain text.
	 * @param mixed $email         The email being sent.
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order || 'yes' !== get_option( 'ioefw_terms_in_emails', 'yes' ) ) {
			return;
		}

		$terms = self::get( $order, 'email' );

		/**
		 * Filters whether an order email shows the order's terms.
		 *
		 * @since 1.0.0
		 * @param bool     $show          Whether to show the terms.
		 * @param WC_Order $order         The order.
		 * @param mixed    $email         The email being sent.
		 * @param bool     $sent_to_admin Whether the email goes to the store.
		 */
		if ( '' === $terms || ! apply_filters( 'ioefw_show_terms_in_email', true, $order, $email, $sent_to_admin ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( wptexturize( wc_strtoupper( self::heading() ) ) ) ) . "\n\n" . esc_html( wp_strip_all_tags( wptexturize( $terms ) ) ) . "\n\n";
			return;
		}

		echo '<div class="ioefw-order-terms" style="margin-bottom: 40px;"><h2>' . esc_html( self::heading() ) . '</h2>' . wp_kses_post( self::html( $terms ) ) . '</div>';
	}

	/**
	 * Shows the terms on the customer's order page and on the order received page.
	 *
	 * @param mixed $order The order.
	 */
	public static function order_page( $order ) {
		if ( ! $order instanceof WC_Order || 'yes' !== get_option( 'ioefw_terms_on_order_page', 'yes' ) ) {
			return;
		}

		$terms = self::get( $order, 'order_page' );

		if ( '' === $terms ) {
			return;
		}

		echo '<section class="woocommerce-order-terms ioefw-order-terms"><h2 class="woocommerce-column__title">' . esc_html( self::heading() ) . '</h2>' . wp_kses_post( self::html( $terms ) ) . '</section>';
	}

	/**
	 * Shows the terms on invoices made by PDF Invoices & Packing Slips for WooCommerce.
	 *
	 * @param string $document_type The document being made, such as invoice.
	 * @param mixed  $order         The order.
	 */
	public static function pdf_invoice( $document_type = '', $order = null ) {
		if ( ! $order instanceof WC_Order || 'yes' !== get_option( 'ioefw_terms_on_pdf', 'yes' ) ) {
			return;
		}

		/**
		 * Filters which PDF documents show the order's terms.
		 *
		 * @since 1.0.0
		 * @param string[] $types Document types. Invoices by default.
		 * @param WC_Order $order The order.
		 */
		$types = (array) apply_filters( 'ioefw_pdf_document_types', array( 'invoice' ), $order );
		$terms = self::get( $order, 'pdf' );

		if ( '' === $terms || ! in_array( $document_type, $types, true ) ) {
			return;
		}

		echo '<div class="ioefw-order-terms"><h3>' . esc_html( self::heading() ) . '</h3>' . wp_kses_post( self::html( $terms ) ) . '</div>';
	}

	/**
	 * Plain-text terms as paragraphs, with web addresses made into links.
	 *
	 * @param string $terms The terms, as plain text.
	 * @return string
	 */
	private static function html( $terms ) {
		return wpautop( make_clickable( esc_html( $terms ) ) );
	}
}
