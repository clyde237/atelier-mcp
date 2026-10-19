# Atelier MCP

Plugin WordPress privé qui transforme un site en **serveur MCP** : Claude (claude.ai, Claude Code, Claude Desktop) se connecte directement au site pour lire, auditer et, dans les versions suivantes, construire des pages Elementor et Gutenberg.

Les outils sont déclarés avec l'**Abilities API** de WordPress et exposés en MCP par le plugin **MCP Adapter**. Les instructions de méthode (conteneurs, responsive, widgets natifs) sont envoyées au modèle à la connexion : aucun plugin n'est nécessaire côté Claude.

## État

Version 0.4.0 : outils de lecture, audit, écriture, révisions, médias, Elementor Pro et Gutenberg.

| Outil MCP | Rôle | Type |
|---|---|---|
| `atelier-site-info` | Versions WordPress/Elementor/Pro, expériences actives, points de rupture, thème | Lecture |
| `atelier-list-documents` | Pages, articles, templates modifiables avec l'éditeur utilisé | Lecture |
| `atelier-get-document` | Plan lisible d'une page ou arbre Elementor complet (avec modified_gmt) | Lecture |
| `atelier-describe-widget` | Noms exacts des réglages d'un widget ou d'un conteneur sur ce site | Lecture |
| `atelier-get-kit` | Couleurs et typographies globales, largeur de conteneur | Lecture |
| `atelier-audit-document` | Audit statique : conteneurs, responsive, titres, accessibilité | Lecture |
| `atelier-save-document` | Enregistrement sécurisé avec concurrence optimiste (modified_gmt), dry-run et révisions | Écriture |
| `atelier-create-document` | Création de pages, articles ou templates Elementor avec arbre initial | Écriture |
| `atelier-list-revisions` | Historique des révisions d'un document avec auteur et dates | Lecture |
| `atelier-restore-revision` | Restauration d'une révision passée (rollback immédiat) | Écriture |
| `atelier-preview-link` | Génération d'une URL signée temporaire pour prévisualisation sans compte | Lecture |
| `atelier-upload-media` | Téléversement d'image Base64 dans la médiathèque avec alt obligatoire | Écriture |
| `atelier-flush-cache` | Purge et recompilation des fichiers CSS et caches Elementor | Écriture |
| `atelier-activity-log` | Journal d'audit chronologique des actions effectuées sur le site | Lecture |
| `atelier-theme-builder` | Gestion des modèles Theme Builder (header, footer, single, etc.) et conditions d'affichage | Écriture |
| `atelier-global-widgets` | Création, lecture et référencement de widgets globaux synchronisés | Écriture |
| `atelier-popups` | Création et configuration des popups (déclencheurs scroll/exit-intent, ciblage, timing) | Écriture |
| `atelier-gutenberg-blocks` | Exploration des types de blocs natifs et inspection de leurs attributs exacts | Lecture |
| `atelier-gutenberg-document` | Lecture d'un document Gutenberg (plan hiérarchique, arbre JSON ou balisage brut) | Lecture |
| `atelier-gutenberg-save` | Enregistrement sécurisé de blocs Gutenberg avec concurrence optimiste (modified_gmt) | Écriture |
| `atelier-gutenberg-patterns` | Découverte et extraction des compositions prédéfinies du thème (patterns) | Lecture |
| `atelier-theme-json` | Système de design theme.json (palette globale, typographies, espacements, modèles) | Lecture |
| `atelier-guide` | Guide méthodologique officiel, règles de construction et protocole de sécurité | Lecture |

## Prérequis

- WordPress 6.9 ou plus récent (Abilities API dans le cœur).
- PHP 7.4 ou plus récent.
- Plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter) installé et activé (non embarqué : c'est la méthode recommandée par ses auteurs pour éviter les conflits de versions).
- Elementor pour les outils Elementor.

## Installation sur un site

1. Installer et activer MCP Adapter : téléverser `https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip` dans Extensions › Ajouter.
2. Téléverser le zip d'Atelier MCP et l'activer.
3. Point d'accès MCP : `https://<site>/wp-json/atelier/mcp`.

Authentification :
- **OAuth 2.1 avec PKCE** (recommandé pour Claude.ai web, ChatGPT, Antigravity) : négociation automatique via découverte RFC 9728 (`/.well-known/oauth-protected-resource`) et RFC 8414.
- **Jetons personnels (Bearer PAT)** : générés dans WordPress (Atelier MCP › Connexion) pour Claude Code CLI ou scripts :
  ```bash
  claude mcp add --transport http atelier https://<site>/wp-json/atelier/mcp --header "Authorization: Bearer <votre_jeton>"
  ```
- **Mots de passe d'application WordPress** (rétrocompatible) : `Authorization: Basic <base64 utilisateur:mot-de-passe>`.

## Mises à jour

Le plugin inclut un mécanisme natif de mise à jour automatique connecté aux releases GitHub du dépôt `clyde237/atelier-mcp`. Dès qu'une nouvelle version est publiée sur GitHub, WordPress la signale dans Extensions et permet la mise à jour en un clic.

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
npm run test:smoke     # test de bout en bout du serveur MCP (13 vérifications)
npm run test:oauth     # test de bout en bout OAuth 2.1 & tokens Bearer (12 vérifications)
npm run test:write     # test de bout en bout des écritures sécurisées (12 vérifications)
npm run test:pro       # test de bout en bout Elementor Pro & Theme Builder (12 vérifications)
npm run test:gutenberg # test de bout en bout Gutenberg & theme.json (12 vérifications)
npm run build          # création du zip de distribution prêt pour WordPress et SHA-256
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
