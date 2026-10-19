#!/usr/bin/env bash
# Prépare l'environnement et exécute tests/write.mjs pour valider les opérations d'écriture.
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { bash dev/env.sh wp "$@" 2>/dev/null; }

FIXTURE="$(cat tests/fixtures/elementor-page.json)"

PAGE_ID="$(wp post list --post_type=page --name=atelier-smoke --field=ID --format=ids || true)"
if [ -z "$PAGE_ID" ]; then
	PAGE_ID="$(wp post create --post_type=page --post_name=atelier-smoke --post_title='Atelier smoke' --post_status=publish --porcelain)"
fi
wp post meta update "$PAGE_ID" _elementor_edit_mode builder >/dev/null
wp post meta update "$PAGE_ID" _elementor_template_type wp-page >/dev/null
wp post meta update "$PAGE_ID" _elementor_data "$FIXTURE" >/dev/null

ADMIN_PASSWORD="$(wp user application-password create admin atelier-write --porcelain | tr -d '\r')"

cleanup() {
	wp user application-password delete admin atelier-write >/dev/null || true
}
trap cleanup EXIT

ATELIER_PAGE_ID="$(echo "$PAGE_ID" | tr -d '\r')" \
ATELIER_ADMIN_USER=admin ATELIER_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
	node tests/write.mjs
