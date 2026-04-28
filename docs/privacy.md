# Privacy

> Updated for v1.0.0. This document describes exactly what the **Donations for WooCommerce Companion** plugin stores, what it doesn't, and how it handles donor data.

This is a *companion* plugin — it sits on top of the **Donation for WooCommerce** parent plugin and a subscription engine (WooCommerce Subscriptions or Subscriptions For WooCommerce). The companion's privacy posture is deliberately minimal: **the companion stores no donor PII, no payment data, and no donation records.**

## What the companion stores

### WordPress options (1 row each)

| Option | Purpose | Personal data? |
|---|---|---|
| `dfwc_companion_global_settings` | Global plugin settings (default template, advanced-intervals toggle, preserve-on-uninstall flag) | No |
| `dfwc_companion_templates` | Named template definitions | No |
| `dfwc_companion_schema_version` | Last-applied migration version | No |
| `dfwc_companion_terms_seeded` | One-time flag indicating starter taxonomy terms were created | No |

### Post meta on `wc-donation` campaign posts

| Meta key | Purpose | Personal data? |
|---|---|---|
| `_dfwc_companion_intervals` | Legacy v0.6.x interval config (read as fallback only; new saves go to `_dfwc_companion_overrides`) | No |
| `_dfwc_companion_display` | Legacy v0.6.x display options | No |
| `_dfwc_companion_template_id` | The template assigned to a campaign | No |
| `_dfwc_companion_overrides` | Per-campaign overrides on top of the assigned template | No |
| `_dfwc_companion_detached` | Whether the campaign is detached from its template's future updates | No |
| `_dfwc_companion_featured` | Whether the campaign is featured in the directory grid | No |

### Transients (cached probe results)

| Transient | TTL | Purpose |
|---|---|---|
| `dfwc_contract_report` | 12 hours | Cached parent-contract diagnostic check results |
| `dfwc_self_check` | 12 hours | Cached admin-notice surface state |
| `dfwc_preview_rate_<user_hash>` | 60 seconds | Per-admin-user rate-limit counter for the live preview REST endpoint |
| `dfwc_grid_rate_<ip_hash>` | 60 seconds | Per-IP rate-limit counter for the public directory grid REST endpoint |

The rate-limit transients hash IPs / user IDs with `wp_hash()` rather than storing them in cleartext. They are cleared on plugin uninstall when the admin opts in to data deletion.

### Taxonomy terms

The companion registers six taxonomies on the `wc-donation` post type: `dfwc_cause`, `dfwc_region`, `dfwc_country`, `dfwc_program`, `dfwc_sponsorship_type`, `dfwc_urgency`. Default starter terms are seeded on first activation. **Terms themselves are admin content** — they are not personal data.

## What the companion does NOT store

- ❌ Donor names, email addresses, phone numbers, mailing addresses
- ❌ Donation amounts, payment method details, card data, billing tokens
- ❌ Subscription IDs, renewal dates, customer IDs from the gateway
- ❌ Order records or transaction history
- ❌ IP addresses or device fingerprints (only short-lived rate-limit hashes)
- ❌ Donor-side cookies of any kind
- ❌ Analytics events (plugin ships no analytics; admins wire their own via Phase 9 event hooks once those land in v1.1.0)

All of the above are stored — to the extent any of them are stored at all — by either the parent **Donation for WooCommerce** plugin, **WooCommerce** core, or the active subscription engine. The companion never touches that data.

## Donor-side cookies

**The companion sets zero cookies on donor-facing pages.**

The donor form is a thin overlay on top of the parent plugin's existing form. The form's behavior — including any cookies the parent plugin or the subscription engine sets — is the parent's responsibility, not the companion's.

## Data flow at submit time

When a donor clicks "Donate":

1. The companion's overlay JS reads the donor's selected interval + amount + custom-amount value from the DOM.
2. The overlay writes those values to **the parent plugin's existing hidden inputs** (the same inputs the parent's vanilla form uses).
3. The overlay calls **the parent plugin's existing `donation_to_order` AJAX handler**. The companion does not POST to its own endpoint.
4. The parent plugin processes the donation as if the donor had used the parent's vanilla form — same nonce, same handlers, same downstream wiring.

The companion adds a small server-side guard (`Frontend\Submit_Guard`) that re-validates the posted amount + interval against the campaign's configured min/max + enabled-intervals allow-list **before** the parent's handler runs. This guard reads only `$_POST` data — it doesn't query, log, or persist anything.

## REST endpoints

The companion exposes two REST routes:

| Route | Auth | Purpose | Personal data? |
|---|---|---|---|
| `POST /wp-json/dfwc-companion/v1/preview` | Admin only (`manage_woocommerce`) | Render the live admin preview pane | No |
| `GET /wp-json/dfwc-companion/v1/grid` | Public read | Refresh the donor directory grid for live search / filtering | No |

The preview endpoint sets `Cache-Control: no-store` and rate-limits to 10 req/sec/user via a transient. The grid endpoint rate-limits to 60 req/min/IP. Both endpoints sanitize all inputs and validate that the requested campaign post is `wc-donation` post type before reading any meta.

## Uninstall behavior

`uninstall.php` respects the admin's `preserve_data_on_uninstall` setting (default: **on**).

- **Preserve on (default):** all options, post meta, and taxonomy terms remain in the database. Plugin caches (the two named transients) are cleared. Reinstalling the plugin recovers everything.
- **Preserve off (admin opts in):** options, companion-prefixed post meta, and `dfwc_`-prefixed transients are deleted. Campaign posts (`wc-donation` post type) are NOT deleted — those are parent-plugin data. Taxonomy terms are NOT deleted — admins who reinstall later may want their classification data back.

The cleanup uses `delete_metadata( 'post', 0, $key, '', true )` for post meta and `$wpdb->prepare` for the transient sweep — no raw SQL.

## GDPR posture

Because the companion stores no personal data, it has no role in GDPR data-subject rights workflows:

- **Right of access (Article 15):** N/A — the companion holds no personal data to surface.
- **Right to erasure (Article 17):** N/A — the companion holds no personal data to erase. Donor erasure is handled by WooCommerce + the parent plugin via the standard `personal_data_eraser` filter pattern; the companion contributes nothing.
- **Right to data portability (Article 20):** N/A — same reason.

If your jurisdiction (or a stricter privacy regime than GDPR) requires you to enumerate **every** plugin's data handling regardless of whether it stores PII, this document is the canonical reference.

## Reporting privacy concerns

If you discover a privacy issue we missed — for example, a code path that stores donor data we didn't intend — please report it privately via the security policy (see [SECURITY.md](../SECURITY.md)) rather than opening a public issue.

## Updates to this document

When a future phase changes the companion's data handling (Phase 9 event hooks, for example, will introduce hooks that pass donor metadata to admin-wired listeners — but the companion will remain a pass-through, not a store), this document is updated as part of the same release.

Last reviewed: v1.0.0.
