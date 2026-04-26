=== Donations for WooCommerce Companion ===
Contributors: davidstells
Tags: woocommerce, donations, recurring, subscriptions, fundraising
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an interval-first donation form (one-time / monthly / annually) on top of the Donation for WooCommerce plugin, without modifying it.

== Description ==

This is a *companion* plugin for [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/). It adds the modern donor-facing form pattern — three side-by-side options for **One-time**, **Monthly**, and **Annually** with separate preset amount tiers per interval — that nonprofits like Charity:Water, NPR, and Wikipedia have made the standard for donation pages.

The companion plugin works by feeding the parent plugin's existing AJAX pipeline with the right parameters, so all downstream wiring (cart, order, subscription, emails, reports, PDF receipts) keeps working untouched. Recurring billing is handled by the parent plugin's existing integration with either WooCommerce Subscriptions (paid) or Subscriptions For WooCommerce by WPS (free) — the companion auto-detects which is active.

= Key features =

* Per-campaign admin meta box: independently configure preset amounts, custom-amount min/max, and CTA text for each interval
* Donor-facing interval-first form via three integration points — shortcode, Gutenberg block, OR Elementor widget — all calling the same renderer for consistent behavior
* Auto-detects WooCommerce Subscriptions vs Subscriptions For WooCommerce; sends both engines' AJAX key sets in one request so it works no matter which is active
* Graceful degradation: when no recurring engine is installed, monthly/annual tabs are visibly disabled, one-time still works
* Optional override of the parent plugin's single-campaign template via opt-in per-campaign meta
* HPOS-compatible and WooCommerce Cart/Checkout Block-compatible
* Self-check probe surfaces an admin notice if a parent plugin update breaks the integration contract
* Production security baseline: nonces on all forms, capability gates on admin, output escaping, server-side amount range enforcement, no direct SQL, no remote runtime fetches

== Installation ==

1. Install and activate the [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/) plugin (required parent).
2. Install and activate either [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) or [Subscriptions For WooCommerce](https://wordpress.org/plugins/subscriptions-for-woocommerce/). Either is fine — the companion auto-detects.
3. Install and activate this companion plugin.
4. Edit a donation campaign and configure the **"Interval-First Donation Form"** meta box: enable the intervals you want, set preset amounts, set min/max for custom amounts.
5. Add the form to a page using one of:
   * Shortcode: `[dfwc_recurring_donation campaign_id="123"]`
   * Gutenberg block: search for **"Recurring Donation"** in the block inserter
   * Elementor widget: search for **"Recurring Donation"** in the Elementor widget panel

== Frequently Asked Questions ==

= Do I need to buy WooCommerce Subscriptions? =

No. The free **Subscriptions For WooCommerce** plugin by WPS works fully. The companion auto-detects which engine you have and feeds the right AJAX parameters in either case.

= Will this plugin break when the parent plugin updates? =

The companion only attaches to documented action and filter hooks the parent plugin already exposes (47+ of them). A built-in self-check runs on every admin page load and shows you a notice if any parent plugin update changes a contract the companion depends on. A CI watcher in the project's GitHub Actions runs weekly to catch parent updates the moment they ship.

= Why does the recurring donation become a one-time charge sometimes? =

The most common cause: the WooCommerce product the parent plugin links to your campaign isn't configured as a subscription product. The meta box shows you a yellow warning when this happens. Fix: edit the linked product via the parent plugin's product editor and mark it as a subscription product.

= Can I use this on a campaign that the parent's "Recurring" setting has set to "Disabled"? =

When you enable Monthly or Annually here, the companion automatically sets the parent plugin's "Recurring" setting on that campaign to **"User chooses"**. This is required for the parent's AJAX handler to actually process recurring submits. The "Companion: Form Mode" side meta box explains this.

= What happens to my data if I deactivate the companion? =

Companion data is stored as post meta (`_dfwc_companion_intervals`, `_dfwc_companion_form_mode`) on your campaign posts. It's left intact if you deactivate the plugin. If you reactivate later, your config returns; if you uninstall, the parent plugin's behavior reverts to its default form.

= Does this work with Stripe? =

Yes, as long as your subscription engine works with Stripe. WooCommerce Subscriptions works with the official WooCommerce Stripe gateway out of the box. Subscriptions For WooCommerce works with WPS's bundled Stripe addon. The companion does not configure Stripe directly.

== Compatibility ==

* PHP 7.4+ (8.3 tested)
* WordPress 6.2+ (6.7 tested)
* WooCommerce 5.0+ (10.2 tested)
* Donation for WooCommerce 3.9.8+
* Compatible with HPOS (High-Performance Order Storage)
* Compatible with the WooCommerce Cart/Checkout Block

== Privacy ==

This plugin stores no donor data. Configuration data (interval presets, etc.) is saved as post meta on `wc-donation` campaign posts. Donation orders, payment records, and donor information are handled by the parent plugin and your subscription engine.

== Known limitations ==

* Companion does NOT support the parent's "fixed admin recurring" mode. Interval-first UX is donor-choice by design. Admins who want fixed recurring should keep using the parent plugin's own form on those campaigns.
* Per-currency preset amounts not supported in v1; presets render in the store base currency.
* The optional template-replacement mode uses an output-buffering pattern around the parent's documented action hooks (because the parent doesn't expose a "skip default form" filter). The CI watcher monitors this for breakage.

== Changelog ==

= 0.1.1 =
* Fix: critical "Call to undefined function wp_doing_autosave()" fatal on campaign update — a hallucinated helper that doesn't exist in WordPress. Replaced with the canonical `defined('DOING_AUTOSAVE') && DOING_AUTOSAVE` check.
* Fix: replaced `wp_doing_ajax()` calls with the `DOING_AJAX` constant for consistency.
* Fix: replaced `wp_unique_id()` (WP 6.4+) with a self-contained UID generator so the plugin works on the declared minimum WP 6.2.

= 0.1.0 =
* Initial release.
* Per-campaign admin meta box for interval-first config.
* Donor-facing form via shortcode, Gutenberg block, and Elementor widget.
* Server-side amount-range guard.
* Self-check health probe + parent-contract CI watcher.
* HPOS + Cart Block compatibility declared.

== Upgrade Notice ==

= 0.1.1 =
Critical fatal-error fix. Upgrade required if you've installed 0.1.0.

= 0.1.0 =
Initial release.
