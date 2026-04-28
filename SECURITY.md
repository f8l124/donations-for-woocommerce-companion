# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in **Donations for WooCommerce Companion**, please report it privately so a fix can ship before details become public.

**Preferred channel:** [GitHub Security Advisories](https://github.com/f8l124/donations-for-woocommerce-companion/security/advisories/new) — private to the maintainers, supports CVE assignment, and integrates with GitHub's coordinated-disclosure flow.

**Alternate:** email **stells.david@gmail.com** with the subject line `[dfwc-companion security]`.

Please include:

- The plugin version affected (e.g. `1.0.0`)
- A description of the issue and the impact (data exposure, RCE, privilege escalation, etc.)
- Steps to reproduce — minimal proof-of-concept preferred
- Your name and contact info if you'd like to be credited in the fix release notes

## What to expect

- **Acknowledgement:** within 72 hours of receipt
- **Initial assessment + planned fix timeline:** within 7 days
- **Fix release:** target depending on severity
  - Critical (RCE, donor-data exposure, auth bypass): patch within 7 days
  - High (admin-only impact, privilege escalation requiring auth): patch within 14 days
  - Medium / low: rolled into the next minor release
- **Public disclosure:** coordinated with you. Credit in the release notes unless you prefer to remain anonymous.

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | ✅ Yes |
| 0.6.x | ✅ Yes (maintenance branch — security patches only) |
| 0.5.x and earlier | ❌ No — please upgrade |

## Security architecture

The plugin's security baseline is documented in the [v2 master plan §6](https://github.com/f8l124/donations-for-woocommerce-companion/blob/main/plans/v2/00-master.md). Key invariants:

- **No direct SQL outside `$wpdb->prepare`.** All persistence via `update_post_meta` / `WP_Query`.
- **All form handlers verify nonces + capabilities** before reading `$_POST`.
- **Output escaping at echo time** — never at construction. `wc_price()` HTML allowed via explicit `wp_kses` allow-list.
- **REST endpoints rate-limited** — preview endpoint to 10 req/sec/user; public grid to 60 req/min/IP.
- **Server-side guard** on every donor submit re-validates amount range + interval allow-list, so client-side bypass via DevTools / curl is rejected with HTTP 422.
- **No remote runtime fetches.** No analytics. No telemetry.
- **Uninstall is opt-in.** `preserve_data_on_uninstall` defaults to ON; admins must explicitly opt in to data deletion.

If you're considering an audit or pen-test, the [`docs/architecture/`](docs/architecture/) directory and the [`includes/Contracts/`](includes/Contracts/) tree are the highest-signal entry points.

## Out of scope

The following are not security issues for this plugin specifically — please report them to the relevant project instead:

- Vulnerabilities in **WooCommerce** core → [WooCommerce GitHub](https://github.com/woocommerce/woocommerce/security/advisories)
- Vulnerabilities in the **Donation for WooCommerce** parent plugin → contact WCDonation support
- Vulnerabilities in **WooCommerce Subscriptions** or **Subscriptions For WooCommerce** → contact those vendors
- Issues that require physical access to the server, an already-compromised admin account, or manual modification of plugin files (i.e., post-exploitation scenarios)
- Theoretical issues without a concrete attack path

For dual-use issues (e.g., a parent-plugin issue exposed via the companion's data flow), the companion side gets a workaround alongside the upstream fix when feasible.
