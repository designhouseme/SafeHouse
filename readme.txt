=== WPHouse ===
Contributors: designhouse
Tags: security, hardening, vulnerability, smtp, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One plugin instead of a dozen small ones: hardening, install lockdown, change and vulnerability alerts, everyday tweaks. Works alongside Wordfence.

== Description ==

WPHouse replaces the single-purpose plugins most sites collect over time with one small plugin. Every feature is a module you switch on or off on the WPHouse page in the admin menu, and every module is safe in WP-CLI, cron, REST and AJAX requests.

= Modules =

On by default:

* **Hardening:** no theme and plugin file editor; no username discovery for visitors (`?author=` scans, the REST users endpoint, the users sitemap, author data in oEmbed); one generic login error on wp-login.php and the WooCommerce login form; WordPress version hidden; XML-RPC off (left on when Jetpack or WooPayments needs it); basic security headers. HSTS is available but off.
* **Change alerts:** an e-mail and a log entry when an administrator is added, a plugin or theme appears or is activated, a mu-plugin or drop-in shows up, or wp-config.php changes.
* **Plugin health:** a weekly check for plugins closed on WordPress.org, not updated for two years, not from WordPress.org, one-time tools left active, inactive leftovers, and plugins a WPHouse module replaces. Also shown in Tools → Site Health.
* **Vulnerability alerts:** for sites without Wordfence. Warns when the installed WordPress, a plugin or a theme has a known security vulnerability and names the version that fixes it. Urgent findings (CVSS 7 or higher, or no fix yet) appear on every admin screen; all findings appear in Site Health and are e-mailed once. While Wordfence is active the module stands down, because Wordfence warns about vulnerable software itself.

Off by default:

* **Install lockdown:** nobody can install plugins or themes or upload ZIP files, not even through a vulnerable plugin that skips permission checks. Updates keep working. Unlock for 30 minutes when you need to install something.
* **Tweaks:** separate switches for comments, front-end search, emojis, embeds, `<head>` clean-up, Heartbeat, self-pingbacks and the number of revisions kept.
* **Duplicate posts and pages:** a "Duplicate" link that creates a draft copy with the content, taxonomies and custom fields. WooCommerce products keep WooCommerce's own duplicate action.
* **SMTP mail:** all WordPress and WooCommerce mail through your SMTP server, with the credentials in wp-config.php. No mail log, so password-reset links are never stored.
* **Header and footer scripts:** tracking codes, verification tags and widgets in `<head>`, after `<body>` or before `</body>`. HTML and JavaScript only, never PHP; only administrators allowed to post unfiltered HTML can edit them.
* **Maintenance mode:** visitors get a short "back soon" page with HTTP 503 and Retry-After. Logged-in staff see the normal site; wp-login, the REST API, cron and payment callbacks keep working. Switching it on or off purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache and Autoptimize; Cloudflare HTML caching (APO, Cache Everything) needs a manual purge.

= Integrations =

WPHouse is built to run alongside these plugins. The Integrations card on the settings page shows which of them a site has and what WPHouse does for each.

* **Wordfence:** WPHouse detects the features Wordfence already provides and skips its own. WPHouse has no firewall, rate limiting, two-factor login or malware scanner; use Wordfence for those.
* **WooCommerce:** compatible with HPOS and the block checkout. Generic login errors also cover the My Account form, maintenance mode lets the Store API and payment callbacks through, and product reviews survive "disable comments".
* **Payment gateways:** Autopay, Przelewy24, PayU, imoje, Paynow, Stripe, PayPal and WooPayments. Their callbacks (`?wc-api=` and the REST API) pass maintenance mode.
* **Elementor** and Elementor Pro.

Design House watches all of these, and every plugin a WPHouse module replaces, for newly published vulnerabilities.

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
* `WPHOUSE_DISABLE_UPDATES`: no update checks. Use it (or `DISALLOW_FILE_MODS`) on sites deployed from git or rsync, otherwise the next deploy reverts an update.
* `WPHOUSE_AUTO_UPDATE`: set to false to stop forcing background updates of WPHouse.
* `WPHOUSE_IGNORE_OVERLAPS`: run WPHouse features even where Wordfence or another plugin already provides them.
* `WPHOUSE_LOCKDOWN_UI_UNLOCK`: set to false to allow unlocking only from WP-CLI.
* `WPHOUSE_TRUSTED_PROXIES` and `WPHOUSE_PROXY_HEADER`: for sites behind a proxy or CDN. Without them WPHouse takes the visitor IP from `REMOTE_ADDR` only, so it cannot be spoofed with request headers.

= Which WP-CLI commands are there? =

`wp wphouse status`, `wp wphouse module enable|disable <module>`, `wp wphouse log`, `wp wphouse safe-mode on|off` and `wp wphouse update-check`. Modules add `wp wphouse unlock` and `wp wphouse lock` (install lockdown), `wp wphouse watch accept` (change alerts; run it at the end of deploy scripts), `wp wphouse plugin-health` and `wp wphouse vulnerabilities`.

= Is it translated? =

English and Polish.

== External services ==

Apart from the SMTP server you configure for the SMTP module, WPHouse contacts two services. Requests use WordPress's HTTP API and its default user agent, which includes the site address.

* **WordPress.org plugin directory** (api.wordpress.org), for Plugin health: once a week, and when plugins are added, removed or updated, it sends the slugs of the installed plugins to read their status (closed, last update). [Terms and privacy](https://wordpress.org/about/privacy/).
* **Design House update host** (updates.designhouse.me): WordPress checks it for WPHouse updates every few hours, and Vulnerability alerts download the signed vulnerability data from it at most every 6 hours. The site never sends its plugin list: it fetches an index and only the data files that cover its installed software, each file covering about 1/256 of all plugins and themes. The vulnerability data comes from Wordfence Intelligence. [Privacy policy](https://designhouse.me/polityka-prywatnosci).

WPHouse sends no telemetry. The activity log stays in the site's database, stores the user and IP address of each event and deletes entries after 90 days.

== Changelog ==

= 0.1.0 =
* Core: module registry with a switch per module, schema-driven settings page, activity log, safe mode, WP-CLI commands, Wordfence overlap detection.
* Hardening: file editor off, username discovery blocked, generic login errors, version hidden, XML-RPC off, basic security headers, optional HSTS.
* Install lockdown, change alerts and plugin health.
* Vulnerability alerts for sites without Wordfence, from signed Wordfence Intelligence data.
* Integrations card for Wordfence, WooCommerce, payment gateways and Elementor.
* Tweaks, duplicate posts, SMTP from wp-config, header and footer scripts, maintenance mode.
* Signed self-hosted updates.
* Design House branding on the settings page, the Updates screen and alert e-mails.
