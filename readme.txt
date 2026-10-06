=== Inline Order Editor for WooCommerce ===
Contributors: danielkam1
Donate link: https://danielk.am/tip/
Tags: woocommerce, phone orders, manual orders, edit order, order items
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take phone orders on the WooCommerce order screen: add any item as a new row, double-click to change a line, and add terms to order emails.

== Description ==

Inline Order Editor is for shops that take orders by phone, over the counter or by message, and type them into WooCommerce. It makes the order screen work like a row on an order pad: type the item, the price and the quantity, press Enter, and the total is already right.

It works on the order screen you already use, under WooCommerce > Orders. There is no new screen to learn.

= Add an item as a new row =

An empty row waits at the bottom of the order's items. Type a name and one of three things happens:

* **A product from your catalogue.** Matching products are suggested as you type, with their price and stock. Pick one and it is added the way WooCommerce adds it.
* **Something that is not in your catalogue.** Keep typing, give it a price and a quantity, and press Enter. It becomes a normal line on the order, with an optional note such as a colour or a size. You do not need to create a product first.
* **A delivery charge.** Choose "Add as a delivery charge" and type the amount. It is added as the order's shipping.

The row shows the line total as you type, and a fresh row is ready as soon as the line is added.

= Double-click to change a line =

Double-click a price, a quantity or a total and type the new value. Enter saves it, Esc cancels, and Tab saves and moves to the next value. Nothing on the screen jumps: the value is edited where it stands.

You can change the price each, which the standard order screen does not offer. You can also rename an item you typed in, and change the name or amount of a fee or a delivery charge.

On a keyboard, move to a value with Tab and press Enter. On a touch screen, tap it.

= Totals that are already right =

After every change the order's taxes and totals are calculated again, the way the Recalculate button does it, and a short message at the bottom of the screen tells you what happened: "Saved. The order total is now $65.00. It was $50.00."

If your store's prices are entered with tax, the amounts you type are read with tax too. Type 10 and the customer pays 10.

Each change also leaves a private order note with the old and the new value, under your name.

= Order terms =

Each order gets an Order terms box for payment, delivery or return terms. Set default terms once and they are filled in on every new order you create in the admin, where you can still change them for that order.

The customer sees the terms in their order emails and on their order page. The terms are saved on the order itself, so changing the default later does not rewrite what an earlier customer was told.

If you use PDF Invoices & Packing Slips for WooCommerce, the terms are printed on its invoices as well.

= A warning about Draft orders =

Draft is a status WooCommerce uses for unfinished checkouts, and it deletes Draft orders by itself about a day after their last change. If you pick Draft for an order you want to finish later, the plugin tells you and suggests Pending payment, which keeps the order.

= Limits worth knowing =

* Lines can be changed on orders WooCommerce treats as editable: Pending payment, On hold, and new orders. To change a paid order, set its status back to Pending payment first.
* An order that has a refund is left alone.
* Percentage and fixed product coupons apply to catalogue products only, so an item you typed in keeps its price. A fixed cart coupon is shared across every line. This is how WooCommerce applies coupons.
* On an order with a coupon, change a line's price or quantity and its total follows the coupon. Each change applies the order's coupons again, which replaces a discount typed by hand on any line.
* An item you typed in has no stock, SKU or weight. Analytics > Products has no product to list it under, so typed items appear there together in one row, marked "(Deleted)".
* Changing a line recalculates the order's taxes. A tax amount you typed by hand with the pencil is replaced by the calculated one.
* Tested on WordPress 7.1.2 with WooCommerce 11.1.2 and PHP 8.5, and on WordPress 6.8.10 with WooCommerce 10.0.6 and PHP 7.4, with High-Performance Order Storage. Not tested on a real phone or tablet, or with subscriptions, bookings, product bundles or tax services such as WooCommerce Tax and Avalara.

= For developers =

* The terms are stored in the order meta key `_ioefw_order_terms`. `ioefw_get_order_terms( $order )` returns them as plain text, ready for a PDF or email template.
* A line that was typed in is a normal product line item with no product ID, marked with the hidden item meta `_ioefw_custom`.
* Existing lines are saved through WooCommerce's own `wc_save_order_items()`, so the hooks that fire for the order screen's Save button fire here too.
* Filters: `ioefw_can_edit_order`, `ioefw_editable_fields`, `ioefw_custom_item_args`, `ioefw_amounts_include_tax`, `ioefw_order_terms`, `ioefw_show_terms_in_email`, `ioefw_pdf_document_types`, `ioefw_product_search_limit`.
* Actions: `ioefw_loaded`, `ioefw_custom_item_added`, `ioefw_delivery_added`, `ioefw_item_updated`, `ioefw_after_recalculate`, `ioefw_terms_saved`, `ioefw_terms_box_actions`.
* The plugin makes no requests to other sites and stores no personal data of its own.

The source and tests are on [GitHub](https://github.com/danielk-am/inline-order-editor-for-woocommerce).

== Installation ==

1. In your WordPress admin, go to Plugins > Add Plugin, search for "Inline Order Editor for WooCommerce" and install it. Or upload the ZIP under Plugins > Add Plugin > Upload Plugin.
2. Activate the plugin. WooCommerce needs to be active.
3. Open any order that is Pending payment or On hold, or go to WooCommerce > Orders > Add new order. The empty row is at the bottom of the items.
4. To set default terms, go to WooCommerce > Settings > General and scroll to Order terms.

== Frequently Asked Questions ==

= Do I have to create a product for a one-off item? =

No. Type its name, price and quantity in the empty row and press Enter. It is saved on the order as a normal line, and it shows on emails and invoices like any other line. Nothing is added to your catalogue.

= Can I change the price of one item on one order? =

Yes. Double-click the price and type the new one. The standard order screen only lets you edit a line's total, so this plugin works the total out for you.

= Why can I not change a paid order? =

WooCommerce only allows changes to orders that are Pending payment or On hold. This plugin follows that rule, because a paid order's amount has usually been charged already. If you need to change a paid order, set its status back to Pending payment, make the change, and settle any difference with the customer yourself.

= I saved an order as Draft and it disappeared. Why? =

Draft is a status WooCommerce uses for checkouts that were started and not finished, and it clears those away about a day after their last change. An order you parked as Draft is cleared with them. Use Pending payment for an order you want to come back to. The plugin warns you when you pick Draft.

= Do my prices include tax when I type them? =

They follow your store. If WooCommerce > Settings > Tax says prices are entered inclusive of tax, the amounts you type on an order include tax, and the price field says so when you point at it. If it says exclusive, tax is added on top.

= Does a coupon apply to an item I typed in? =

A fixed cart coupon does: WooCommerce shares it across every line. Percentage and fixed product coupons do not, because WooCommerce applies those to catalogue products only.

= Where do the order terms show? =

In the order emails WooCommerce sends, below the order details, and on the customer's order page and order received page. You can switch each place off under WooCommerce > Settings > General > Order terms. With PDF Invoices & Packing Slips for WooCommerce, they are printed on invoices too.

= Can I put the terms on my own PDF or email template? =

Yes. They are stored on the order under the meta key `_ioefw_order_terms`, and `ioefw_get_order_terms( $order )` returns them as plain text.

= Does it work with High-Performance Order Storage? =

Yes. It was built and tested with HPOS switched on. On WordPress 7.1.2 with WooCommerce 11.1.2, the same checks also pass with the older post-based order storage.

= Does it work on a phone or tablet? =

The order screen is WooCommerce's own, so it has the same layout it has today. On a touch screen you tap a value to change it instead of double-clicking.

= What happens if I deactivate the plugin? =

The order screen goes back to how WooCommerce ships it. Lines you added stay on their orders, and saved terms stay in the order's data, but they are no longer shown in emails or on the order page.

== Screenshots ==

1. Type a name in the empty row. Products from your catalogue are suggested, and anything else can be added as a new item or a delivery charge.
2. Double-click a quantity, a price or a total to change it where it stands.
3. An order with a catalogue product, a typed item and a delivery charge. The message at the bottom left says what the last change did to the total.
4. The Order terms box on the order, filled in from your default terms.
5. The terms in the customer's order email.

== Changelog ==

= 1.0.1 =
* Saved messages and errors now show as toast notifications at the bottom of the screen.
* The tip line under the order's items is gone. How to change a line is in the plugin's description.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
Saved messages and errors now show as toast notifications.

= 1.0.0 =
First release.
