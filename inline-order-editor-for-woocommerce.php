<?php
/**
 * Plugin Name: Inline Order Editor for WooCommerce
 * Plugin URI: https://github.com/danielk-am/inline-order-editor-for-woocommerce
 * Description: Take phone orders on the WooCommerce order screen. Type a name in the empty row to add any item. Double-click a price, quantity or total to change it: Enter saves, Esc cancels. Add terms that reach the customer's email.
 * Version: 1.0.1
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Daniel Kam
 * Author URI: https://danielk.am
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: inline-order-editor-for-woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 11.1
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

define( 'IOEFW_VERSION', '1.0.1' );
define( 'IOEFW_PLUGIN_FILE', __FILE__ );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once __DIR__ . '/includes/class-ioefw-orders.php';
require_once __DIR__ . '/includes/class-ioefw-ajax.php';
require_once __DIR__ . '/includes/class-ioefw-terms.php';
require_once __DIR__ . '/includes/class-ioefw-settings.php';
require_once __DIR__ . '/includes/class-ioefw-plugin.php';
require_once __DIR__ . '/includes/ioefw-functions.php';

add_action( 'plugins_loaded', array( 'IOEFW_Plugin', 'init' ) );
