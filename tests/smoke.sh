#!/usr/bin/env bash
# Prépare l'environnement local (page de test, comptes, mots de passe d'application)
# puis lance tests/smoke.mjs. Les identifiants créés ici n'existent que dans l'environnement local.
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

if ! wp user get atelier-abonne --field=ID >/dev/null; then
	wp user create atelier-abonne atelier-abonne@example.test --role=subscriber >/dev/null
fi

# Un mot de passe d'application neuf à chaque passage ; les anciens sont supprimés.
for user in admin atelier-abonne; do
	wp user application-password delete "$user" --all >/dev/null || true
done
ADMIN_PASSWORD="$(wp user application-password create admin atelier-smoke --porcelain | tr -d '\r')"
SUBSCRIBER_PASSWORD="$(wp user application-password create atelier-abonne atelier-smoke --porcelain | tr -d '\r')"

ATELIER_PAGE_ID="$(echo "$PAGE_ID" | tr -d '\r')" \
ATELIER_ADMIN_USER=admin ATELIER_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
ATELIER_SUBSCRIBER_USER=atelier-abonne ATELIER_SUBSCRIBER_PASSWORD="$SUBSCRIBER_PASSWORD" \
	node tests/smoke.mjs
