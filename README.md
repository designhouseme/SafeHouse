<h1>
  <picture>
    <source media="(prefers-reduced-motion: reduce)" srcset="design/hero/hero-still.webp">
    <img src="design/hero/hero.webp" width="1200" alt="SafeHouse">
  </picture>
</h1>

**One WordPress plugin instead of the single-purpose ones most sites collect over time.** Hardening, bot protection, login limits, install lockdown, change and vulnerability alerts, page and object caching, and the everyday tweaks people usually install a separate plugin for.

Each feature is a module with its own switch. The regression suites cover WordPress requests, WP-CLI, cron and selected REST/WooCommerce flows; third-party integrations still need testing on the target site. SafeHouse can run alongside Wordfence.

SafeHouse has no firewall and no malware scanner. Leave those to Wordfence, Cloudflare or your host.

## Modules

| Module | What it does | Default |
|---|---|---|
| Hardening | File editor off, no username discovery, generic login errors, version hidden, XML-RPC off, security headers, no admin role for new accounts | on |
| Change alerts | Queued alerts for sensitive capability grants, custom roles, plugins, themes, files and key settings | on |
| Plugin health | Flags plugins that are closed, abandoned, not from WordPress.org, left over, or replaceable by SafeHouse | on |
| Stability | Local PHP incident samples, temporary HTTP timing, and bounded WP-Cron/Action Scheduler diagnostics | on |
| Vulnerability alerts | Known vulnerabilities in core, plugins and themes, from signed Wordfence Intelligence data (stands down when Wordfence runs) | on |
| Login limits | Address lockouts that grow longer each time; accounts under attack are paused only for new devices | on |
| Omnibus price history | Recorded pre-reduction minimum; incomplete or missing history is identified explicitly | on with WooCommerce |
| Bot protection | Honeypot, plus Cloudflare Turnstile on login, registration, comments and both WooCommerce checkouts (Store API included) | off |
| Install lockdown | Nobody installs plugins or themes or uploads ZIPs; updates still work | off |
| LiteSpeed page cache | Cache headers and purges for LiteSpeed servers, with no files written | off |
| Cloudflare cache | Queues whole-zone purges after content changes and retries failed requests | off |
| SMTP mail, Header and footer scripts, Duplicate posts, Maintenance mode, Tweaks | The small things sites usually install separate plugins for | off |

A Redis object cache (signed values, its own keys only) is installed with `wp shouse object-cache enable`.

Stability records observed requests over three seconds, memory pressure and PHP failures after SafeHouse loads. A temporary 15-minute session samples roughly 10% of requests and measures up to 20 WordPress HTTP API calls per sampled request. It stores no raw error messages, URLs, request bodies, cookies, SQL or credentials. Storage is a fixed set of 256 replaceable groups, with at most one saved observation per ten seconds site-wide. The panel shows the last seven days; daily WP-Cron cleanup removes older records when cron runs. Counts are saved samples, not complete traffic/error totals. An error location does not establish which plugin caused resource exhaustion.

In **SafeHouse → Stability**, use **Check background queues** for a bounded scheduler snapshot. Checks also run hourly; unavailable, stale and truncated results are labelled. A disabled visitor-triggered cron can be intentional when the host runs cron. SafeHouse never executes or deletes third-party work during a diagnostic check. Custom Action Scheduler storage implementations are reported as unsupported. The observer runs in PHP and cannot interrupt arbitrary stuck code, record every out-of-memory error, or observe a process killed by the host. Recovery Mode and hosting logs remain necessary.

```sh
wp shouse stability status       # cached observations and delivery state
wp shouse stability scan         # collect scheduler observations; no jobs executed
wp shouse stability sample on    # expires after 15 minutes; use off to stop early
wp shouse queue status           # includes paused work, also available in safe mode
wp shouse queue run --limit=10   # one bounded delivery batch
wp shouse queue resume --limit=20 # only after correcting the cause of failure
```

Delivery uses one leased worker per site, at most 20 jobs per invocation and a five-second cooperative budget with memory headroom. Limits are checked between operations and cannot preempt a blocked callback. After five failed attempts a job is retained as paused; inspect and resume it explicitly. Alert and purge delivery requires working cron. Pending work remains durable without a hard backlog size cap, so prolonged transport failures require operator attention. Full privilege inventories run in resumable slices; direct sensitive grants/revocations are still checked immediately.

<details>
<summary><strong>The plugins it replaces</strong></summary>
<br>

Plugin health recognises these plugins and names the SafeHouse module that does their job. The animation above folds the same list.

| SafeHouse module | Instead of |
|---|---|
| SMTP mail | `wp-mail-smtp`, `post-smtp`, `fluent-smtp`, `easy-wp-smtp`, `smtp-mailer`, `wp-smtp` |
| Login limits | `limit-login-attempts-reloaded`, `loginizer`, `login-lockdown`, `limit-login-attempts`, `wp-limit-login-attempts` |
| Bot protection | `simple-cloudflare-turnstile`, `recaptcha-woo`, `advanced-nocaptcha-recaptcha`, `google-captcha`, `honeypot` |
| Duplicate posts and pages | `duplicate-page`, `duplicate-post`, `post-duplicator` |
| Maintenance mode | `wp-maintenance-mode`, `coming-soon`, `maintenance`, `under-construction-page` |
| Header and footer scripts | `insert-headers-and-footers`, `header-and-footer-scripts`, `header-footer-code-manager`, `head-footer-code`, `wp-headers-and-footers`, `tracking-code-manager`, `hotjar`, `microsoft-clarity` |
| Hardening | `disable-xml-rpc`, `disable-xml-rpc-api`, `stop-user-enumeration` |
| Tweaks | `disable-comments`, `disable-search`, `disable-emojis`, `disable-embeds`, `heartbeat-control` |
| Omnibus price history | `omnibus`, `wc-price-history`, `omnibus-by-ilabs`, `omnibus-for-woocommerce`, `product-price-history` |
| Cloudflare cache | `cloudflare` |

</details>

The [wiki](https://github.com/designhouseme/SafeHouse/wiki) is the full documentation: getting started, every module, the wp-config constants and WP-CLI commands, troubleshooting and the external services SafeHouse contacts. [`plugin/readme.txt`](plugin/readme.txt) is the short version that ships with the plugin.

## With Wordfence

Wordfence is optional. When it is active, the SafeHouse features it already provides stand down on their own, so the two never do the same job twice:

- Hardening skips what Wordfence has switched on: username discovery blocking, version hiding and, on sites without WooCommerce, login error masking. The settings page marks each skipped field "Handled by Wordfence, skipped here."
- Vulnerability alerts stand down, because Wordfence warns about vulnerable software itself.
- Login limits stand down while Wordfence brute force protection is on.
- The login and registration captcha steps aside when Wordfence Login Security has its reCAPTCHA on.

## Built not to be the way in

A security plugin must not become the weak spot itself:

- **Signed updates.** WordPress installs a SafeHouse update only when its Ed25519 signature and its checksum both match. How releases are built, signed and checked is under [Install](#install).
- **Authorized changes.** Administrative actions check the required capability and a nonce. Duplication also checks metadata and taxonomy permissions. Public authentication and checkout handlers use the relevant WordPress/WooCommerce checks and bot verification.
- **Limited filesystem changes.** SafeHouse does not edit `.htaccess` or `wp-config.php`. Updates use private temporary files and WordPress replaces the plugin package. CLI can install the Redis drop-in and manage the safe-mode flag; deactivation/uninstall removes the owned drop-in.
- **No telemetry.** The activity log stays in the site's database and deletes entries after 90 days.
- **Safe mode.** If a change locks you out, upload an empty file named `shouse-safe-mode` to `wp-content` (FTP is enough), add `define( 'SHOUSE_SAFE_MODE', true );` to `wp-config.php`, or run `wp shouse safe-mode on`. Feature modules stop until you remove the flag. The updater, diagnostics, already queued alert delivery and pending cache invalidation remain available; safe mode is not a read-only database mode. After enabling it by a file/constant, visit an uncached endpoint such as `wp-admin/admin-ajax.php` or ask the host to purge: a pre-existing server cache hit cannot execute PHP. The CLI command triggers that request automatically.

## Install

Requirements: WordPress 6.6+ and PHP 8.1+. WooCommerce is optional.

1. Download `shouse-x.y.z.zip` from [Releases](https://github.com/designhouseme/SafeHouse/releases) and upload it in Plugins → Add New → Upload Plugin.
2. Activate it and open **SafeHouse** in the admin menu.

Updates come from the Design House update host, not WordPress.org. Every release is signed with Ed25519, and WordPress installs a package only when the manifest signature and the package checksum both match. New releases carry the ZIP, atomic `release.json` envelope and legacy `manifest.json` / `manifest.json.sig` files, so anyone can check a download against the signed checksum. The [Release workflow](.github/workflows/release.yml) builds from the version tag and puts signing credentials in the `release` environment. Configure required reviewers for that environment in GitHub; the workflow file alone cannot enforce a human approval. The build is reproducible: `./dev/release.sh build x.y.z` rebuilds a byte-identical zip from the tag.

Do not run the plugin from a clone of this repository on a live site. A copy inside a git working copy never updates itself.

## Security guarantees and operation

- Signed release and advisory metadata carry expiry and generation numbers. New clients reject older accepted generations and expired data; a compromised host can still withhold data. Correct server time and metadata renewal are required. See [protocol and rollout](dev/SIGNED-DATA-PROTOCOL.txt) before changing the Workers or publisher.
- Redis signatures prevent unsigned value injection. User/session/role-option groups remain request-local so replayed Redis values cannot restore their old state. Other business caches still require isolated Redis ACLs: signatures alone do not prevent replay. Redis failure during a request uses a runtime cache; transient data may disappear between requests, as allowed by WordPress. Startup recovery uses the database generation and invalidates stale keys before Redis is reused.
- Alerts and Cloudflare purges use a durable database queue. WP-Cron retries failures with backoff and pauses jobs after five failed attempts; Site Health reports pending/retried work. Use a reliable system cron on sites without regular traffic. SMTP acceptance does not prove inbox delivery, and a crash after remote acceptance can cause a duplicate. Pending alert recipients and bodies stay in the site's database until accepted; password-reset mail is not added to this outbox.
- Change alerts monitor stored sensitive capabilities, including custom roles and direct grants, plus the documented inventory. Runtime capability filters and arbitrary file contents are outside that inventory; it is not a malware scanner.
- LiteSpeed public caching stays paused until server cookie vary rules cover every required login, cart, comment and password cookie. Apply the per-site rules shown in the module panel, restart LiteSpeed and run Site Health. Existing sessions must bypass without `_lscache_vary`. This was verified on OpenLiteSpeed 1.9.2; PHP response headers alone were insufficient. The plugin does not edit `.htaccess`.
- Content, taxonomy, stock and price changes conservatively purge the whole page cache/Cloudflare zone. This includes old URLs and unknown listing pages, at the cost of additional cache misses. An API acknowledgement does not independently prove edge eviction.
- Price history reports observed data only. A shorter observation period is labelled partial; missing history is shown as unavailable. The schema upgrade starts a new trusted observation period because older code could infer historical timestamps; old rows remain inspectable. Long active reductions retain their preceding window. No reconstructed history or automatic legal-compliance guarantee is provided.

## Repository layout

```
plugin/     the plugin as it ships: shouse.php, src/, assets/, languages/, readme.txt, uninstall.php
site/       the landing page (static files on Cloudflare Workers)
updates/    the update host: a read-only Cloudflare Worker serving release files and vulnerability data from R2,
            and updates/ingest/, the token-protected publisher with separate release/advisory credentials
dev/        Docker environments, test scripts and release tooling
design/     the README animation: an HTML composition rendered to WebP (design/hero/README.md)
CHANGELOG.md, composer.json, phpcs.xml.dist, phpstan.neon.dist
```

Release ZIPs are built from `plugin/` only.

## Development

You need Docker. You don't need PHP or Composer on the host.

```sh
./dev/setup.sh                                # localhost:8894; admin password in ignored build/dev-credentials.env
./dev/wp.sh shouse status                      # WP-CLI inside the container
./dev/lint.sh                                  # PHPCS (WordPress coding standards) + PHPStan
./dev/smoke.sh                                 # HTTP and WP-CLI regression checks against the dev site
./dev/security-test.sh                         # disposable release-gate tests, including real Redis
```

Mailpit catches outgoing dev mail at http://localhost:8025. All published dev ports bind to `127.0.0.1`. Setup generates random database and administrator passwords in `build/dev-credentials.env` (mode 0600). Source `dev/env.sh` before raw Compose commands. Existing volumes retain their credentials: deliberately migrate them or recreate disposable volumes; setup does not silently reset a site. The standalone security gate publishes no ports and blocks outbound mail and HTTP in its fixtures.

Other test environments:

- `dev/ols/`: OpenLiteSpeed with Redis, for the page cache, object cache, login limits and Cloudflare tests.
- `dev/update-test.sh`: signed updates end to end, on an isolated site with a throwaway key.
- `dev/package-test.sh`: install and uninstall from a release ZIP.
- `dev/updates-test.sh` and `dev/ingest-test.sh`: both Workers against a local R2 bucket.
- `dev/cve-watch-test.sh`: the CVE watch issue logic against a mock GitHub API.

### Rules for changes

- Administrative mutations require an appropriate capability and a nonce; public handlers enforce their own protocol and authentication checks.
- No `eval`, `unserialize`, `extract` or `do_shortcode` on input.
- `$wpdb->prepare()` everywhere, and output is escaped.
- The visitor IP comes from `REMOTE_ADDR`, unless trusted-proxy rules say otherwise.
- Do not edit server configuration. Keep authorized update, drop-in and safe-mode filesystem changes narrow and documented.
- No remote calls beyond those listed under External services in `readme.txt`.
- Code and source strings are in English. Translations go in `plugin/languages/` (`./dev/i18n.sh`).

## Security

Please report vulnerabilities privately: on the **Security** tab, use **Report a vulnerability**. Do not open a public issue.

## License

Apache License 2.0. See [LICENSE](LICENSE). Releases up to 0.2.0 were published under GPL-2.0-or-later. The Geist fonts in `design/hero/fonts/` are under the SIL Open Font License.
