# WPML + WCML Integration

> End-to-end multilingual + multi-currency setup for the companion. Covers translation of admin-defined strings, taxonomy terms, per-currency presets, RTL languages, and common multilingual pitfalls.

The companion was designed multilingual-first. Every translatable surface — preset labels, impact text, subtitles, CTA templates, taxonomy terms, custom-cadence labels — registers with WPML String Translation under the **`Donations Companion`** domain at save time. WCML's multi-currency layer drives the per-currency preset resolution. WPML's term-translation layer drives the taxonomy filtering on the directory grid.

If you're not running a multilingual site, you can skip this doc — the companion works perfectly on monolingual sites without WPML, with zero overhead.

---

## What you need

| Plugin | Required | What it does |
|---|---|---|
| **WPML Multilingual CMS** | Yes (for any multilingual feature) | Core multilingual engine |
| **WPML String Translation** | Yes (for admin-defined strings) | Translates strings registered via `icl_register_string` / `wpml_register_single_string` |
| **WPML Translation Management** | Recommended | Translates taxonomy terms via the standard Term Translation UI |
| **WPML WooCommerce Multilingual** (WCML) | Yes (for multi-currency) | Multi-currency layer + WC-aware translation handling |

WPML core gives you per-language post translation. String Translation handles strings WP doesn't know about (the companion's admin-defined strings fall here). Translation Management makes term translation manageable at scale. WCML is the WC-specific layer that adds multi-currency.

For donation sites, all four are typically present. If you're running just WPML core without String Translation, the companion's strings render in the source language — translations don't take effect. Add String Translation.

---

## Companion's WPML surface

### Custom fields declared in `wpml-config.xml`

```xml
<custom-fields>
    <custom-field action="copy">_dfwc_companion_intervals</custom-field>
    <custom-field action="copy">_dfwc_companion_display</custom-field>
    <custom-field action="copy">_dfwc_companion_template_id</custom-field>
    <custom-field action="copy">_dfwc_companion_overrides</custom-field>
    <custom-field action="copy">_dfwc_companion_detached</custom-field>
    <custom-field action="copy">_dfwc_companion_featured</custom-field>
</custom-fields>
```

`action="copy"` means WPML copies the value verbatim from source to translation. Our config blobs (intervals, presets, amounts) are structurally identical across translations; the **strings inside** the blobs are translated separately via WPML String Translation.

### Admin texts declared in `wpml-config.xml`

```xml
<admin-texts>
    <key name="dfwc_companion_global_settings">
        <key name="display"><key name="cause_heading"/></key>
        <key name="one_time">
            <key name="cta_template"/>
            <key name="subtitle"/>
            <key name="annual_equivalency"/>
        </key>
        <key name="monthly">...</key>
        <key name="annual">...</key>
    </key>
</admin-texts>
```

Option-stored strings that should appear in WPML's String Translation UI for translators to handle. The dotted nesting mirrors the option's array structure.

### Taxonomies declared `translate="1"`

```xml
<taxonomies>
    <taxonomy translate="1">dfwc_cause</taxonomy>
    <taxonomy translate="1">dfwc_region</taxonomy>
    <taxonomy translate="1">dfwc_country</taxonomy>
    <taxonomy translate="1">dfwc_program</taxonomy>
    <taxonomy translate="1">dfwc_sponsorship_type</taxonomy>
    <taxonomy translate="1">dfwc_urgency</taxonomy>
</taxonomies>
```

Each taxonomy term is itself translatable. WPML's Translation Management handles per-term translation natively.

---

## Translatable string registration

The companion calls `WPML_Strings::register( $key, $value )` (a wrapper around `wpml_register_single_string`) at save time. The Domain is always `Donations Companion`. Keys follow this pattern:

| String | Key |
|---|---|
| Template name | `<template_id>.name` |
| Template description | `<template_id>.description` |
| `cta_template`, `subtitle`, `annual_equivalency`, `custom_amount_impact_label` | `<namespace>.<interval>.<field>` |
| Per-preset `label`, `impact_label` | `<namespace>.<interval>.presets.<idx>.<field>` |
| Custom interval's `custom_label` | `<namespace>.custom.custom_label` |
| Display options' `cause_heading` | `<namespace>.display.cause_heading` |

`<namespace>` is:

- The template ID for template-defined strings (e.g., `school_sponsorship.monthly.subtitle`)
- `_campaign:<campaign_id>` for per-campaign override strings
- `_global` for global-defaults strings

At render time, `Config_Resolver::resolve()` walks every translatable field and passes each through `WPML_Strings::translate()`. The translator chooses the namespace based on the precedence layer — template translations resolve via the template's namespace, campaign override translations via `_campaign:<id>`, global default translations via `_global`.

---

## End-to-end multilingual setup

### Step 1: WPML Languages

WPML → Languages → enable the languages you want. Pick the source language (typically English) — all admin content is authored in this language and translated outward.

### Step 2: WCML currencies (for multi-currency)

WPML → WooCommerce Multilingual → Multi-currency → enable per language → set exchange rates (or override per product / per campaign).

For nonprofit sites running campaigns globally, you'll want at least:

- USD (base, US)
- GBP (UK)
- EUR (Eurozone)

Optionally CAD, AUD for English-speaking diaspora. Add others as your donor base grows.

### Step 3: Author content in the source language

Create `wc-donation` campaigns, configure them via the companion's meta box, save. Authoring happens in your source language only. Translations come later.

### Step 4: Translate post-level content

WPML → Translation Management → assign campaigns to translators (or use professional translation services). The campaign's title + content translates per language; the campaign post itself becomes a separate post per language (linked via WPML's translation table).

### Step 5: Translate taxonomy terms

WPML → Translation Management → Translatable Content → tick the dfwc_* taxonomies. Then visit each taxonomy → translate each term per language.

### Step 6: Translate companion strings

WPML → String Translation → filter by Domain: **`Donations Companion`**. You'll see every preset label, impact label, subtitle, CTA template, etc. that admins have filled in. Translate each.

The translation appears immediately on the donor-facing form when a donor in that language visits the campaign permalink. No re-save required.

### Step 7: Configure per-currency presets (optional)

Edit a campaign that has WCML currencies enabled. The "Per-currency preset amounts" section is now visible — fill in psychologically rounded amounts per currency. See [`multi-currency.md`](multi-currency.md) for the detailed flow.

---

## RTL language considerations

The companion ships LTR-by-default CSS. For RTL languages (Arabic, Hebrew, Persian, Urdu), WordPress core flips the `dir` attribute on `<html>`, but custom CSS may not auto-flip.

The companion's CSS uses logical properties where possible (`margin-inline-start` instead of `margin-left`, etc.) — these auto-flip under RTL. But some legacy declarations use physical properties; these don't flip without an RTL stylesheet.

**Quick check:** load the companion on an Arabic donor page. If the form layout looks wrong (text alignment, preset chip order, button positions), file an issue with a screenshot.

**Fix path:** add an `rtl.css` stylesheet that overrides the LTR-specific rules. WordPress auto-loads `rtl.css` for RTL languages via the standard convention. Phase 11 didn't ship one because the LTR CSS is mostly logical-property-based; future v1.x may ship a tested RTL stylesheet.

---

## How translations cascade through the layered config

The companion's config has four layers (Defaults → Global → Template → Campaign Overrides). Each layer can carry translatable strings. Translation namespacing follows precedence:

```
Default: "Donate {amount}"
   ↓
Global: "" (not overridden)
   ↓
Template "school_sponsorship":
    cta_template = "Donate {amount}/month"
    Translation key: school_sponsorship.monthly.cta_template
    French translation: "Don de {amount}/mois"
   ↓
Campaign #42 override:
    cta_template = "Donate {amount} for school supplies"
    Translation key: _campaign:42.monthly.cta_template
    French translation: "Don de {amount} pour fournitures scolaires"
   ↓
RESOLVED for French donor on campaign #42:
    "Don de {amount} pour fournitures scolaires"
    (donor sees the campaign-override translation, not the template's)
```

Each layer's strings register under that layer's namespace. The resolver translates at render time using whichever layer "wins" the override stack.

---

## WCML currency switching

When a donor switches currency (via WCML's currency switcher widget), three things happen:

1. WCML re-renders the page with the new currency in `get_woocommerce_currency()`.
2. The companion's `Currency_Preset_Resolver::active_currency()` picks up the new value via `wcml_get_user_currency()`.
3. The donor-form overlay JS reads the wrapper's `data-config` attribute and re-renders preset chips with the new currency's amounts.

For mid-page currency switching (without full reload), the WCML currency-switcher emits a `wcmlPriceLoad` event. A future v1.x will listen for this event in `dfwc-overlay.js` and re-render presets via the same REST endpoint pattern Phase 8's preview pane uses. v1.1.0 doesn't ship this — donors switching currency mid-flow will need to reload the page (most WCML switchers do a full reload anyway).

---

## Common multilingual pitfalls

### "My French translations don't appear on the donor form"

Three causes:

1. **String not registered in WPML.** Save the campaign once after activating WPML — the save handler registers strings. Check WPML → String Translation → Domain: `Donations Companion`. If your string isn't there, it didn't register.
2. **Translation not entered.** The string is registered but not translated. Click the string in WPML → enter the French translation → save.
3. **WPML doesn't recognize the donor's language.** Verify via *WPML → Languages* that French is enabled, and that the donor is being routed to French via your URL strategy (subdirectory `/fr/`, subdomain `fr.example.com`, or query param `?lang=fr`).

### "Taxonomy terms aren't filtering correctly in the directory grid"

Same flow as for any WPML-translated taxonomy:

1. WPML → Translation Management → Translatable Content → tick the dfwc_* taxonomies (one-time setup).
2. WPML → Taxonomy Translation → translate each term.
3. Verify the donor's URL is in the right language — the directory grid filters by language by default.

For cross-language directories (showing campaigns from all languages), use the `lang="all"` shortcode attribute:

```
[dfwc_campaign_grid lang="all"]
```

### "Per-currency preset section doesn't appear in admin"

WCML must be active and report active currencies. Check:

```bash
wp eval "var_dump( wcml_multi_currency()->get_active_currencies() );"
```

If the array is empty or just contains the base currency, configure WCML's multi-currency settings. The companion's per-currency UI only renders when ≥1 non-base currency is enabled.

### "Donor sees base USD amounts even though I configured GBP overrides"

Run `Currency_Preset_Resolver::active_currency()` against the donor's session:

```php
echo \DFWC\Companion\Config\Currency_Preset_Resolver::active_currency();
```

If it returns `USD` for a donor browsing the GBP version of the site, WCML's `wcml_get_user_currency()` isn't reporting GBP. Check WCML's currency-switcher config + the donor's current cookie state. Often the issue is cache: a logged-out donor's currency preference cookie has expired or hasn't been set.

### "WPML compatibility certification flagged us for X"

WPML's compat-cert team submits feedback after their review. Common items they flag:

- Strings in the donor form that aren't registered (we've covered all of them as of v1.1.0)
- Taxonomies not declared `translate="1"` in `wpml-config.xml` (covered)
- Custom fields not declared `action="copy"` (covered for all six dfwc keys)
- Hardcoded language assumptions (e.g., `if ( 'en' === $lang )`) — none in the companion
- Donor-facing strings not wrapped in `__()` / `esc_html__()` — none should remain after the v1.1.0 audit

If you receive flagging on something not in the list above, file an issue — likely a regression we can fix in a v1.1.x patch.

---

## Programmatic language detection

For listener code (event hooks, filters), the canonical way to detect the donor's language:

```php
use DFWC\Companion\I18n\WPML_Strings;

if ( WPML_Strings::wpml_active() ) {
    $lang = WPML_Strings::current_language();
    // e.g., 'en', 'fr', 'pt-br'
} else {
    $lang = explode( '_', get_locale() )[0]; // 'en', 'fr', etc.
}
```

`WPML_Strings::current_language()` returns the WPML active language code or `''` if WPML isn't loaded. Always combine with the `get_locale()` fallback for sites in mid-migration.

The Phase 9 event hooks (`form_viewed`, `donation_submitted`, etc.) carry `$language` directly — use that instead of re-deriving in the listener.

---

## Compatibility certification status

WPML's "Works with WPML" certification is **not yet granted** for the companion as of v1.1.0. The certification submission process is queued for post-WordPress.org-acceptance — see [`release-readiness.md`](release-readiness.md) for the full sequence.

When granted, the `[Works with WPML]` badge will appear in the readme.txt + README.md. Until then, the companion is "WPML-compatible by self-declaration" — the wpml-config.xml + WPML_Strings registration patterns match WPML's documented requirements; certification is the formal third-party verification.

---

## Performance

Translation runs at render time inside `Config_Resolver::resolve()`. For each translatable field, one `apply_filters( 'wpml_translate_single_string', ... )` call. With ~30 translatable strings per campaign at full configuration (3 intervals × ~10 fields), that's 30 filter calls per resolve.

WPML's String Translation cache makes these calls cheap (in-memory after the first resolve per request). Net cost: negligible — a few milliseconds per request, unmeasurable at scale.

For monolingual sites, `WPML_Strings::wpml_active()` returns false, the translation pass is short-circuited entirely, and there's zero overhead.

---

## Storage shape

| Where | What |
|---|---|
| `_dfwc_companion_intervals` / `_dfwc_companion_overrides` post meta | Per-campaign config (source-language strings) |
| `dfwc_companion_templates` option | Template configs (source-language strings) |
| `dfwc_companion_global_settings` option | Global defaults (source-language strings) |
| WPML's `wp_icl_strings` table | Registered strings + translations |
| WPML's `wp_icl_translations` table | Post + term translation links |

The companion stores only source-language strings. Translations live in WPML's tables.

---

## What the companion does NOT translate

- **Currency override amounts** — numeric, not translatable. Currency context is shared across languages; converting numbers via translation makes no semantic sense. WCML handles cross-language currency presentation.
- **Cadence configuration** (`custom_period`, `custom_interval`) — config, not content. The custom interval's `custom_label` IS translatable (it's the donor-facing copy); the underlying cadence is not.
- **Engine slugs** (`wcs`, `wps_sfw`, `none`) — internal identifiers.
- **Interval keys** (`one_time`, `monthly`, etc.) — internal identifiers. The donor-facing labels (`One-time`, `Monthly`, etc.) translate via the standard `__()` / text-domain flow.

---

## Migration: existing campaigns to multilingual

If you have existing v1.0.x campaigns and you're activating WPML for the first time:

1. Activate WPML + String Translation + Translation Management + WCML.
2. Configure WPML languages.
3. **Save each existing campaign once** — the meta-box save handler triggers `WPML_Strings::register` for every translatable field. Without re-saving, the strings exist in storage but aren't yet registered with WPML.
4. Visit *WPML → String Translation → Domain: Donations Companion* — your strings should now appear, ready for translation.
5. Translate.

For sites with many campaigns, automate the re-save:

```bash
wp post list --post_type=wc-donation --post_status=publish --format=ids \
  | xargs -d ' ' -I{} wp post update {} --post_status=publish
```

This re-fires the save handler on each campaign, registering all current strings.
