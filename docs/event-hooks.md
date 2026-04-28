# Event Hooks

> Available from **v1.1.0**. Six WordPress action hooks fire at key points in the donor flow. Wire your own analytics, CRM, or webhook listeners — the companion ships the events; you decide what to do with them.

The companion deliberately does **not** ship its own analytics dashboard or storage layer. ~95% of nonprofit users already pay for an analytics tool (GA4, Mixpanel, Plausible) and a CRM (FluentCRM, Mailchimp, Groundhogg). Surfacing well-documented hooks and integration recipes is higher leverage than reinventing those tools.

---

## Quick reference

| Hook | Fires when | Args |
|---|---|---|
| [`dfwc_companion_form_viewed`](#hook-form_viewed) | Donor sees the donation form | `$campaign_id`, `$context`, `$language` |
| [`dfwc_companion_interval_selected`](#hook-interval_selected) | Donor changes the active interval tab | `$campaign_id`, `$interval`, `$context`, `$language` |
| [`dfwc_companion_preset_selected`](#hook-preset_selected) | Donor clicks a preset amount button | `$campaign_id`, `$interval`, `$amount`, `$context`, `$currency`, `$language` |
| [`dfwc_companion_custom_amount_entered`](#hook-custom_amount_entered) | Donor types in the custom-amount input (after blur) | `$campaign_id`, `$interval`, `$amount`, `$context`, `$currency`, `$language` |
| [`dfwc_companion_donation_submitted`](#hook-donation_submitted) | Server-side success after parent's AJAX completes | `$campaign_id`, `$interval`, `$amount`, `$context`, `$currency`, `$language`, `$reason` |
| [`dfwc_companion_donation_failed`](#hook-donation_failed) | Server-side failure (parent rejected, gateway hiccup, etc.) | `$campaign_id`, `$interval`, `$amount`, `$context`, `$currency`, `$language`, `$reason` |

The first four ship as donor-side JS events that batch to a `track` REST endpoint. The last two fire directly in PHP at the form-submit boundary.

**Stability guarantee:** these signatures are stable through v1.x. v2.x will document migration paths if anything changes.

---

## Privacy posture

Every event passes through `Analytics\Privacy_Guard::sanitize_event` before any `do_action` fires. The Guard:

- Allow-lists event types, intervals, contexts, currency codes, and language codes.
- Replaces client-supplied timestamps with server time (defeats clock drift / replay).
- Caps the `$amount` field at $999,999,999 and rejects negatives / `INF`.
- Caps the `$reason` field at 200 chars and `sanitize_text_field`s it.
- **Never** accepts donor PII — even if a malicious client tries to POST `donor_email`, `ip`, `user_agent`, or `session_id`, those keys are silently dropped before `do_action`.

The Guard's interface is the public contract: any future v2.x analytics storage will run through it.

If your listener needs PII (CRM contact tagging, for example), pull it from your own source — `WC()->customer->get_email()` is the FluentCRM recipe's pattern. The companion's hooks are aggregate-only by design.

---

## Hook reference

### Hook: `form_viewed`

```php
add_action( 'dfwc_companion_form_viewed', function ( $campaign_id, $context, $language ) {
    // your listener
}, 10, 3 );
```

| Arg | Type | Description |
|---|---|---|
| `$campaign_id` | `int` | The `wc-donation` post ID. |
| `$context` | `string` | One of `single \| shortcode \| widget \| checkout \| cart \| cart_block \| block \| elementor \| preview \| unknown`. |
| `$language` | `string` | WPML active language code (e.g. `en`, `fr`, `pt-br`) or empty if WPML inactive / unknown. |

Fires once per `init` of each overlay on the page. Multi-instance pages (two shortcodes) emit two `form_viewed` events.

The `preview` context fires when admins inspect the live preview pane on the campaign edit screen. Filter on `$context !== 'preview'` if you want to exclude admin behavior from your analytics.

---

### Hook: `interval_selected`

```php
add_action( 'dfwc_companion_interval_selected', function ( $campaign_id, $interval, $context, $language ) {
    // your listener
}, 10, 4 );
```

| Arg | Type | Description |
|---|---|---|
| `$campaign_id` | `int` | |
| `$interval` | `string` | One of `one_time \| monthly \| annual \| weekly \| quarterly \| semiannual \| custom`. |
| `$context` | `string` | |
| `$language` | `string` | |

Fires every time the donor clicks (or arrow-key navigates) to a different interval tab. Use to track "consideration" depth before a donor selects an amount.

---

### Hook: `preset_selected`

```php
add_action( 'dfwc_companion_preset_selected', function ( $campaign_id, $interval, $amount, $context, $currency, $language ) {
    // your listener
}, 10, 6 );
```

| Arg | Type | Description |
|---|---|---|
| `$campaign_id` | `int` | |
| `$interval` | `string` | |
| `$amount` | `float` | The preset's amount in `$currency`. |
| `$context` | `string` | |
| `$currency` | `string` | ISO 4217 (e.g. `USD`, `GBP`). Reflects the donor's active WCML currency at click time. |
| `$language` | `string` | |

Fires on every preset click. Use to identify which presets drive the most engagement and inform Phase 6 per-currency tuning.

---

### Hook: `custom_amount_entered`

```php
add_action( 'dfwc_companion_custom_amount_entered', function ( $campaign_id, $interval, $amount, $context, $currency, $language ) {
    // your listener
}, 10, 6 );
```

Same args as `preset_selected`. Fires on **blur** (debounced after the donor finishes typing), not per keystroke — keeps event volume sane and matches analyst-grade granularity.

---

### Hook: `donation_submitted`

```php
add_action( 'dfwc_companion_donation_submitted', function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) {
    // your listener
}, 10, 7 );
```

| Arg | Type | Description |
|---|---|---|
| `$campaign_id` | `int` | |
| `$interval` | `string` | |
| `$amount` | `float` | The donor's chosen amount (preset OR custom — already resolved). |
| `$context` | `string` | |
| `$currency` | `string` | |
| `$language` | `string` | |
| `$reason` | `string` | Empty for success. |

Fires server-side after parent's AJAX handler completes successfully. **This is the conversion event** — the one most analytics dashboards care about. Wire GA4, Mixpanel, Zapier here.

---

### Hook: `donation_failed`

```php
add_action( 'dfwc_companion_donation_failed', function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) {
    // your listener
}, 10, 7 );
```

Same args as `donation_submitted`; `$reason` carries parent's failure message (sanitized + capped at 200 chars).

Useful for monitoring — alert when failure rate spikes, e.g. due to a gateway outage.

---

## Integration recipes

### Recipe A: Google Analytics 4 (Measurement Protocol)

Server-side event ingestion via GA4's Measurement Protocol. No client-side script tag needed; events flow GA4 → your dashboard within ~10 seconds.

**One-time setup**: in the GA4 admin, create a Measurement Protocol API secret. You'll need the `measurement_id` (`G-...`) and the `api_secret` you generated.

```php
add_action( 'dfwc_companion_donation_submitted',
    function ( $campaign_id, $interval, $amount, $context, $currency, $language ) {
        $payload = array(
            'client_id' => 'server.' . wp_hash( site_url() ),
            'events'    => array(
                array(
                    'name'   => 'donation_completed',
                    'params' => array(
                        'value'         => $amount,
                        'currency'      => $currency,
                        'campaign_id'   => $campaign_id,
                        'campaign_name' => get_the_title( $campaign_id ),
                        'interval'      => $interval,
                        'context'       => $context,
                        'language'      => $language,
                    ),
                ),
            ),
        );
        wp_remote_post(
            'https://www.google-analytics.com/mp/collect'
                . '?measurement_id=G-XXXXXXX'
                . '&api_secret=' . rawurlencode( 'YOUR_API_SECRET' ),
            array(
                'body'     => wp_json_encode( $payload ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'blocking' => false, // critical: don't block the donor's submit on this
                'timeout'  => 5,
            )
        );
    },
    10,
    6
);
```

**Verify in GA4 DebugView**: append `&debug_mode=1` to the URL while testing; events appear in real time.

---

### Recipe B: FluentCRM contact tagging

Tag donors in FluentCRM when they convert. The donor's email comes from WooCommerce's cart context (Woo populates it from checkout / billing fields).

```php
add_action( 'dfwc_companion_donation_submitted',
    function ( $campaign_id, $interval, $amount ) {
        if ( ! function_exists( 'FluentCrmApi' ) ) {
            return;
        }

        $email = '';
        if ( function_exists( 'WC' ) && WC()->customer ) {
            $email = WC()->customer->get_email();
        }
        if ( ! $email ) {
            return; // nothing to tag against
        }

        FluentCrmApi( 'contacts' )->createOrUpdate( array(
            'email' => $email,
            'tags'  => array(
                'donor',
                'donor-' . $interval,
                'campaign-' . $campaign_id,
            ),
        ) );
    },
    10,
    3
);
```

Sites running Groundhogg or Mailchimp follow the same pattern with their respective CRM API.

---

### Recipe C: Generic webhook (Zapier / Make / n8n / Uncanny Automator)

POST a JSON payload to any webhook URL. Use this for "I want events to flow into [whatever-the-org-already-uses]" without writing per-tool integration code.

```php
add_action( 'dfwc_companion_donation_submitted',
    function ( $campaign_id, $interval, $amount, $context, $currency, $language ) {
        wp_remote_post(
            'https://hooks.zapier.com/hooks/catch/12345/abcdef/',
            array(
                'body' => wp_json_encode( array(
                    'event'         => 'donation_submitted',
                    'campaign_id'   => $campaign_id,
                    'campaign_name' => get_the_title( $campaign_id ),
                    'interval'      => $interval,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'context'       => $context,
                    'language'      => $language,
                    'site_url'      => site_url(),
                    'ts'            => time(),
                ) ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'blocking' => false,
                'timeout'  => 5,
            )
        );
    },
    10,
    6
);
```

The same recipe wires Zapier, Make.com, n8n, or any HTTP-receiving endpoint. The payload schema is yours to define — the snippet above is a starting point.

---

## Performance considerations

**Always use `blocking => false` for `wp_remote_post` in donation hooks.** A blocking call adds the listener's HTTP latency to the donor's submit experience — a 2-second GA4 lookup turns into a 2-second donor-facing pause. Non-blocking calls fire-and-forget; the donor sees their cart immediately.

If a listener needs the response (rare — most analytics / CRM endpoints don't return useful data), defer it:

```php
add_action( 'dfwc_companion_donation_submitted',
    function ( $campaign_id, $interval, $amount ) {
        wp_schedule_single_event( time() + 10, 'my_deferred_donation_handler', array( $campaign_id, $interval, $amount ) );
    },
    10,
    3
);
add_action( 'my_deferred_donation_handler', function ( $campaign_id, $interval, $amount ) {
    // blocking call OK — runs out-of-band via WP-Cron
    $resp = wp_remote_post( '...', array( 'body' => '...', 'timeout' => 30 ) );
    // do something with $resp
}, 10, 3 );
```

---

## Donor-side rate limit

The donor-side track endpoint (`POST /wp-json/dfwc-companion/v1/track`) is **public** (donor analytics is fundamentally public-by-design — donors don't authenticate). To prevent abuse:

| Limit | Value |
|---|---|
| Events per request | 50 |
| Events per IP per minute | 100 |
| HTTP status when exceeded | 429 Too Many Requests |

The donor-side overlay JS buffers events for ~1 second between flushes, so a typical donor session triggers 5–10 events total — well under the limit. Sustained abuse hashes the IP and short-circuits at the transient layer.

---

## What's NOT a hook

The companion **does not** fire hooks for:

- Donor PII (name, email, IP, user agent, session ID) — never. If you need PII, source it yourself from the cart.
- Cart-side events (cart line item added, removed) — those are WooCommerce hooks; the companion doesn't re-emit them.
- Subscription renewal events (created, renewed, cancelled, failed) — those are subscription-engine hooks (`woocommerce_subscription_status_*` for WCS; `wps_sfw_*` for WPS SFW).

If you need any of these, hook into WooCommerce / the subscription engine directly. The companion's hooks scope to the donor-flow surface — initial donation submit and below.

---

## Troubleshooting

### Events aren't firing in PHP

1. Run `wp dfwc-companion health` — verify the parent plugin and engine checks all pass.
2. Verify the track endpoint is reachable: `curl -X POST https://your-site.test/wp-json/dfwc-companion/v1/track -d '{"events":[{"type":"form_viewed","campaign_id":1}]}' -H "Content-Type: application/json"`. Should respond 200.
3. Check browser DevTools → Network tab on a campaign page. Look for a POST to `/wp-json/dfwc-companion/v1/track` after you click presets / change tabs.
4. Confirm your listener is registered (try `error_log` inside it to verify it's hit when the event fires).

### `$amount` is 0 in `donation_submitted`

The amount field comes from the donor's POST. If it's 0 server-side, it means the overlay JS didn't write a value to parent's hidden price input — usually means a custom theme overrode the form structure and the overlay's preset write failed. Run the diagnostic page to confirm overlay assets are still registered.

### `$language` is empty

The language is read from the wrapper's `data-language` attribute, which Renderer populates from `WPML_Strings::current_language()` (when WPML is active) or `get_locale()`. If both fail, the field is empty — listeners should treat empty as "unknown".

---

## Listener execution context

Listeners run **synchronously** in the same request as the triggering action:

- The four donor-side events (`form_viewed`, `interval_selected`, `preset_selected`, `custom_amount_entered`) fire during the `track` REST request — the donor's browser POST.
- The two server-side events (`donation_submitted`, `donation_failed`) fire during the parent plugin's `donation_to_order` AJAX handler — the donor's submit POST.

Listeners run with the **caller's permission context** — typically a logged-out donor user. Listeners that need admin permissions should perform their own checks. Listeners that need outbound network calls must use `blocking => false` (see above) to avoid stalling the donor.

---

## Migration from third-party analytics plugins

If you're already using a plugin like Site Kit, MonsterInsights, or Independent Analytics and they don't track donations specifically:

1. Keep them — they cover page views and other analytics.
2. Add a small mu-plugin or theme-functions snippet using the recipes above.
3. The companion's hooks complement, not replace, those tools.

The companion's role is to fire the events; it's deliberately agnostic about where they go.
