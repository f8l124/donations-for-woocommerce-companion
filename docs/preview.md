# Live Admin Preview Pane

> Available from **v0.9.0**. Render the donor-facing form into an iframe alongside the admin config UI. Debounced 350ms updates, viewport / engine / language / currency simulation, and triple-defense to prevent the preview from ever submitting a real donation.

The Phase 8 problem the preview solves: admins editing a campaign or a template had to save → visit the frontend → eyeball the donor form → return to the edit screen → make changes → save again → refresh frontend → repeat. The preview pane collapses this cycle into a live, in-place rendering below the meta box.

```
┌─ Campaign edit screen ─────────────────────────────────────────┐
│                                                                │
│  Title: [School Sponsorship Campaign                       ]   │
│                                                                │
│  ┌─ Interval-First Donation Form ───────────────────────────┐  │
│  │  ○ One-time   ● Monthly   ○ Annually                    │  │
│  │  Presets:  $25  $50  $100                               │  │
│  │  Min: 5     Max: 1000                                   │  │
│  │  Impact: "Provides school supplies"                     │  │
│  └─────────────────────────────────────────────────────────┘  │
│                                                                │
│  ┌─ Live preview ─────────── [Desktop] [Tablet] [Mobile] ───┐  │
│  │                                                           │  │
│  │      ○ One-time     ● Monthly     ○ Annually              │  │
│  │      ─────────────────────────────────────────            │  │
│  │      ○ $25  ●$50  ○ $100                                  │  │
│  │           Provides school supplies                        │  │
│  │      [   Donate $50/month   ]                             │  │
│  │                                                           │  │
│  └───────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

---

## Where the preview appears

Three admin screens host the preview pane:

| Screen | Where in WP Admin |
|---|---|
| Campaign edit screen | Below the "Interval-First Donation Form" meta box on individual `wc-donation` posts |
| Templates page edit form | At the bottom of the *Donations Companion → Templates → Edit* form |
| Settings page | Below the Settings form at *Donations Companion → Settings* (renders against the global defaults) |

Each instance shares the same `Frontend\Preview_Renderer` and the same overlay JS that runs on donor pages — pixel-faithful results.

---

## Toolbar

Each preview pane has a toolbar with these controls:

### Viewport simulation

`[Desktop] [Tablet] [Mobile]` buttons clamp the iframe's max-width:

| Button | Width clamp |
|---|---|
| Desktop | unconstrained (matches admin column) |
| Tablet | 768px max |
| Mobile | 375px max |

The actual donor experience at each viewport is rendered (responsive CSS in `dfwc-overlay.css` reflows the layout). Use this to verify your impact-display mode works at narrow widths before saving.

### Engine simulation

`[Auto ▾]` dropdown:

| Option | Behavior |
|---|---|
| Auto *(default)* | Use whichever engine is actually active on the site |
| WC Subscriptions | Force-render as if WCS were the engine |
| Subscriptions for WooCommerce | Force-render as if WPS SFW were the engine |
| No engine | Force-render as if no engine were active (recurring tabs disabled) |

Lets admins verify how the form degrades across engine states without juggling test sites.

### Language (when WPML active)

`[Language ▾]` dropdown shows every language WPML reports as active. Selecting a language re-renders the preview with `do_action( 'wpml_switch_language', $lang )` wrapped around the render call — so admins can verify translations without leaving the admin.

Hidden when WPML isn't loaded.

### Currency (when WCML active)

`[Currency ▾]` dropdown shows every currency WCML has enabled. Pass-through for Phase 6's per-currency presets — selecting GBP renders the preview with GBP-currency presets resolved.

Hidden when WCML isn't loaded.

---

## How it renders

`Admin\Preview_Controller` enqueues `assets/js/dfwc-admin-preview.js` and `assets/css/dfwc-admin-preview.css` on the three admin screens. The JS:

1. Watches for changes to any input inside the meta box / template form / settings form.
2. Debounces 350ms — admins typing in fields don't trigger one render per keystroke.
3. After debounce, serializes the form via `serializeConfig()` — walks every input's `name` attribute and rebuilds the nested config tree.
4. POSTs to `/wp-json/dfwc-companion/v1/preview` with the serialized config + toolbar state (viewport, engine, language, currency).
5. The REST endpoint sanitizes the inbound config via `Validation\Template_Validator`, calls `Frontend\Preview_Renderer::render()`, returns the rendered HTML.
6. The JS writes the response into the iframe using `srcdoc` — so the iframe's HTML is fully self-contained (no external network request from the iframe; works offline; survives page CSP).

Same overlay JS that runs on donor pages also runs in the preview iframe (injected inline). The preview is a **pixel-faithful** rendering, not a "preview-only" approximation.

---

## Mock-parent scope

`Frontend\Preview_Renderer` produces a snippet that mocks parent's `.wc-donation-in-action` scope so the same overlay JS mounts identically:

```html
<div class="dfwc-overlay dfwc-preview ..." data-dfwc-overlay-target data-preview="1" ...>
    <div class="wc-donation-in-action dfwc-preview__scope">
        <h2 class="campaign-title">(Preview campaign title)</h2>
        <div class="block-campaign-thumbnail">[image placeholder]</div>
        <div class="row2">
            <h3 class="wc-donation-title">Select Cause</h3>
            <select class="dfwc-preview__cause-select" disabled>
                <option>— Preview cause —</option>
            </select>
        </div>
        <div class="row1 dfwc-preview__row1">
            <select class="dfwc-preview__amount-select" disabled>
                <option>--Please select--</option>
            </select>
        </div>
        <input type="hidden" name="wc-donation-price" value="">
        <input type="hidden" name="wc-donation-cause" value="">
        <input type="hidden" class="wc_donation_camp" value="0">
        <input type="hidden" class="wp_rand" value="preview">
        <input type="checkbox" class="donation-is-recurring" style="display:none">
        <button type="button" class="wc-donation-f-submit-donation dfwc-preview__submit" disabled>
            Donate
        </button>
    </div>
</div>
```

This is everything the overlay JS expects to find on a real donor page. The overlay locates `.row1`, hides it, mounts our 3-tab UI in its place, and writes preset / amount / interval state to the hidden inputs — exactly as it does on the donor frontend.

---

## Defense in depth on submit

Three independent layers prevent preview HTML from ever submitting a real donation:

### Layer 1 — Server-side `Submit_Guard`

If the donor's POST carries `dfwc_preview=1`, parent's AJAX action is rejected with HTTP 422 before parent processes anything. The flag is set by `Preview_Renderer` in the iframe HTML; it should never reach a donor-side submit, but if someone scrapes the preview HTML and tries to submit it via curl, the server-side guard catches it.

```php
// includes/Frontend/Submit_Guard.php
if ( ! empty( $_POST['dfwc_preview'] ) ) {
    $this->reject( __( 'Preview submissions are not accepted. Reload the donor form and try again.', 'dfwc-companion' ) );
}
```

### Layer 2 — Overlay JS short-circuit

The overlay JS sees `data-preview="1"` on the wrapper and:

- Disables the submit button (`disabled` attribute)
- Adds a click handler that swaps in a "Preview only — donations cannot be submitted from this view." toast on click
- Injects a hidden `dfwc_preview=1` field so even if the click bypasses the JS handler, the server-side guard still fires

### Layer 3 — Iframe HTML

The mock submit button ships with `disabled` already set in the rendered HTML. So even before the overlay JS runs, the donor (or admin) can't accidentally click it.

All three layers are independent — knocking out one still leaves two protections.

---

## REST endpoint

`POST /wp-json/dfwc-companion/v1/preview`

| Aspect | Value |
|---|---|
| Permission | `manage_woocommerce` capability required |
| Rate limit | 10 requests per second per user (transient-backed) |
| Cache | `Cache-Control: no-store` |
| Inputs | `config` (full template/campaign config object), `engine`, `currency`, `language`, `viewport` |
| Output | `{ html: '<!DOCTYPE>...', timestamp: ..., engine: ..., currency: ... }` |

The endpoint sanitizes the inbound config via `Validation\Template_Validator` — same allow-list sanitizer used by save handlers. Even if an admin posts an arbitrary config blob, the rendered preview reflects only fields the sanitizer accepts.

---

## Customization

### Skip preview on specific screens

The preview pane attaches via the `dfwc_companion_after_templates_edit_form` and `dfwc_companion_after_settings_form` hooks (and `edit_form_after_editor` from WP core for the campaign edit screen). To suppress on a specific screen:

```php
remove_action( 'edit_form_after_editor', array( \DFWC\Companion\Plugin::instance(), 'render_preview_pane' ) );
```

(Note: there's no canonical "remove the preview" filter shipped — if you need this regularly, file an issue.)

### Customize the preview's mock data

The `Preview_Renderer::render( $config, $args )` method takes the config + args. The mock heading ("Preview campaign title") is hardcoded to `__( '(Preview campaign title)', 'dfwc-companion' )`. To customize per-screen:

```php
add_filter( 'dfwc_companion_preview_mock_title', function ( $title ) {
    return 'Demo Campaign — Preview';
} );
```

(The filter doesn't currently exist in v1.1.0 — it would be a small addition for a future minor.)

### Restyle the preview viewport indicators

CSS-only — override `assets/css/dfwc-admin-preview.css` rules in your admin theme. All preview-specific classes are scoped under `.dfwc-preview` so they don't leak.

---

## Troubleshooting

### "The preview pane is blank"

See [`troubleshooting.md`](troubleshooting.md#symptom-live-preview-pane-shows-blank). The most common cause is a security plugin (Wordfence, iThemes Security) blocking REST access for admins.

### "The preview shows but the form layout looks wrong"

The preview iframe loads the same `dfwc-overlay.css` as donor pages. If the iframe's rendered form doesn't match what donors see, your theme is overriding our styles in a way the iframe doesn't inherit. The iframe injects only our overlay CSS + parent's relevant CSS — your theme stylesheet is NOT loaded inside the iframe (by design — the iframe should reflect the form behavior, not your theme's framing).

If you need theme-aware preview, wrap the iframe contents in your theme's container styles. Phase 8 doesn't ship this; it's a future enhancement.

### "Changes don't update the preview"

1. Ensure JavaScript is enabled in the admin browser session.
2. Check the browser console for errors. The preview JS catches errors silently to avoid breaking the admin form, but logs them.
3. Verify the preview JS is enqueued: View → Page Source → search for `dfwc-admin-preview.js`. If absent, the conditional enqueue logic in `Admin\Preview_Controller` may have skipped it (check the screen ID).

### "Engine simulation isn't working"

The dropdown's "WCS / WPS SFW / No engine" options are simulations — they tell `Preview_Renderer` to render as if that engine were active. They don't change which engine is actually loaded on the site. So if you've selected "WC Subscriptions" but no engine is loaded, the preview correctly shows the WCS layout (recurring tabs enabled) but a real donor visiting the site would still see the no-engine fallback.

This is intentional. The simulation is for visual verification of the form's UX under different engine states without requiring you to run a separate test site for each engine.

---

## Performance

The preview's REST endpoint is rate-limited to 10 req/sec/user. The 350ms debounce means a typical admin's typing produces ~3 reqs/sec at most — well under the limit. If you somehow hit the limit (e.g., by running an automated form-fill script during testing), the endpoint returns `429 Too Many Requests` and the preview shows a "Rate limited — wait a moment" notice.

The endpoint sets `Cache-Control: no-store` so server-side proxies / CDNs don't cache the preview response. Each preview is admin-specific and reflects the current form state.

---

## Privacy

The preview accepts the admin's submitted config + toolbar state and renders an iframe. No donor data, no PII, no cart data flows through the preview path. The preview iframe's HTML carries `data-preview="1"` so even if the iframe HTML somehow leaks (it shouldn't — it's only ever rendered inside the admin), the donor-side defenses prevent any submission.

The preview's POST request is scoped to the admin's session via the standard WP REST nonce + `manage_woocommerce` capability check. No anonymous access.
