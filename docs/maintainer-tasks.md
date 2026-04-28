# Maintainer Tasks

> Step-by-step walkthroughs for manual operations the maintainer (you) needs to do periodically. The release-readiness doc lists them; this doc explains how.

---

## 1. Regenerate the translation `.pot` file

**When:** before tagging any release that adds, removes, or changes user-facing strings (admin labels, error messages, donor-facing text, etc.).

**Why:** the `languages/dfwc-companion.pot` file is the master translation template. Every translatable string — anything wrapped in `__()`, `_e()`, `esc_html__()`, `esc_attr_e()`, etc. — must be in the .pot, or translators can't translate it. After v1.0.0 lands on wp.org, [translate.wordpress.org](https://translate.wordpress.org/) auto-extracts strings from the plugin source and the .pot becomes less critical, but until then it's the canonical translation surface.

**How:**

```bash
# 1. Spin up wp-env (if it's not already running). Any fixture works; we just
#    need wp-cli with WordPress loaded.
npm run env:start

# 2. Regenerate the .pot via wp-cli's i18n make-pot command.
npm run env:cli i18n make-pot . languages/dfwc-companion.pot

# 3. Verify the regenerated file:
#    - The Project-Id-Version line should match the current plugin version
#    - The string count should reflect the current source (rough check:
#      the file size should grow as features are added)
git diff languages/dfwc-companion.pot | head -30

# 4. Commit + push as part of the release commit. Per docs/release-process.md,
#    the .pot regen happens in the same commit that bumps the version.
git add languages/dfwc-companion.pot
```

### Troubleshooting

**"Could not find .pot file"** — make sure you're in the plugin directory. The command's `.` arg means "current directory."

**"Some strings have HTML in them"** — wp-cli's i18n extractor handles this; review the `.pot` to confirm strings escape correctly. Rare false positives can be fixed with translator-comment annotations:

```php
/* translators: %s: WooCommerce version */
sprintf( __( 'WooCommerce %s active.', 'dfwc-companion' ), $version );
```

**"Make-pot didn't pick up a string I just added"** — confirm the string is wrapped in a recognized i18n function (`__`, `_e`, `esc_html__`, etc.) with the `'dfwc-companion'` text domain. Strings without the domain or wrapped in custom functions won't be extracted.

**"My local PHP version is wrong"** — the wp-env CLI runs PHP inside Docker, so your local PHP version doesn't matter. If you see PHP errors, check `npm run env:logs` for the wp-env container log.

### Alternative: outside wp-env

If you have wp-cli + a WordPress install on your local machine (without wp-env):

```bash
wp i18n make-pot /path/to/donations-for-woocommerce-companion languages/dfwc-companion.pot
```

This works equivalently. Either method produces the same output.

---

## 2. Configure `DFWC_PARENT_ZIP_URL` secret for CI

**When:** before the first wp.org submission (so Plugin Check + parent contract watcher run in CI), or whenever the parent plugin releases a new version you want CI to verify against.

**Why:** the parent plugin (Donation for WooCommerce by WPExperts) is a paid Woo extension. CI workflows that need the parent (Plugin Check, parent-contract watcher, Playwright E2E with full fixtures) can't fetch it from wp.org. Without the secret, those CI jobs skip cleanly with a `::notice` line.

The full setup is documented in [`docs/ci.md`](ci.md). Quick summary:

### Steps

1. **Get the parent zip.** Sign in to your woocommerce.com account → My Account → Downloads → grab the latest `donation-for-woocommerce.zip`. Save it locally.

2. **Host it privately.** Three options, pick whichever you have access to:

   #### Option A — private GitHub release (recommended for personal projects)

   ```bash
   # Create a private repo (one-time)
   gh repo create dfwc-fixtures --private --description "Private fixtures for f8l124/donations-for-woocommerce-companion"

   # Tag a "release" carrying the parent zip as an asset
   cd /path/to/dfwc-fixtures
   git init && git commit --allow-empty -m "init"
   git remote add origin https://github.com/<your-username>/dfwc-fixtures.git
   git push -u origin main
   gh release create v3.9.8 --notes "Parent plugin v3.9.8" /path/to/donation-for-woocommerce.zip
   ```

   The asset URL is `https://github.com/<your-username>/dfwc-fixtures/releases/download/v3.9.8/donation-for-woocommerce.zip`. To download from GitHub Actions you'll need a PAT — see Option A.1.

   #### Option A.1 — GitHub PAT

   Create a fine-grained PAT with `Read access to code and metadata` on the `dfwc-fixtures` repo only. Use the URL format:

   ```
   https://<TOKEN>@github.com/<user>/dfwc-fixtures/releases/download/v3.9.8/donation-for-woocommerce.zip
   ```

   #### Option B — S3 / Cloudflare R2 with signed URL

   Upload the zip to a private S3 / R2 bucket. Generate a presigned URL with 1-year expiry. Rotate annually.

   #### Option C — Cloudflare R2 with public bucket + obscure path

   Make the bucket public-read but use an unguessable path (`r2.example.com/cdf6a7e3-9d4c-...long-random.../donation-for-woocommerce.zip`). Lower-friction; security through obscurity.

3. **Add the secret to GitHub.**

   ```bash
   # Via gh CLI:
   gh secret set DFWC_PARENT_ZIP_URL --body "<the URL from step 2>"
   ```

   Or via the GitHub web UI: Settings → Secrets and variables → Actions → New repository secret. Name: `DFWC_PARENT_ZIP_URL`. Value: your URL.

4. **Verify by re-running a workflow.**

   ```bash
   # Re-run the latest release workflow
   gh run list --workflow=release.yml --limit 1
   gh run rerun <RUN_ID>
   ```

   Or push a new tag (which fires the release workflow). Watch the **"Run Plugin Check on release zip"** step — should now run instead of being skipped.

### Verifying the URL works

Before adding the secret, sanity-check the URL fetches:

```bash
curl -sSLf -o /tmp/parent.zip "<your URL>"
ls -la /tmp/parent.zip   # should be ~few MB
unzip -l /tmp/parent.zip | head -10   # should list parent plugin files
```

If `curl` fails, the workflow will too. Fix the URL before adding the secret.

### Refreshing when the parent updates

The parent plugin (Donation for WooCommerce) periodically releases new versions. When that happens:

1. Download the new zip from woocommerce.com.
2. Upload to your host (overwriting or as a new versioned filename — your choice).
3. If the URL changed, update the `DFWC_PARENT_ZIP_URL` secret value (`gh secret set DFWC_PARENT_ZIP_URL --body "<new URL>"`).
4. Run the parent-contract baseline regen locally:

   ```bash
   # Place the new parent zip at tests/donation-for-woocommerce.zip first
   cp /path/to/new-parent.zip tests/donation-for-woocommerce.zip

   # Then regenerate the contract baseline against the new parent
   composer test:contract:update
   ```

5. Commit + push the new baseline. CI's parent-contract watcher then validates against the new version.

---

## 3. Capture screenshots for wp.org

**When:** before WordPress.org submission. Screenshots are required for the wp.org plugin directory listing.

**Why:** wp.org's plugin directory shows a screenshot carousel at the top of every plugin page. Five screenshots is the typical count. Without them, the listing looks unprofessional and conversions suffer.

**How:**

1. **Spin up wp-env with a fixture that has WPS SFW + parent active:**

   ```bash
   npm run env:start:wps
   ```

   Wait for `localhost:8888` to respond (~30 seconds for the first run, faster on subsequent runs).

2. **Set up demo data.** Create a campaign, configure it, set up a template, etc. — enough to make the screenshots interesting.

   ```bash
   npm run env:cli post create --post_type=wc-donation --post_title="Demo Campaign — School Sponsorship" --post_status=publish
   ```

   Visit the admin and configure the campaign meta box with realistic data.

3. **Capture each screenshot at 1280px wide.** macOS: Cmd+Shift+4 then drag. Windows: Win+Shift+S. Or use a browser DevTools device-emulator viewport.

   Recommended captures (per `docs/release-readiness.md` §1.2):

   - `screenshot-1.png` — Donor view: form on a campaign page with the three intervals + presets visible
   - `screenshot-2.png` — Admin meta box: the Interval-First Donation Form with one tab expanded
   - `screenshot-3.png` — Live preview pane on the campaign edit screen
   - `screenshot-4.png` — Templates list page (`Donations Companion → Templates`)
   - `screenshot-5.png` — Diagnostics page with all 13 health checks visible

4. **Save as PNG to `assets/`:**

   ```bash
   # Move into the right location
   mv ~/Downloads/screenshot-1.png assets/screenshot-1.png
   mv ~/Downloads/screenshot-2.png assets/screenshot-2.png
   # ... etc
   ```

5. **Optional: optimize file size with pngquant or oxipng.** wp.org won't reject large screenshots but smaller is faster to load:

   ```bash
   # macOS: brew install pngquant
   pngquant --quality 75-90 --force --output assets/screenshot-1.png assets/screenshot-1.png
   ```

6. **Add the `== Screenshots ==` block to `readme.txt`.** wp.org parses this section to build the carousel:

   ```
   == Screenshots ==

   1. The interval-first donor form: One-time / Monthly / Annually tabs with separate preset amounts per interval.
   2. Per-campaign admin meta box for configuring intervals.
   3. Live admin preview pane shows exactly what donors will see.
   4. Named templates: configure once, apply to many campaigns.
   5. Diagnostics page: 13 health checks with copy-paste-ready support report.
   ```

   The order matches the `screenshot-N.png` filenames.

7. **Commit + push.** Screenshots ship with the plugin zip; the release workflow includes them automatically.

---

## 4. WordPress.org submission

**When:** after Plugin Check passes in CI (verifying via `DFWC_PARENT_ZIP_URL` secret), readme.txt is finalized, and screenshots are captured.

**Why:** wp.org listing is the path to broad discovery + auto-update support for end users.

**How:**

1. **Verify Plugin Check passes locally** (it will run automatically in CI once the secret is set, but double-check before submission):

   ```bash
   npm run build:zip
   # If you have wp-cli + a WP install:
   wp plugin-check dist/donations-for-woocommerce-companion.zip
   ```

2. **Visit https://wordpress.org/plugins/developers/add/** logged in as your wp.org account.

3. **Fill the form:**
   - Plugin name: `Donations for WooCommerce Companion`
   - Slug check: visit `https://wordpress.org/plugins/donations-for-woocommerce-companion/` to confirm available
   - Plugin URL: https://github.com/f8l124/donations-for-woocommerce-companion
   - Plugin description: copy from `readme.txt`'s `== Description ==`
   - Categorization tags: `donation`, `donations`, `nonprofit`, `recurring`
   - Upload the latest release zip (download from GitHub Releases)

4. **Submit + wait.** Typical review time: 2-6 weeks. wp.org may email you back with feedback — common items:
   - Bundled minified vendor code without source — none in our case
   - Loading external resources at runtime — none
   - License + headers — already present
   - Sanitization / escaping — covered in SECURITY.md

   If feedback is given, address + reply to the email thread. Don't re-submit a new plugin; iterate on the existing submission.

5. **On approval:** wp.org provides SVN access to a new repository at `plugins.svn.wordpress.org/donations-for-woocommerce-companion/`. Follow [`docs/release-process.md`](release-process.md) §"WordPress.org SVN dance" to publish v1.1.0 to the SVN repository.

---

## 5. Submit for WPML compatibility certification

**When:** after wp.org acceptance + at least one stable release with WPML config in place (we have this from v0.7.0 onward).

**Why:** the "Works with WPML" badge is a credibility signal for multilingual nonprofit sites. WPML's review team verifies wpml-config.xml correctness, taxonomy translation, multi-currency, and string-translation registration.

**How:**

1. **Visit https://wpml.org/contact-us/** and pick the "Compatibility check / certification" topic.

2. **Provide:**
   - Plugin slug + wp.org URL: `donations-for-woocommerce-companion` / `https://wordpress.org/plugins/donations-for-woocommerce-companion/`
   - GitHub link: https://github.com/f8l124/donations-for-woocommerce-companion
   - Brief description of WPML integration (the [`docs/wpml.md`](wpml.md) file is a good reference — link it)
   - Any specific testing instructions (the seven-step "End-to-end multilingual setup" in `docs/wpml.md` works)

3. **Wait for review.** Their team contacts you with feedback or approval. Budget time for fixes — certification often surfaces real bugs that we can patch in a v1.1.x release.

4. **On approval:** add the badge to `readme.txt` and `README.md`. WPML provides badge HTML/Markdown. Tag a v1.1.x release that includes the badge.

---

## 6. Refresh the parent-contract baseline

**When:** the parent plugin (Donation for WooCommerce) ships a new version that changes line numbers or content in the ranges the contract watcher checks.

**Why:** the contract watcher compares SHA-256 of specific line ranges in the parent plugin against a baseline. When line numbers shift due to parent edits, the hashes don't match and CI fails — but if the new shape still works at runtime, the failure is informational, not real.

**How:**

```bash
# 1. Place the new parent zip at the standard path
cp /path/to/new-parent.zip tests/donation-for-woocommerce.zip

# 2. Verify the new shape still works at runtime
composer test:contract           # runs current baseline; expect failures
# Manually inspect the failing ranges to confirm the parent's new shape
# is functionally equivalent to the old (rather than a real break).

# 3. Regenerate the baseline against the new parent
composer test:contract:update

# 4. Re-run to verify clean
composer test:contract           # should pass now

# 5. Commit + push
git add tests/parent-contract.baseline.json
git commit -m "tests: refresh parent-contract baseline against parent vX.Y.Z"
git push origin main
```

If the new parent breaks the companion (the shape isn't equivalent — parent removed a hook, changed a method signature, etc.), file a hotfix issue and cut a v1.1.x release that adapts the companion to the new parent.

---

## 7. Manual QA matrix walk-through

**When:** before tagging any release, especially a major version bump.

**Why:** automated tests cover code paths but not visual / interaction quality. The matrix in `docs/manual-qa.md` (and `docs/release-readiness.md` §3) catches things automation can't.

**How:**

The most efficient flow:

1. `npm run env:start:wps` (WPS SFW fixture)
2. Walk through every line of the matrix as a fresh donor + admin
3. `npm run env:destroy && npm run env:start:none` (no engine)
4. Walk through the engine-missing portion of the matrix
5. (Optional) Manual WCS test on staging, since CI doesn't run WCS

Mark each line ✅ as you complete. If any line fails, file a hotfix + don't tag the release until resolved.

Estimated time: ~1 hour for the full matrix.

---

## 8. Post-release monitoring

**When:** for 48 hours after every release.

**Why:** real-world deployment surfaces issues lab testing missed. First 48h is when most regression reports come in; after that the long tail.

**How:**

- Watch GitHub issues: `gh issue list --label bug --state open`
- Watch the Slack / Discord / wherever your community gathers
- Spot-check `wp dfwc-companion health` against any nonprofit you have direct contact with (if they're willing to share the report)
- Watch error monitoring (Sentry, etc.) for spikes attributed to the new version

If a critical bug surfaces, follow the hotfix process in [`docs/release-process.md`](release-process.md) §"Hotfix process".

---

## Quick reference

| Task | Document |
|---|---|
| `.pot` regen | This doc §1 |
| `DFWC_PARENT_ZIP_URL` setup | This doc §2 + [`ci.md`](ci.md) |
| Screenshots for wp.org | This doc §3 |
| wp.org submission | This doc §4 |
| WPML certification | This doc §5 + [`wpml.md`](wpml.md) |
| Contract baseline refresh | This doc §6 |
| Manual QA | This doc §7 + [`manual-qa.md`](manual-qa.md) |
| Post-release monitoring | This doc §8 |
| Cutting a release | [`release-process.md`](release-process.md) |
| Where things stand | [`release-readiness.md`](release-readiness.md) |
