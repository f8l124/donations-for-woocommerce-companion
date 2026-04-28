# Named Templates

> Available from **v0.7.0**. Save donation form configuration once; apply to many campaigns. Edit the template, propagate the change everywhere it's used — except where a campaign explicitly overrides.

The companion's layered config model has four layers. Each one merges into the next:

```
  Plugin defaults
  ↓ (always present)
  Global settings
  ↓ (admin-configured at WooCommerce → Donations Companion → Settings)
  Named template
  ↓ (assigned per-campaign, optional)
  Campaign overrides
  ↓ (per-campaign edits in the meta box)
  → Resolved config the donor sees
```

**Detached** campaigns get a frozen snapshot at detach time and ignore future template changes.

---

## When to use a template

| Number of campaigns | Recommendation |
|---|---|
| 1–5 campaigns, each unique | Don't bother with templates. Edit the meta box per-campaign. |
| 5+ campaigns sharing structure (same preset amounts, same impact messaging, same cadences) | Define a template once; assign it to all of them. |
| Multiple distinct campaign types (e.g., monthly sponsorship vs. one-time emergency relief) | One template per type. Apply via bulk action on the campaign list. |

Common template patterns:

- **"School Sponsorship"** — monthly enabled with $25 / $50 / $100 presets; impact labels like "Provides school supplies for one student"
- **"Emergency Relief"** — one-time only with $50 / $100 / $250 / $500 presets; subtitle "100% goes to disaster response"
- **"General Fund"** — all three intervals enabled; smaller preset amounts; less impact-heavy copy

---

## Creating a template

**Donations Companion → Templates → Add New**

The form mirrors the campaign meta box: per-interval enable, presets, min/max, CTA template, impact messaging, per-currency overrides. Plus:

- **Name** — used in admin lists and campaign meta-box selectors. Translatable via WPML.
- **Description** — optional. Shown in the templates list to help admins remember what the template is for.
- **Default interval** — the interval pre-selected when a donor lands on a campaign using this template.

Save. The template now appears in:

- The campaign meta box's template dropdown
- The wc-donation list-screen bulk action dropdown (`Apply template: <name>`)
- The Settings page's "Default template for new campaigns" field

---

## Assigning a template

### Per campaign

Edit a campaign → top of the **"Interval-First Donation Form"** meta box → pick a template from the dropdown → click **"Apply"**.

The campaign's resolved config is now the template's config. Any per-campaign edits below become **overrides** on top of the template (see [Override-aware save](#override-aware-save)).

### Bulk apply via campaign list

The wc-donation campaign list (the WordPress admin's list-table at `Donations`) has bulk actions:

- **Apply template: <name>** — one entry per template
- **Reset Companion settings** — clears all companion-side config from selected campaigns
- **Detach from template** — converts the assigned template's resolved values into per-campaign overrides; future template edits won't affect detached campaigns

Per-campaign capability check (`current_user_can( 'edit_post', $campaign_id )`) gates each operation. The bulk action surfaces an applied / skipped count via dismissible admin notice.

### Default for new campaigns

**Donations Companion → Settings → Default template for new campaigns** — pick a template. Every newly created campaign starts with this template assigned. Doesn't affect existing campaigns.

---

## Override-aware save

When you edit a campaign that has a template assigned and click Save, the companion does NOT save your full form — it saves only the **delta** between what you submitted and the template's resolved values. Fields you didn't change stay tracked as inherited from the template.

Why: future template edits should propagate to unchanged fields automatically, **but** any field you explicitly edited at the campaign level should stay campaign-specific.

Example:

| Step | Action | Result |
|---|---|---|
| 1 | Define template "Sponsor" with monthly $25/$50/$100 + impact labels | Template stored in `wp_options` |
| 2 | Apply "Sponsor" to campaign #42 | `_dfwc_companion_template_id = 'sponsor'`; no overrides |
| 3 | Save campaign #42 with NO edits | `_dfwc_companion_overrides = []` (empty) |
| 4 | Edit template: change $50 to $60 | Template updated |
| 5 | Visit campaign #42 | Resolver sees $25/$60/$100 — propagated cleanly |
| 6 | Edit campaign #42: change $25 to $20 (only that one preset) | `_dfwc_companion_overrides = { monthly: { presets: [ {amount: 20}, ...inherited... ] } }` (sparse) |
| 7 | Edit template: change $100 to $120 | Template updated |
| 8 | Visit campaign #42 | Resolver sees $20/$60/$120 — campaign override wins for the first preset; template propagates for the third |

The `Campaign_Config_Repository::compute_override_delta` method does the diff. Sequential arrays (preset lists) are compared structurally; if any field within differs, the whole list lands in the overrides.

---

## Detaching a campaign

If a campaign needs to fully diverge from its template — without losing the template's current config as a starting point — click **"Detach"** on the meta box.

What happens:

1. The resolver computes the full resolved config (including all template fields).
2. That snapshot is written into `_dfwc_companion_overrides` as the campaign's complete config.
3. `_dfwc_companion_detached = '1'` is set.
4. `_dfwc_companion_template_id` is cleared.

Future template edits don't affect detached campaigns. To re-attach, re-apply a template via the dropdown.

---

## Resetting

**"Reset Companion settings"** (bulk action) clears `_dfwc_companion_overrides`, `_dfwc_companion_template_id`, `_dfwc_companion_detached`, and `_dfwc_companion_featured`. Donors then see whatever the global defaults specify (which may be defaults-only if no global default-template is set).

The reset doesn't touch parent-plugin meta — `wc-donation-recurring`, the linked product's subscription configuration, etc. all stay as the parent plugin set them.

---

## WPML translation

Template strings register with WPML String Translation under namespace = template ID. Visit *WPML → String Translation → Domain: Donations Companion* to translate. Strings registered:

| Field | Key |
|---|---|
| Template name | `<template_id>.name` |
| Template description | `<template_id>.description` |
| `cta_template`, `subtitle`, `annual_equivalency`, `custom_amount_impact_label` | `<template_id>.<interval>.<field>` |
| Per-preset `label`, `impact_label` | `<template_id>.<interval>.presets.<idx>.<field>` |
| Custom interval's `custom_label` | `<template_id>.custom.custom_label` |
| Display options' `cause_heading` | `<template_id>.display.cause_heading` |

Per-campaign overrides register under `_campaign:<campaign_id>` namespace; global-default strings under `_global`. The resolver translates at render time via the appropriate namespace.

---

## Programmatic management

```php
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\Config\Defaults;

$repo = new Template_Repository();

// Create a template.
$config = Defaults::for_campaign();
$config['monthly']['enabled'] = true;
$config['monthly']['presets'] = array(
    array( 'amount' => 25.0, 'label' => '', 'impact_label' => 'Provides school supplies', 'is_featured' => true,  'sort_order' => 10 ),
    array( 'amount' => 50.0, 'label' => '', 'impact_label' => 'Sponsors a teacher',         'is_featured' => false, 'sort_order' => 20 ),
    array( 'amount' => 100.0, 'label' => '', 'impact_label' => 'Funds a classroom',         'is_featured' => false, 'sort_order' => 30 ),
);

$tpl = new Template_Config(
    'school-sponsorship',                              // id (sanitize_key result)
    'School Sponsorship',                              // name
    'Monthly recurring at school-fund tiers',          // description
    time(),                                            // created_at
    time(),                                            // updated_at
    $config
);

$repo->save( $tpl );

// Apply to campaigns.
use DFWC\Companion\Config\Campaign_Config_Repository;
( new Campaign_Config_Repository() )->apply_template( 42, 'school-sponsorship' );
```

The repository handles WPML registration automatically on save.

---

## Storage shape

| Key | Type | Where |
|---|---|---|
| `dfwc_companion_templates` | option (autoload off) | All templates serialized as a single array. |
| `_dfwc_companion_template_id` | post meta on `wc-donation` | The template ID a campaign is currently using. Empty string = no template. |
| `_dfwc_companion_overrides` | post meta on `wc-donation` | Sparse override delta. Empty array when nothing overrides the template. |
| `_dfwc_companion_detached` | post meta on `wc-donation` | `'1'` if the campaign is detached from its (former) template. |

Legacy v0.6.x meta (`_dfwc_companion_intervals`, `_dfwc_companion_display`) is read by the resolver as a fallback layer when no template + no overrides exist. The next admin save in v0.7+ migrates legacy meta to overrides automatically (`Campaign_Config_Repository::migrate_legacy_to_overrides_if_needed`).

---

## Caveats

- **Templates can't be nested.** A template doesn't reference another template. (If you need shared base config, define it at the global-settings layer.)
- **Bulk apply is not undoable.** Detaching from a template after bulk-apply is a per-campaign operation. Plan template changes before applying.
- **The default-template-for-new-campaigns setting is not retroactive.** Existing campaigns aren't affected when you change the default; only newly created ones are.
- **Sequential arrays (presets) replace wholesale, not deep-merge.** A campaign override that adds one preset to a template's three doesn't append — the override's preset list becomes the resolved list. To extend, copy the template's presets into your override and add yours.
