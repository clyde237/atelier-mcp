#!/usr/bin/env bash
# Télécharge une fois les plugins tiers nécessaires aux tests dans .dev-cache/ (non versionné).
# Reprend automatiquement en cas de coupure réseau.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .dev-cache

fetch() {
	local name="$1" url="$2" zip=".dev-cache/$1.zip"
	if [ -f ".dev-cache/$name/readme.txt" ]; then
		echo "$name : déjà présent"
		return
	fi
	curl -fL --retry 10 --retry-all-errors --retry-delay 5 -C - -o "$zip" "$url"
	rm -rf ".dev-cache/$name"
	unzip -q "$zip" -d .dev-cache/
	rm -f "$zip"
	echo "$name : téléchargé"
}

fetch mcp-adapter https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip
fetch elementor https://downloads.wordpress.org/plugin/elementor.latest-stable.zip
