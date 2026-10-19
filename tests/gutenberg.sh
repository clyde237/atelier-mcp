#!/usr/bin/env bash
# Prépare l'environnement et exécute tests/gutenberg.mjs pour valider le support Gutenberg
# (Lecture, écriture avec concurrence optimiste, blocs installés, patterns, theme.json).
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { bash dev/env.sh wp "$@" 2>/dev/null; }

INITIAL_CONTENT='<!-- wp:heading {"level":1} --><h1>Bienvenue sur Gutenberg</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Premier paragraphe de test.</p><!-- /wp:paragraph -->'

PAGE_ID="$(wp post list --post_type=page --name=atelier-gutenberg-smoke --field=ID --format=ids || true)"
if [ -z "$PAGE_ID" ]; then
	PAGE_ID="$(wp post create --post_type=page --post_name=atelier-gutenberg-smoke --post_title='Atelier Gutenberg Smoke' --post_content="$INITIAL_CONTENT" --post_status=publish --porcelain)"
else
	wp post update "$PAGE_ID" --post_content="$INITIAL_CONTENT" >/dev/null
fi

# S'assurer que le mode Elementor n'est pas actif sur cette page Gutenberg
wp post meta delete "$PAGE_ID" _elementor_edit_mode >/dev/null || true

ADMIN_PASSWORD="$(wp user application-password create admin atelier-gutenberg --porcelain | tr -d '\r')"

cleanup() {
	wp user application-password delete admin atelier-gutenberg >/dev/null || true
}
trap cleanup EXIT

ATELIER_PAGE_ID="$(echo "$PAGE_ID" | tr -d '\r')" \
ATELIER_ADMIN_USER=admin ATELIER_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
	node tests/gutenberg.mjs
