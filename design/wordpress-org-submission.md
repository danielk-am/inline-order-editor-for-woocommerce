# WordPress.org submission notes

Working notes for the upload at https://wordpress.org/plugins/developers/add/. Not shipped in the ZIP. The upload form, its tick boxes and the ZIP upload are the account owner's to complete.

Rules read on 6 October 2026: the upload form (from its source in WordPress/wordpress.org, `shortcodes/class-upload.php`), the Detailed Plugin Guidelines, the plugin assets page, and "How your readme.txt works".

## Status on 6 October 2026

Not submitted yet. The upload page offers no new submission form while an earlier plugin from the same account is in review. Once that review is finished, the form comes back and this plugin can go in with the ZIP below.

## What to upload

Build it with `bin/build.sh --zip`. The file is `build/inline-order-editor-for-woocommerce.zip`. The same file is attached to the v1.0.0 release on GitHub, with its checksum (sha256 `b7d74083b6273fb2ef4ef118aa3261872ea9c3d1d91be3e6dc158a3e19f54061`) and the Plugin Check result for that exact file.

The repository has been public since 6 October 2026, so the links in the plugin's header and readme resolve.

WordPress.org takes one new plugin at a time from an account. While an earlier submission is waiting for its first review, or is in review, the upload page shows a notice in place of the form. If it does, this plugin waits until that one is approved.

After approval, the files in `.wordpress-org/` go to the `assets` folder of the plugin's SVN repository: `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1.png` to `screenshot-5.png`.

## The form's confirmations, and what supports each

| Confirmation on the form | Evidence |
| --- | --- |
| "I have read the Frequently Asked Questions." | Yours to read and tick. |
| "I have read and make sure that this plugin complies with all of the Plugins Directory Guidelines." | GPL-2.0-or-later code and assets. No external requests, no tracking, no remote code, no updater, no locked features, no admin notices or upsells. Yours to confirm. |
| "I confirm that the plugin has been tested with the Plugin Check plugin, and all indicated issues resolved." | Plugin Check 2.1.0, all five categories, on the shipped fileset: no errors, no warnings. 29 checks ran. Five runtime checks did not run in WordPress Playground (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`). What they test was checked directly: the plugin's script (42 KB) and stylesheet (8 KB) load only on the screen where one order is edited, the script in the footer. |
| "I have chosen a plugin name that is not confusingly similar to existing plugins, projects, organizations, or trademarks. I searched on the internet for similar names and found nothing similar." | See "Name" below. This one needs your decision. |
| "I have permission to upload this plugin ... using a WordPress.org account that accurately represents the plugin owner." | Yours to confirm. The readme lists the contributor `danielkam1`. |
| "I confirm that my plugin code does not include artificial limitations to the included functionality." | True of the code: every feature in the plugin works without a licence, a limit or a paid add-on. |
| The two acknowledgements in step 3. | Yours to tick. |

## Name

"Inline Order Editor for WooCommerce". The slug `inline-order-editor-for-woocommerce` is free in the directory and on GitHub, and no project with the same name was found on the web (checked 6 October 2026).

The form warns that generic, descriptive names "are unlikely to be accepted, even if the plugin slug is available", and suggests a unique identifier. This name is descriptive, so a reviewer may ask for a more distinctive one. The nearest existing plugins, and how this one differs:

- **Phone Orders for WooCommerce** (AlgolPlus): its own order-taking screen under a separate menu. This plugin changes nothing but WooCommerce's own order screen.
- **Custom Product in Woo Order** (wizbee IT): adds an "Add Custom Item(s)" row. No in-place editing, no terms, and it does not declare HPOS support.
- **Admin Order Modifier** and **Quick Edit and View Orders** (WooCommerce.com): price fields in the pencil's edit mode, and a popup from the orders list.

A fallback that passed the same checks is "Order Jotter for WooCommerce" (slug `order-jotter-for-woocommerce`). A rename changes the plugin name, main file, folder, text domain, readme, README, banner and repository. The `ioefw` prefix in code can stay.

## Common rejection reasons, mapped to the code

| Reason on the form | In this plugin |
| --- | --- |
| Output that is not escaped | PHP output goes through `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` or `wp_kses_post`. The order's state is printed as one `esc_attr( wp_json_encode() )` attribute. The script builds its own elements with `createElement` and `textContent`. The only HTML it inserts is the Items box and notes list drawn by WooCommerce's own templates, as WooCommerce's script does. |
| Data that is not sanitised | The four request handlers read named fields only, each unslashed and passed through `sanitize_text_field`, `absint` or `sanitize_key`. Amounts go through `wc_format_decimal`, names through `sanitize_text_field`, terms through `sanitize_textarea_field`. |
| Form data processed without a nonce | Every request handler starts with `check_ajax_referer` and `current_user_can( 'edit_shop_orders' )`. The Order terms box has its own nonce, checked before saving. |
| Arbitrary code, or code downloaded from elsewhere | None. No `eval`, no file writes, no HTTP requests. |
| Trialware | None. There is no licence check and no feature that is switched off. |
| Calling files or the database directly | No SQL. Orders are read and written with `WC_Order` methods, so HPOS and post storage both work. |

## Additional Information (draft for the form's note field)

Inline Order Editor adds three things to WooCommerce's own order edit screen, for shops that type in phone orders: an empty row that adds a catalogue product, a typed item with no product, or a delivery charge; double-click editing of a line's price, quantity and total in place; and an Order terms box whose text is shown in order emails and on the customer's order page. Changes are saved by AJAX through WooCommerce's own `wc_save_order_items()`, and taxes and totals are then recalculated the way the Recalculate button does it.

Similar names: Phone Orders for WooCommerce uses its own screen, and Custom Product in Woo Order adds a custom item row only. This plugin works inside the standard Items box, adds in-place editing and order terms, and declares HPOS compatibility.

For the reviewer: no external requests, no tracking, no build step, no bundled libraries. It stores five options (`ioefw_default_terms`, `ioefw_terms_heading`, `ioefw_terms_in_emails`, `ioefw_terms_on_order_page`, `ioefw_terms_on_pdf`), removed on uninstall, one order meta key (`_ioefw_order_terms`) and one order item meta key (`_ioefw_custom`). It registers four `wp_ajax_` handlers for logged-in users, each checking a nonce and the `edit_shop_orders` capability.

Source and tests: https://github.com/danielk-am/inline-order-editor-for-woocommerce. To test: activate with WooCommerce, open WooCommerce > Orders > Add new order, type a name in the empty row at the bottom of the items, then double-click a quantity.

## After the upload

Record the status the page shows, the slug it assigned, the date, and the address the review email goes to. If the plugin changes while it waits, rebuild the ZIP, run Plugin Check on it again, and upload the new file from the same page.
