#!/usr/bin/env bash
# Prépare l'environnement et exécute tests/pro.mjs pour valider le support Elementor Pro
# (Theme Builder, Conditions d'affichage, Widgets Globaux, Popups).
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { bash dev/env.sh wp "$@" 2>/dev/null; }

ADMIN_PASSWORD="$(wp user application-password create admin atelier-pro --porcelain | tr -d '\r')"

cleanup() {
	wp user application-password delete admin atelier-pro >/dev/null || true
}
trap cleanup EXIT

ATELIER_ADMIN_USER=admin ATELIER_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
	node tests/pro.mjs
