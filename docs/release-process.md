# Release Process

How to cut a release of the Donations for WooCommerce Companion plugin. Applies to both stable releases (vX.Y.Z) and pre-release candidates (vX.Y.Z-rcN).

---

## Pre-flight (1 day before tag)

- [ ] All phase tests green locally: `composer check`
- [ ] Parent contract verified: `composer test:contract`
- [ ] Manual QA matrix walked through where applicable: [docs/manual-qa.md](manual-qa.md)
- [ ] Plugin Check run locally on the current main if you have it set up
- [ ] Translation `.pot` regenerated if any user-facing strings changed
- [ ] No `WP_DEBUG` notices on a fresh wp-env activate

---

## Bump (commit `vX.Y.Z: <one-line summary>`)

The version string lives in **four** canonical places. All four must match — the [`scripts/check-version-consistency.js`](../scripts/check-version-consistency.js) script gates CI on this.

1. **`donations-for-woocommerce-companion.php`** — both the `Version:` header line and the `define( 'DFWC_COMPANION_VERSION', '...' )` constant.
2. **`package.json`** — `"version"` field.
3. **`readme.txt`** — `Stable tag:` field.
4. **`.release-notes.md`** — file is overwritten per release with the current version's notes.

Plus the per-release content:

5. **Changelog entry in `readme.txt`** under `== Changelog ==`. Use the existing entries as a stylistic template — bullet points, bold markers for `**New:**`, `**Fix:**`, `**Internal:**`, etc.
6. **Upgrade Notice in `readme.txt`** under `== Upgrade Notice ==`. Short (1–2 sentences) — the wp.org plugin updater shows this to admins before they click "Update".

Commit:

```bash
git add donations-for-woocommerce-companion.php package.json readme.txt .release-notes.md
git commit -m "vX.Y.Z: <one-line summary>"
```

Commit message style examples (from history):

```
v0.6.6: stop hiding parent's recurring select + fix custom-amount toggle persistence
v0.9.0: bump version + changelog + release notes
```

---

## Tag and push

```bash
git tag vX.Y.Z
git push origin main vX.Y.Z
```

The release workflow at [.github/workflows/release.yml](../.github/workflows/release.yml) fires on tag-push:

1. Runs the full lint + test pipeline one more time
2. Optionally runs Plugin Check (if `DFWC_PARENT_ZIP_URL` secret is set)
3. Builds the distribution zip via [`scripts/build-zip.js`](../scripts/build-zip.js)
4. Verifies the version in the zip matches the tag
5. Creates a GitHub Release with the zip attached and the contents of `.release-notes.md` as the release body

Verify by visiting https://github.com/f8l124/donations-for-woocommerce-companion/releases.

---

## Post-release

- [ ] Watch GitHub issues for the first 48h for regressions
- [ ] Spot-check on a real wp-env install: download the release zip, install + activate, walk a donor flow
- [ ] If a critical issue surfaces: cut a vX.Y.Z+1 hotfix following the same process. Don't amend or force-push the original tag — release zips that have already been downloaded must stay reproducible.

---

## WordPress.org SVN dance (post v1.0.0 acceptance)

After the wp.org repository accepts the plugin (typical 2–6 weeks after submission), publishing a new version to wp.org requires SVN. The GitHub Releases continue alongside.

```bash
# Initial setup (once)
svn checkout https://plugins.svn.wordpress.org/donations-for-woocommerce-companion/ wp-org-svn
cd wp-org-svn

# For each new release
# 1. Sync the plugin contents into trunk/ (excluding repo-only files; see .distignore)
# 2. Update readme.txt's Stable tag field if appropriate
# 3. svn add new files, svn rm deleted files
svn ci -m "vX.Y.Z release"

# 4. Tag the release
svn cp trunk tags/X.Y.Z
svn ci -m "Tagging vX.Y.Z"
```

The `Stable tag:` field in `readme.txt` is what wp.org's update system actually serves. Bump it to the new version only AFTER `svn cp` to `tags/X.Y.Z` succeeds — otherwise the wp.org auto-update infrastructure will start serving a tag that doesn't exist yet and update fetches will 404.

---

## Hotfix process

When a critical issue ships with a stable release:

1. Branch from the tag: `git checkout -b hotfix/vX.Y.Z+1 vX.Y.Z`
2. Fix + commit + push the branch
3. Open a PR targeting `main`. Smaller scope = faster review.
4. After merge, follow the standard bump + tag + push process for `vX.Y.Z+1`. The hotfix release notes should explicitly call out what regressed and what the fix changes.
5. If the maintenance branch (`v0.6.x`) is affected by the same issue, cherry-pick the fix there too and tag a maintenance release.

---

## Release version semantics

| Bump | When |
|---|---|
| **Major** (vX.0.0) | Public API breaks, schema migrations that aren't backward-compatible, removal of a documented feature |
| **Minor** (v1.X.0) | New feature, no breakage. The default for shipping a phase. |
| **Patch** (v1.0.X) | Bug fix only, no feature additions, fully backward compatible |

The v0.6.x → v1.0.0 bump was MAJOR because v1.0.0 is the first stable; subsequent feature releases (Phase 9 event hooks, etc.) are MINOR.

---

## What NOT to do

- Don't force-push tags. A released zip must stay reproducible — admins who downloaded vX.Y.Z need their cached zip to still match the tag.
- Don't skip the `.release-notes.md` update. The release workflow uses it as the GitHub Release body.
- Don't tag from a branch other than `main`. Hotfix branches should land via PR first.
- Don't bypass `composer check` to ship faster. The check pipeline is fast (~30 seconds) and catches things you can't see.
