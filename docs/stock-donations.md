# Stock Donations

> Available from **v1.3.0**. Two modes for accepting donations of appreciated securities, picked per-site by the admin:
>
> 1. **Built-in pledge form** — the donor fills a form on your campaign page, the companion captures the pledge, emails the donor your DTC transfer instructions, and tracks the pledge through to receipt.
> 2. **Overflow integration** — the donor clicks through to your hosted [Overflow](https://overflow.co/) page where the entire transfer is automated; Overflow notifies the companion via webhook when the donation completes.

Both modes are off by default. Enabling either does NOT enable the other; pick the one that matches your operational model.

---

## Why this exists

Stock gifts are a major giving channel for nonprofits — a 2024 Bridgespan study found ~70% of donor wealth in the U.S. is held in non-cash assets. But the donor flow is process-not-instant: the donor *pledges* the gift, then their broker transfers shares to the org's brokerage, which can take days or weeks. The companion's role is to capture the pledge cleanly, send the donor what they need to forward to their broker, and track the pledge through to receipt — at which point the standard `dfwc_companion_donation_submitted` hook fires so QBO sync, FluentCRM tagging, GA4 events, etc. all see stock donations as first-class donations alongside cash.

Two modes exist because two distinct operational models exist:

- **Pledge form mode** is right for orgs that already have a brokerage account, can publish DTC instructions, and want stock gifts to flow through their own infrastructure (no third-party fees).
- **Overflow mode** is right for orgs that want a fully managed flow with KYC, tax-receipt generation, and broker-side automation handled by a SaaS provider — at the cost of Overflow's per-transaction fee.

---

## Mode 1: built-in pledge form

### Donor flow

1. Donor visits a campaign page. Alongside the cash-donation tabs, a **"Donate stock"** toggle appears.
2. Donor expands the panel. An inline form asks for: their name + email + phone, broker name, ticker symbol, share count, estimated value, optional notes.
3. Donor submits. The companion records the pledge as `pledged`, sends the donor a confirmation email containing your org's DTC transfer instructions, sends an admin notification, and shows a success state on-page.
4. The donor's broker initiates the share transfer. Days or weeks later, your brokerage account receives the shares.
5. An admin marks the pledge `received` with the actual transfer value and date. The standard `dfwc_companion_donation_submitted` hook fires with `$context = 'stock'` and the actual value — your QBO sync, CRM listeners, analytics, etc. all pick it up.

### Setup

*WooCommerce → Donations Companion → Settings → Stock donations:*

1. **Enable stock donations** — turn on.
2. **Mode** — choose **Built-in pledge form**.
3. **Brokerage name** — your org's brokerage (e.g., "Charles Schwab Charitable").
4. **DTC account number** — your account at the brokerage.
5. **DTC clearing house number** — the standard DTC routing number for your brokerage.
6. **Org tax ID (EIN)** — appears on the donor confirmation for tax-receipt context.
7. **Admin notification email** — where pledge alerts go (defaults to the WP admin email if blank).

Save. The donor-side toggle now appears on every campaign with a configured donation form.

### Reconciliation

*WooCommerce → Donations Companion → Stock Pledges* lists every pledge with status filter (Pledged / In transit / Received / Cancelled).

Click a pledge to see the full record. Two forms on the edit screen:

- **Mark received** — the primary path. Enter the actual transfer value (FMV at transfer date) and the date the shares cleared, optional admin notes. This fires the Phase 9 hook.
- **Update status** — for transitional bookkeeping (`In transit` once you receive broker confirmation but before clearance, or `Cancelled` if the donor backs out). Doesn't fire the donation hook.

Use **Mark received**, not **Update status**, when shares actually arrive — the hook firing is the moment your downstream systems treat the donation as real.

### Tax receipt

The companion does not generate IRS tax receipts. The donor confirmation email captures the legal pledge details (donor name, ticker, share count, estimated value, transfer date). Your org's standard receipt-generation process — letter, PDF, dedicated receipt platform — should reference these.

When the pledge moves to `received`, your standard tax-receipt flow can fire off the `dfwc_companion_donation_submitted` hook in PHP:

```php
add_action( 'dfwc_companion_donation_submitted', function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) {
    if ( 'stock' !== $context ) {
        return;
    }
    // $amount is the actual transfer value at receipt date.
    // Read the pledge meta off the linked pledge post for ticker, shares, donor email, etc.
    // Fire your receipt template here.
}, 10, 7 );
```

For the donor's email + ticker + shares, query for the most recently `received` pledge on `$campaign_id`.

### Reading pledge data

```php
use DFWC\Companion\Stock\Stock_Pledge_Handler;

$pledge = Stock_Pledge_Handler::get_pledge( $pledge_id );
// $pledge['donor_email'], $pledge['ticker'], $pledge['shares'],
// $pledge['estimated_value'], $pledge['actual_value'], $pledge['received_at']
```

---

## Mode 2: Overflow integration

### Donor flow

1. Donor visits a campaign page. Instead of the inline form, the stock panel shows a single **"Donate stock via Overflow"** button.
2. Donor clicks. They're routed to your configured Overflow page (with `?utm_source=dfwc-companion&campaign_id=N` appended for attribution).
3. Donor completes the gift on Overflow — share selection, broker auth, signing, etc., all handled by Overflow's UX.
4. Overflow processes the transfer. When the donation completes, Overflow sends a webhook to your site at `/wp-json/dfwc-companion/v1/overflow-webhook`.
5. The webhook handler verifies the HMAC signature, creates a `received` pledge record, and fires `dfwc_companion_donation_submitted` with `$context = 'stock'`. Your downstream systems pick it up exactly as they would in pledge-form mode.

### Setup

*WooCommerce → Donations Companion → Settings → Stock donations:*

1. **Enable stock donations** — turn on.
2. **Mode** — choose **Overflow integration**.
3. **Overflow URL** — the public URL of your Overflow donation page.
4. **Webhook secret** — paste the HMAC signing secret Overflow gave you (under your Overflow dashboard's webhook settings). Keep this private.

In your Overflow dashboard:

5. **Webhook endpoint** — set to `https://your-site.example/wp-json/dfwc-companion/v1/overflow-webhook`.
6. **Webhook signing key** — must match the secret pasted in step 4.

Save both sides. Overflow will send a test ping; the companion's webhook handler accepts only signed payloads, so unsigned tests should 403.

### Webhook security

The handler uses HMAC-SHA256 over the raw request body:

- The signature header (Overflow sends it as `X-Overflow-Signature` or similar — consult Overflow's docs for current header name; the handler tries multiple known header names) is compared via `hash_equals` for constant-time equality.
- An unverified payload returns HTTP 403.
- The handler is idempotent: each Overflow donation has a unique `transaction_id` (or `donation_id` / `id`, depending on Overflow's payload version), and the companion stores it in `_dfwc_stock_pledge_overflow_id` post meta. A replay of the same webhook is recognized and ignored without firing the donation hook a second time.

If Overflow disables your webhook for repeated 4xx responses, check your site's error log for `dfwc_overflow_webhook_*` entries. Common causes:

- Webhook secret mismatch (pastebin vs Overflow dashboard).
- WP firewall stripping the signature header (Cloudflare, Wordfence) — allow-list it.
- Stock donations toggled off in companion settings — the webhook returns 404 (not 403) when the feature is disabled, so Overflow's retry logic backs off cleanly.

### What if I want both modes simultaneously?

You can't. The mode selector is exclusive — picking one removes the other from the donor flow. If you want a "donor-chooses-channel" UX, build it outside the companion (e.g., a static page that links to both your campaign page and your Overflow page).

---

## Phase 9 hook integration

Stock donations are first-class donations once received. Two hooks fire in the stock flow:

### `dfwc_companion_stock_pledge_created`

Fires when a donor submits the pledge form (mode 1 only — Overflow mode skips this since the gift is already real when the webhook arrives). Listeners can use this to:

- Push a "pending pledge" entry to your CRM.
- Slack the development team that a stock pledge came in.
- Pre-populate a custom donor portal.

```php
add_action( 'dfwc_companion_stock_pledge_created', function ( $pledge_id, $campaign_id, $donor_email_hashed ) {
    // $donor_email_hashed is sha256(lowercase(trim(email))) — no raw PII through Phase 9 hooks.
}, 10, 3 );
```

### `dfwc_companion_donation_submitted`

The standard Phase 9 donation hook. Fires:

- In pledge-form mode: when an admin runs **Mark received** on a pledge.
- In Overflow mode: when a verified Overflow webhook arrives.

```php
add_action( 'dfwc_companion_donation_submitted', function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) {
    if ( 'stock' !== $context ) {
        return;
    }
    // $amount is the actual transfer value (mode 1) or Overflow's reported value (mode 2).
    // $interval is always 'one_time' for stock.
}, 10, 7 );
```

The contract is identical to cash donations. Listeners that already consume `donation_submitted` for cash donations get stock donations for free; only listeners that want to *exclude* stock from their flow need to check `$context`.

---

## Privacy posture

Stock donations carry more donor PII than cash (broker name, full donor name, phone) because the operational flow requires it — your team needs to be able to email the donor's broker if shares get stuck. The companion's privacy posture for this PII:

- Donor PII is stored in WP post meta on the `dfwc_stock_pledge` CPT, accessible only to admins with the `manage_options` capability.
- The Phase 9 `dfwc_companion_stock_pledge_created` hook receives a sha256 hash of the donor email, never the raw value.
- The standard `dfwc_companion_donation_submitted` hook receives `context='stock'` and the donation amount but no donor identifiers — same posture as cash.
- Overflow mode: the donor's PII lives in Overflow's system; the companion only stores Overflow's donation_id + the amount + currency.

If your downstream listeners (CRM tagging, email automation) need the raw donor email, read it from the pledge post meta in your hook callback rather than expecting it to flow through the hook arguments.

---

## Storage

### Pledge custom post type

`dfwc_stock_pledge` — admin-only CPT. Each pledge is a discrete record with status tracked in the `_dfwc_stock_pledge_status` meta key:

```
pledged → in_transit → received  (terminal)
              ↘
               cancelled
```

The post's `post_status` stays `publish` throughout — status workflow is meta-driven so the standard WP_Query surface keeps working without filtering.

Meta keys:

| Key | Type | Notes |
|---|---|---|
| `_dfwc_stock_pledge_donor_name` | string | Required at create |
| `_dfwc_stock_pledge_donor_email` | string | Required at create |
| `_dfwc_stock_pledge_donor_phone` | string | Optional |
| `_dfwc_stock_pledge_broker_name` | string | Required at create |
| `_dfwc_stock_pledge_ticker` | string | Uppercased; regex `^[A-Z]{1,5}(\.[A-Z]{1,2})?$` |
| `_dfwc_stock_pledge_shares` | float | (0, 1,000,000] |
| `_dfwc_stock_pledge_estimated_value` | float | Donor's estimate at pledge time |
| `_dfwc_stock_pledge_actual_value` | float | Set at receipt |
| `_dfwc_stock_pledge_received_at` | int | Unix timestamp |
| `_dfwc_stock_pledge_campaign_id` | int | Linked `wc-donation` post ID |
| `_dfwc_stock_pledge_status` | string | `pledged` \| `in_transit` \| `received` \| `cancelled` |
| `_dfwc_stock_pledge_donor_notes` | string | Optional, capped at 2000 chars |
| `_dfwc_stock_pledge_admin_notes` | string | Admin-set during reconciliation |
| `_dfwc_stock_pledge_overflow_id` | string | Mode 2 only — Overflow's external donation ID, used for idempotency |

### Global settings (in `dfwc_companion_global_settings`)

```php
[
    // ... other v1.x settings ...
    'stock_donations_enabled'         => false,
    'stock_giving_mode'               => 'pledge_form',  // 'pledge_form' | 'overflow'
    'stock_broker_name'               => '',
    'stock_dtc_account_number'        => '',
    'stock_dtc_clearing_house_number' => '',
    'stock_admin_email'               => '',
    'stock_tax_id'                    => '',
    'stock_overflow_url'              => '',
    'stock_overflow_webhook_secret'   => '',  // sensitive; redacted in admin UI on save
]
```

`uninstall.php` wipes both the option and all `dfwc_stock_pledge` posts when *Preserve data on uninstall* is off.

---

## Edge cases

### Donor submits the same stock pledge twice

The companion doesn't dedupe by ticker+shares+email — duplicate pledges are accepted and create two records. Admins reconcile manually if needed (mark one cancelled). Reasoning: a donor *might* legitimately pledge the same stock twice (e.g., an additional gift on top of a prior one); silently dropping the second is worse than letting the admin sort it out.

### Donor's broker partially fills the transfer

Shares received < shares pledged. Mark the pledge `received` with the actual value of the partial transfer; create no new pledge. Document the shortfall in admin notes.

### Donor ticker symbol is non-standard (foreign exchange, OTC)

The ticker regex permits 1–5 letters + optional dotted suffix (e.g., `BRK.B`). Truly foreign tickers (`7203` for Toyota in Tokyo) are rejected; the companion targets U.S. equity transfers via DTC. If your org accepts foreign stock gifts, route those donors to Overflow mode (which handles the broader market) or out-of-band.

### Overflow webhook arrives before our settings have the secret

Returns HTTP 403. Overflow retries; once you save the secret, the next retry succeeds. No data loss as long as you save the secret within Overflow's retry window (typically 24h).

### Admin renames the stock_giving_mode after pledges already exist

Existing pledges keep their data. The donor-side UI changes immediately to match the new mode; existing pledges in the admin list are unaffected. You can switch mid-stream without losing reconciliation data.

### Multi-currency campaigns (Phase 6)

Stock donations always denominate in the parent plugin's base currency. If your site accepts donations in GBP/EUR for cash, stock donations still post in USD (or whatever your parent's base currency is). The receipt FMV is in base currency; the amount that flows to QBO sync (Phase 15+) is in base currency. The donor never sees a stock-side currency selector — they entered shares + estimated USD value, not a currency choice.

---

## Verification

```bash
composer check          # PHPCS / PHPStan / PHPUnit all green

# Manual mode 1 test:
# 1. Configure DTC details in Stock Settings.
# 2. As a donor, submit a $5,000 / 50-share pledge via the campaign page form.
# 3. Verify pledge appears in Donations Companion → Stock Pledges in Pledged status.
# 4. Verify donor + admin emails arrive.
# 5. Click pledge → Mark received → enter $5,247 actual value.
# 6. Verify status flips to Received.
# 7. Verify dfwc_companion_donation_submitted fired (e.g., via a debug listener).

# Manual mode 2 test (sandbox):
# 1. Configure Overflow URL + webhook secret.
# 2. Trigger a sandbox donation via Overflow's test mode.
# 3. Verify webhook hits /wp-json/dfwc-companion/v1/overflow-webhook with 200.
# 4. Verify a new received-status pledge appears in admin.
# 5. Verify dfwc_companion_donation_submitted fired with context='stock'.
```

---

## What this doesn't do

- **Doesn't generate IRS tax receipts.** Pledge data feeds your existing receipt flow; the receipt template itself is your responsibility.
- **Doesn't talk to broker APIs directly.** Mode 1 is form + email — the actual share transfer is donor-initiated through their broker. Direct broker integrations are a multi-broker compliance surface that's out of scope.
- **Doesn't auto-mark pledges received.** In mode 1 the admin is the source of truth on "did the shares arrive?" Auto-detection would require live brokerage API access (out of scope per above).
- **Doesn't track donor cost basis.** IRS rules say stock-gift receipts use FMV at transfer date; the donor's cost basis is the donor's tax-prep concern, not the org's.
- **Doesn't accept crypto.** Crypto donations ship in v1.4.0 via The Giving Block integration. Roadmap separately.
