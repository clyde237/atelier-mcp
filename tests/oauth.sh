#!/usr/bin/env bash
# Prépare l'environnement pour tester OAuth 2.1 et lance tests/oauth.mjs.
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { bash dev/env.sh wp "$@" 2>/dev/null; }

ADMIN_ID="$(wp user get admin --field=ID | tr -d '\r')"

# Créer un PAT de test directement via l'API PHP de TokenStore
TEST_PAT="$(wp eval '$t = \Atelier\Auth\TokenStore::create_personal_token(1, "Smoke Test PAT", ["atelier:read", "atelier:write", "atelier:admin"]); echo $t["token"];' | tr -d '\r')"

ATELIER_TEST_PAT="$TEST_PAT" \
ATELIER_ADMIN_ID="$ADMIN_ID" \
	node tests/oauth.mjs
