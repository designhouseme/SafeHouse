=== WPHouse ===
Contributors: designhouse
Tags: security, hardening, captcha, vulnerability, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One plugin instead of a dozen small ones: hardening, bot protection, install lockdown, change and vulnerability alerts, everyday tweaks. Works on its own or next to Wordfence.

== Description ==

WPHouse replaces the single-purpose plugins most sites collect over time with one small plugin. Every feature is a module you switch on or off on the WPHouse page in the admin menu, and every module is safe in WP-CLI, cron, REST and AJAX requests.

WPHouse does not need Wordfence. When Wordfence is active, the WPHouse features it already provides stand down on their own, so the two never do the same job twice.

= Modules =

On by default:

* **Hardening:** no theme and plugin file editor; no username discovery for visitors (`?author=` scans, the REST users endpoint, the users sitemap, author data in oEmbed); one generic login error on wp-login.php and the WooCommerce login form; WordPress version hidden; XML-RPC off (left on when Jetpack or WooPayments needs it); basic security headers; new accounts never get an admin-level role, even when the default role was changed straight in the database. HSTS is available but off. Adds a registration check to Tools → Site Health.
* **Change alerts:** an e-mail and a log entry when an administrator is added, a plugin or theme appears or is activated, a mu-plugin or drop-in shows up, wp-config.php changes, or someone opens registration or changes the default role for new accounts, the admin e-mail or the site address. A changed admin e-mail is also reported to the previous address.
* **Plugin health:** a weekly check for plugins closed on WordPress.org, not updated for two years, not from WordPress.org, one-time tools left active, inactive leftovers, and plugins a WPHouse module replaces. Also shown in Tools → Site Health.
* **Vulnerability alerts:** for sites without Wordfence. Warns when the installed WordPress, a plugin or a theme has a known security vulnerability and names the version that fixes it. Urgent findings (CVSS 7 or higher, or no fix yet) appear on every admin screen; all findings appear in Site Health and are e-mailed once. While Wordfence is active the module stands down, because Wordfence warns about vulnerable software itself.
* **Login limits:** stops password guessing on wp-login.php, the WooCommerce login form, XML-RPC and application passwords. Five failures from one address in 15 minutes lock it out for 15 minutes, and every further lockout lasts four times longer (up to 24 hours); IPv6 counts by /64. An account under attack is never locked for everyone: after ten failures in an hour it is paused only for devices that never logged into it, while devices that did (they carry a signed cookie) keep working. Behind Cloudflare or another proxy, set the proxy (see below), otherwise every visitor has the proxy's address and WPHouse blocks no addresses at all. Stands down while Wordfence brute force protection is on.

Off by default:

* **Bot protection:** stops automated sign-ups, spam comments, password-reset floods, scripted logins and fake orders. A honeypot (an invisible trap field plus proof that a person used the form) guards registration, lost password and comments with no outside service. With Cloudflare Turnstile keys in wp-config.php, Turnstile also guards login, registration, lost password, comments and reviews, and the classic and block checkout, including orders sent straight to the WooCommerce Store API. You choose what happens when Cloudflare cannot be reached.
* **Install lockdown:** nobody can install plugins or themes or upload ZIP files, not even through a vulnerable plugin that skips permission checks. Updates keep working. Unlock for 30 minutes when you need to install something.
* **Tweaks:** separate switches for comments, front-end search, emojis, embeds, `<head>` clean-up, Heartbeat, self-pingbacks and the number of revisions kept.
* **Duplicate posts and pages:** a "Duplicate" link that creates a draft copy with the content, taxonomies and custom fields. WooCommerce products keep WooCommerce's own duplicate action.
* **SMTP mail:** all WordPress and WooCommerce mail through your SMTP server, with the credentials in wp-config.php. No mail log, so password-reset links are never stored.
* **Header and footer scripts:** tracking codes, verification tags and widgets in `<head>`, after `<body>` or before `</body>`. HTML and JavaScript only, never PHP; only administrators allowed to post unfiltered HTML can edit them.
* **Maintenance mode:** visitors get a short "back soon" page with HTTP 503 and Retry-After. Logged-in staff see the normal site; wp-login, the REST API, cron and payment callbacks keep working. Switching it on or off purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache and Autoptimize; Cloudflare HTML caching (APO, Cache Everything) needs a manual purge.
* **LiteSpeed page cache:** on LiteSpeed servers, pages for visitors who are not logged in and have no cart are served from the server cache without running WordPress. WPHouse only sends cache headers and clears the cache after content, menu, theme, plugin and stock changes; it writes no files and no `.htaccess`. Logged-in users, commenters, visitors with a cart, and the cart, checkout and account pages are never cached. On LiteSpeed Enterprise the host, or one `CacheLookup public on` line in `.htaccess`, turns the server cache on; Site Health checks that it works. Stands down while the LiteSpeed Cache plugin is active.
* **Cloudflare cache:** for sites where Cloudflare caches whole pages (APO or a Cache Everything rule). After a change WPHouse clears the post's own page and the listings it appears on (home, archives, its categories); menus, widgets, themes and plugins clear everything. At most one call every 30 seconds; changes in between follow by cron. Needs `WPHOUSE_CLOUDFLARE_TOKEN` (an API token with only Zone → Cache Purge) and `WPHOUSE_CLOUDFLARE_ZONE` in wp-config.php.

= Integrations =

WPHouse is built to run alongside these plugins. The Integrations card on the settings page shows which of them a site has and what WPHouse does for each.

* **Wordfence:** optional. When it is active, WPHouse skips what Wordfence already does: username discovery blocking, login error masking (on sites without WooCommerce, whose login form Wordfence does not cover), version hiding, vulnerability alerts, and the login and registration captcha when Wordfence Login Security has its reCAPTCHA on. WPHouse has no firewall and no malware scanner; use Wordfence or Cloudflare for those. Login limits stand down while Wordfence brute force protection is on. Two-factor login is not in WPHouse yet.
* **WooCommerce:** compatible with HPOS and the block checkout. Generic login errors also cover the My Account form, bot protection covers the My Account forms and both checkouts, maintenance mode lets the Store API and payment callbacks through, and product reviews survive "disable comments".
* **Payment gateways:** Autopay, Przelewy24, PayU, imoje, Paynow, Stripe, PayPal and WooPayments. Their callbacks (`?wc-api=` and the REST API) pass maintenance mode.
* **Elementor** and Elementor Pro.
* **Redis Object Cache:** its connection status is shown, but WPHouse has its own Redis object cache (below).
* **Cloudflare:** real visitor addresses ("Proxy in front of the site"), cache clearing (Cloudflare cache module) and Turnstile on forms (Bot protection), each switched on separately.

Design House watches all of these, and every plugin a WPHouse module replaces, for newly published vulnerabilities.

= Redis object cache =

WPHouse has its own persistent object cache for servers with Redis and the PhpRedis extension. It keeps database results in Redis between requests, like the Redis Object Cache plugin, and reads the same `WP_REDIS_*` constants, so a site can switch by replacing the drop-in.

* Installed and removed with WP-CLI: `wp wphouse object-cache enable` and `disable`. There is no button in wp-admin: the drop-in file is written only by WP-CLI and removed only by WP-CLI or when WPHouse is deactivated or uninstalled.
* Needs Redis 6.0 or newer and the PhpRedis extension in both the web server's PHP and the PHP that runs WP-CLI.
* Every cached value is signed with a key derived from the site's secret keys and checked before it is unserialized, so other sites on a shared Redis cannot plant objects.
* Only this site's keys are ever deleted, never the whole Redis database.
* When Redis is down, WordPress falls back to its own cache and the site keeps working. Whatever changed meanwhile is not served stale later: the first request that reaches Redis again clears this site's keys first. The same happens after WPHouse safe mode, and when WP-CLI runs on a PHP without PhpRedis.
* Tested with WordPress's own object cache and option tests (wordpress-develop) and a WooCommerce cart and checkout run against Redis.

= Updates =

Updates come from the Design House update host, not WordPress.org. Each release is signed (Ed25519): WordPress installs a package only when the manifest signature and the package checksum both match. A copy of WPHouse that lives in a git working copy never updates itself.

== Installation ==

1. Upload the ZIP in Plugins → Add New → Upload Plugin, or unpack it into `wp-content/plugins/wphouse`.
2. Activate it. WPHouse supports single sites; network activation on multisite is refused.
3. Open WPHouse in the admin menu, set the alert recipients and switch modules on or off.

== Frequently Asked Questions ==

= Something broke. How do I switch WPHouse off without wp-admin? =

Turn on safe mode, which stops every module without touching the database: upload an empty file named `wphouse-safe-mode` to `wp-content` (FTP is enough), add `define( 'WPHOUSE_SAFE_MODE', true );` to wp-config.php, or run `wp wphouse safe-mode on`. Remove the file or the constant to resume.

= Can I pin the configuration in wp-config.php? =

Yes. `define( 'WPHOUSE_MODULES', [ 'lockdown' => true, 'scripts' => false ] );` forces modules on or off, and `define( 'WPHOUSE_LOCK_SETTINGS', true );` makes the settings page read-only.

= Which other wp-config.php constants are there? =

* `WPHOUSE_SMTP_HOST`, `WPHOUSE_SMTP_PORT`, `WPHOUSE_SMTP_USER`, `WPHOUSE_SMTP_PASS`, `WPHOUSE_SMTP_SECURE`: SMTP credentials.
* `WPHOUSE_TURNSTILE_SITE_KEY` and `WPHOUSE_TURNSTILE_SECRET_KEY`: Cloudflare Turnstile keys for Bot protection. Cloudflare's test keys are refused on production sites.
* `WPHOUSE_DISABLE_UPDATES`: no update checks. Use it (or `DISALLOW_FILE_MODS`) on sites deployed from git or rsync, otherwise the next deploy reverts an update.
* `WPHOUSE_AUTO_UPDATE`: set to false to stop forcing background updates of WPHouse.
* `WPHOUSE_IGNORE_OVERLAPS`: run WPHouse features even where Wordfence or another plugin already provides them.
* `WPHOUSE_LOCKDOWN_UI_UNLOCK`: set to false to allow unlocking only from WP-CLI.
* `WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_REDIS_PASSWORD`, `WP_REDIS_DATABASE`, `WP_REDIS_PREFIX` and the other `WP_REDIS_*` constants: the Redis connection for the object cache. `WPHOUSE_OBJECT_CACHE` set to false switches the cache off without removing it.
* `WPHOUSE_TRUSTED_PROXIES` and `WPHOUSE_PROXY_HEADER`: for sites behind a proxy or CDN. `'cloudflare'` (or the "Proxy in front of the site" setting) trusts Cloudflare's visitor header, but only on connections from Cloudflare's own addresses; an array of ranges does the same for other proxies. Without them WPHouse takes the visitor IP from `REMOTE_ADDR` only, so it cannot be spoofed with request headers.
* `WPHOUSE_LOGIN_ALLOWLIST`: an array of addresses or ranges that login limits never lock out.
* `WPHOUSE_CLOUDFLARE_TOKEN` and `WPHOUSE_CLOUDFLARE_ZONE`: the Cloudflare cache module's API token (Zone → Cache Purge only) and zone ID.

= Which WP-CLI commands are there? =

`wp wphouse status`, `wp wphouse module enable|disable <module>`, `wp wphouse log`, `wp wphouse safe-mode on|off` and `wp wphouse update-check`. Modules add `wp wphouse unlock` and `wp wphouse lock` (install lockdown), `wp wphouse watch accept` (change alerts; run it at the end of deploy scripts), `wp wphouse plugin-health`, `wp wphouse vulnerabilities`, `wp wphouse cache purge` (LiteSpeed page cache) and `wp wphouse login status|unlock <address or login>|--all` (login limits) and `wp wphouse cloudflare purge` (Cloudflare cache). `wp wphouse object-cache enable|disable|status|flush` manages the Redis object cache.

= Is it translated? =

English and Polish.

== External services ==

Apart from the SMTP and Redis servers you configure yourself, WPHouse contacts up to four services. Requests use WordPress's HTTP API and its default user agent, which includes the site address.

* **WordPress.org plugin directory** (api.wordpress.org), for Plugin health: once a week, and when plugins are added, removed or updated, it sends the slugs of the installed plugins to read their status (closed, last update). [Terms and privacy](https://wordpress.org/about/privacy/).
* **Design House update host** (updates.designhouse.me): WordPress checks it for WPHouse updates every few hours, and Vulnerability alerts download the signed vulnerability data from it at most every 6 hours. The site never sends its plugin list: it fetches an index and only the data files that cover its installed software, each file covering about 1/256 of all plugins and themes. The vulnerability data comes from Wordfence Intelligence. [Privacy policy](https://designhouse.me/polityka-prywatnosci).

* **Cloudflare Turnstile** (challenges.cloudflare.com), for Bot protection, only when its keys are set in wp-config.php. Pages with a protected form load Cloudflare's Turnstile script in the visitor's browser. When the form is sent, the site sends the Turnstile token to Cloudflare to check it, together with the visitor's IP address when the site knows it (no proxy in front, or trusted proxies set in `WPHOUSE_TRUSTED_PROXIES`). [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/), [Cloudflare privacy policy](https://www.cloudflare.com/privacypolicy/).
* **Cloudflare API** (api.cloudflare.com), for the Cloudflare cache module, only when it is on and its token is set: the zone ID and the addresses of changed pages, at most once every 30 seconds. [Cloudflare's privacy policy](https://www.cloudflare.com/privacypolicy/).

WPHouse sends no telemetry. The activity log stays in the site's database, stores the user and IP address of each event and deletes entries after 90 days.

== Changelog ==

= 0.1.0 =
* Core: module registry with a switch per module, schema-driven settings page, activity log, safe mode, WP-CLI commands, Wordfence overlap detection.
* Hardening: file editor off, username discovery blocked, generic login errors, version hidden, XML-RPC off, basic security headers, optional HSTS, no admin-level role for new accounts, registration check in Site Health.
* Bot protection: honeypot on registration, lost password and comments; Cloudflare Turnstile on login, registration, lost password, comments and checkout, including the Store API.
* Install lockdown, change alerts (also for open registration, the default role, the admin e-mail and the site address) and plugin health.
* Vulnerability alerts for sites without Wordfence, from signed Wordfence Intelligence data.
* Login limits: lockouts by address, accounts paused only for new devices, Cloudflare visitor addresses.
* Cloudflare cache: clears changed pages after edits, everything after site-wide changes.
* Integrations card for Wordfence, WooCommerce, payment gateways and Elementor.
* LiteSpeed page cache without the LiteSpeed Cache plugin: cache headers only, safe for WooCommerce.
* Redis object cache with signed values, installed with WP-CLI.
* Tweaks, duplicate posts, SMTP from wp-config, header and footer scripts, maintenance mode.
* Signed self-hosted updates.
* Design House branding on the settings page, the Updates screen and alert e-mails.
