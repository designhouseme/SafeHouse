# Changelog

Each release needs a `## x.y.z` section; its body is shown in the WordPress "View details" window.

## Unreleased

- Canonical account limits, early password-check rejection, application-password lock stability, strict proxy CIDRs and operation-scoped Turnstile checks.
- Metadata and taxonomy authorization when duplicating content.
- Durable alert/purge queues, retries and Site Health status; monitoring of stored sensitive capabilities, custom roles and direct user grants.
- Redis recovery generations, isolated user/role state, unambiguous namespaces, multisite runtime separation and atomic signed counters.
- Cache lifecycle fixes and conservative invalidation for old URLs, terms, prices and listings.
- Observed price history only: unavailable/partial states, complete windows beyond 1000 changes, long-reduction retention, zero prices and visible storage failures. Migration begins a new trusted observation period.
- Atomic signed release/advisory envelopes, expiry and persistent anti-rollback floors, private size-limited downloads and conditional immutable publication. See dev/SIGNED-DATA-PROTOCOL.txt for rollout and renewal.
- Isolated digest-pinned signer, security release gates, local-only dev ports, random dev credentials and strict SMTP encryption validation.

## 0.2.0

- Omnibus price history for WooCommerce, on by default: every price change of products and variations is recorded, and the lowest price from the 30 days before a reduction is shown next to reduced prices on product pages, in lists and for the picked variation. A product already reduced when recording starts shows its regular price there until its price next changes. `wp shouse omnibus status` lists reduced products and where each figure comes from.
- Renamed from WPHouse to SafeHouse. The plugin folder and file, the WP-CLI command, the wp-config.php constants and everything stored in the database now use the `shouse` prefix (`shouse/shouse.php`, `wp shouse`, `SHOUSE_*`).
- Moving an existing site: activate `shouse`. Settings, the activity log, login limits and scheduled checks move over on their own. `WPHOUSE_*` constants and the `wphouse-safe-mode` file keep working, so wp-config.php can be updated later. With the Redis object cache on, run `wp shouse object-cache enable` once to install the new loader; until then WordPress uses its own cache.

## 0.1.0

- Core: module registry with per-module switches, schema-driven settings page, event log, safe mode, WP-CLI (`wp shouse …`), Wordfence overlap detection.
- Hardening: file editor off, username discovery blocked, generic login errors, version hidden, XML-RPC off, basic security headers, optional HSTS, no admin-level role for new accounts, registration check in Site Health.
- Bot protection: honeypot on registration, lost password and comments; Cloudflare Turnstile on login, registration, lost password, comments and checkout, including the Store API.
- Install lockdown: no new plugins, themes or ZIP uploads (updates keep working), timed unlock.
- Change alerts: new administrators, plugins, themes, mu-plugins, drop-ins, wp-config.php edits, and changes to registration, the default role, the admin e-mail and the site address.
- Plugin health: closed, abandoned, unknown-source and forgotten plugins, also in Site Health.
- Vulnerability alerts for sites without Wordfence, from signed Wordfence Intelligence data.
- Login limits: lockouts by address, accounts paused only for new devices; Cloudflare visitor addresses (`Proxy in front of the site`).
- Cloudflare cache: clears changed pages after edits, everything after site-wide changes (API token in wp-config.php).
- Integrations card for Wordfence, WooCommerce, payment gateways and Elementor.
- LiteSpeed page cache without the LiteSpeed Cache plugin: cache headers only, safe for WooCommerce.
- Redis object cache with signed values, installed with WP-CLI (`wp shouse object-cache enable`).
- Tweaks, duplicate posts, SMTP from wp-config, header/footer scripts, maintenance mode.
- Signed self-hosted updates.
- Design House branding on the settings page, the Updates screen and alert e-mails.
