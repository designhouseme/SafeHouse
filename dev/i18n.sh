#!/usr/bin/env bash
# Translations: regenerate plugin/languages/shouse.pot from the code, merge it into every .po,
# then compile .mo and .l10n.php (fast loading, WP 6.5+). Edit the .po files by hand or in Poedit.
set -euo pipefail
cd "$(dirname "$0")/.."
cli() { docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wordpress:cli-php8.3 wp "$@"; }
cli i18n make-pot plugin plugin/languages/shouse.pot --domain=shouse \
	--headers='{"Report-Msgid-Bugs-To":"https://designhouse.me/","Language-Team":"Design House"}'
for po in plugin/languages/*.po; do
	[ -e "$po" ] || continue
	cli i18n update-po plugin/languages/shouse.pot "$po"
done
cli i18n make-mo plugin/languages
cli i18n make-php plugin/languages
ls -1 plugin/languages
