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

## Ce qui est fait — phase 3 (Écriture sûre Elementor)

Ensemble complet d'outils d'écriture, de rollback et de gestion des médias via l'API native d'Elementor :

- **`atelier-save-document`** :
  - Concurrence optimiste obligatoire avec jeton de version `modified_gmt` : rejet immédiat (409) en cas de modification concurrente.
  - Mode simulation `dry_run=true` : pré-audite statiquement l'arbre et valide la structure sans toucher à la base de données.
  - Création automatique d'une révision WordPress préalable (`create_revision: true`) pour permettre un retour arrière instantané.
  - Enregistrement par l'API Document d'Elementor, purge et recompilation du cache CSS (`files_manager->clear_cache()`).
  - Lien d'aperçu signé retourné automatiquement.
- **`atelier-create-document`** :
  - Création de pages, articles ou templates Elementor avec statut configurable (`draft`, `publish`, etc.).
  - Initialisation immédiate avec un arbre de conteneurs initial.
- **`atelier-restore-revision` & `atelier-list-revisions`** :
  - Liste chronologique des révisions avec identification de l'auteur et de l'horodatage.
  - Rollback complet et sécurisé appliquant les métadonnées antérieures et régénérant le CSS.
- **`atelier-upload-media`** :
  - Téléversement d'images / icônes (formats jpg, png, webp, svg, gif) en Base64.
  - Texte alternatif (`alt_text`) obligatoire pour l'accessibilité (zéro image muette).
  - Génération automatique des miniatures et insertion dans la médiathèque WordPress.
- **`atelier-preview-link` (Lien d'aperçu signé)** :
  - Signature HMAC SHA-256 temporaire permettant à quiconque de prévisualiser un brouillon sans avoir de compte WordPress.
- **`atelier-flush-cache`** :
  - Purge manuelle des fichiers CSS et caches compilés.
- **`atelier-activity-log` (Traçabilité & audit trail)** :
  - Journal horodaté conservant l'historique des actions, des utilisateurs et des révisions créées.
- **Suite de tests automatisée** (`npm run test:write`) : 12 vérifications d'écriture réussies (100%).

## Ce qui est fait — phase 4 (Support Elementor Pro)

Gestion avancée des fonctionnalités professionnelles Elementor (Theme Builder, conditions d'affichage, widgets globaux, popups) :

- **`atelier-theme-builder` (Gabarits & Conditions d'affichage)** :
  - Emplacements supportés (`locations`) : `header`, `footer`, `single`, `single-post`, `single-page`, `archive`, `search-results`, `error-404`, `popup`, `section`.
  - Création de gabarits Theme Builder (`create`) avec enregistrement simultané dans le CPT `elementor_library`, la métadonnée `_elementor_template_type` et la taxonomie native `elementor_library_type`.
  - Configuration granulaire des conditions d'affichage (`set_conditions`) : syntaxe hiérarchique standard Elementor Pro (`include/general`, `include/singular/page`, `exclude/...`).
  - Synchronisation bidirectionnelle : méta post individuelle `_elementor_conditions` et table d'index / cache global Elementor Pro `elementor_pro_theme_builder_conditions`.
- **`atelier-global-widgets` (Widgets globaux synchronisés)** :
  - Création (`create`) de widgets globaux à partir de n'importe quel élément standard (`heading`, `button`, etc.).
  - Génération automatique du code d'insertion réutilisable (`usage_snippet` avec `elType="widget"`, `widgetType="global"`, `templateID`).
  - Consultation (`get`) et listing (`list`) des widgets globaux du site.
- **`atelier-popups` (Fenêtres modales & Déclencheurs)** :
  - Création (`create`) de popups avec définition immédiate des déclencheurs et de l'arbre de conteneurs.
  - Configuration avancée (`configure`) : déclencheurs événementiels (`page_load`, `scroll`, `exit_intent`, `inactivity`) et règles de fréquence (`views_count`, etc.).
  - Intégration complète avec les conditions d'affichage du Theme Builder.
- **Suite de tests automatisée** (`npm run test:pro`) : 12 vérifications réussies (100%).

## Ce qui est fait — phase 5 (Support Gutenberg & theme.json)

Gestion complète de l'éditeur de blocs natif de WordPress et de son système de design :

- **`atelier-gutenberg-blocks` (Découverte et attributs des blocs)** :
  - Liste de tous les types de blocs natifs et tiers enregistrés avec filtrage par catégorie ou recherche textuelle.
  - Inspection détaillée (`describe`) de chaque bloc : schéma d'attributs exacts, valeurs par défaut, capacités (`supports`) et relations parent/enfant.
- **`atelier-gutenberg-document` (Lecture & Arbre AST)** :
  - Détection automatique du mode `editor="gutenberg"` sur les pages et articles.
  - Rendu en plan synthétique hiérarchique lisible (`outline`) avec statistiques de profondeur et de typologie.
  - Extraction de l'arbre JSON structuré de blocs (`blocks`) ou du balisage brut (`raw`).
  - Préservation du jeton de concurrence `modified_gmt`.
- **`atelier-gutenberg-save` (Sauvegarde sécurisée & Concurrence optimiste)** :
  - Contrôle anti-écrasement strict basé sur le `modified_gmt` : rejet immédiat (409) en cas de modification concurrente.
  - Mode simulation `dry_run=true` pour prévisualiser l'outline et vérifier la syntaxe sans toucher à la base de données.
  - Sérialisation conforme native (`serialize_blocks`) et création systématique d'une révision WordPress préalable pour rollback.
- **`atelier-gutenberg-patterns` (Compositions de blocs)** :
  - Exploration des catégories et compositions préconçues (`patterns`) fournies par le thème ou les extensions.
  - Extraction du contenu de composition prêt à l'emploi avec outline et statistiques.
- **`atelier-theme-json` (Système de design global)** :
  - Inspection de la configuration globale définie par `theme.json` : palette de couleurs, échelles typographiques, règles d'espacement et largeurs de conteneurs (`content_size`, `wide_size`).
  - Liste des modèles de thèmes à blocs (`wp_template`) et parties de modèles (`wp_template_part`).
- **Suite de tests automatisée** (`npm run test:gutenberg`) : 12 vérifications réussies (100%).

## Ce qui manque

### Phase 6 — Transversal & Distribution (prochaine étape)

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
