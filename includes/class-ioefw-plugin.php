<?php
/**
 * Starts the plugin and sets up the order screen.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class IOEFW_Plugin {

	/**
	 * Hooks. Runs on plugins_loaded, once WooCommerce is there.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		IOEFW_Orders::init();
		IOEFW_Ajax::init();
		IOEFW_Terms::init();
		IOEFW_Settings::init();

		add_action( 'add_meta_boxes', array( __CLASS__, 'setup_order_screen' ), 20, 2 );
		add_action( 'woocommerce_order_item_add_action_buttons', array( __CLASS__, 'render_state' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IOEFW_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );

		/**
		 * Fires when Inline Order Editor has loaded. Add-ons start here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'ioefw_loaded' );
	}

	/**
	 * Adds the Order terms box and the script to the screen where one order is edited.
	 *
	 * WordPress passes a post type here, and WooCommerce's own orders screen passes its screen ID.
	 *
	 * @param string $screen_or_post_type The screen or post type the boxes are for.
	 * @param mixed  $post_or_order       The order being edited.
	 */
	public static function setup_order_screen( $screen_or_post_type, $post_or_order = null ) {
		$order_screens = array( 'shop_order', function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order' );

		if ( ! in_array( $screen_or_post_type, $order_screens, true ) ) {
			return;
		}

		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : false );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		IOEFW_Terms::add_meta_box( $screen_or_post_type );
		self::enqueue( $order );
	}

	/**
	 * Draws the order's state inside the Items box, so it is there again each time the box is redrawn.
	 *
	 * @param mixed $order The order.
	 */
	public static function render_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		printf( '<span class="ioefw-state" hidden data-state="%s"></span>', esc_attr( (string) wp_json_encode( IOEFW_Orders::get_state( $order ) ) ) );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links The plugin's links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( IOEFW_Settings::url() ) . '">' . esc_html__( 'Settings', 'inline-order-editor-for-woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * Loads the order screen's script and styles.
	 *
	 * @param WC_Order $order The order being edited.
	 */
	private static function enqueue( WC_Order $order ) {
		$tax_classes = array();

		if ( wc_tax_enabled() ) {
			foreach ( wc_get_product_tax_class_options() as $slug => $label ) {
				$tax_classes[] = array(
					'slug'  => (string) $slug,
					'label' => (string) $label,
				);
			}
		}

		$settings = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( IOEFW_Ajax::NONCE ),
			'orderId'      => $order->get_id(),
			'decimals'     => wc_get_price_decimals(),
			'decimalPoint' => wc_get_price_decimal_separator(),
			'taxClasses'   => count( $tax_classes ) > 1 ? $tax_classes : array(),
		);

		wp_enqueue_style( 'ioefw-order-editor', plugins_url( 'assets/css/order-editor.css', IOEFW_PLUGIN_FILE ), array(), IOEFW_VERSION );
		wp_enqueue_script( 'ioefw-order-editor', plugins_url( 'assets/js/order-editor.js', IOEFW_PLUGIN_FILE ), array( 'jquery', 'wp-i18n', 'wp-a11y' ), IOEFW_VERSION, true );
		wp_set_script_translations( 'ioefw-order-editor', 'inline-order-editor-for-woocommerce' );
		wp_add_inline_script( 'ioefw-order-editor', 'window.ioefwSettings = ' . wp_json_encode( $settings ) . ';', 'before' );
	}
}
