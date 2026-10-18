#!/usr/bin/env bash
# Pilote l'environnement local : start | stop | destroy | wp <commande>.
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE=(docker compose -f docker-compose.yml)
URL="http://localhost:8888"

wp() {
	"${COMPOSE[@]}" run --rm -T cli wp "$@"
}

start() {
	for dir in mcp-adapter elementor; do
		if [ ! -f "../.dev-cache/$dir/readme.txt" ]; then
			echo "Plugin manquant : .dev-cache/$dir. Lancer d'abord : bash dev/fetch.sh" >&2
			exit 1
		fi
	done

	"${COMPOSE[@]}" up -d db wordpress

	echo "Attente de WordPress sur $URL…"
	for _ in $(seq 1 60); do
		code="$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-login.php" || true)"
		[ "$code" != "000" ] && break
		sleep 2
	done

	if ! wp core is-installed 2>/dev/null; then
		wp core install --url="$URL" --title="Atelier dev" --admin_user=admin --admin_password=password \
			--admin_email=admin@example.test --skip-email
	fi
	wp rewrite structure '/%postname%/' >/dev/null
	wp plugin activate mcp-adapter elementor atelier-mcp
	echo "Prêt : $URL (admin / password), MCP : $URL/wp-json/atelier/mcp"
}

case "${1:-}" in
	start) start ;;
	stop) "${COMPOSE[@]}" stop ;;
	destroy) "${COMPOSE[@]}" down -v ;;
	wp) shift; wp "$@" ;;
	*) echo "Usage : $0 start|stop|destroy|wp <commande>" >&2; exit 1 ;;
esac
