# CI Setup

## Why CI needs a private secret

The parent plugin **Donation For Woocommerce** (by WPExperts) is a paid Woo extension sold via [woocommerce.com](https://woocommerce.com/), not a free plugin on wordpress.org. CI can't fetch it from `downloads.wordpress.org` and the companion can't bundle the parent zip in its repo (it's not ours to redistribute).

To run the **Playwright E2E** and **Parent contract watcher** workflows in GitHub Actions, you need to host the parent zip somewhere that supports a stable HTTPS download (private GitHub release on a separate repo, S3 with a long-lived signed URL, Backblaze B2, etc.) and configure that URL as a repo secret.

The **Lint** workflow doesn't need the parent and runs unconditionally.

If `DFWC_PARENT_ZIP_URL` is not set, the parent-dependent workflows skip with a clear `::notice` line in the run summary — they don't fail.

## Setting `DFWC_PARENT_ZIP_URL`

1. Sign in to your WooCommerce.com account → My Account → Downloads → grab the latest `donation-for-woocommerce.zip`.
2. Upload it to a host of your choice. Easiest options:
   - **Private GitHub release on a sibling repo.** Create a private repo (e.g. `dfwc-fixtures`), publish a release, attach the zip. The asset URL is `https://github.com/<user>/dfwc-fixtures/releases/download/v3.9.8/donation-for-woocommerce.zip` and requires a GitHub PAT with `repo` scope on that repo to download. Use a per-repo deploy token rather than a global PAT if possible.
   - **S3 / Backblaze with a signed URL.** Generate a signed URL with a long expiry (e.g. 1 year). Rotate annually.
   - **Cloudflare R2 with public bucket + obscure path.** Lower-friction; security through obscurity. Acceptable since the zip is your personally-licensed copy, not redistributable confidential material.
3. In the companion repo settings → Secrets and variables → Actions → New repository secret:
   - Name: `DFWC_PARENT_ZIP_URL`
   - Value: the HTTPS URL from step 2.
4. Re-run any failed workflow (Actions tab → workflow → Re-run jobs).

The IDE's GitHub Actions linter may show a "Context access might be invalid: DFWC_PARENT_ZIP_URL" warning until the secret exists. It's safe to ignore.

## Refreshing the URL

When the parent plugin updates and you want CI to track the new version:

1. Download the new zip from woocommerce.com.
2. Upload to the same host (overwriting or new versioned filename).
3. If the URL changed, update the `DFWC_PARENT_ZIP_URL` secret value.
4. Run `npm run test:contract:update` locally against the new parent to refresh `tests/parent-contract.baseline.json`.
5. Commit + push the new baseline. CI's parent-contract watcher will then validate the new version against the new baseline.

## Local development setup

Local runs (`npm run env:start:wps` etc.) read the parent zip from a relative path:

```
tests/donation-for-woocommerce.zip
```

This file is gitignored. Set it up once:

```bash
# From wherever you've stashed the parent zip from woocommerce.com:
cp /path/to/donation-for-woocommerce.zip tests/donation-for-woocommerce.zip
```

Then `npm run env:start:wps` works end-to-end without needing the secret.

## Workflows summary

| Workflow | Needs parent zip? | Skips gracefully without it? |
|---|---|---|
| `lint.yml` | No | n/a |
| `parent-contract.yml` | Yes | Yes — `::notice` and exit success |
| `playwright.yml` | Yes | Yes — `::notice` and exit success |
