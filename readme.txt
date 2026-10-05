=== WPHouse ===
Contributors: designhouse
Tags: security, hardening, maintenance, smtp, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lean, audited replacements for small utility plugins. Runs alongside Wordfence and WooCommerce.

== Description ==

WPHouse replaces the single-purpose plugins most sites collect over time with one small plugin whose every feature is a switch:

* Hardening: no file editor, no username discovery, generic login errors, hidden version, XML-RPC off, basic security headers.
* Install lockdown: no new plugins, themes or ZIP uploads, even through vulnerable code paths. Updates keep working.
* Change alerts: e-mail when an administrator, plugin, mu-plugin or drop-in appears or wp-config.php changes.
* Plugin health: closed, abandoned and forgotten plugins, also in Site Health.
* Tweaks, duplicate posts, SMTP (credentials in wp-config.php), header and footer scripts, maintenance mode.

Features that Wordfence or wp-config.php already provide are skipped automatically. WPHouse has no firewall, rate limiting, two-factor login or malware scanner: use Wordfence for those.

Updates are self-hosted and signed (Ed25519); a package is installed only when its signature and checksum match.

== Changelog ==

= 0.1.0 =
* First release.
