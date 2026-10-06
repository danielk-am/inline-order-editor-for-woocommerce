<?php
/**
 * The plugin's settings: the default order terms and where terms are shown.
 * They sit at the end of WooCommerce > Settings > General.
 *
 * @package Inline_Order_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
final class IOEFW_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_general_settings', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * The address of the settings.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=wc-settings&tab=general#ioefw_default_terms' );
	}

	/**
	 * Adds the Order terms section to the General settings.
	 *
	 * @param array $settings WooCommerce's General settings.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		$settings   = (array) $settings;
		$settings[] = array(
			'title' => __( 'Order terms', 'inline-order-editor-for-woocommerce' ),
			'type'  => 'title',
			'desc'  => __( 'Terms you add to an order, for example payment, delivery or return terms. Each order keeps its own copy, which you can change on the order.', 'inline-order-editor-for-woocommerce' ),
			'id'    => 'ioefw_terms_options',
		);
		$settings[] = array(
			'title'    => __( 'Default terms', 'inline-order-editor-for-woocommerce' ),
			'desc'     => __( 'Filled in on each new order you create in the admin. Leave empty to start every order without terms.', 'inline-order-editor-for-woocommerce' ),
			'id'       => 'ioefw_default_terms',
			'type'     => 'textarea',
			'css'      => 'min-width: 50%; height: 100px;',
			'default'  => '',
			'autoload' => false,
		);
		$settings[] = array(
			'title'       => __( 'Heading', 'inline-order-editor-for-woocommerce' ),
			'desc'        => __( 'Shown above the terms.', 'inline-order-editor-for-woocommerce' ),
			'id'          => 'ioefw_terms_heading',
			'type'        => 'text',
			'default'     => '',
			'placeholder' => __( 'Terms', 'inline-order-editor-for-woocommerce' ),
			'desc_tip'    => true,
			'autoload'    => false,
		);
		$settings[] = array(
			'title'         => __( 'Show terms', 'inline-order-editor-for-woocommerce' ),
			'desc'          => __( 'In order emails', 'inline-order-editor-for-woocommerce' ),
			'id'            => 'ioefw_terms_in_emails',
			'type'          => 'checkbox',
			'default'       => 'yes',
			'checkboxgroup' => 'start',
			'autoload'      => false,
		);
		$settings[] = array(
			'desc'          => __( "On the customer's order page", 'inline-order-editor-for-woocommerce' ),
			'id'            => 'ioefw_terms_on_order_page',
			'type'          => 'checkbox',
			'default'       => 'yes',
			'checkboxgroup' => '',
			'autoload'      => false,
		);
		$settings[] = array(
			'desc'          => __( 'On invoices made by PDF Invoices & Packing Slips for WooCommerce', 'inline-order-editor-for-woocommerce' ),
			'id'            => 'ioefw_terms_on_pdf',
			'type'          => 'checkbox',
			'default'       => 'yes',
			'checkboxgroup' => 'end',
			'autoload'      => false,
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'ioefw_terms_options',
		);

		return $settings;
	}
}
