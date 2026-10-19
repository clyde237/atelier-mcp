# Atelier MCP — Guide Officiel d'Installation et d'Utilisation

> **Version du document :** 0.4.0  
> **Compatibilité :** WordPress 6.9+, PHP 7.4 à 8.3+, Elementor 3.x/4.x, Gutenberg natif  
> **Dépôt officiel :** [https://github.com/clyde237/atelier-mcp](https://github.com/clyde237/atelier-mcp)

---

## 1. Introduction & Architecture

### Qu'est-ce qu'Atelier MCP ?
**Atelier MCP** est une extension WordPress privée qui transforme un site WordPress en **serveur MCP (Model Context Protocol)**. Il permet aux assistants d'intelligence artificielle modernes (**Claude**, **ChatGPT**, **Codex**, **Antigravity / Gemini**) de se connecter directement à votre site pour inspecter, auditer, concevoir et modifier des pages construites avec **Elementor**, **Elementor Pro** ou l'éditeur de blocs natif **Gutenberg**.

### Pourquoi le plugin officiel `MCP Adapter` est-il indispensable ?
Atelier MCP s'appuie sur deux piliers complémentaires de l'écosystème WordPress :
1. **L'Abilities API** (nativement intégrée au cœur de WordPress 6.9+) : fournit un cadre standardisé permettant à WordPress et à ses extensions de déclarer leurs fonctionnalités de manière typée et sécurisée.
2. **Le plugin officiel `MCP Adapter`** (développé par l'équipe officielle de WordPress) : implémente la couche protocolaire MCP standard (transport HTTP avec Server-Sent Events SSE, gestion des sessions JSON-RPC 2.0).

**Atelier MCP** implémente 23 outils métier haut niveau (inspection Elementor, concurrence optimiste, Theme Builder, Gutenberg AST, theme.json) et les enregistre auprès de l'Abilities API. **MCP Adapter** se charge de les exposer sur le protocole MCP aux clients IA.

### Pourquoi MCP Adapter n'est-il pas directement inclus dans Atelier MCP ?
L'équipe WordPress recommande expressément de ne pas embarquer MCP Adapter au sein d'autres extensions :
- **Prévention des conflits** : si plusieurs extensions utilisent MCP Adapter, son embarquement créerait des collisions fatales de classes PHP.
- **Cycle de vie indépendant** : la couche transport protocolaire peut évoluer et être mise à jour indépendamment du savoir-faire métier d'Atelier MCP.
- **Mutualisation** : un seul endpoint centralisé gère l'ensemble des capacités MCP du site.

---

## 2. Prérequis Techniques

Avant de commencer l'installation, assurez-vous que votre environnement respecte les prérequis suivants :

| Prérequis | Version minimale | Recommandé | Notes |
|---|---|---|---|
| **WordPress** | 6.9 | 7.1+ | Requiert l'Abilities API intégrée au cœur |
| **PHP** | 7.4 | 8.1 ou 8.2 | Compatible jusqu'à PHP 8.3 |
| **HTTPS** | Recommandé en local | **Obligatoire en production** | Nécessaire pour OAuth 2.1 et les flux sécurisés |
| **Droits WordPress** | Rôle Administrateur | Administrateur | Capacité `install_plugins` et `activate_plugins` |
| **Extensions tierces** | `mcp-adapter` | Dernière release | Indispensable au fonctionnement du serveur MCP |
| **Constructeurs** | Elementor ou Gutenberg | Elementor 3.20+ ou Pro | Gutenberg est inclus nativement dans WordPress |

---

## 3. Guide d'Installation Pas à Pas

### Étape 1 : Téléchargement et Installation de `MCP Adapter`

1. **Télécharger le plugin MCP Adapter officiel** :
   - Rendez-vous sur le dépôt officiel : [https://github.com/WordPress/mcp-adapter/releases](https://github.com/WordPress/mcp-adapter/releases)
   - Téléchargez l'archive zip de la dernière version :  
     `https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip`
2. **Installer l'extension dans WordPress** :
   - Connectez-vous à l'administration de votre site (`/wp-admin`).
   - Allez dans le menu **Extensions › Ajouter une extension**.
   - En haut de l'écran, cliquez sur **Téléverser une extension**.
   - Sélectionnez le fichier `mcp-adapter.zip` sur votre ordinateur.
   - Cliquez sur **Installer maintenant**.
   - Une fois l'installation terminée, cliquez sur **Activer l'extension**.

### Étape 2 : Téléchargement et Installation d'Atelier MCP

1. **Récupérer l'archive d'Atelier MCP** :
   - Utilisez le fichier `dist/atelier-mcp.zip` généré par la commande `npm run build` (ou téléchargez la release zip depuis votre dépôt GitHub privé).
2. **Installer l'extension dans WordPress** :
   - Dans votre administration WordPress, allez dans **Extensions › Ajouter une extension**.
   - Cliquez sur **Téléverser une extension**.
   - Choisissez le fichier `atelier-mcp.zip`.
   - Cliquez sur **Installer maintenant**, puis sur **Activer l'extension**.
3. **Vérification** :
   - Un nouveau menu intitulé **Atelier MCP** apparaît dans la colonne latérale de votre administration WordPress.
   - Si `MCP Adapter` n'était pas activé, une notification d'avertissement vous rappellerait de l'installer.

L'endpoint du serveur MCP est immédiatement actif sur :  
`https://<votre-domaine.com>/wp-json/atelier/mcp`

---

## 4. Configuration de l'Authentification

Atelier MCP intègre un système d'authentification complet et sécurisé accessible depuis le menu **Atelier MCP › Connexion**.

### Méthode 1 : Jeton d'accès personnel (Bearer PAT) — Recommandé pour Claude Code CLI et scripts

Cette méthode est la plus simple et la plus rapide pour connecter Claude Code ou un script d'automatisation :

1. Rendez-vous dans **Atelier MCP › Connexion** dans l'admin WordPress.
2. Dans le bloc **Créer un jeton personnel (PAT)** :
   - **Nom du jeton** : Donnez un nom explicite (ex. `Claude Code Mac`, `Script Recette`).
   - **Portées (Scopes)** :
     - `atelier:read` : permet la lecture, l'audit et l'inspection de pages.
     - `atelier:write` : autorise la modification de contenu, la création et la restauration de révisions.
   - **Expiration** : choisissez la durée de validité (30 jours, 90 jours, 1 an ou illimitée).
3. Cliquez sur **Générer le jeton**.
4. **Important** : Copiez le jeton affiché immédiatement. Pour des raisons de sécurité, le jeton est stocké haché en **SHA-256** dans la base de données et ne pourra plus jamais être réaffiché.

### Méthode 2 : Authentification OAuth 2.1 avec PKCE — Recommandé pour Claude.ai Web et ChatGPT

Atelier MCP intègre son propre serveur d'autorisation conforme aux standards IETF et MCP :
- **Découverte automatique** : les métadonnées de la ressource protégée (`RFC 9728`) et du serveur d'autorisation (`RFC 8414`) sont exposées publiquement sur :
  - `/.well-known/oauth-protected-resource`
  - `/.well-known/oauth-authorization-server`
- **PKCE obligatoire** : le protocole exige `code_challenge_method=S256` pour prévenir toute interception de code.
- **Écran de consentement natif** : lors de la première connexion depuis Claude.ai ou ChatGPT, vous êtes redirigé vers une page WordPress sécurisée vous demandant de valider l'accès de l'application à votre compte.

### Méthode 3 : Mots de passe d'application WordPress (Application Passwords)

Pour des raisons de rétrocompatibilité, Atelier MCP accepte également les mots de passe d'application natifs de WordPress :
- Allez dans **Utilisateurs › Votre profil**.
- Faites défiler jusqu'à la section **Mots de passe d'application**.
- Saisissez un nom d'application et générez un mot de passe.
- Utilisez l'en-tête standard : `Authorization: Basic <base64(identifiant:mot-de-passe)>`.

---

## 5. Connexion des Assistants IA (Clients MCP)

### 1. Claude Code (CLI terminal)

Connectez Claude Code en une seule commande dans votre terminal :

```bash
claude mcp add --transport http atelier https://<votre-domaine.com>/wp-json/atelier/mcp --header "Authorization: Bearer <votre_jeton_pat>"
```

Vérifiez ensuite la connexion en lançant `claude` puis en tapant `/mcp` pour voir les 23 outils Atelier actifs.

### 2. Claude Desktop

Ouvrez votre fichier de configuration `claude_desktop_config.json` :
- **macOS** : `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows** : `%APPDATA%\Claude\claude_desktop_config.json`
- **Linux** : `~/.config/Claude/claude_desktop_config.json`

Ajoutez le serveur Atelier dans le bloc `mcpServers` :

```json
{
  "mcpServers": {
    "atelier": {
      "url": "https://<votre-domaine.com>/wp-json/atelier/mcp",
      "headers": {
        "Authorization": "Bearer <votre_jeton_pat>"
      }
    }
  }
}
```

Redémarrez Claude Desktop. L'icône de marteau (outils) affichera les outils Atelier.

### 3. Claude.ai (Interface Web)

1. Ouvrez Claude.ai et rendez-vous dans vos paramètres de compte (**Settings › Integrations / MCP Connectors**).
2. Ajoutez un nouveau connecteur MCP en sélectionnant le transport **HTTP** et en renseignant l'URL :
   `https://<votre-domaine.com>/wp-json/atelier/mcp`
3. Claude.ai détecte automatiquement la configuration OAuth 2.1 via RFC 9728.
4. Une fenêtre d'authentification WordPress s'ouvre : connectez-vous avec votre compte administrateur et cliquez sur **Autoriser**.
5. Vos sessions Claude.ai disposent dès lors des outils Atelier en direct.

---

## 6. Répertoire des 23 Outils MCP Disponibles

| Outil MCP | Rôle | Type d'accès |
|---|---|---|
| `atelier-site-info` | Détection des versions WordPress, Elementor, Pro, points de rupture et thème | Lecture |
| `atelier-guide` | Guide méthodologique intégré, règles de construction et protocole de sécurité | Lecture |
| `atelier-list-documents` | Liste des pages, articles et gabarits modifiables avec détection de l'éditeur | Lecture |
| `atelier-get-document` | Lecture d'une page Elementor sous forme de plan lisible ou arbre JSON complet | Lecture |
| `atelier-describe-widget` | Noms exacts des contrôles et réglages d'un widget ou conteneur sur le site | Lecture |
| `atelier-get-kit` | Extraction des styles globaux du Kit Elementor (palette, typographies, largeurs) | Lecture |
| `atelier-audit-document` | Audit statique : conteneurs flex/grid, responsive, titres Hn, images sans alt | Lecture |
| `atelier-save-document` | Sauvegarde sécurisée Elementor avec concurrence optimiste et révisions | **Écriture** |
| `atelier-create-document` | Création d'une nouvelle page ou d'un gabarit Elementor avec arbre initial | **Écriture** |
| `atelier-list-revisions` | Historique complet des révisions d'un document avec auteur et dates | Lecture |
| `atelier-restore-revision` | Restauration d'une révision antérieure (rollback instantané sans perte) | **Écriture** |
| `atelier-preview-link` | Génération d'une URL signée temporaire pour prévisualisation sans compte | Lecture |
| `atelier-upload-media` | Téléversement d'image Base64 dans la médiathèque avec alt text obligatoire | **Écriture** |
| `atelier-flush-cache` | Purge et recompilation manuelle des fichiers CSS et caches Elementor | **Écriture** |
| `atelier-activity-log` | Journal d'audit chronologique de toutes les actions opérées sur le site | Lecture |
| `atelier-theme-builder` | Gestion des modèles Theme Builder (header, footer, 404) et conditions de ciblage | **Écriture** |
| `atelier-global-widgets` | Création, lecture et référencement de widgets globaux synchronisés | **Écriture** |
| `atelier-popups` | Création et réglage de fenêtres modales (déclencheurs scroll, sortie, timing) | **Écriture** |
| `atelier-gutenberg-blocks` | Exploration des types de blocs installés et schéma détaillé de leurs attributs | Lecture |
| `atelier-gutenberg-document` | Lecture d'un document Gutenberg (plan hiérarchique, blocs AST ou code brut) | Lecture |
| `atelier-gutenberg-save` | Enregistrement sécurisé de blocs Gutenberg avec concurrence optimiste | **Écriture** |
| `atelier-gutenberg-patterns` | Découverte et extraction des compositions de blocs préconçues du thème | Lecture |
| `atelier-theme-json` | Consultation du design system `theme.json` (palette, typographies, modèles) | Lecture |

---

## 7. Les Règles d'Or de Sécurité et de Méthode

Pour garantir zéro régression et une sécurité absolue de vos contenus de production, Atelier MCP applique 5 principes stricts :

1. **Protection anti-écrasement (Concurrence optimiste)** :  
   Chaque outil d'écriture (`atelier-save-document`, `atelier-gutenberg-save`) exige le paramètre `modified_gmt` récupéré lors de la dernière lecture. Si un autre utilisateur ou une extension a modifié la page entre-temps, WordPress renvoie immédiatement une erreur **HTTP 409 Conflict**. L'assistant est obligé de relire la page avant de réécrire, évitant tout écrasement accidentel.
2. **Simulation préalable obligatoire (`dry_run=true`)** :  
   L'assistant peut tester une modification complexe en passant `dry_run: true`. WordPress simule la sauvegarde, audite la structure et renvoie le plan résultant sans toucher à la base de données.
3. **Création systématique de révision (Filet de sécurité)** :  
   Chaque sauvegarde crée une révision WordPress préalable. En cas de résultat insatisfaisant, l'outil `atelier-restore-revision` permet un rollback instantané.
4. **Zéro supposition de réglage (`describe-widget` / `gutenberg-blocks`)** :  
   L'assistant ne doit jamais deviner un nom de contrôle Elementor ou un attribut de bloc Gutenberg. Il doit systématiquement interroger `atelier-describe-widget` ou `atelier-gutenberg-blocks` pour utiliser les clés officielles du site.
5. **Accessibilité stricte** :  
   Le téléversement d'image via `atelier-upload-media` exige obligatoirement un texte alternatif (`alt_text`). Aucune image muette ne peut être injectée sur le site.

---

## 8. Scénarios Types et Exemples de Prompts

Voici quelques exemples de commandes à donner à votre assistant IA pour exploiter Atelier MCP :

### Scénario 1 : Audit et diagnostic de santé d'une page
> *"Consulte le guide méthodologique avec atelier-guide. Liste ensuite les pages du site, puis lance un audit complet sur la page d'accueil avec atelier-audit-document. Indique-moi les problèmes de conteneurs, les défauts de responsive et les anomalies de titres Hn."*

### Scénario 2 : Modification sécurisée avec simulation et aperçu
> *"Lis la page 'Contact' avec atelier-get-document. Vérifie les couleurs globales avec atelier-get-kit. Fais une simulation avec atelier-save-document en dry_run=true pour ajouter un conteneur CTA en bas de page. Si le plan est propre, enregistre la modification réelle et donne-moi le lien atelier-preview-link pour que je puisse relire le résultat."*

### Scénario 3 : Conception d'un gabarit Theme Builder Elementor Pro
> *"Utilise atelier-theme-builder pour inspecter les emplacements supportés. Crée un nouveau gabarit de type 'header' intitulé 'En-tête Principal 2026' et applique la condition d'affichage pour l'ensemble du site ['include/general']."*

### Scénario 4 : Rédaction d'un article Gutenberg conforme au thème
> *"Consulte atelier-theme-json pour connaître la palette de couleurs et les typographies du thème. Explore les compositions avec atelier-gutenberg-patterns. Crée ensuite le contenu d'un article Gutenberg bien structuré en utilisant atelier-gutenberg-save."*

---

## 9. Mises à Jour Automatiques & Dépannage

### Mises à jour en un clic depuis GitHub
Atelier MCP inclut son propre updater natif ([`src/Updater.php`](file:///home/blackcode/Documents/atelier-mcp/src/Updater.php)) :
- Dès qu'une nouvelle version est publiée sur le dépôt GitHub `clyde237/atelier-mcp`, WordPress détecte la mise à jour dans le menu **Extensions**.
- Cliquez simplement sur **Mettre à jour maintenant** pour appliquer la nouvelle version sans aucune manipulation manuelle par FTP.

### FAQ & Dépannage

- **Erreur HTTP 401 Unauthorized** :  
  Vérifiez que le jeton Bearer PAT est valide et transmis dans l'en-tête `Authorization: Bearer <jeton>`. Assurez-vous que votre serveur web (Apache/Nginx) ne supprime pas l'en-tête `Authorization` (dans Apache, activer `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
- **Erreur HTTP 403 Forbidden** :  
  L'utilisateur associé au jeton ne dispose pas des droits nécessaires (`edit_posts` au minimum pour une session MCP, ou rôle Administrateur pour les détails serveur).
- **Erreur HTTP 409 Conflict** :  
  Le `modified_gmt` fourni ne correspond pas à la date de dernière modification en base de données. Demandez à l'assistant de relire la page avec `atelier-get-document` avant de réessayer la sauvegarde.
- **Notice 'Atelier MCP nécessite MCP Adapter'** :  
  Installez et activez le plugin officiel `mcp-adapter` depuis GitHub Releases.

---
*Document généré automatiquement pour le projet Atelier MCP — Licence GPL-2.0-or-later.*
