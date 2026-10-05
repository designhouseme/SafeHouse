# Changelog

Each release needs a `## x.y.z` section; its body is shown in the WordPress "View details" window.

## 0.1.0

- Core: module registry with per-module switches, schema-driven settings page, event log, safe mode, WP-CLI (`wp wphouse …`), Wordfence overlap detection.
- Hardening: file editor off, username discovery blocked, generic login errors, version hidden, XML-RPC off, basic security headers, optional HSTS.
- Install lockdown: no new plugins, themes or ZIP uploads (updates keep working), timed unlock.
- Change alerts: new administrators, plugins, themes, mu-plugins, drop-ins and wp-config.php edits.
- Plugin health: closed, abandoned, unknown-source and forgotten plugins, also in Site Health.
- Tweaks, duplicate posts, SMTP from wp-config, header/footer scripts, maintenance mode.
- Signed self-hosted updates.
