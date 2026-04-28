# Contributing to Donations for WooCommerce Companion

Thanks for your interest. This is a small, focused plugin and contributions are welcome — bug reports, feature requests, pull requests, even just feedback on the [pinned roadmap issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues/1).

## Local setup

You'll need PHP ≥ 7.4, Node ≥ 18, Composer, and Docker (for `wp-env`).

```bash
git clone https://github.com/f8l124/donations-for-woocommerce-companion.git
cd donations-for-woocommerce-companion
composer install      # dev tooling only — runtime ships without vendor/
npm install           # Playwright + wp-env
```

The parent plugin (Donation for WooCommerce) is a paid Woo extension and isn't on the WordPress.org repository. To run integration tests locally, place a copy of the parent plugin zip at `tests/donation-for-woocommerce.zip` (gitignored). Without it, the contract watcher and Playwright tests skip cleanly — PHP lint, PHPCS, PHPStan, and PHPUnit still run.

## Running checks

```bash
composer check          # PHPCS + PHPStan + PHPUnit (combined gate)
composer lint:phpcs     # PHPCS only
composer fix:phpcbf     # auto-fix PHPCS violations where possible
composer analyze        # PHPStan only
composer test:unit      # PHPUnit only
composer test:contract  # parent contract watcher (requires tests/donation-for-woocommerce/)
npm run test:e2e        # Playwright (requires tests/donation-for-woocommerce.zip + Docker)
npm run build:zip       # produces dist/donations-for-woocommerce-companion.zip
```

CI runs the same checks on every pull request. CI workflows that need the parent plugin gracefully skip when the `DFWC_PARENT_ZIP_URL` repo secret isn't configured — fork PRs don't fail because of it.

## Architecture & roadmap

Before writing code, please read:

- [docs/architecture/current-state.md](docs/architecture/current-state.md) — how the plugin works
- [docs/architecture/parent-contract.md](docs/architecture/parent-contract.md) — what we depend on in the parent plugin
- [docs/architecture/compatibility-matrix.md](docs/architecture/compatibility-matrix.md) — what's tested where
- The [pinned roadmap issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues/1) — what's coming when

Per-phase implementation plans live in `plans/v2/` (gitignored — local working docs). The cross-cutting WPML integration spec is at `plans/v2/AA-wpml-integration.md`. If you don't have the plan files locally, browsing the existing `includes/` and the architecture docs is enough to get oriented.

## Code style

- **Coding standards.** WordPress Coding Standards (WPCS) via PHPCS. Run `composer fix:phpcbf` before committing; it auto-fixes most violations.
- **Prefix everything global.** Functions, classes, hooks, options, transients, cron events: `dfwc_companion_*` for hooks/options, `DFWC_COMPANION_*` for constants, `DFWC\Companion\*` for classes.
- **Escape on output, not on assignment.** Variables built in PHP get escaped at echo via `esc_html` / `esc_attr` / `esc_url` / `wp_kses` / `wp_json_encode`.
- **Sanitize at boundary.** All `$_POST` / `$_GET` reads run through `wp_unslash` then a type-appropriate sanitizer (`sanitize_text_field`, `absint`, `wc_format_decimal`, allow-list).
- **No raw SQL.** Use `update_post_meta`, `update_option`, `WP_Query`. If a future feature requires SQL, use `$wpdb->prepare`.
- **Capability checks before writes.** `current_user_can( 'manage_woocommerce' )` for admin pages; `current_user_can( 'edit_post', $id )` for per-post writes.
- **Nonces on every form.** `wp_nonce_field` + `check_admin_referer`. REST writes via `permission_callback`.
- **Translatable strings.** Wrap user-visible text in `__()` / `esc_html__()` / `esc_attr__()` with text domain `dfwc-companion`. Add `/* translators: ... */` comments for ambiguous strings.

PHPCS will catch most of these automatically.

## Architectural principles

The plugin operates under these rules; PRs that violate them will be asked to revise:

1. **Augment, don't replace.** The parent plugin owns transaction processing — payment, cart, checkout, order, subscription, email, gateway. The companion controls presentation and configuration. Don't fork the parent's payment logic.
2. **Hooks-only integration with the parent.** Use the parent's documented action/filter surface. No reach-into-private-class patterns. No copy-paste of parent code.
3. **Tight integration.** Settings live under `WooCommerce → Donations Companion`. Use WP admin's existing UI vocabulary (`form-table`, `notice` classes, `WP_List_Table`, `submit_button()`). The "users can't tell this is a third-party plugin" goal is non-negotiable.
4. **Backward compat.** Existing campaigns must continue working through every release without admin action. Schema migrations are forward-only and idempotent.
5. **Security baseline.** See [README architecture docs](docs/architecture/) and the WordPress security posture in WPCS — every PR must pass these gates.
6. **WPML compatibility.** Every admin-defined string is translatable via WPML's String Translation API. See `plans/v2/AA-wpml-integration.md` for specifics.

## Commit messages

Present-tense, scoped. The scope identifies the area touched.

Good:
```
feat(templates): add bulk-apply action on campaign list
fix(overlay): hide parent's .row3 wrapper for WCS recurring controls
docs(architecture): document parent contract bug at line 1655
chore(ci): add Plugin Check workflow
```

Bad (too vague):
```
update files
fixed bug
```

**Do not include AI tooling attribution** (`Co-Authored-By: Claude` etc.) — David Stells is the sole author. PRs with AI attribution will be asked to revise.

## Pull request flow

1. Open an issue first for non-trivial changes — saves rework if the design needs discussion
2. Branch from `main`
3. Make focused commits; one concern per commit
4. Run `composer check` + `npm run test:e2e` (if you have the parent zip) locally before opening the PR
5. Open the PR with a clear description: what changed, why, and how to verify
6. Address CI feedback
7. Squash-merge after approval

For PRs targeting v0.6.x bug fixes, branch from `v0.6.x` (the maintenance branch), not `main`.

## Reporting bugs

[Open a GitHub issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues/new). Include:

- Plugin version (`Donations for WooCommerce Companion: x.y.z`)
- Parent plugin version (`Donation for WooCommerce: x.y.z`)
- WooCommerce version
- WordPress version
- Active subscription engine (WCS / WPS SFW / none)
- Steps to reproduce
- Expected vs. actual behavior

If the issue surfaces on the donor side, copy the **Diagnostics support report** from `WooCommerce → Donations Companion → Diagnostics` (Phase 2 deliverable; available from v0.7.0 forward).

## Reporting security issues

Please do not open public issues for security vulnerabilities. Email **stells.david@gmail.com** or use [GitHub Security Advisories](https://github.com/f8l124/donations-for-woocommerce-companion/security/advisories/new). I'll respond within 7 days.

## Release process

Maintainer-only. See [docs/release-process.md](docs/release-process.md) (Phase 11 deliverable).

## License

By contributing, you agree your contributions are licensed under [GPL-2.0-or-later](LICENSE), the same license as the plugin.
