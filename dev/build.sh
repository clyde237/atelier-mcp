#!/usr/bin/env bash
# Construit l'archive zip de distribution d'Atelier MCP pour production WordPress.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Extraction de la version..."
VERSION="$(grep -m1 "define( 'ATELIER_MCP_VERSION'" atelier-mcp.php | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")"
echo "Version détectée : ${VERSION}"

DIST_DIR="dist"
STAGING_DIR="${DIST_DIR}/staging"
PACKAGE_DIR="${STAGING_DIR}/atelier-mcp"

echo "==> Nettoyage du répertoire de build..."
rm -rf "${DIST_DIR}"
mkdir -p "${PACKAGE_DIR}"

echo "==> Copie des fichiers de production..."
cp -a atelier-mcp.php "${PACKAGE_DIR}/"
cp -a README.md "${PACKAGE_DIR}/"
cp -a src "${PACKAGE_DIR}/"

echo "==> Vérification de la syntaxe PHP du package..."
if command -v php >/dev/null 2>&1; then
	find "${PACKAGE_DIR}" -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null
else
	docker run --rm -v "$PWD":/app -w /app --entrypoint sh wordpress:php8.2 -c "find \"${PACKAGE_DIR}\" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
fi

echo "==> Création de l'archive ZIP de distribution..."
(
	cd "${STAGING_DIR}"
	zip -r -q "../atelier-mcp.zip" atelier-mcp/
	cp "../atelier-mcp.zip" "../atelier-mcp-${VERSION}.zip"
)

echo "==> Génération de l'empreinte SHA-256..."
(
	cd "${DIST_DIR}"
	sha256sum "atelier-mcp.zip" > "atelier-mcp.zip.sha256"
	sha256sum "atelier-mcp-${VERSION}.zip" > "atelier-mcp-${VERSION}.zip.sha256"
)

rm -rf "${STAGING_DIR}"

ZIP_SIZE="$(du -h "${DIST_DIR}/atelier-mcp.zip" | cut -f1)"
echo "==> Archive prête avec succès :"
echo "    - ${DIST_DIR}/atelier-mcp.zip (${ZIP_SIZE})"
echo "    - ${DIST_DIR}/atelier-mcp-${VERSION}.zip"
echo "    - ${DIST_DIR}/atelier-mcp.zip.sha256"
