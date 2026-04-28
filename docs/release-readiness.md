# Release Readiness — v1.1.0 → WordPress.org

> **Status as of 2026-04-28:** v1.1.0 is shipped on GitHub Releases. This doc tracks the work between "shipped on GitHub" and "live on the WordPress.org plugin directory." Some items are blocking; others are nice-to-have.

This is the consolidated punch list extracted from the v2 master plan's Phase 11 acceptance criteria, the wp.org submission requirements, and the deferred items called out in the per-release notes. Items are grouped by who can do them (maintainer, automated, user-action-required).

---

## Status snapshot

| Area | Status |
|---|---|
| **Code** | ✅ All gates green (PHPCS, PHPStan, PHPUnit 201/518, parent-contract 16/16) |
| **Releases** | ✅ v1.1.0 published, zip attached (~196 KB) |
| **Docs (admin-facing)** | ✅ getting-started, troubleshooting, multi-currency, advanced-intervals, templates, privacy |
| **Docs (developer-facing)** | ✅ developer-hooks, event-hooks, release-process, architecture/* |
| **Repo polish** | ✅ README.md, CONTRIBUTING.md, SECURITY.md, CODE_OF_CONDUCT.md, issue templates, PR template |
| **Plugin Check** | ⚠️ Skipped in CI (gated on `DFWC_PARENT_ZIP_URL` secret) |
| **Translation .pot** | ⚠️ Header version bumped; full regen requires `wp i18n make-pot` against running WP |
| **Screenshots** | ❌ None in `assets/screenshot-*.png` (required for wp.org submission) |
| **WordPress.org submission** | ❌ Not yet submitted |
| **WPML compatibility certification** | ⏳ Post-acceptance only |

---

## 1. Maintainer can do without code changes

### 1.1 Configure `DFWC_PARENT_ZIP_URL` secret (~10 minutes)

The release workflow + CI both gate Plugin Check on this secret. Without it, the plugin-check step skips with a `::notice` and the build still succeeds — but you don't know whether the zip would pass wp.org's automated linter.

**Steps:**

1. Host the parent plugin zip somewhere private the workflow can reach: a GitHub release in a separate private repo, an S3 / R2 bucket, or a private GitHub Gist.
2. Repo settings → Secrets and variables → Actions → New repository secret:
   - **Name:** `DFWC_PARENT_ZIP_URL`
   - **Value:** the direct download URL (must respond 200 to `curl -sSLf <URL>`).
3. Re-run the v1.1.0 release workflow (or wait for the next tag) to verify Plugin Check runs.

`docs/ci.md` walks through the hosting options in detail.

### 1.2 Capture screenshots (~30 minutes, requires running site)

WordPress.org's plugin directory expects 5 screenshots in `assets/screenshot-{1..5}.png`. The screenshots are referenced in `readme.txt`'s implied `== Screenshots ==` section (currently absent — **add the section**).

Recommended captures:

1. **screenshot-1.png** — donor view of the form on a live campaign page. Show the three intervals (one-time / monthly / annually) with presets visible. 1280px wide.
2. **screenshot-2.png** — the campaign meta box (`Donations Companion` interval-first form). Show one tab with presets, impact labels, featured preset, custom amount, CTA template fields visible.
3. **screenshot-3.png** — the live admin preview pane. Show the iframe rendering alongside the meta box.
4. **screenshot-4.png** — the Templates list page (`Donations Companion → Templates`). Show 2-3 templates with their assignment counts.
5. **screenshot-5.png** — the Diagnostics page (`Donations Companion → Diagnostics`). Show 13 health checks with the support-report copy button.

**Capture process:**

```bash
npm run env:start          # spin up wp-env
npm run env:cli plugin install woocommerce --activate
npm run env:cli plugin install subscriptions-for-woocommerce --activate
# manually install + activate the parent plugin zip
npm run env:cli post create --post_type=wc-donation --post_title="Demo Campaign" --post_status=publish
```

Then visit `localhost:8888` and the relevant admin screens, take screenshots at 1280px wide, save as PNG (or run the captures through `pngquant` to keep file sizes reasonable for the wp.org repository).

Add an `== Screenshots ==` block to `readme.txt`:

```
== Screenshots ==

1. The interval-first donor form: One-time / Monthly / Annually tabs with separate preset amounts per interval.
2. Per-campaign admin meta box for configuring intervals.
3. Live admin preview pane shows exactly what donors will see.
4. Named templates: configure once, apply to many campaigns.
5. Diagnostics page: 13 health checks with copy-paste-ready support report.
```

### 1.3 Regenerate the translation `.pot` file (~5 minutes)

The header is bumped to v1.1.0; the strings haven't been re-extracted since v0.6.4. ~30 new strings (Phase 6/7/8/9) are missing.

```bash
npm run env:cli i18n make-pot . languages/dfwc-companion.pot
```

Or via wp-cli outside wp-env if available:

```bash
wp i18n make-pot . languages/dfwc-companion.pot
```

Commit the regenerated file. After v1.1.0 lands on wp.org, translate.wordpress.org takes over.

### 1.4 WordPress.org submission (~15 minutes form-fill, then 2–6 weeks review)

Visit https://wordpress.org/plugins/developers/add/ logged in as the maintainer's wp.org account.

**Required fields:**

- Plugin name: `Donations for WooCommerce Companion`
- Slug: `donations-for-woocommerce-companion` (verify available at https://wordpress.org/plugins/donations-for-woocommerce-companion/)
- Plugin URL: https://github.com/f8l124/donations-for-woocommerce-companion
- Plugin author: David Stells
- Categorization: `donation` / `donations` / `nonprofit` / `recurring`
- ZIP upload: the v1.1.0 release zip from GitHub

**Likely review feedback** (per past wp.org reviews of similar plugins):

- License + license URI in the plugin header — already present (GPLv2+)
- No external runtime fetches — already verified (no `wp_remote_*` calls outside admin-only contexts)
- No bundled minified vendor code without source — already true (vanilla JS, no bundler)
- Prefixed everything — already true (`dfwc_companion_*` for hooks, `_dfwc_companion_*` for meta, `dfwc-companion` for CSS)
- Sanitization + escaping documented — covered in `SECURITY.md` + the v2 plan's §6 baseline

After approval, follow `docs/release-process.md` §"WordPress.org SVN dance" to publish v1.1.0 to the SVN repository.

### 1.5 WPML compatibility certification (post-acceptance)

The "Works with WPML" badge requires WPML's team to review:

- `wpml-config.xml` correctness
- All admin-defined strings translatable
- Multi-currency works with WCML
- Taxonomies translate cleanly

Submit at https://wpml.org/contact-us/ once the plugin is on wp.org. Budget time for review feedback — certification often surfaces real bugs.

After granted, add the badge to `readme.txt` and `README.md`.

---

## 2. Optional polish (not blocking submission)

### 2.1 Bump GitHub Actions to Node 24 (when available)

The release workflow shows a deprecation annotation:

> Node.js 20 actions are deprecated. The following actions are running on Node.js 20 and may not work as expected: actions/checkout@v4, actions/setup-node@v4, softprops/action-gh-release@v2. Actions will be forced to run with Node.js 24 by default starting June 2nd, 2026.

The fix: bump these actions to v5+ when their authors publish a Node-24-compatible major version. Until then, the warnings are informational only.

### 2.2 Write the remaining docs

`docs/getting-started.md` references three docs that don't exist yet:

- `docs/campaign-directory.md` (~250 lines, Phase 4 deep-dive)
- `docs/impact-messaging.md` (~150 lines, Phase 5 deep-dive)
- `docs/preview.md` (~150 lines, Phase 8 deep-dive)
- `docs/recurring-engines.md` (~250 lines, WCS vs WPS SFW choice + setup)
- `docs/hpos-compatibility.md` (~80 lines)
- `docs/wpml.md` (~400 lines, end-to-end WPML setup)

The dead links are now redirected to inline coverage in `readme.txt`. Writing the dedicated docs is a polish item — useful for sites doing deep customization.

### 2.3 E2E test cleanup

Several `tests/e2e/*.spec.ts` files reference legacy `[data-dfwc-form]` selectors from the v0.3.x replacement-mode era. Post-v0.4.0 the augment-don't-replace pattern uses `[data-dfwc-overlay-target]`. The legacy specs still exist but are stale relative to the current selectors.

Refactoring them to current selectors is post-v1.x technical debt cleanup. Doesn't affect behavior; just affects the credibility of the E2E suite.

### 2.4 PHPStan 2.x upgrade

`composer check` shows:

> This project is using PHPStan 1.12. PHPStan 2.x adds a new level 10, list types, @phpstan-pure enforcement, and uses 50-70% less memory.

Bumping is straightforward but adds level-10 strictness; budget time for fixing newly-flagged issues.

---

## 3. Manual QA checklist (for v1.1.0 release verification)

Walk through the following before publishing the v1.1.0 release to wp.org. Most are also captured in `docs/manual-qa.md`.

### 3.1 Donor flow

- [ ] One-time $50 → cart shows $50, no subscription meta
- [ ] Monthly $25 → cart shows recurring; `billing_period=month`
- [ ] Annual $300 → cart shows `billing_period=year`
- [ ] Weekly $5 (advanced toggle on) → cart shows `billing_period=week, billing_interval=1`
- [ ] Quarterly $50 → `billing_period=month, billing_interval=3`
- [ ] Custom "every 6 weeks" $30 → `billing_period=week, billing_interval=6`
- [ ] Per-currency: WCML active with GBP, donor in GBP sees £20 preset; submits → cart receives £20 line item
- [ ] Custom amount $0.50 with min=$5 → server rejects with 422

### 3.2 Engines

- [ ] WPS SFW only — all flows work
- [ ] WCS only — all flows work
- [ ] Neither engine — recurring tabs disabled; one-time still works; admin notice surfaces
- [ ] Engine swap (deactivate WCS, activate WPS SFW) — no data loss; campaigns continue to render

### 3.3 Admin

- [ ] Meta box round-trips all 7 intervals' configuration
- [ ] Templates list / edit / apply / detach / reset all work
- [ ] Diagnostics page renders all 13 checks; "Re-check" button works; support-report copy works
- [ ] Live preview pane updates within 350ms of input change
- [ ] Bulk apply template to 5 campaigns at once

### 3.4 Multi-context

- [ ] Single-campaign permalink → augmented form
- [ ] `[wc_woo_donation]` shortcode → augmented form
- [ ] `[dfwc_recurring_donation]` shortcode → augmented form
- [ ] Gutenberg block → augmented form
- [ ] Elementor widget → augmented form
- [ ] Two shortcodes on one page → both work independently

### 3.5 WPML

- [ ] Multilingual site with WPML + WCML — admin sees per-currency UI
- [ ] Donor in French sees French-translated impact_label
- [ ] Donor in GBP sees £20 preset (not auto-converted from $25)

### 3.6 Event hooks (Phase 9)

- [ ] GA4 recipe (from `docs/event-hooks.md`) — donor submits → GA4 DebugView shows `donation_completed` event
- [ ] FluentCRM recipe — donor submits → contact tagged in FluentCRM
- [ ] Zapier webhook recipe — donor submits → Zapier receives JSON

### 3.7 Uninstall

- [ ] Uninstall with `preserve_data_on_uninstall=ON` (default) — companion options + meta preserved; reinstalled plugin recovers config
- [ ] Uninstall with `preserve_data_on_uninstall=OFF` — companion options + meta wiped; campaigns themselves untouched

---

## 4. Items the user must do manually

These cannot be automated from a development environment:

| Item | Why manual |
|---|---|
| Configure `DFWC_PARENT_ZIP_URL` repo secret | Requires GitHub UI access + private hosting decision |
| Capture screenshots | Requires running wp-env + visual verification |
| WordPress.org submission via /developers/add/ | Manual form-fill on wordpress.org with maintainer's account |
| SVN setup post-acceptance | Manual `svn co` + initial trunk push |
| WPML compatibility cert submission | Manual via wpml.org/contact-us/ |
| Translation .pot regen via `wp i18n make-pot` | Requires wp-cli + running WP install (the wp-env CLI works) |
| Manual QA matrix walk-through | Requires running the donor + admin flows on a real site |

---

## 5. Tracking

| Item | Owner | Status |
|---|---|---|
| Configure `DFWC_PARENT_ZIP_URL` secret | maintainer | open |
| Capture 5 screenshots | maintainer | open |
| Add `== Screenshots ==` block to `readme.txt` | maintainer | open |
| Regenerate `.pot` | maintainer | open (header bumped, strings stale) |
| Run Plugin Check on v1.1.0 zip | automated (after secret set) | open |
| Manual QA matrix walk-through | maintainer | open |
| WordPress.org submission | maintainer | open |
| WPML compatibility certification | maintainer | post-acceptance |
| Bump GH Actions to v5 / Node 24 | maintainer | optional, post-June-2026 deprecation date |
| Write remaining docs (campaign-directory, impact-messaging, preview, recurring-engines, hpos-compatibility, wpml) | maintainer | optional polish |
| E2E spec selector cleanup (data-dfwc-form → data-dfwc-overlay-target) | maintainer | tech debt |
| PHPStan 2.x upgrade | maintainer | optional |

---

## Done when

- v1.1.0 zip is live on the WordPress.org plugin directory
- Plugin Check (running in CI on every release) passes with zero `error` severity
- Five screenshots in `assets/`
- Translation .pot regenerated with v1.1.0 strings
- One nonprofit running v1.1.0 in production with feedback collected
- "Works with WPML" badge displayed in `readme.txt`
