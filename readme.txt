=== Donations for WooCommerce Companion ===
Contributors: TBD
Tags: woocommerce, donations, recurring, subscriptions, fundraising
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an interval-first donation form (one-time / monthly / annually) on top of the "Donation for WooCommerce" plugin.

== Description ==

This is a *companion* plugin for [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/). It adds a modern donor-facing form pattern — three side-by-side options for One-time, Monthly, and Annually with separate preset amount tiers per interval — without modifying the parent plugin.

It works by feeding the parent plugin's existing AJAX pipeline with the right parameters, so all downstream wiring (cart, order, subscription, emails, reports, PDF receipts) keeps working untouched. Recurring billing is handled by either WooCommerce Subscriptions (paid) or Subscriptions For WooCommerce by WPS (free) — auto-detected.

= Features =

* Per-campaign admin UI to configure preset amounts independently per interval
* Donor-facing interval-first form (shortcode + Gutenberg block)
* Auto-detects WooCommerce Subscriptions vs WPS Free Subscriptions
* Graceful degradation when no recurring engine is installed (one-time still works)
* Optional override of the parent plugin's single-campaign template
* HPOS and WooCommerce Cart Block compatible

== Installation ==

1. Install and activate the [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/) plugin (required parent).
2. Install and activate either [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) or [Subscriptions For WooCommerce](https://wordpress.org/plugins/subscriptions-for-woocommerce/) (optional but required for recurring intervals).
3. Install and activate this companion plugin.
4. Edit a donation campaign and configure the "Interval-First Donation Form" meta box.
5. Add the shortcode `[dfwc_recurring_donation campaign_id="123"]` to any page, or use the Gutenberg block.

== Frequently Asked Questions ==

= Do I need WooCommerce Subscriptions? =

No — the free "Subscriptions For WooCommerce" by WPS works too. The companion auto-detects which one you have.

= Will this break when the parent plugin updates? =

The companion plugin only attaches to documented action/filter hooks. A built-in self-check warns you in the admin if a parent plugin update changes something the companion depends on.

== Changelog ==

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
