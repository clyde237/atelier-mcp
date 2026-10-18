# Atelier MCP

Plugin WordPress privé qui transforme un site en **serveur MCP** : Claude (claude.ai, Claude Code, Claude Desktop) se connecte directement au site pour lire, auditer et, dans les versions suivantes, construire des pages Elementor et Gutenberg.

Les outils sont déclarés avec l'**Abilities API** de WordPress et exposés en MCP par le plugin **MCP Adapter**. Les instructions de méthode (conteneurs, responsive, widgets natifs) sont envoyées au modèle à la connexion : aucun plugin n'est nécessaire côté Claude.

## État

Version 0.1.0 : outils en **lecture seule**, Elementor uniquement.

| Outil MCP | Rôle |
|---|---|
| `atelier-site-info` | Versions WordPress/Elementor/Pro, expériences actives, points de rupture, thème, page d'accueil |
| `atelier-list-documents` | Pages, articles, templates modifiables par l'utilisateur, avec l'éditeur utilisé |
| `atelier-get-document` | Plan lisible d'une page ou arbre Elementor complet (ou un seul élément) |
| `atelier-describe-widget` | Noms exacts des réglages d'un widget ou d'un conteneur sur ce site |
| `atelier-get-kit` | Couleurs et typographies globales, largeur de conteneur |
| `atelier-audit-document` | Structure ancienne, code personnalisé, responsive, titres, images, liens |

Prévu : écriture via l'API PHP d'Elementor (révisions, cache), lien d'aperçu signé, OAuth 2.1 pour claude.ai web, Elementor Pro (Theme Builder), Gutenberg.

## Prérequis

- WordPress 6.9 ou plus récent (Abilities API dans le cœur).
- PHP 7.4 ou plus récent.
- Plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter) installé et activé (non embarqué : c'est la méthode recommandée par ses auteurs pour éviter les conflits de versions).
- Elementor pour les outils Elementor.

## Installation sur un site

1. Installer et activer MCP Adapter : téléverser `https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip` dans Extensions › Ajouter.
2. Téléverser le zip d'Atelier MCP et l'activer.
3. Point d'accès MCP : `https://<site>/wp-json/atelier/mcp`.

Authentification (version 0.1) : mot de passe d'application WordPress (Profil › Mots de passe d'application), envoyé en `Authorization: Basic`. Utilisable depuis Claude Code :

```bash
claude mcp add --transport http atelier https://<site>/wp-json/atelier/mcp --header "Authorization: Basic <base64 utilisateur:mot-de-passe-application>"
```

claude.ai web exigera OAuth : prévu dans une version suivante.

## Droits

- Ouvrir une session MCP : droit `edit_posts` (contributeur et plus).
- Lire ou auditer un document : droit `edit_post` sur **ce** document.
- Détails serveur (version PHP, plugins actifs) : administrateurs (`manage_options`) seulement.

## Développement

Prérequis : Docker et Node.js. Environnement en `docker compose` avec les images officielles `wordpress`, `wordpress:cli` et `mariadb` (pas de wp-env : il télécharge Composer et wordpress-develop à chaque construction, ce qui échoue sur une connexion instable).

```bash
npm run env:fetch      # une fois : télécharge MCP Adapter et Elementor dans .dev-cache/ (avec reprises)
npm run env:start      # WordPress 7.1 sur http://localhost:8888 (admin / password), plugins activés
npm run lint:php       # php -l sur tous les fichiers
npm run test:smoke     # test de bout en bout du serveur MCP
npm run env:stop
```

`tests/smoke.sh` crée une page Elementor de test, un compte abonné et des mots de passe d'application **dans l'environnement local uniquement**, puis `tests/smoke.mjs` vérifie : refus sans authentification et pour un abonné, instructions envoyées, liste des outils, résultat de chaque outil, constats de l'audit.

## Structure

```
atelier-mcp.php            en-tête du plugin, constantes, démarrage
src/Plugin.php             vérifie les dépendances, branche les hooks
src/Server.php             serveur MCP dédié (/wp-json/atelier/mcp), droit d'accès au transport
src/Instructions.php       instructions MCP (filtre atelier_mcp_instructions)
src/Documents.php          types de contenus gérés, éditeur utilisé, résumé commun
src/Abilities/             une classe par outil, base commune Ability, Registry
src/Elementor/             accès à Elementor (Environment), lecture d'arbre (Tree), audit (Audit)
dev/                       docker-compose.yml, env.sh (start/stop/destroy/wp), fetch.sh
tests/                     test de bout en bout et page Elementor de test
```

## Points d'extension

- `atelier_mcp_instructions` : adapter les instructions envoyées au modèle (charte, conventions du site).
- `atelier_mcp_post_types` : ajouter des types de contenus (mises en page d'un thème).
