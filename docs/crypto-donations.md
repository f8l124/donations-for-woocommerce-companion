# Crypto donations via The Giving Block

> **Shipped in v2.3.0.** Adds cryptocurrency donations as a third channel alongside cash (existing) and stock (v1.3.0). The Giving Block (TGB) handles wallet UX, KYC/AML, supported-coin lifecycle, and on-chain confirmation. Companion's role is widget mount + webhook receipt + WC order recording — never blockchain interaction directly.

## Table of contents

1. [What ships](#what-ships)
2. [Prerequisites](#prerequisites)
3. [Setup walkthrough](#setup-walkthrough)
4. [Per-campaign routing](#per-campaign-routing)
5. [Donor experience](#donor-experience)
6. [How donations record](#how-donations-record)
7. [Phase 9 hook integration](#phase-9-hook-integration)
8. [Diagnostics](#diagnostics)
9. [Limitations + documented constraints](#limitations--documented-constraints)
10. [Troubleshooting](#troubleshooting)
11. [Filterable seams](#filterable-seams)

---

## What ships

- **Donor-side:** "Donate Crypto" button below the cash form on every crypto-enabled campaign. Click → lazy-loads TGB widget script → donor commits → on-hold WC order created immediately → flips to `completed` when TGB's webhook confirms.
- **Admin settings page:** `WooCommerce → Donations Companion → Crypto Donations`. Sandbox/production toggle, organization id, API key (encrypted at rest), webhook secret (encrypted), default project id, master "enable" toggle, Test connection / Disconnect / Refresh project list actions.
- **Per-campaign meta box:** "Crypto donations" side meta box on every campaign edit screen (visible only when crypto is globally enabled + connected). Per-campaign opt-out + project routing dropdown.
- **REST endpoints:** `POST /wp-json/dfwc-companion/v1/crypto-pending-order` (donor commits), `POST /wp-json/dfwc-companion/v1/tgb-webhook` (TGB confirmation).
- **Custom WC payment method:** `dfwc_crypto` (hidden from donor checkout; assigned programmatically to companion-created crypto orders for WC-report filtering and downstream sync routing).
- **Phase 9 hook fire:** `dfwc_companion_donation_submitted` fires with `$context = 'crypto'` after webhook confirms — listeners (FluentCRM, GA4, custom CRM webhooks) see crypto donations identically to cash.
- **Diagnostics:** two new checks in the diagnostics page — connection health + webhook activity proxy.

## Prerequisites

- A nonprofit account with [The Giving Block](https://thegivingblock.com/). Credentials (API key, webhook secret, organization id, project ids) are issued from their dashboard.
- WordPress 6.2+ + WooCommerce 5.0+ + Donation for WooCommerce 3.9.8+ (the parent plugin — companion's standard requirements).
- A linked WC product on every campaign that should accept crypto. The companion creates crypto orders against the campaign's `wc_donation_product` — campaigns missing a linked product can't record crypto donations.
- A site reachable from the public internet (the TGB webhook calls back from their servers; localhost / private intranets won't work for production donations).

## Setup walkthrough

### 1. Get TGB credentials

In the TGB dashboard:
- Find your **Organization ID** (public, surfaced under organization settings).
- Generate an **API key** (private, single-use display — copy immediately).
- Generate a **Webhook secret** (private, single-use display — copy immediately).
- Decide on **environment**: sandbox first (recommended for first install) or production (live donations).
- Note your **Default Project ID** (the org-wide project donations route to when no campaign-specific override exists).

### 2. Configure companion

Navigate to `WooCommerce → Donations Companion → Crypto Donations`.

- **Environment**: Sandbox (recommended for first install).
- **Organization ID**: paste from TGB.
- **API key**: paste from TGB. Stored encrypted at rest (AES-256-CBC, key derived from `AUTH_KEY . AUTH_SALT`).
- **Webhook secret**: paste from TGB. Same encryption.
- **Default Project ID**: leave blank for now (we'll populate after the project list loads).
- Click **Save Changes**.
- Click **Test connection** — should display "Connected as <Your Org Name>". If it errors, re-check the API key + environment.
- Click **Refresh project list** — companion fetches your TGB sub-projects (cached 1 hour).
- Re-edit the page → the **Default Project ID** field is now a dropdown of your projects. Pick the org-wide default.
- Toggle **Enable crypto donations** ON.
- Click **Save Changes** again.

### 3. Configure TGB's webhook URL

Back in the TGB dashboard, set the webhook URL to the value displayed on the companion's Crypto Donations page:

```
https://<your-site>/wp-json/dfwc-companion/v1/tgb-webhook
```

TGB will POST `donation.confirmed` events here when on-chain confirmations land. The companion's HMAC-SHA256 verification (using the webhook secret) rejects forgeries.

### 4. Verify with sandbox

In sandbox environment, complete a test donation:
- Visit any published `wc-donation` campaign on your site
- Scroll to the donor form
- Click "Donate Crypto" (button appears below the cash form)
- Complete a test donation in TGB's sandbox widget (testnet wallet, fake coin)
- Verify:
  - WC order created (status: `on hold`) in `WooCommerce → Orders`
  - Webhook arrives within ~30 seconds
  - Order flips to `completed`
  - Phase 9 hook fired (verify via your test FluentCRM/GA4/custom listener)

### 5. Switch to production

When sandbox testing passes:
- Re-enter production credentials in the Crypto Donations page (sandbox creds remain stored under-the-hood but inactive)
- Switch the **Environment** toggle to Production
- Update TGB's webhook URL configuration to point at the production environment if you separated them
- Test with a small real donation
- Verify the order completion + Phase 9 hook

## Per-campaign routing

Each campaign can route donations to a specific TGB sub-project. Useful when an org has separate projects for "Annual Fund" / "Disaster Relief" / "Capital Campaign" sub-buckets.

On any campaign edit screen, the side meta box "Crypto donations" appears (when global crypto is on + connected). Two controls:

- **Show "Donate Crypto" button on this campaign** (checkbox, default ON). Uncheck to hide the button on a specific campaign — useful for memorial-only or stock-only campaigns where crypto isn't appropriate.
- **TGB Project** (dropdown). "Inherit (org default)" sends donations to the default project from the global Crypto Donations page. Pick a specific project to override.

The dropdown is populated from the cached TGB project list (refreshed via the **Refresh project list** button on the Crypto Donations settings page). If you create a new project in TGB after the cache was refreshed, either:
- Click Refresh on the settings page, then come back to the campaign meta box.
- OR enter the project ID directly — the meta box accepts custom values and surfaces them as "Custom: \<id\>".

## Donor experience

The donor visiting a crypto-enabled campaign sees:
- The standard cash + recurring form (unchanged from v2.2.x)
- A divider: "or donate non-cash"
- Two buttons: **Donate Crypto** + **Donate Stock** (the latter only when stock is also configured)

Clicking "Donate Crypto":
1. Button disables, shows a loading spinner.
2. Companion lazy-loads TGB's widget script (only on click — never on page load, never on campaigns without crypto enabled).
3. TGB widget mounts in a container directly below the button.
4. Donor selects coin (BTC / ETH / USDC / etc.), enters amount, completes wallet authorization in TGB's UI.
5. Donor sees a confirmation message: "Donation submitted. Awaiting on-chain confirmation…"
6. (Behind the scenes: companion creates an `on-hold` WC order linked to the donor's TGB donation_id.)
7. Webhook arrives within minutes (typically ~5-30 minutes depending on chain). Order flips to `completed`.
8. Donor receives TGB's standard email confirmation (we don't send our own — TGB owns the confirmation flow).

If the donor closes the widget mid-flow: nothing persists on our side. No pending order. They can re-open and try again.

## How donations record

Order shape:
- `status` = `on-hold` (initial), then `completed` (after webhook)
- `payment_method` = `dfwc_crypto`
- `payment_method_title` = "Crypto via The Giving Block"
- `currency` = `USD` (TGB's reporting unit; see [limitations](#limitations--documented-constraints))
- Line item = the campaign's linked `wc_donation_product`, qty 1, price = USD-equivalent value at commit time
- Line item meta:
  - `_dfwc_companion_crypto_donation_id` — TGB's donation id (idempotency key for replays)
  - `_dfwc_companion_crypto_currency` — coin symbol (BTC, ETH, USDC, …)
  - `_dfwc_companion_crypto_amount` — crypto-denominated amount (e.g., 0.00125 for 0.00125 BTC)
  - `_dfwc_companion_crypto_project_id` — TGB project the donation routed to
  - `_dfwc_companion_context` — `crypto`

Idempotency: replayed webhooks (TGB retries on transient failures) match by donation_id and no-op. The Phase 9 hook fires exactly once per donation_id.

Race-safe: if the webhook arrives before the donor's pending-order POST (rare but possible — slow client, queued requests), the webhook handler creates the order from the webhook payload directly. The deferred POST then sees the existing order and returns 200 idempotent.

## Phase 9 hook integration

Crypto donations fire the same `dfwc_companion_donation_submitted` action as cash and stock — listeners (FluentCRM, GA4, Mixpanel, Slack, Zapier, custom CRM webhooks) tag them via the `$context` parameter:

```php
add_action(
    'dfwc_companion_donation_submitted',
    function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) {
        if ( 'crypto' === $context ) {
            // Crypto-specific handling. $amount is USD; $currency is 'USD'.
            // Original coin + crypto-amount available via the order's
            // line-item meta (_dfwc_companion_crypto_currency,
            // _dfwc_companion_crypto_amount).
        }
    },
    10,
    7
);
```

For crypto:
- `$interval` = `'one_time'` (recurring crypto deferred to v2.3.1+)
- `$amount` = USD value as reported by TGB at confirmation time
- `$context` = `'crypto'`
- `$currency` = `'USD'`
- `$language` = `''` (webhook doesn't carry locale)
- `$reason` = `''` (success path; failure events do not call this hook)

The original coin-denominated amount is intentionally **not** in the hook payload — listeners that need it should query the order's line-item meta. This keeps the Phase 9 contract uniform across cash, stock, and crypto.

## Diagnostics

Two checks added under `WooCommerce → Donations Companion → Diagnostics`:

- **TGB connection** — Auto-passes silently when crypto is disabled. Warns when crypto is on but Token_Store credentials are missing or unreadable (e.g., `AUTH_KEY` rotated since storage). Passes when credentials are stored. Does **not** round-trip the live API per check — admins explicitly verify with the Test connection button on the settings page.
- **TGB webhook activity** — Soft warning when the combination of "no webhook ever received" + "on-hold crypto orders > 7 days old" suggests delivery is broken. Either signal alone is benign (fresh install, or quiet period); both together is the fingerprint of a misconfigured webhook URL or firewall blocking.

## Limitations + documented constraints

1. **Recurring crypto deferred to v2.3.1+.** TGB supports it for a limited coin set; donor-side authorization UX is murky (wallets handle subscriptions inconsistently). Only one-time crypto in v2.3.0.
2. **Currency is always USD.** TGB reports in USD regardless of the WC store's base currency. The order is denominated USD; the original coin+amount lives in line-item meta. Cross-currency stores (e.g., GBP base currency) see USD amounts in WC reports for crypto donations — admins should plan their reporting accordingly. Cross-currency reconciliation is a v2.3.x concern.
3. **No refund flow at v2.3.0.** TGB-side refunds require manual coordination via TGB's dashboard. The companion doesn't auto-process `donation.refunded` webhooks at this release. v2.3.1+ candidate.
4. **No native KYC/AML collection.** TGB handles donor identity verification above their threshold ($5,000+ as of writing). The companion never sees donor wallet addresses or transaction hashes — we receive only the TGB donation_id and USD value.
5. **No on-chain interaction.** Companion never talks to a blockchain. TGB is the only counterparty; if TGB is down, crypto donations fail (cash + stock channels are unaffected).
6. **No wallet-direct mode.** Self-custody (org runs their own wallet) is out of scope. Sites that need this should use a different gateway entirely.
7. **Single TGB org per site.** Multi-org agencies managing multiple nonprofits from one site need separate WP installs at v2.3.0. v2.4+ candidate.
8. **TGB API stability.** TGB has shipped breaking changes historically. The widget script URL + signature header name + verification scheme are filterable seams (see below) so admins can adapt without code changes if TGB ships a new format.
9. **Project list cache is per-environment.** Switching from sandbox to production after a long session shows the sandbox project list until you click Refresh.
10. **Donor PII never reaches Phase 9.** TGB's webhook sometimes includes a hashed donor email; we do not propagate it to the Phase 9 hook payload (per the Privacy_Guard contract). Listeners that need donor identity should use TGB's dashboard or their own integration.

## Troubleshooting

### Donor sees "Could not load the donation widget"
- Check browser console for the actual error. Most common cause: `dfwcCompanion.tgb.widgetScriptUrlSandbox` / `widgetScriptUrlProduction` not set and the default URL doesn't match TGB's current widget endpoint. Override via `dfwc_companion_tgb_widget_localize` filter.
- Network tab: check whether the script returned 200 OK. If 404, the URL is wrong. If blocked by ad-blocker, donor sees a friendly fallback (link to org's hosted TGB page if configured).

### Donor commits but no order appears
- Check `WooCommerce → Orders` for `payment_method = dfwc_crypto`. If absent, the pending-order REST POST may have failed.
- Check browser console + network tab on the donor's session.
- Confirm the campaign has a linked WC product (`wc_donation_product` meta key).

### Order stays at `on hold` forever
- TGB webhook isn't reaching us. Diagnostics will warn after 7+ days.
- Check `WooCommerce → Donations Companion → Diagnostics` for the **TGB webhook activity** check.
- Verify the webhook URL is correctly pasted into the TGB dashboard.
- Test webhook reachability manually: `curl https://<site>/wp-json/dfwc-companion/v1/tgb-webhook` should return `{"error":"bad_request"}` (400) — empty body. If it returns 404, the REST endpoint isn't registered (companion not booted, or crypto disabled).
- Check the site's firewall / security plugin for REST endpoint blocking. Wordfence, Sucuri, and similar sometimes whitelist `/wp-admin/admin-ajax.php` but block `/wp-json/*` from external IPs.
- Manually flip the order to `completed` in WC if you've verified the TGB-side donation is real and the webhook simply failed delivery.

### "Invalid signature" in webhook logs
- Webhook secret rotated in TGB but not updated in companion. Re-enter on the Crypto Donations page.
- `AUTH_KEY` rotated since the secret was stored. Companion's Token_Store can't decrypt the stored value. Re-enter the webhook secret.

### Diagnostic warns "Crypto is enabled but TGB credentials are missing or unreadable"
- Same `AUTH_KEY` rotation case. Re-enter both API key and webhook secret.

### Per-campaign project dropdown is empty
- Project list cache is empty. Click **Refresh project list** on the Crypto Donations settings page.
- If still empty, the TGB API call is failing — Test connection from the settings page.

## Filterable seams

For admins on hosted-private TGB deployments or testing TGB API revisions:

| Filter | Purpose | Default |
|---|---|---|
| `dfwc_companion_tgb_base_url` | TGB REST API base URL | `https://api.tgbwidget.com/v1` (prod) / sandbox equivalent |
| `dfwc_companion_tgb_widget_localize` | TGB widget JS URL + hosted-page fallback | `['widgetScriptUrlSandbox' => '', 'widgetScriptUrlProduction' => '', 'hostedUrl' => '']` |
| `dfwc_companion_tgb_webhook_signature_header` | Header name carrying the HMAC signature | `'X-TGB-Signature'` |
| `dfwc_companion_tgb_webhook_verify` | Custom signature verifier closure | `TGB_Webhook_Handler::verify_signature` (raw hex HMAC-SHA256) |
| `dfwc_companion_donation_submitted` | Phase 9 fire — listen here for cross-channel donor events | n/a (action) |

Example — Stripe-style timestamped signature header:

```php
add_filter( 'dfwc_companion_tgb_webhook_signature_header', fn() => 'X-TGB-Signature' );

add_filter( 'dfwc_companion_tgb_webhook_verify', function ( $default_result, $body, $signature, $secret ) {
    // Parse "t=<unix>,v1=<hex>" format and verify.
    // ... custom HMAC scheme here ...
    return $is_valid;
}, 10, 4 );
```

---

*This document covers v2.3.0. For the technical implementation plan, see [`plans/v2/crypto-tgb.md`](../plans/v2/crypto-tgb.md). For the v3.0.0 channel-extension API that crypto becomes part of, see [`plans/v2/v3-channel-api-and-extraction.md`](../plans/v2/v3-channel-api-and-extraction.md).*
