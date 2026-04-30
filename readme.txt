=== Donations for WooCommerce Companion ===
Contributors: davidstells
Tags: woocommerce, donations, recurring, subscriptions, fundraising
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an interval-first donation form (one-time / monthly / annually) on top of the Donation for WooCommerce plugin, without modifying it.

== Description ==

This is a *companion* plugin for [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/). It adds the modern donor-facing form pattern — three side-by-side options for **One-time**, **Monthly**, and **Annually** with separate preset amount tiers per interval — that nonprofits like Charity:Water, NPR, and Wikipedia have made the standard for donation pages.

The companion plugin works by feeding the parent plugin's existing AJAX pipeline with the right parameters, so all downstream wiring (cart, order, subscription, emails, reports, PDF receipts) keeps working untouched. Recurring billing is handled by the parent plugin's existing integration with either WooCommerce Subscriptions (paid) or Subscriptions For WooCommerce by WPS (free) — the companion auto-detects which is active.

= Key features =

* **Per-campaign admin meta box:** independently configure preset amounts, custom-amount min/max, and CTA text for each interval
* **Donor-facing interval-first form** via four integration points — shortcode, Gutenberg block, Elementor widget, or auto-augmentation of the parent plugin's own form — all calling the same renderer for consistent behavior
* **Layered config model:** Defaults → Global → Named Template → Campaign Overrides. Save once, apply to many.
* **Per-currency presets** (v1.0.0): admins define psychologically rounded amounts per currency ("$25 / £20 / €22") that resolve at render time based on the donor's active WCML currency. Filter-based extension for WCPay Multi-Currency, Aelia Currency Switcher.
* **Advanced cadences** (v1.0.0, opt-in): weekly, quarterly, semi-annually, and admin-defined custom cadence ("every 6 weeks") in addition to the standard one-time / monthly / annually.
* **Donor impact messaging:** per-preset impact labels with four display modes (inline, below preset, tooltip, card), featured-preset badges, per-interval subtitles, and live annual-equivalency text with token substitution.
* **Live admin preview pane** on the campaign edit screen, Templates page, and Settings page — debounced 350ms updates render the donor form into an iframe with viewport / engine / language / currency simulation. Defense-in-depth prevents preview HTML from ever submitting a real donation.
* **Donor-facing campaign directory** with six taxonomies (cause / region / country / program / sponsorship type / urgency), filterable grid via shortcode + block + Elementor widget, featured campaigns, REST-driven live search.
* **WPML + WCML support** end-to-end: all admin-defined strings registered with WPML String Translation; taxonomies translate per-term; per-currency preset amounts resolve via WCML.
* **Auto-detects** WooCommerce Subscriptions vs Subscriptions For WooCommerce; sends both engines' AJAX key sets in one request so it works no matter which is active.
* **Graceful degradation:** when no recurring engine is installed, recurring tabs are visibly disabled and one-time still works. Diagnostics page surfaces 13 contract checks with actionable remediations.
* **HPOS** + **WooCommerce Cart/Checkout Block** + **WP-CLI** (`wp dfwc-companion health`) compatible.
* **Production security baseline:** nonces on all forms, capability gates on admin, output escaping, server-side amount range enforcement, server-side interval allow-listing, no direct SQL outside `$wpdb->prepare`, no remote runtime fetches.

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

* Companion does NOT support the parent plugin's "fixed admin recurring" mode. Interval-first UX is donor-choice by design. Admins who want fixed recurring should keep using the parent plugin's own form on those campaigns.
* The auto-augmentation pattern uses CSS / JS to replace parent's amount block in place. If the parent plugin restructures the relevant DOM regions in a future major release, the companion's CI watcher (`tests/parent-contract.test.php`) will catch it; the donor falls back to parent's vanilla form rather than producing a half-broken UI.
* Advanced intervals (weekly / quarterly / semi-annually / custom cadence) are off by default — admins must opt in via *WooCommerce → Donations Companion → Settings → Advanced giving intervals*.
* Per-currency preset amounts require WCML (WPML WooCommerce Multilingual) for the active-currency lookup to resolve automatically. Without WCML, the companion falls back to the WC base currency. WCPay Multi-Currency and Aelia Currency Switcher are supported via the `dfwc_companion_active_currency` filter (5-line snippet documented in `docs/multi-currency.md`).

== Changelog ==

= 2.1.0 =
**Architectural cleanup.** QuickBooks Online sync extracted to a sibling plugin: [Donations for WooCommerce QuickBooks Sync](https://github.com/f8l124/donations-for-woocommerce-qbo-sync). The companion's job is donor-form UX + emitting Phase 9 event hooks; anything that consumes those hooks (this, future FluentCRM/GA4/Xero connectors) belongs separately. Migration is automatic: existing tokens, account mappings, sync log, and pending Action Scheduler jobs all carry over because option keys + the action slug are identical between companion v2.0.x and qbo-sync v1.0.0. Sites that upgrade companion to v2.1.0 without installing the sibling plugin see a one-time admin notice with a download link.

* **Removed:** `includes/QuickBooks/` (7 classes), `includes/Admin/QuickBooks_Page.php`, `includes/REST/QBO_OAuth_Callback_Controller.php`, `templates/quickbooks-page.php`, `docs/quickbooks-sync.md`, the 4 `qbo_*` global settings + their Settings UI section + sanitizer, the `dfwc-companion-quickbooks` admin sub-page, the 2 QBO diagnostic checks (`qbo_connection`, `qbo_sync_health`), the `wp dfwc-companion qbo-sync` CLI subcommand, and the 4 QBO unit-test files. Companion has zero references to `QuickBooks\*`, `qbo_*`, or `quickbooks-*` after this release.
* **Added:** `Admin\QBO_Migration_Notice` — surfaces ONLY when the companion-era `dfwc_qbo_oauth_tokens` option exists AND the sibling plugin isn't installed. Dismissible. Auto-disappears once the sibling is detected.
* **Hook contract unchanged:** `dfwc_companion_donation_submitted`, `dfwc_companion_donation_failed`, the other Phase 9 hooks — all signatures and firing semantics identical. Existing listeners (FluentCRM tagging, GA4, custom CRM webhooks, etc.) continue to work without modification.
* **Diagnostics page extensibility:** the existing `dfwc_companion_contracts` filter is the canonical extension seam for plugins like qbo-sync to inject health checks. The sibling plugin uses it; future connectors can do the same.
* **Internal:** test coverage 272 → 245 cases, 691 → 629 assertions (the 27 QBO-specific tests carried over to the sibling plugin's repo).
* **Backward compat:** existing v2.0.x sites with QBO active should install the sibling plugin BEFORE upgrading to companion v2.1.0 (or as soon as possible after) to keep sync running. Tokens / mappings / log all preserved either way; the gap is just whether sync is paused while the sibling is missing. Sites without QBO are unaffected.
* **Major version rationale:** technically a feature removal from this plugin (per release-process.md, that's a major-bump trigger), but the functionality moves to a sibling rather than going away — so v2.1.0 (minor) reflects that. Sites that don't install the sibling lose QBO; sites that do, lose nothing.

= 2.0.1 =
**Critical fix.** Donor-side AJAX submit was throwing `ArgumentCountError` and stalling on an infinite spinner on every site running v1.1.0 — v2.0.0. Root cause: `Analytics\Submission_Tracker` registered the parent's `wc_donation_alter_donate_response` filter with `accepted_args=2`, but the parent plugin's three apply_filters call sites (`class-wcdonationorder.php:1592, 1634, 1821`) all pass only ONE argument. Any donor reaching the submit step on a site running v1.1.0+ saw their AJAX request 500-out, the loader spin forever, and no donation get processed. Fix: bind the filter with `accepted_args=1` and read `$_POST` inside the callback (we're inside parent's AJAX action at that point, so `$_POST` is the donor's submission). Privacy posture unchanged — every read still flows through Privacy_Guard's allow-list sanitizers. New regression test (`Submission_Tracker_Test`, 4 cases) locks the binding; tests would have caught the original mismatch had this codepath been exercised end-to-end. Upgrade required for any v1.1.0 — v2.0.0 site that ever takes a donation.

= 2.0.0 =
QuickBooks Online sync release. Real-time per-donation Sales Receipt creation in QBO. OAuth2 with admin-supplied app credentials, encrypted token storage, campaign-to-income-account mapping, async retry queue powered by Action Scheduler. Listens on the standard `dfwc_companion_donation_submitted` Phase 9 hook so cash + stock + (eventually) crypto donations all sync uniformly through the same pipeline. Off by default — existing v1.3.x sites see zero behavior change until admin opts in.

* **New: `QuickBooks\Token_Store`.** Encrypted persistence (AES-256-CBC, key derived from `AUTH_KEY . AUTH_SALT`, per-write random IV) for OAuth2 access + refresh tokens. Plaintext never leaves this class except via the explicit getter; `get_option('dfwc_qbo_*')` from elsewhere can never accidentally surface plaintext. Defends against the SQLi / leaked-backup threat model; a filesystem-read attack with `wp-config.php` access remains out of scope (use a real KMS for that threat).
* **New: `QuickBooks\OAuth_Client`.** Hand-rolled Intuit OAuth2 (no SDK; ~250 LOC). Three flows: `authorize_url()` mints + transient-stores a 32-char state nonce; `exchange_code()` swaps the auth code for tokens; `ensure_fresh_token()` runs before every API call and force-refreshes within a 5-minute safety window. State nonces are single-use (consume_state burns the transient). Disconnect calls Intuit's revoke endpoint to invalidate refresh tokens server-side too.
* **New: `REST\QBO_OAuth_Callback_Controller`.** `GET /wp-json/dfwc-companion/v1/qbo-oauth-callback` — the URL admins register at developer.intuit.com. Permission gated on `manage_options` (a non-admin who somehow lands here gets 401). Verifies state, exchanges code, redirects to the QuickBooks Sync admin page with success / error flash.
* **New: `QuickBooks\API_Client`.** Three-endpoint surface: create Sales Receipt, list income accounts (for the mapping UI), fetch company name (for status display). Auto-refresh on 401 with single retry; pinned to API minor version 70 for shape stability.
* **New: `QuickBooks\Account_Mapper`.** Per-campaign + `_default` income-account mapping. Account IDs sanitized via positive-integer regex (rejects "haxx"/etc.). Empty values strip the entry rather than persisting empty.
* **New: `QuickBooks\Sync_Queue` + `Sync_Handler`.** Action Scheduler-backed async queue (AS ships with WC, no install friction). Backoff schedule: 60s → 5m → 15m → giving up. Retryable = 5xx, 408, 429, network-layer; non-retryable = 4xx other than 401 (which API_Client handles). Bounded ring-buffer log (last 50 entries) for the admin Recent Activity panel. Failures surface in the new `qbo_sync_health` diagnostic check.
* **New: `QuickBooks\Donation_Listener`.** Bridges Phase 9 `dfwc_companion_donation_submitted` to the sync queue. Self-gates on `qbo_sync_enabled` + token presence, so toggling sync on doesn't require deactivate/reactivate. Crypto donations (v1.4.0) will sync automatically when shipped — no per-channel wiring needed.
* **New: `Admin\QuickBooks_Page`.** Connect / Disconnect / Account mapping table / Recent activity log. Conditional submenu — only registered when `qbo_sync_enabled` is true. Three admin-post.php actions, all capability + nonce gated.
* **New: 4 admin settings.** *WooCommerce → Donations Companion → Settings → QuickBooks Online sync*: enable toggle, environment dropdown (production/sandbox), client ID, client secret. Client secret is sensitive; redacted in admin UI on save (placeholder shown in the input rather than the real value); preserves existing stored value when admin re-saves without changing the field.
* **New: 2 diagnostic checks (`qbo_connection`, `qbo_sync_health`).** Pass silently when sync is disabled; warn when tokens are missing, refresh-token expiry is within 10 days, or 24h failure count exceeds 5. Surfaces on the Diagnostics page and via `wp dfwc-companion health`.
* **New: `wp dfwc-companion qbo-sync` CLI command.** Re-enqueue historical donations. Supports `--campaign=<id>` filter, `--days=<N>` lookback (default 30), `--dry-run`. Useful for backfill after a connection gap or migration from another QBO sync tool.
* **New: `dfwc_companion_qbo_payload` filter.** Mutate the Sales Receipt payload before submit. Use to add named-customer references, QBO classes/locations, custom memo lines, etc. Ships with empty default — admins who don't filter get an anonymous-donor receipt.
* **New: `docs/quickbooks-sync.md`.** ~400-line reference covering Intuit app registration walk-through, setup, listener path diagram, retry policy, idempotency tradeoffs, threat model (token storage, OAuth callback CSRF, refresh-token exposure), filters, diagnostics, manual re-sync via CLI, troubleshooting.
* **Internal:** test coverage 241 → 268 cases, 621 → 683 assertions. New `QBO_Token_Store_Test` (10 cases — round-trip encrypt/decrypt, ciphertext-vs-plaintext guarantee, IV randomness across writes, corrupt-blob fail-closed), `QBO_Account_Mapper_Test` (8 cases — per-campaign / default fallback / sanitize / empty-strip), `QBO_Sync_Queue_Test` (7 cases — log buffer cap, failures-since window, AS-unavailable graceful path), `QBO_OAuth_State_Test` (5 cases — single-use state nonce, missing-creds path, scope correctness).
* **Backward compat:** Phase 9 hook signature unchanged. The new admin submenu is conditional; sites that don't enable QBO sync see zero UI changes. No new DB tables — Action Scheduler reuses its own tables and we add three options. The existing `Frontend\Submit_Guard`, `Stock_Pledge_Handler`, and Overflow webhook code paths fire the standard donation_submitted hook unchanged; sync wiring sits at the listener boundary.

= 1.3.0 =
Stock-donations release. Two opt-in modes for accepting gifts of appreciated securities, picked per-site by the admin: a built-in pledge form that captures pledge details and emails the donor your DTC transfer instructions, or an integration with [Overflow](https://overflow.co/) that routes donors to your hosted stock-giving page and listens for completion via webhook. Crypto donations ship in v1.4.0.

* **New: built-in stock pledge form (mode 1).** Donors fill an inline form on the campaign page (donor info + broker + ticker + shares + estimated value + optional notes); companion records the pledge as `pledged`, sends donor + admin emails containing the org's DTC transfer instructions, and tracks the pledge through to receipt. Admin reconciles in *Donations Companion → Stock Pledges* by entering the actual transfer value + date — at that moment the standard `dfwc_companion_donation_submitted` hook fires with `$context = 'stock'` so QBO sync (Phase 15+), CRM listeners, GA4 events, etc. all see stock donations as first-class donations alongside cash.
* **New: Overflow integration (mode 2).** Alternative to mode 1 for orgs that prefer a hosted stock-giving flow. Companion shows a single "Donate stock via Overflow" button on the campaign page that routes to the configured Overflow URL with `?utm_source=dfwc-companion&campaign_id=N` appended for attribution. Overflow notifies the companion via webhook on completion; companion verifies HMAC-SHA256 signature with `hash_equals`, deduplicates by Overflow's transaction ID (replay-safe), creates a `received`-status pledge record, and fires the standard donation-submitted hook with `$context = 'stock'`.
* **New: `dfwc_stock_pledge` custom post type.** Admin-only CPT with status workflow tracked in meta (`pledged → in_transit → received` OR `pledged → cancelled`). The post's `post_status` stays `publish` throughout — meta-driven workflow keeps standard WP_Query usable. 13 meta keys for donor info, broker info, stock details, status, admin notes, plus a 14th (`_dfwc_stock_pledge_overflow_id`) for mode-2 idempotency.
* **New: `Stock_Pledge_Handler`.** Centralized create / mark-received / transition-status / read-pledge surface. Donor input is sanitized via allow-list rules: ticker regex `^[A-Z]{1,5}(\.[A-Z]{1,2})?$`, shares ∈ (0, 1,000,000], estimated value ∈ (0, $100,000,000], donor notes capped at 2000 chars, campaign_id must reference a `wc-donation` post. PII never flows raw through Phase 9: `dfwc_companion_stock_pledge_created` receives a sha256 hash of the donor email; `dfwc_companion_donation_submitted` carries only aggregate data (campaign, amount, currency, language).
* **New: `POST /wp-json/dfwc-companion/v1/stock-pledge`.** Public REST endpoint for donor-side submits, rate-limited 5/IP/min, returns DTC instructions in the success response. Returns 404 (not 403) when the feature is disabled so misconfigured donor-side JS doesn't trip security scanners.
* **New: `POST /wp-json/dfwc-companion/v1/overflow-webhook`.** HMAC-SHA256 verified webhook receiver. Idempotent via Overflow's external donation ID. Permissive about Overflow's exact field names (`transaction_id` || `donation_id` || `id`).
* **New: 9 admin settings.** *WooCommerce → Donations Companion → Settings → Stock donations*: enable toggle, mode dropdown, broker name, DTC account number + clearing house number, admin notification email, org tax ID (mode 1 fields), Overflow URL + webhook secret (mode 2 fields). Webhook secret is sensitive and redacted in the admin UI on save.
* **New: 3 wrapper attributes.** `data-stock-pledge-enabled`, `data-stock-mode`, `data-stock-overflow-url` emitted from all three wrapper sites (`Renderer::wrap_with_overlay`, `Context_Augmenter::emit_open`, `Preview_Renderer::render`). Overlay JS reads them and renders the appropriate panel: inline form for mode 1, anchor button for mode 2.
* **New: `docs/stock-donations.md`.** ~280 lines covering both modes, donor flow, setup, reconciliation, tax-receipt guidance, Phase 9 hook integration, privacy posture, storage, edge cases, verification.
* **New: `'stock'` event context.** `Privacy_Guard::contexts()` allow-list extended so `dfwc_companion_donation_submitted` events with `$context = 'stock'` flow through the analytics layer cleanly.
* **Internal:** test coverage 214 → 241 cases, 552 → 621 assertions. New `Stock_Pledge_Handler_Test` (24 cases) covers sanitizer validation paths, Phase 9 hook firing on create + mark-received, hashed-email guarantee, transition-status guards, and the defensive default for missing status meta. `Privacy_Guard_Test` extends with the `stock` context check.
* **Backward compat:** existing v1.2.x campaigns render unchanged. The three new wrapper attributes are additive; sites without stock donations enabled emit `data-stock-pledge-enabled="0"` and the donor-side panel never mounts. No new tables. The new `dfwc_stock_pledge` CPT is admin-only and irrelevant on sites that haven't enabled stock donations.

= 1.2.0 =
Goal-aware giving release. Two opt-in features that read the parent plugin's campaign goal and shape donor behavior: (1) clamp the donor's max custom amount to the campaign's remaining goal so a one-time donor can fully fund the campaign but no more; (2) when a campaign meets its goal, surface a "Goal met! Support our general fund" card so donors can keep giving without overshooting.

* **New: dynamic max from remaining goal (Phase 13).** When the parent plugin's goal type is "Fixed Amount", a donor on the One-time tab cannot exceed (goal − raised). Both donor-side (`Frontend\Renderer`) and server-side (`Frontend\Submit_Guard`) clamp through the shared `Config\Goal_State::clamp_max()` so the two layers cannot drift. Recurring intervals are intentionally NOT clamped — the donor's first charge fits within remaining, but renewals would exceed; silently capping renewals is worse than letting the goal be modestly overshot.
* **New: goal-met affordance.** When `Goal_State::is_fully_funded()` returns true AND the admin has configured a general-fund campaign, the donor sees a green card above the form with copy + a CTA linking to the general fund. Default mode: card surfaces but the form still accepts donations to the funded campaign. Strict mode (admin opts in via second toggle): Submit_Guard rejects donations to the funded campaign with a friendly redirect message.
* **New: `Config\Goal_State`.** Read-only view onto the parent plugin's goal-tracking surface. Reads `wc-donation-goal-display-option`, `wc-donation-goal-display-type`, `wc-donation-goal-fixed-amount-field`, `wc-donation-goal-fixed-initial-amount-field` from the campaign post and `total_donation_amount` from the linked WooCommerce product. Mirrors the parent's own progress formula (initial seed counts toward raised). Per-request cached.
* **New: 3 admin settings.** `WooCommerce → Donations Companion → Settings → Goal-aware giving`: enable goal-based max checkbox, strict-mode checkbox, general-fund campaign dropdown. All default off — existing v1.1.x sites see zero behavior change until admin opts in.
* **New: 2 wrapper attributes.** `data-fully-funded="0|1"` and `data-general-fund-url="..."` emitted from all three wrapper sites (`Renderer::wrap_with_overlay`, `Context_Augmenter::emit_open`, `Preview_Renderer::render`). Overlay JS reads them to render the goal-met card.
* **New: `docs/goal-aware-giving.md`.** ~360 lines covering parent-plugin meta surface, setup, dynamic-max behavior under per-currency presets / recurring intervals, fully-funded redirect modes, edge cases, programmatic control, storage shape.
* **Internal:** test coverage 201 → 214 cases, 518 → 552 assertions. New `Goal_State_Test` (13 cases) covers no-goal / non-amount-goal / fixed-amount-goal / initial-seed / fully-funded thresholds / negative-raised clamp / per-campaign cache / cache reset.
* **Backward compat:** existing v1.1.x campaigns render unchanged. The two new wrapper attributes are additive; sites that haven't opted in see `data-fully-funded="0"` and an empty `data-general-fund-url` and no behavior change. Per-currency presets (Phase 6) interact correctly: the goal is denominated in base currency; the clamp resolves in base currency for donors in any currency via WCML's exchange rate.

= 1.1.0 =
Event hooks release. Surface six donor-flow events as WordPress action hooks so admins can pipe to GA4, Mixpanel, FluentCRM, Zapier, Make, n8n, or any custom destination — with a privacy-by-default posture (no PII; no donor data) and three end-to-end integration recipes shipped in the docs.

* **New: six action hooks (Phase 9).** `dfwc_companion_form_viewed`, `dfwc_companion_interval_selected`, `dfwc_companion_preset_selected`, `dfwc_companion_custom_amount_entered`, `dfwc_companion_donation_submitted`, `dfwc_companion_donation_failed`. The first four ship as donor-side JS events that batch to a `track` REST endpoint; the last two fire directly in PHP at the form-submit boundary via the parent's `wc_donation_alter_donate_response` filter. Hook signatures stable through v1.x.
* **New: `Analytics\Privacy_Guard`.** Allow-list sanitizer for every inbound event field. Rejects unknown event types, unknown intervals, unknown contexts, non-ISO-4217 currency codes, and language codes outside the WPML active set + site locale fallback. Caps amounts at $999,999,999, replaces client-supplied timestamps with server time, caps `$reason` strings at 200 chars. Drops PII fields (donor_email, ip, user_agent, session_id, etc.) silently — even if a malicious client tries to POST them, they never reach `do_action`.
* **New: `REST\Track_REST_Controller`.** `POST /wp-json/dfwc-companion/v1/track` with public `permission_callback`. Rate-limited to 100 events per IP per minute (hashed-IP keys). Batch size capped at 50 events per request. Each batched event flows through `Privacy_Guard::sanitize_event` then fires the matching hook in PHP.
* **New: `Analytics\Submission_Tracker`.** Hooks the parent's `wc_donation_alter_donate_response` filter — fires `dfwc_companion_donation_submitted` on success, `dfwc_companion_donation_failed` on parent rejection. Reads `dfwc_context` / `dfwc_currency` / `dfwc_language` hidden inputs the overlay JS injects so the success / failure events carry render-time correlation data.
* **New: donor-side event buffer.** `dfwc-overlay.js` pushes events into a 1-second-debounced buffer that flushes via `fetch(..., { keepalive: true })`. Survives page navigation; `pagehide` + `beforeunload` listeners flush proactively. Failures are caught silently — analytics never breaks donor flow.
* **New: `data-context` + `data-language` overlay attributes.** `Renderer::wrap_with_overlay` and `Context_Augmenter` both emit them so the overlay JS can attach context to every event without an extra round-trip. Language reads `WPML_Strings::current_language()` when WPML is active, else `get_locale()`.
* **New: `docs/event-hooks.md`.** ~400-line reference covering all six hooks, privacy posture, three end-to-end integration recipes (GA4 Measurement Protocol, FluentCRM contact tagging, generic webhook), performance considerations, troubleshooting, and migration guidance from third-party analytics plugins.
* **Internal:** test coverage 186 → 200+ cases. New `Privacy_Guard_Test` (16 cases) covers the allow-list sanitizers + the PII-strip guarantee. The Track REST endpoint and Submission_Tracker rely on integration testing in CI rather than unit tests (the WP REST infrastructure they touch isn't readily mockable in the bootstrap-stubs test environment).
* **Backward compat:** existing v1.0.x campaigns continue to render unchanged. The new overlay attributes are additive; sites without listeners see zero behavior change. The track REST endpoint is registered but unused unless an admin has wired a listener.

= 1.0.0 =
First stable release. Bundles the v0.9.0 → v1.0.0 changes: per-currency preset amounts (WCML primary, WC base fallback, filter-based WCPay/Aelia extension) and advanced giving intervals (weekly / quarterly / semi-annually / admin-defined custom cadence). Plus a clean uninstall path that respects the admin's data-preservation preference, a `wp dfwc-companion health` CLI command, GitHub repo polish, and the v1.0.0 documentation suite.

* **New: per-currency preset amounts (Phase 6).** Define psychologically rounded amounts per currency ("$25 monthly" in USD, "£20 monthly" in GBP, "€22 monthly" in EUR) — sparse storage; only fields that differ from base are saved; per-currency labels / impact_label inherit from the base preset by design. WCML primary integration: active currency via `wcml_get_user_currency()`, supported set via `wcml_multi_currency()->get_active_currencies()`. Three filter hooks (`dfwc_companion_active_currency`, `dfwc_companion_resolved_currency_block`, `dfwc_companion_supported_currencies`) cover non-WCML stacks (WCPay Multi-Currency, Aelia Currency Switcher) via a 5-line snippet. Donor-side `Submit_Guard` honors per-currency min/max so a donor in GBP isn't rejected against a base-USD threshold. Admin UI adds a per-interval "Per-currency preset amounts" section with sparse preset tables for each non-base supported currency.
* **New: advanced giving intervals (Phase 7).** Off by default. Flip *WooCommerce → Donations Companion → Settings → Advanced giving intervals* to expose four extra cadences in the campaign meta box and Templates page: weekly, quarterly (every 3 months), semi-annually (every 6 months), and a fully admin-defined custom cadence ("every N day/week/month/year"). Each gets the same preset / min-max / CTA / impact / per-currency configuration as the standard three. Custom interval also gets a translatable donor-facing label ("every 6 weeks") and a `{custom_label}` token usable in the CTA template.
* **New: `Engine_Interval_Map`.** Single source of truth for the interval → engine cadence translation. Both supported subscription engines (WCS, WPS SFW) accept the full set; both engine key sets ship in every recurring submit.
* **New: donor-side overflow menu.** When ≥5 intervals are enabled on a campaign, the first 3 stay inline and the rest move into a "More options ▾" dropdown. Click outside / Escape / item-select all close. Narrow-screen fallback stacks the menu inline.
* **New: `wp dfwc-companion health` CLI command.** Runs the parent-contract diagnostic checks and prints the report in JSON / table / markdown formats. Suitable for piping into Slack / monitoring agents.
* **New: `uninstall.php`.** Respects the admin's `preserve_data_on_uninstall` setting (default: on). Opt-in deletion wipes companion options, post meta on `wc-donation` posts, and `dfwc_`-prefixed transients via `$wpdb->prepare`. Never touches campaign posts, orders, or non-companion data.
* **New: documentation suite.** `docs/getting-started.md`, `docs/release-process.md`, `docs/privacy.md`, `docs/multi-currency.md` (Phase 6), `docs/advanced-intervals.md` (Phase 7), `SECURITY.md`, `CODE_OF_CONDUCT.md`, GitHub issue + PR templates. Existing architecture docs at `docs/architecture/` updated for the v1.0.0 surface.
* **New: diagnostic check `advanced_intervals_engine`.** Surfaces a warning on the Diagnostics page when admins enable advanced intervals globally without an active subscription engine. Silent when toggle off or engine present.
* **Internal:** test coverage 145 → 186 cases, 362 → 466 assertions. New `Currency_Preset_Resolver_Test` (17 cases), `Engine_Interval_Map_Test` (23 cases), additional `Defaults_Test` cases for the v1.0.0 schema additions.
* **Backward compat:** existing v0.9.x campaigns continue to render unchanged. Per-interval `currency_overrides` and the four advanced-interval slots default to empty/disabled — no UI changes for upgrading users until they opt in.

= 0.9.0 =
Conversion UX release. Per-preset impact labels turn abstract amounts into concrete outcomes; admins see a faithful donor-form preview as they configure templates and campaigns.

* **New: per-preset impact labels.** Each preset gets a free-form text field ("Provides school supplies for one student") translatable via WPML. Four display modes per interval: inline (inside the preset button), below preset (default), tooltip on hover/focus, or full card layout. Tooltip mode includes an `aria-describedby` for screen readers.
* **New: featured presets.** Mark one preset per interval as featured; donors see a "Most popular" badge and a subtle border accent. Single-featured-per-interval enforced server-side; admins clicking multiple boxes don't break the data.
* **New: per-interval subtitle.** Free-form copy above the preset grid ("Become a monthly sponsor") translatable via WPML.
* **New: annual equivalency.** Token-substituted text below the form (e.g. "$25/month equals $300/year") with `{amount}` and `{annual_amount}` tokens that update live as the donor changes amounts. Most useful on the Monthly tab; multipliers wired for Phase 7's weekly/quarterly/semi-annual cadences too.
* **New: custom-amount impact label.** Free-form text shown alongside the donor's custom-amount input — useful when per-preset impact labels don't apply to free-form amounts ("Every gift makes a difference"). Also translatable via WPML.
* **New: live admin preview pane** at *Donations Companion → Settings*, *Donations Companion → Templates → Edit*, and on the campaign edit screen. Debounced 350ms updates render the donor-facing form into an iframe via a REST endpoint. Same overlay JS that runs on donor pages also runs in the preview iframe — pixel-faithful results.
* **New: preview toolbar** with viewport simulation (Desktop / Tablet / Mobile), engine simulation (Auto / WC Subscriptions / Subscriptions for WooCommerce / No engine), and language + currency selectors when WPML/WCML are active.
* **Defense in depth on submit:** preview HTML carries a `data-preview="1"` flag; the donor-side `Submit_Guard` rejects any AJAX submit with the matching POST field, the overlay JS disables the submit button on `data-preview` wrappers, and the iframe's mock submit button ships with the `disabled` attribute already set. Three independent layers prevent preview HTML from ever submitting a real donation.
* **Internal:** new `Validation\Template_Validator` (centralized config sanitizer), `Frontend\Preview_Renderer` (standalone, no DB lookups), `REST\Preview_REST_Controller` (admin-only POST endpoint, rate-limited to 10 req/sec/user, `Cache-Control: no-store`), `Admin\Preview_Controller` (wires the pane on three admin screens).
* **Test coverage:** 115 → 145 unit tests, 287 → 362 assertions. New `Template_Validator_Test`, `Preview_Renderer_Test`, `Phase5_Sanitizer_Test`.
* **Backward compat:** existing v0.8.x campaigns continue to render unchanged. Per-preset impact_label / is_featured / sort_order fields were already in storage from Phase 3; v0.9.0 wires them into the donor-side renderer. New per-interval fields (subtitle, annual_equivalency, impact_display_mode, custom_amount_impact_label) default to safe values and don't alter existing behavior until admins fill them in.

= 0.8.0 =
Adds the missing nonprofit campaign-management layer for WooCommerce. Donors can now browse a filterable directory of campaigns; admins classify campaigns by cause, region, country, and more.

* **New: six campaign taxonomies** registered against `wc-donation`: Cause Category, Region, Country, Program, Sponsorship Type, Urgency. Default starter terms (Education / Discipleship / Medical / Food / Construction / Missions / Leadership Training; School / Classroom / Student / Pastor / Teacher / Church / Missionary; Normal / Priority / Urgent) seeded once on first activation. Admins can edit, delete, or extend freely.
* **New: donor-facing directory grid.** `[dfwc_campaign_grid]` shortcode renders a filterable grid of campaigns with search + per-taxonomy filters + sort + pagination. Three layout modes: grid, list. Cards link to the single-campaign permalink; donor flow continues through the existing overlay.
* **New: Gutenberg block** `dfwc-companion/campaign-grid` with full InspectorControls; server-rendered so the editor preview shows exactly what donors see.
* **New: Elementor widget** "Donation Campaign Grid" registered alongside the recurring-donation widget. Same configuration surface as the shortcode and block.
* **New: Featured campaigns.** Side meta box on the campaign edit screen toggles featured status. The directory's "Featured first" sort puts featured campaigns at the top with menu_order as tie-breaker.
* **New: REST live search.** `GET /wp-json/dfwc-companion/v1/grid?...` returns rendered grid HTML so the directory's filter UI swaps in place without full reloads. Browser URL updates via history.replaceState; deep links continue to work. Public read endpoint with 60-req/min/IP rate limit.
* **New: progressive-enhancement JS** (`assets/js/dfwc-directory.js`) — debounced search-as-you-type, immediate fetch on filter changes, falls back to full submit if REST fails. Without JS, the form submits normally.
* **New: WPML support.** All six taxonomies declared `translate="1"` in `wpml-config.xml` — WPML's Translation Management handles per-term translation natively. The new `_dfwc_companion_featured` meta key declared `action="copy"`. The `lang="all"` shortcode attribute overrides WPML's default language scoping for cross-language directories.
* **Internal:** new `Taxonomy\Campaign_Taxonomies` (registration + seeding), `Taxonomy\Campaign_Query_Builder` (filter → WP_Query args), `Frontend\Campaign_Card_Renderer`, `Frontend\Campaign_Directory_Renderer`, `Frontend\Campaign_Grid_Shortcode`, `Frontend\Campaign_Grid_Block`, `Frontend\Elementor_Campaign_Grid_Widget`, `Frontend\Directory_Assets`, `REST\Grid_REST_Controller`.
* **Test coverage:** 98 → 115 unit tests, 245 → 287 assertions. New `Campaign_Query_Builder_Test` covers filter handling, per-page/page clamping, orderby/order allow-listing, featured-first sort, IN-operator for array terms, search passthrough, and filter hooks.

= 0.7.0 =
Headline release. Solves the admin-scale problem: a nonprofit with 50+ campaigns can now configure once and apply to many at a time.

* **New: layered config model.** Campaigns now resolve config through four layers: plugin defaults → global settings → named template → campaign overrides. Sequential arrays (presets) replace wholesale; associative arrays deep-merge. Detached campaigns get a frozen snapshot and ignore future template changes.
* **New: named templates.** Create reusable configuration packages ("School Sponsorship", "Emergency Relief", "General Fund") at *WooCommerce → Donations Companion → Templates*. Each template defines preset amounts, min/max, CTA template, custom-amount toggle, and display options for all three intervals.
* **New: bulk apply.** Three new bulk actions on the wc-donation campaign list: "Apply template: <name>" (one entry per template), "Reset Companion settings", "Detach from template". Per-campaign capability check; surfaces applied/skipped counts via dismissible admin notice.
* **New: global settings.** *WooCommerce → Donations Companion → Settings* lets admins set a default template applied to new campaigns and toggle data preservation on plugin uninstall.
* **New: override-aware save.** When you save a campaign with a template assigned, only the fields you actually changed land in the override meta. If you don't change anything, future template edits propagate normally — honest inheritance.
* **New: parent-contract diagnostics page.** *WooCommerce → Donations Companion → Diagnostics* surfaces 12 health checks (WC + parent + engine + WPML + asset registration) with status pills, suggested remediations, and a copy-paste-ready Markdown support report (path-stripped, pipe-escaped, allow-listed context).
* **New: frontend gating.** When the contract is broken (parent inactive, AJAX action missing, etc.), the donor-side overlay falls back to parent's vanilla form rather than producing a half-broken UI. Override via `dfwc_companion_contract_skip_augment` filter for edge cases.
* **New: WPML integration.** Comprehensive coverage per `plans/v2/AA-wpml-integration.md`. `wpml-config.xml` declares all custom fields and admin-text strings. Templates and campaign overrides register translatable strings with WPML String Translation under stable keys (`<template_id>.<interval>.<field>`). Strings translate at render time. Monolingual sites pay zero overhead.
* **New: maintenance branch.** v0.6.x lives on its own branch for hotfix backports without v0.7.x contamination.
* **New: CI hardening.** Composer + WPCS + PHPStan (level 5) + PHPUnit + Plugin Check + parent-contract watcher + Playwright + zip-install-validation, all gated on PRs and main pushes. Release workflow auto-publishes the zip on tag push, with version-consistency check across the four canonical sources.
* **New: CONTRIBUTING.md** documenting local setup, running checks, code style, commit messages, PR flow, security reporting.
* **New: comprehensive architecture docs** at `docs/architecture/` covering current state, parent contract reference, and compatibility matrix.
* **Refactor:** `Self_Check` is now a thin admin-notice surface that delegates to `Parent_Form_Contract_Checker`. One source of truth, one cache, no drift between notice copy and Diagnostics page copy.
* **Refactor:** `Config_Resolver` moved from `DFWC\Companion\Config_Resolver` to `DFWC\Companion\Config\Config_Resolver` (sub-namespace). Public surface preserved.
* **Test coverage:** 37 → 98 unit tests, 100 → 245 assertions. New test bootstrap with real-but-minimal filter/action/transient implementation.
* **Backward compat:** existing v0.6.x campaigns continue to render exactly as before. Legacy `_dfwc_companion_intervals` and `_dfwc_companion_display` meta is read by the resolver as a fallback layer; on the next admin save in v0.7.0+, the legacy keys migrate to `_dfwc_companion_overrides` automatically.

= 0.6.6 =
* Fix: per-interval "Allow donors to enter a custom amount" toggle was re-enabling itself on every save when unchecked. The save-side sanitizer used `isset()`-with-default-`true` to derive the boolean, but HTML form-checkbox semantics send nothing in `$_POST` for an unchecked checkbox — so `isset()` was false and the default-`true` branch fired. Switched to `! empty()` (missing = false), matching the pattern used for the main "Offer X donations" enable checkbox in the same method.

= 0.6.5 =
* Fix: definitive resolution of the "linked product is not configured as a subscription" warning that 0.6.1–0.6.4 chased through five increasingly defensive layers. Root cause was structural, not a race: the parent plugin's save handler at `class-wcdonationcampaignsetting.php:1739-1744` reads `wc-donation-recurring` from post meta and writes `_wps_sfw_product` / `_wps_sfw_users` based on that value. Our 0.2.0 admin tab injector hid parent's "Display Type" select via `display:none`, so its default value `'disabled'` was POSTing → parent wrote `_wps_sfw_product='no'` on every save → our subsequent override fought the wrong battle.
* The fix flips the strategy: don't hide parent's recurring control — auto-sync it. The admin tab injector now keeps `<select name="wc-donation-recurring">` visible inside the relocated tab, watches our monthly/annual enable checkboxes, and forces the select to `'user'` (then disables it for visual feedback). Parent's own save handler now writes `_wps_sfw_product='yes'` naturally, with no override needed.
* Removed the v0.6.4 defensive hooks: `save_post_wc-donation` priority 9999 and `woocommerce_process_product_meta` priority 99. They fought a race that was actually a missing $_POST value, and added 60+ lines of fragile last-write-wins code.
* Removed the v0.6.3 `error_log` diagnostic in `auto_configure_product_subscription`. No longer needed — parent's natural flow handles the marker meta. Method retained for the WPS SFW cadence meta + WCS product_type taxonomy term, both of which parent does not manage on its own.
* UX: when monthly or annual is enabled on a campaign, the admin sees parent's "Display Type" select set to `'User chooses'` and disabled, with an italic "Auto-managed by Donations for WooCommerce Companion. Disable Monthly and Annually above to unlock." hint below it. Single source of truth, fully transparent.

= 0.6.4 =
* Fix: subscription-product meta now wins the last-write race against WPS SFW's default. Previously, our save handler wrote `_wps_sfw_product='yes'` correctly, but the parent plugin's `class-wcdonationsetting` save handler then called `wp_update_post` on the linked product later in the same save flow (`class-wcdonationsetting.php:681`). That nested update triggered `woocommerce_process_product_meta`, which fires WPS SFW's handler at priority 10. WPS SFW reads `$_POST['_wps_sfw_product']` — but we're saving a campaign, not a product, so the field is absent and the handler defaults to writing `'no'`. Last write wins; our `'yes'` got clobbered.
* Two new hooks defend against that race:
  - `save_post_wc-donation` at priority 9999 runs after every priority-10 handler completes, then re-asserts our subscription meta one final time.
  - `woocommerce_process_product_meta` at priority 99 catches any product save where WC processes the product (including parent's nested `wp_update_post`) and re-writes our values immediately after WPS SFW's default-`'no'`.
* Both hooks gate on `Config_Resolver::is_configured()` + recurring enabled, so non-companion products are unaffected.

= 0.6.3 =
* Diagnostic: warning text in the meta box now includes the actual values read for the WPS SFW subscription markers (`_wps_sfw_product`, `_wps_sfw_users`) so we can see at a glance whether auto-config wrote them or not.
* Belt-and-suspenders: auto-config now ALSO calls `update_post_meta` directly after `wps_sfw_update_meta_data`, in case the helper takes an unexpected path on some site configs. WP dedupes same-value writes; harmless redundancy.
* When `WP_DEBUG_LOG` is enabled, auto-config logs a single line to `wp-content/debug.log` after each save with the product ID, campaign ID, period, and read-back values. Lets us verify auto-config actually ran (vs. a save handler short-circuit) without exposing internals to end users.

= 0.6.2 =
* Fix: 0.6.1's product auto-config wrote the wrong meta key. Inspecting the WPS SFW source confirms the engine's "this is a subscription product" marker is `_wps_sfw_product='yes'`, not `_wps_sfw_users='user'` — that latter key is the parent plugin's internal tracking, used in `class-wcdonationorder.php:1601` to decide which subscription POST keys to read. Both 0.6.1's auto-config and the warning detection were checking parent's internal key. Result: product never got recognized by the subscription engine; warning persisted; recurring donations silently downgraded to one-time despite the "fix" in 0.6.1.
  - Auto-config now writes `_wps_sfw_product='yes'` (engine marker) AND keeps `_wps_sfw_users='user'` (parent compat) plus the `wps_sfw_subscription_*` cadence meta.
  - Warning detection now checks `_wps_sfw_product='yes'` so it clears correctly after the next save.
* Internal: new `tests/smoke-product-config.php` smoke harness invokes the private auto-config method via Reflection and asserts the written meta. Confirms in wp-env that `_wps_sfw_product=yes`, `_wps_sfw_users=user`, `interval=month`, `number=1` all land on the linked product.

= 0.6.1 =
* Fix: when monthly or annual is enabled on a campaign, the linked WC product is now auto-configured as a subscription product. Previously, parent's "Recurring Donations" tab held the controls that did this — but v0.2.0's tab-injector hid those controls, leaving admins with no UI path to configure the product. Donations would silently downgrade to one-time charges because the subscription engine looks at the product's own subscription configuration, not just the POST data.
  - WPS SFW path: writes `_wps_sfw_users='user'` plus `wps_sfw_subscription_number/interval/expiry_*` meta on the product.
  - WCS path: sets the product type taxonomy to `subscription` and seeds `_subscription_period/_period_interval/_length` meta.
  - No-op when no recurring engine is installed.
* As a result, the "linked product is not configured as a subscription product" warning in the meta box now goes away after the first save with monthly or annual enabled.

= 0.6.0 =
* New: per-interval "Allow donors to enter a custom amount" toggle (defaults checked). Admins can now force preset-only amount selection on monthly while still allowing free entry on one-time, etc. The min/max range still applies for preset-amount validation when custom is off.
* New: Display Options fieldset in the meta box with three controls — "Show campaign title", "Show campaign image" (both default checked, preserve existing behavior), and a "Cause section heading" text field (leave blank for parent's default "Select Cause" text).
* New: `_dfwc_companion_display` post meta key stores the display options as a separate concern from per-interval config.
* Internal: overlay JS reads a new `data-display` attribute carrying display options as JSON; on init, it hides parent's `.campaign-title` / `.block-campaign-thumbnail` per admin choice and replaces parent's `.row2 h3.wc-donation-title` text via `textContent` (XSS-safe) when a custom heading is set.
* Internal: parent-contract baseline grows from 14 to 16 locked line ranges. New entries: parent's title+image render block at `frontend-order-donation.php:400-420` and the entire cause block at `frontend-donation-cause-disp.php:1-24`. Watcher fails CI if either restructures.

= 0.5.1 =
* Fix: overlay UI now inserts at parent's amount-block position (replacing the `.row1` block in place) instead of being prepended above the entire form. Parent's "Frontend Ordering" admin choice (Cause → Amount → Subscription → Tribute → …) is now respected: wherever the admin placed "Donation Amount" in the sequence, our interval-first UI takes that spot. Cause selector, image, title, and other parent blocks now appear in their admin-configured order around the overlay instead of after it.

= 0.5.0 =
* New: auto-augmentation across all six render paths the parent plugin uses — single-campaign permalink, parent's `[wc_woo_donation]` shortcode, donation widget, donation-on-cart, donation-on-cart-block, and donation-on-checkout. Previously only our `[dfwc_recurring_donation]` shortcode/block/widget triggered augmentation; now any place parent renders a donation form will get the interval-first overlay automatically (when the campaign has companion config saved).
* New: per-campaign + per-context opt-out filter `dfwc_should_augment_parent_form` (signature: `apply_filters( 'dfwc_should_augment_parent_form', bool $augment, int $campaign_id, string $context )` where context is one of single/shortcode/widget/checkout/cart/cart_block).
* New: gating — campaigns without saved companion config render parent's vanilla form unchanged. Augmentation only kicks in for campaigns the admin explicitly opted into via the meta box.
* New: i18n — `languages/dfwc-companion.pot` template ships with the plugin; ~71 translatable strings.
* Internal: `Frontend\Context_Augmenter` is the new auto-augmentation hook handler. Re-entrancy-safe: when our `Renderer::render()` is wrapping (driven from `[dfwc_recurring_donation]`), the augmenter checks `Renderer::is_inside_render()` and skips its own wrapping to avoid double-wrapping.
* Internal: `Renderer` exposes `build_overlay_attributes()` and `wrap_with_overlay()` so the same overlay payload shape is used by all render paths.

**Upgrade action:** if you previously embedded `[dfwc_recurring_donation]` directly in a campaign's post content because the auto-render didn't augment, you can remove it now — parent's auto-render on the campaign permalink will be augmented automatically. Leaving the shortcode in place will produce two augmented forms on the same page; remove one.

= 0.4.0 =
* Architectural refactor: companion now AUGMENTS the parent's donation form instead of replacing it. Cause selector, campaign image, processing fee, gift aid, tributes, e-card recipient, and donor-wall integration that the parent plugin renders are now visible alongside our interval-first tabs.
* Implementation: parent plugin renders its full form via its existing render path; on every page that contains our shortcode/block/Elementor widget, a tiny vanilla-JS overlay (`assets/js/dfwc-overlay.js`) finds parent's amount block and recurring controls in the DOM, hides them, and mounts our 3-tab interval UI in their place. As the donor changes our tabs/presets/custom amount, the overlay writes to parent's existing hidden inputs. Parent's existing submit handler runs unchanged — the AJAX call to `donation_to_order` happens through parent's tested pipeline.
* Removed: `Form_Replacer` class and its `wc_donation_before/after_*_add_donation` ob_start/ob_get_clean wrapping. The 0.3.0 `dfwc_should_replace_parent_form` filter is gone.
* Removed: legacy `_dfwc_companion_form_mode` post meta read path + the `META_KEY_FORM_MODE` / `FORM_MODE_*` constants in `Config_Resolver`. The post meta on legacy campaigns is left in the DB unread (allows rolling back to 0.3.0 if needed).
* Removed: deleted templates/recurring-donation-form.php, assets/js/dfwc-form.js, assets/css/dfwc-form.css. Replaced by the overlay JS+CSS at assets/{js,css}/dfwc-overlay.{js,css}.
* New: `tests/parent-contract.baseline.json` covers six additional file/line ranges in the parent — every selector the overlay depends on is locked. CI's parent-contract watcher fails loudly if the parent restructures any of: `.wc-donation-in-action` wrapper, `.row1` amount block, hidden price inputs, recurring checkbox, `_subscription_*` selects, WPS SFW fields, or the submit handler at `assets/js/frontend.js:470`.
* Block editor preview short-circuit: the Gutenberg block renders a placeholder card in the editor instead of triggering parent's frontend-only render path through `ServerSideRender`.
* Power-user opt-out: don't enqueue the overlay assets (filter `wp_enqueue_scripts` to dequeue `dfwc-overlay`) on pages where you want parent's form unmodified. Per-page granularity instead of the per-campaign toggle 0.3.0 had.

**Upgrade action required:** if you put `[dfwc_recurring_donation campaign_id="..."]` directly in a campaign's post content during 0.1.x–0.3.x, leave it there — it now produces the augmented form. Don't ALSO place `[wc_woo_donation]` on the same page; you'd get two forms.

= 0.3.0 =
* Improvement: parent plugin's donation form is now ALWAYS replaced by the interval-first form on every render path (single-campaign permalink, [wc_woo_donation] shortcode, widget, and WC checkout context). The previous per-campaign opt-in caused dual-form display when sites upgraded from 0.1.x — eliminated.
* Improvement: removed the "Companion: Form Mode" side meta box. Power users who specifically need to keep parent's form on certain campaigns can opt out via the new `dfwc_should_replace_parent_form` filter (signature: `apply_filters( 'dfwc_should_replace_parent_form', bool $replace, int $campaign_id )`).
* Improvement: Form_Replacer now wraps four parent render contexts (was two): single, shortcode, widget, checkout. Donor sees the same interval-first UX wherever parent would have rendered its form.
* Fix: legacy campaigns saved under 0.1.x with `_dfwc_companion_form_mode = shortcode_only` are no longer respected — the meta is left in the DB but ignored. Eliminates the "I upgraded but still see two forms" trap.

= 0.2.0 =
* Improvement: companion config UI is now relocated into the parent plugin's "Recurring Donations" tab via a small JS injector — admins see one unified place to configure recurring intervals instead of the two separate UIs in 0.1.x.
* Improvement: parent plugin's "Display Type" / "Recurring Text" / WPS-SFW interval controls inside the Recurring Donations tab are auto-hidden when the companion is configuring recurring intervals (avoids conflicting/duplicated controls).
* Improvement: form-mode default flipped from `shortcode_only` to `replace`. When you embed the companion's form via shortcode/block/widget, the parent plugin's default form on the same campaign's permalink page is now suppressed automatically. Existing campaigns that were explicitly set to `shortcode_only` keep that setting; only campaigns that never set a value get the new default.
* Improvement: side meta box "Form Mode" copy rewritten — both options shown clearly with a descriptive paragraph.
* Improvement: graceful fallback — if the parent plugin restructures its tab DOM in a future release, the JS injector logs a warning to console and leaves the companion meta box at its default location instead of breaking the page.
* Internal: smoke-test harness in `tests/smoke-save.php` validates class loading, engine detection, Renderer output (incl. the wp_unique_id replacement), and Meta_Box::save() invocation against a real wp-env instance.

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

= 2.1.0 =
QuickBooks Online sync moved to a separate plugin (donations-for-woocommerce-qbo-sync v1.0.0). If you use QBO sync, install the sibling plugin alongside this upgrade — your existing tokens, mappings, and sync log carry over with no reconnection. Sites that don't use QBO are unaffected. Backward-compatible Phase 9 hooks unchanged.

= 2.0.1 =
Critical fix: donor-side AJAX submit was throwing ArgumentCountError on every site running v1.1.0 — v2.0.0, leaving donors stuck on an infinite spinner and processing no donations. Mismatch between our filter binding (2 args) and the parent plugin's actual call shape (1 arg). Upgrade required if you've taken any donation traffic on v1.1.0 or later.

= 2.0.0 =
Major version bump: introduces the first runtime third-party API surface (QuickBooks Online sync) and persistent encrypted-secret storage. Adds real-time per-donation Sales Receipt creation in QBO via OAuth2 with admin-supplied app credentials; works for cash, stock, and (when shipped) crypto donations through the standard Phase 9 hook. Off by default — existing v1.3.x sites see zero behavior change until admins opt in via Settings → QuickBooks Online sync. Backward-compatible.

= 1.3.0 =
Adds stock-donation support in two modes (built-in pledge form OR Overflow integration). Both off by default — existing sites see zero behavior change until admins opt in via Settings → Stock donations. Stock donations route through the same `dfwc_companion_donation_submitted` Phase 9 hook (with `$context = 'stock'`) as cash, so existing CRM/analytics listeners pick them up automatically. Backward-compatible.

= 1.2.0 =
Adds goal-aware giving: opt-in clamp of the donor's max custom amount to the campaign's remaining goal, plus a "Goal met! Support our general fund" card when a campaign is fully funded. Both off by default — existing sites see zero behavior change until admins opt in via Settings → Goal-aware giving. Backward-compatible.

= 1.1.0 =
Adds six donor-flow event hooks (form viewed / interval selected / preset selected / custom amount entered / donation submitted / donation failed) so admins can pipe events to GA4, FluentCRM, Zapier, etc. via small custom snippets. Privacy-by-default: aggregate-only data, no donor PII. Documented integration recipes in docs/event-hooks.md. Backward-compatible — sites without listeners see zero behavior change.

= 1.0.0 =
First stable release. Adds per-currency preset amounts (WCML primary; WCPay/Aelia via filter), opt-in advanced cadences (weekly / quarterly / semi-annually / custom "every N period"), `wp dfwc-companion health` CLI, and a clean opt-in uninstall path. Backward-compatible — existing campaigns render unchanged until admins opt into the new features.

= 0.9.0 =
Conversion UX release. Per-preset impact labels with four display modes (inline, below button, tooltip, card), featured-preset badges, per-interval subtitles, annual equivalency text with live token substitution, and a custom-amount impact label. Plus a live admin preview pane on the campaign edit screen, Templates page, and Settings page — debounced 350ms updates render the donor form into an iframe so admins can see exactly what donors will see before saving. Backward-compatible.

= 0.8.0 =
Adds the donor-facing campaign directory with six taxonomies (cause / region / country / program / sponsorship type / urgency), a filterable grid via shortcode + Gutenberg block + Elementor widget, featured campaigns, and progressive-enhancement live search. Default starter terms seeded on first activation; existing campaigns gain the taxonomy editing UI but no behavior changes until classified. Backward-compatible.

= 0.7.0 =
Headline release: named templates + bulk apply for nonprofits running many campaigns. New Diagnostics page surfaces parent-plugin compatibility status with a copy-paste support report. Comprehensive WPML integration via wpml-config.xml + String Translation registration. Existing v0.6.x campaigns continue to work unchanged; legacy meta migrates to the new schema on next admin save. CI now runs PHPCS + PHPStan + Plugin Check on every PR; release zip auto-publishes on tag.

= 0.6.6 =
Fixes the per-interval "Allow custom amount" checkbox silently re-enabling itself every save. Unchecking and saving now correctly persists as off.

= 0.6.5 =
Definitive fix for the "linked product is not configured as a subscription" warning. Drops the v0.6.4 defensive hook stack; instead, the admin tab injector now keeps parent's "Display Type" select visible (locked to "User chooses" while monthly/annual are enabled), so parent's own save handler writes the WPS SFW marker meta correctly. Save any campaign once and the warning clears.

= 0.6.4 =
The subscription-product warning now resolves correctly after one save. Adds two late-running hooks that re-assert our `_wps_sfw_product='yes'` write after parent's nested `wp_update_post` triggers WPS SFW's default-`'no'` overwrite.

= 0.6.3 =
Diagnostic release for the persisting "linked product is not configured" warning. Warning now includes the actual meta read so we can see why detection thinks the product isn't a subscription.

= 0.6.2 =
Hotfix for 0.6.1: product auto-config now writes the correct WPS SFW marker meta key (`_wps_sfw_product=yes`) so the subscription engine actually recognizes the linked product. If you were on 0.6.1 and the warning persisted, save the campaign once on 0.6.2 and the warning clears.

= 0.6.1 =
Fixes the "linked product is not a subscription" warning by auto-configuring the linked WC product as a subscription product when you enable monthly or annual on a campaign. No admin action needed — just save the campaign once on 0.6.1.

= 0.6.0 =
Adds per-interval custom-amount toggle, campaign title/image show-hide, and cause heading customization in a new Display Options section of the meta box. All defaults preserve existing behavior — safe to upgrade.

= 0.5.1 =
Fixes overlay placement: the interval-first UI now drops into the parent plugin's amount-block position (respecting the admin's Frontend Ordering setting). Cause selector and other blocks now render in their configured positions around the overlay.

= 0.5.0 =
Auto-augmentation now extends to all six contexts where parent renders a donation form (cart, checkout, widget, etc.) — previously only our shortcode/block triggered it. Includes a `.pot` translation template. If you put `[dfwc_recurring_donation]` directly in a campaign's post content as a workaround in 0.4.x, you can remove it.

= 0.4.0 =
Major architectural refactor: companion now augments parent's form instead of replacing it. Cause selector, gift aid, processing fee, tributes, and other parent-rendered fields are now visible alongside our interval tabs. Safe to upgrade — overlay falls back gracefully (donor sees parent's unmodified form) if any required parent DOM element is missing.

= 0.3.0 =
Replacement of the parent plugin's donation form is now unconditional across single-campaign pages, shortcodes, widgets, and checkout contexts. Removes the form-mode opt-in that caused dual-form display on upgrade. If you need parent's form preserved on specific campaigns, use the new `dfwc_should_replace_parent_form` filter.

= 0.2.0 =
Major UX improvement: companion config moves into the parent plugin's Recurring Donations tab; default form mode flipped to "Replace" so you no longer see two donation forms on the same page. Safe to upgrade.

= 0.1.1 =
Critical fatal-error fix. Upgrade required if you've installed 0.1.0.

= 0.1.0 =
Initial release.
