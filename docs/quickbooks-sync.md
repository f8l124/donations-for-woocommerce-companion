# QuickBooks Online Sync

> Available from **v2.0.0**. Real-time per-donation Sales Receipt creation in QuickBooks Online. OAuth2 connection scoped to a single QBO realm; encrypted token storage; campaign-to-income-account mapping; async retry queue powered by Action Scheduler. Off by default — admins opt in via *WooCommerce → Donations Companion → Settings → QuickBooks Online sync*.

The sync listens on the standard `dfwc_companion_donation_submitted` Phase 9 hook. Cash donations sync. Stock donations (v1.3.0) sync. Crypto donations (v1.4.0) will sync the moment they ship — no per-channel wiring needed.

---

## Why this exists

Bookkeepers running QBO want every donation as a Sales Receipt **automatically**. The manual flow (export CSV from WC, import to QBO) is brittle: forgotten exports, currency mismatches, donor-name typos, account-mapping drift. Real-time sync also surfaces revenue dashboards in QBO without waiting on a month-end batch.

The companion does NOT replace QBO's reporting — it just makes sure every donation is in QBO when the bookkeeper looks. Account categorization is a single dropdown per campaign in our admin UI.

---

## Setup

### 1. Register an Intuit app

Each admin must register their own app at [developer.intuit.com](https://developer.intuit.com/). The plugin doesn't ship shared client credentials — keeps you off Intuit's app-review treadmill and avoids shared rate-limit pain.

Walk-through:

1. Sign in at developer.intuit.com.
2. **Dashboard → My Apps → Create an app → QuickBooks Online and Payments**.
3. Name it (e.g., "My Org Donations Sync"). Pick **com.intuit.quickbooks.accounting** as the only scope.
4. Under **Keys & OAuth**, copy the **Client ID** and **Client Secret** for either Development or Production keys.
5. Under **Redirect URIs**, paste the value the companion shows in *Settings → QuickBooks Online sync* — typically:
   ```
   https://your-site.example/wp-json/dfwc-companion/v1/qbo-oauth-callback
   ```

### 2. Configure the companion

*WooCommerce → Donations Companion → Settings → QuickBooks Online sync:*

1. **Enable QuickBooks sync** — turn on.
2. **Environment** — Sandbox while you verify; Production for real syncing. Switching environments invalidates any stored tokens — you'll need to reconnect.
3. **Client ID** — paste from Intuit dashboard step 4.
4. **Client secret** — paste from Intuit dashboard step 4. Stored encrypted at rest; redacted in the admin UI on subsequent visits.

Save.

### 3. Connect the QBO realm

Visit *Donations Companion → QuickBooks Sync*. Click **Connect to QuickBooks**. You'll be redirected to Intuit; sign in, pick your QBO company, consent. Intuit redirects back to your site with an auth code; the companion exchanges it for a token bundle and stores it encrypted in `wp_options`.

The page now shows: **Connected to <Org Name>** + the realm ID + the connect timestamp.

### 4. Map campaigns to QBO income accounts

Same page, **Account mapping** section:

- **Default (fallback)** — pick the income account every unmapped campaign uses.
- **Per campaign** — override the default for specific campaigns (e.g., "School Sponsorship" → "Donations - Restricted - Education").

Save mapping. From this point onward, every new donation flows to the right QBO account automatically.

---

## How sync works

### Listener path

```
donor submits donation (cash / stock / crypto)
        ↓
parent plugin / Stock_Pledge_Handler / TGB_Webhook_Handler
        ↓
do_action( 'dfwc_companion_donation_submitted', ... )
        ↓
QuickBooks\Donation_Listener
        ↓
Sync_Queue::enqueue( $job )            ← writes Action Scheduler row
        ↓
Action Scheduler invokes Sync_Handler::process( $job )    ← async, immediate
        ↓
API_Client::create_sales_receipt
        ↓
QBO Sales Receipt created
```

The donor-facing flow never blocks on QBO. Submit_Guard returns success the moment the donation lands; sync runs in the background and won't slow donor pages even when QBO is down.

### Retry policy

Failures route through `Sync_Handler::is_retryable`:

| HTTP status / error | Retry? |
|---|---|
| 5xx server errors | yes |
| 408 / 429 (rate-limited) | yes |
| 401 (token expired) | API client auto-refreshes once + retries; second 401 → fail |
| 400-403, 404, 422 | no — payload problem; logged + admin notification |
| Network-layer (cURL timeout, DNS, etc.) | yes |

Backoff: 60s → 5m → 15m → giving up. After 3 retries the job is marked failed and surfaces in the admin sync log + the diagnostic check `qbo_sync_health`.

### Retries vs idempotency

QBO's create-sales-receipt endpoint has no built-in idempotency key, so retries that succeed AFTER a prior duplicate-write would create two receipts. In practice:

- The 401 → auto-refresh path is single-retry; risk is bounded.
- 5xx → backoff retries hit the same endpoint; if the original 5xx came after QBO actually persisted the receipt (rare but possible), the retry creates a duplicate.

The bookkeeper's safety net: every receipt has a `PrivateNote` of the form `dfwc-companion campaign=<id> context=<ctx>`. A monthly query in QBO for `PrivateNote like 'dfwc-companion%'` plus a `TxnDate` group-by surfaces dupes for manual cleanup. Acceptably rare for v2.0.0; optional idempotency keys via QBO's webhook reconciliation are roadmap for v2.1.

---

## Privacy posture

The sync surface follows the Phase 9 hook contract: **aggregate-only data flows to QBO**. The Sales Receipt payload contains:

- Campaign title (admin-supplied; no donor PII)
- Donation amount + currency
- Income account ID
- Transaction date
- Private note carrying campaign ID + context

The Sales Receipt does NOT include the donor's name, email, IP, address, or any other identifier. Admins who want named-customer receipts can layer that on via the `dfwc_companion_qbo_payload` filter — see [Filters](#filters) below.

---

## Filters

### `dfwc_companion_qbo_payload`

Mutate the Sales Receipt payload before submit. Useful when you want named customers, classes, locations, or custom memo lines.

```php
add_filter( 'dfwc_companion_qbo_payload', function ( $payload, $job ) {
    // Always tag receipts with a "Donations" class for QBO Class Tracking.
    $payload['Line'][0]['SalesItemLineDetail']['ClassRef'] = array(
        'value' => '7',  // QBO class ID for "Donations"
    );
    return $payload;
}, 10, 2 );
```

### `dfwc_companion_contracts`

Add diagnostic checks. Phase 15 already adds `qbo_connection` and `qbo_sync_health`; third-party listeners can add their own QBO-specific checks (e.g., Slack alerting on failed sync count).

---

## Threat model

### Token storage

**In scope:** an attacker who reads `wp_options` via SQLi, a leaked SQL backup, or a misconfigured backup-as-zip download must not get plaintext access tokens.

Implementation: tokens stored AES-256-CBC encrypted in `wp_options` under `dfwc_qbo_oauth_tokens`. Encryption key derived from `AUTH_KEY . AUTH_SALT . 'dfwc-qbo-token-store'` via SHA-256. Per-write random IV prefixed onto ciphertext. Without `AUTH_KEY` the ciphertext is indistinguishable from random.

**Out of scope:** an attacker with full filesystem read on the WP install. They can read `AUTH_KEY` from `wp-config.php` and decrypt; defending against that requires a real KMS, which is out of scope for a self-hosted plugin. Recommendation if your deployment makes that threat real (shared filesystem hosting, etc.): use a third-party secrets manager and inject `AUTH_KEY` from there.

### OAuth callback CSRF

`OAuth_Client::authorize_url()` mints a 32-char random state nonce, stores it in a 10-minute transient keyed by the admin user ID, and includes it in the Intuit redirect URL. `OAuth_Client::consume_state()` verifies the state on callback and burns the transient (single-use).

If an attacker tricks an admin into visiting `…/qbo-oauth-callback?code=evil&state=guessed`, the state won't match a stored nonce → callback returns an error notice → no token is exchanged. Rate of nonce collision is `2^-128` per attempt; not exploitable.

### Webhook spoofing

QBO's REST API is initiated from us — not webhook-driven for v2.0.0. There's no inbound surface to spoof. (Future v2.1+ if QBO webhook reconciliation lands: HMAC-SHA256 signature verification, same pattern as the Overflow webhook in v1.3.0.)

### Refresh token exposure

Refresh tokens are also encrypted at rest. The disconnect flow calls Intuit's revoke endpoint to invalidate the refresh token server-side too — even if the local DB is later compromised, the leaked refresh token won't grant access tokens.

---

## Diagnostics

`Donations Companion → Diagnostics` adds two checks (Phase 15):

### `qbo_connection`

- **Off** when QBO sync is disabled (silent pass)
- **Warn** when sync is enabled but no tokens are stored ("Open QuickBooks Sync and click Connect")
- **Warn** when refresh token is within 10 days of expiry ("Reconnect soon")
- **Pass** otherwise — shows connected company name + days remaining

### `qbo_sync_health`

- **Off** when QBO sync is disabled (silent pass)
- **Warn** when more than 5 sync failures in the last 24 hours
- **Pass** otherwise — shows recent failure count

CLI: `wp dfwc-companion health` includes both checks in its output.

---

## Manual re-sync

Useful when:

- You connected QBO mid-month and want to backfill.
- A sync gap from a transient outage left a few donations un-synced.
- You're moving from another QBO sync tool and want historical data in QBO.

```bash
# Re-enqueue donations from the last 30 days for ALL campaigns.
wp dfwc-companion qbo-sync

# Restrict to a single campaign + look back 90 days.
wp dfwc-companion qbo-sync --campaign=123 --days=90

# Preview without enqueueing.
wp dfwc-companion qbo-sync --dry-run
```

The CLI command reads completed/processing WC orders, matches `_dfwc_companion_campaign_id` post meta, and enqueues identical jobs to those the runtime listener creates. Bypasses dedup — running it twice creates two QBO receipts per donation. Run intentionally.

---

## Troubleshooting

### "QuickBooks API returned 400: Invalid object reference"

Almost always means the configured Income Account ID was deleted or merged in QBO. Visit *QuickBooks Sync* → re-pick the Income Account → save mapping. Failed jobs in the queue retry on the new mapping automatically.

### "Sync exceeded 3 retries; giving up"

Visit *QuickBooks Sync* → Recent activity panel for the underlying error. Common causes:

- Invalid client_id / client_secret → re-paste in Settings.
- Org's QBO subscription expired → renew at intuit.com.
- A hard 4xx that retries can't recover (e.g., reusable-line-item violation; only a payload change fixes it).

The companion does NOT auto-retry permanent failures. Once the underlying problem is fixed, run `wp dfwc-companion qbo-sync --days=N` to backfill.

### "OAuth state mismatch"

You completed consent more than 10 minutes after clicking Connect. State transients expire after 10 minutes. Click Connect again and finish the flow within the window.

### Webhook secret / firewall

Not applicable to v2.0.0 — sync is outbound-only. If your firewall blocks outbound calls to `*.api.intuit.com`, allow-list those subdomains.

---

## Storage shape

### Tokens (`dfwc_qbo_oauth_tokens` option)

```php
[
    'access_token_enc'    => '<base64 IV+ciphertext>',
    'refresh_token_enc'   => '<base64 IV+ciphertext>',
    'access_expires_at'   => 1735689600,
    'refresh_expires_at'  => 1744323200,
    'realm_id'            => '4620816365316892901',
    'company_name'        => 'Example Nonprofit Inc',
    'connected_at'        => 1735603200,
]
```

### Account mapping (`dfwc_qbo_account_mapping` option)

```php
[
    '42'      => '101',  // campaign 42 → income account 101
    '99'      => '102',
    '_default' => '999', // unmapped campaigns → income account 999
]
```

### Sync log (`dfwc_qbo_sync_log` option)

Bounded ring-buffer of up to 50 most-recent entries:

```php
[
    [
        'ts'          => 1735689632,
        'status'      => 'success',  // 'success' | 'error' | 'failed' | 'deferred'
        'message'     => 'Synced as Sales Receipt 1234',
        'campaign_id' => 42,
        'amount'      => 25.0,
        'attempt'     => 0,
        'receipt_id'  => '1234',
    ],
    // ...
]
```

`uninstall.php` (when *Preserve data on uninstall* is off) wipes all three options. Tokens are also revoked at Intuit before local clear during a normal Disconnect.

---

## Multi-currency

QBO's Sales Receipt accepts a `CurrencyRef` field. The companion sets it from the donation's currency (`USD`, `GBP`, etc.). Per-currency Income Account mapping is **not** in v2.0.0 — every campaign maps to one account regardless of donor currency. Roadmap for v2.1+.

---

## What this doesn't do (yet)

- **Refunds.** Refund handling is out of scope for v2.0.0 MVP. WC refunds don't flow to QBO; bookkeepers reconcile manually. Roadmap for v2.1.
- **Per-currency account mapping.** All currencies for a campaign route to one income account. Roadmap for v2.1.
- **Customer creation.** Receipts post as anonymous donor records. Use the `dfwc_companion_qbo_payload` filter if you want named-customer flow.
- **Webhook reconciliation.** QBO supports webhooks for inbound notifications; we don't subscribe (no use case in scope). Could enable smarter idempotency in v2.1+.
- **Multi-realm (multi-org).** One QBO realm per WP install. Sites that admin multiple orgs need one WP install per realm.
