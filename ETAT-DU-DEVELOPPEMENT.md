# État du développement — Atelier MCP

Mis à jour le 18 octobre 2026.

## Objectif

Un plugin WordPress privé, installé sur chaque site, qui fait du site un **serveur MCP**. Un assistant IA s'y connecte directement pour lire, auditer puis construire des pages Elementor (puis Gutenberg).

Clients visés : **Claude** (claude.ai web en priorité), **Codex / ChatGPT** (OpenAI) et **Antigravity** (Gemini). MCP étant un standard, le plugin n'est lié à aucun d'eux ; ce qui change d'un client à l'autre, c'est la manière de s'authentifier.

## Décisions prises

| Sujet | Décision |
|---|---|
| Architecture | Plugin WordPress seul, qui porte les outils **et** le savoir-faire (pas de plugin côté client). |
| Socle technique | Abilities API (cœur WordPress) + plugin officiel [MCP Adapter](https://github.com/WordPress/mcp-adapter), installé à côté et non embarqué. |
| Versions minimales | WordPress 6.9, PHP 7.4. |
| Constructeurs de pages | Elementor d'abord (site pilote : Paroisse Saint-Gilbert), puis Elementor Pro, puis Gutenberg. |
| Diffusion | Privée : zip et mises à jour depuis un dépôt GitHub privé. |
| Règles de construction | Conteneurs Flexbox/Grille, widgets natifs uniquement (pas de widget HTML ni de CSS personnalisé), responsive obligatoire. |

## Ce qui est fait — phase 1 (commit `9d8a187`)

Serveur MCP dédié sur `/wp-json/atelier/mcp`, avec six outils **en lecture seule** :

| Outil | Rôle |
|---|---|
| `atelier-site-info` | Versions WordPress / Elementor / Pro, expériences actives, points de rupture, thème |
| `atelier-list-documents` | Pages, articles et templates modifiables, avec l'éditeur utilisé |
| `atelier-get-document` | Plan lisible d'une page, ou arbre Elementor complet (ou un seul élément) |
| `atelier-describe-widget` | Noms exacts des réglages d'un widget ou d'un conteneur sur ce site |
| `atelier-get-kit` | Couleurs et typographies globales, largeur de conteneur |
| `atelier-audit-document` | Structure ancienne, code personnalisé, responsive, titres, images, liens |

Également en place :

- **Instructions MCP** envoyées au modèle à la connexion (méthode et règles), modifiables par le filtre `atelier_mcp_instructions`.
- **Droits** : `edit_posts` pour ouvrir une session, `edit_post` sur chaque document lu, détails serveur (PHP, plugins) réservés aux administrateurs.
- **Environnement local** (`dev/`) : `docker compose` avec les images officielles WordPress 7.1, WP-CLI et MariaDB. wp-env a été abandonné : il dépend du réseau à chaque démarrage et échouait sur une connexion instable.
- **Test de bout en bout** (`npm run test:smoke`) : 12 vérifications, toutes au vert (refus sans authentification et pour un abonné, instructions, liste des outils, résultat de chaque outil, constats d'audit attendus, absence de faux positifs).

Points techniques appris en route :

- Elementor 4 stocke souvent un réglage responsive en **un seul contrôle** (`is_responsive`, défauts dans `tablet_default` / `mobile_default`) au lieu d'un contrôle par appareil. Le plugin gère les deux modes.
- WordPress refuse un appel d'outil sans arguments si le schéma d'entrée n'a pas de valeur par défaut : un `{}` par défaut est ajouté à tous les outils.
- MCP Adapter exige l'en-tête `MCP-Protocol-Version` sur chaque requête après l'initialisation.
- MCP Adapter est en version 0.x : son API peut encore changer. Fixer la version utilisée et tester en local avant chaque mise à jour.

## Ce qui est fait — phase 2 (Authentification multi-clients OAuth 2.1)

Serveur d'autorisation OAuth 2.1 complet et conforme aux spécifications MCP et IETF :

- **Découverte standardisée** :
  - RFC 9728 (Protected Resource Metadata) sur `/.well-known/oauth-protected-resource` et `/wp-json/atelier/oauth/protected-resource`.
  - RFC 8414 (OAuth 2.1 Authorization Server Metadata) sur `/.well-known/oauth-authorization-server` et `/wp-json/atelier/oauth/authorization-server`.
  - Réponse HTTP 401 avec en-tête `WWW-Authenticate: Bearer resource_metadata="..."` sur `/wp-json/atelier/mcp`.
- **Résolution multi-clients** :
  - Enregistrement dynamique DCR (RFC 7591) sur `/wp-json/atelier/oauth/register` (Claude.ai, Codex).
  - Documents de métadonnées CIMD (Client ID Metadata Document) pour ChatGPT.
  - Clients pré-enregistrés (Claude.ai, ChatGPT, Antigravity, Claude CLI loopback).
  - Validation stricte des adresses de retour (exact matching pour le web, port variable autorisé sur loopback RFC 8252).
- **Sécurité et flux OAuth 2.1** :
  - PKCE obligatoire avec `code_challenge_method=S256` (aucun flux sans PKCE).
  - Écran de consentement natif WordPress (identification de l'utilisateur, demande des portées, validation avec nonce).
  - Code d'autorisation temporaire (5 min) à consommation unique stricte.
  - Hachage systématique en SHA-256 de tous les jetons en base (zéro secret en clair).
  - Rotation obligatoire des jetons de rafraîchissement (RFC 6749 / OAuth 2.1).
  - Révocation immédiate (RFC 7009) sur `/wp-json/atelier/oauth/revoke`.
- **Jetons personnels d'accès (PAT)** :
  - Génération de jetons Bearer configurables (scopes `atelier:read`, `atelier:write`, `atelier:admin`, expiration de 30j à illimitée).
  - Authentification transparente dans WordPress (`determine_current_user` et `rest_authentication_errors`).
- **Administration WordPress** :
  - Page « Connexion » (`Atelier MCP` dans le menu d'admin) avec guides pas à pas et gestion des jetons.
- **Suite de tests automatisée** (`npm run test:oauth`) : 12 vérifications de conformité réussies (100%).

## Ce qui manque

### Phase 3 — Écriture sûre (Elementor — prochaine étape)

- `save-document` via l'API PHP d'Elementor (révision WordPress, cache et CSS régénérés), avec **dry-run** et **jeton de version** (`modified_gmt`) obligatoires.
- `create-document`, `restore-revision`, `flush-cache`, `upload-media`.
- **Lien d'aperçu signé** pour voir un brouillon sans être connecté.
- **Journal** des modifications.
- Premier usage réel : conversion de la page d'accueil de Saint-Gilbert en conteneurs.

### Phase 4 — Elementor Pro

Templates du Theme Builder (en-tête, pied de page, articles, archives) et leurs conditions d'affichage, popups, widgets globaux.

### Phase 5 — Gutenberg

Lecture et écriture des blocs, description des blocs installés, compositions, `theme.json`, templates des thèmes à blocs.

### Transversal

- Outil `atelier-guide` qui renvoie la méthode, car certains clients ne transmettent pas les instructions du serveur au modèle.
- Schémas d'entrée limités à un sous-ensemble JSON Schema accepté par Gemini et OpenAI, puis test réel avec chaque client.
- Construction du zip de distribution et mises à jour depuis GitHub.
- Tests unitaires PHP (aujourd'hui, seul le test de bout en bout existe).

## Questions ouvertes

1. **OpenAI** : viser Codex (CLI et éditeur) seulement, ou aussi le chat ChatGPT (abonnement payant et mode développeur requis) ?
2. **Ordre de test des clients** proposé : claude.ai, Codex, Antigravity, ChatGPT.
3. **Saint-Gilbert** (hébergé chez WordPress.com) : le forfait permet-il d'installer MCP Adapter et Atelier MCP ?
4. La conversion en conteneurs de la page d'accueil de Saint-Gilbert attend toujours l'accord pour publication.
