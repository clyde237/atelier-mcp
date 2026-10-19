#!/usr/bin/env python3
"""
Générateur du Guide d'Installation et d'Utilisation officiel d'Atelier MCP.
Produit un document Markdown et un document PDF haute fidélité (ReportLab).
"""

import os
import sys
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, KeepTogether, HRFlowable
)
from reportlab.pdfgen import canvas

PDF_PATH = "GUIDE_INSTALLATION_ET_UTILISATION.pdf"
MD_PATH = "GUIDE_INSTALLATION_ET_UTILISATION.md"

class NumberedCanvas(canvas.Canvas):
    """Canvas personnalisé pour ajouter les en-têtes et pieds de page avec numérotation X / Y."""
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._saved_page_states = []

    def showPage(self):
        self._saved_page_states.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        num_pages = len(self._saved_page_states)
        for state in self._saved_page_states:
            self.__dict__.update(state)
            self.draw_page_decorations(num_pages)
            canvas.Canvas.showPage(self)
        canvas.Canvas.save(self)

    def draw_page_decorations(self, page_count):
        # Ne pas afficher sur la première page (page de couverture)
        if self._pageNumber == 1:
            return

        self.saveState()
        self.setFont("Helvetica-Bold", 8)
        self.setFillColor(colors.HexColor("#4A5568"))

        # En-tête
        self.drawString(54, 802, "ATELIER MCP")
        self.setFont("Helvetica", 8)
        self.setFillColor(colors.HexColor("#718096"))
        self.drawRightString(541, 802, "Guide Officiel d'Installation et d'Utilisation • v0.4.0")
        
        self.setStrokeColor(colors.HexColor("#CBD5E0"))
        self.setLineWidth(0.75)
        self.line(54, 794, 541, 794)

        # Pied de page
        self.line(54, 45, 541, 45)
        self.setFont("Helvetica", 8)
        self.setFillColor(colors.HexColor("#718096"))
        self.drawString(54, 32, "Atelier MCP — Serveur MCP WordPress pour Elementor & Gutenberg")
        
        page_str = f"Page {self._pageNumber} sur {page_count}"
        self.drawRightString(541, 32, page_str)
        self.restoreState()


def build_markdown_guide():
    """Génère la version Markdown du guide d'installation et d'utilisation."""
    md_content = """# Atelier MCP — Guide Officiel d'Installation et d'Utilisation

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
- **Windows** : `%APPDATA%\\Claude\\claude_desktop_config.json`
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
"""
    with open(MD_PATH, "w", encoding="utf-8") as f:
        f.write(md_content)
    print(f"==> Guide Markdown généré : {MD_PATH}")


def build_pdf_guide():
    """Génère la version PDF professionnelle du guide d'installation et d'utilisation."""
    doc = SimpleDocTemplate(
        PDF_PATH,
        pagesize=A4,
        leftMargin=50,
        rightMargin=50,
        topMargin=54,
        bottomMargin=54
    )

    styles = getSampleStyleSheet()

    # Définition des couleurs de charte
    c_primary = colors.HexColor("#1A365D")   # Bleu marine profond
    c_secondary = colors.HexColor("#2B6CB0") # Bleu ardoise
    c_accent = colors.HexColor("#2F855A")    # Vert émeraude
    c_dark = colors.HexColor("#2D3748")      # Texte principal
    c_muted = colors.HexColor("#718096")     # Texte secondaire
    c_light_bg = colors.HexColor("#F7FAFC")  # Fond de bloc
    c_border = colors.HexColor("#E2E8F0")    # Bordures
    c_alert_bg = colors.HexColor("#EBF8FF")  # Fond alerte
    c_alert_border = colors.HexColor("#3182CE")

    # Styles typographiques personnalisés
    style_cover_title = ParagraphStyle(
        'CoverTitle',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=24,
        leading=30,
        textColor=c_primary,
        alignment=0,
        spaceAfter=10
    )

    style_cover_sub = ParagraphStyle(
        'CoverSub',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=12,
        leading=16,
        textColor=c_secondary,
        alignment=0,
        spaceAfter=15
    )

    style_badge = ParagraphStyle(
        'Badge',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=9,
        leading=12,
        textColor=colors.white,
        alignment=0
    )

    style_h1 = ParagraphStyle(
        'CustomH1',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=16,
        leading=20,
        textColor=c_primary,
        spaceBefore=14,
        spaceAfter=8,
        keepWithNext=True
    )

    style_h2 = ParagraphStyle(
        'CustomH2',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=12,
        leading=16,
        textColor=c_secondary,
        spaceBefore=10,
        spaceAfter=6,
        keepWithNext=True
    )

    style_body = ParagraphStyle(
        'CustomBody',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=9.5,
        leading=14,
        textColor=c_dark,
        spaceAfter=6
    )

    style_body_bold = ParagraphStyle(
        'CustomBodyBold',
        parent=style_body,
        fontName='Helvetica-Bold'
    )

    style_bullet = ParagraphStyle(
        'CustomBullet',
        parent=style_body,
        leftIndent=15,
        firstLineIndent=-10,
        spaceAfter=4
    )

    style_code = ParagraphStyle(
        'CustomCode',
        parent=styles['Normal'],
        fontName='Courier',
        fontSize=8,
        leading=11,
        textColor=colors.HexColor("#805AD5"),
        spaceAfter=0
    )

    style_callout_title = ParagraphStyle(
        'CalloutTitle',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=10,
        leading=14,
        textColor=c_secondary,
        spaceAfter=4
    )

    style_table_header = ParagraphStyle(
        'TableHeader',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=8.5,
        leading=11,
        textColor=colors.white,
        alignment=0
    )

    style_table_cell = ParagraphStyle(
        'TableCell',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8,
        leading=11,
        textColor=c_dark,
        alignment=0
    )

    style_table_cell_code = ParagraphStyle(
        'TableCellCode',
        parent=style_table_cell,
        fontName='Courier',
        fontSize=7.5,
        leading=10,
        textColor=colors.HexColor("#2C5282")
    )

    story = []

    # ==========================================
    # EN-TÊTE / BANDEAU COUVERTURE
    # ==========================================
    story.append(Spacer(1, 10))
    story.append(Paragraph("ATELIER MCP", ParagraphStyle('SuperTitle', fontName='Helvetica-Bold', fontSize=10, leading=12, textColor=c_accent, spaceAfter=4)))
    story.append(Paragraph("Guide Officiel d'Installation & d'Utilisation", style_cover_title))
    story.append(Paragraph("Connecter WordPress à Claude, ChatGPT et Antigravity via le Model Context Protocol", style_cover_sub))
    
    # Boîte méta
    meta_data = [
        [
            Paragraph("<b>Version :</b> 0.4.0", style_table_cell),
            Paragraph("<b>WordPress :</b> 6.9+ (Abilities API)", style_table_cell),
            Paragraph("<b>PHP :</b> 7.4 à 8.3+", style_table_cell),
            Paragraph("<b>Licence :</b> GPL-2.0-or-later", style_table_cell)
        ]
    ]
    meta_table = Table(meta_data, colWidths=[120, 140, 110, 125])
    meta_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), c_light_bg),
        ('BOX', (0,0), (-1,-1), 1, c_border),
        ('INNERGRID', (0,0), (-1,-1), 0.5, c_border),
        ('TOPPADDING', (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
        ('LEFTPADDING', (0,0), (-1,-1), 8),
        ('RIGHTPADDING', (0,0), (-1,-1), 8),
    ]))
    story.append(meta_table)
    story.append(Spacer(1, 15))
    story.append(HRFlowable(width="100%", thickness=1.5, color=c_primary, spaceBefore=0, spaceAfter=15))

    # ==========================================
    # 1. INTRODUCTION & ARCHITECTURE
    # ==========================================
    story.append(Paragraph("1. Architecture & Rôle du plugin MCP Adapter", style_h1))
    story.append(Paragraph(
        "<b>Atelier MCP</b> transforme un site WordPress en serveur MCP autonome. L'assistant d'intelligence artificielle "
        "(Claude Code, Claude.ai, ChatGPT, Antigravity) s'y connecte de manière sécurisée pour lire, analyser et construire des pages "
        "directement dans <b>Elementor</b> et <b>Gutenberg</b>.",
        style_body
    ))
    story.append(Paragraph(
        "<b>Pourquoi le plugin officiel MCP Adapter est-il obligatoire ?</b><br/>"
        "Atelier MCP s'appuie sur la division des responsabilités recommandée par l'équipe officielle de WordPress :",
        style_body
    ))
    story.append(Paragraph("• <b>Abilities API (Cœur WordPress 6.9+) :</b> Fournit le cadre officiel pour déclarer les fonctionnalités du site de manière typée et sécurisée.", style_bullet))
    story.append(Paragraph("• <b>MCP Adapter (Équipe officielle WordPress) :</b> Implémente la couche protocolaire MCP standard (transport HTTP/SSE, JSON-RPC 2.0).", style_bullet))
    story.append(Paragraph("• <b>Atelier MCP (Notre plugin) :</b> Fournit les 23 outils métier haut niveau (Elementor, Theme Builder, Gutenberg AST, révisions, sécurité).", style_bullet))

    # Boîte Callout Recommandation
    callout_data = [[
        Paragraph(
            "<b>Pourquoi MCP Adapter n'est-il pas directement embarqué dans Atelier MCP ?</b><br/>"
            "L'équipe officielle de WordPress recommande expressément de ne jamais embarquer MCP Adapter au sein d'un plugin tiers. "
            "Cela évite les conflits fatals de versions PHP si un autre plugin du site utilise également MCP, et permet de maintenir la "
            "couche transport réseau à jour indépendamment du plugin métier.",
            style_body
        )
    ]]
    callout_table = Table(callout_data, colWidths=[495])
    callout_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), c_alert_bg),
        ('BOX', (0,0), (-1,-1), 1, c_alert_border),
        ('LEFTPADDING', (0,0), (-1,-1), 12),
        ('RIGHTPADDING', (0,0), (-1,-1), 12),
        ('TOPPADDING', (0,0), (-1,-1), 8),
        ('BOTTOMPADDING', (0,0), (-1,-1), 8),
    ]))
    story.append(Spacer(1, 6))
    story.append(callout_table)
    story.append(Spacer(1, 12))

    # ==========================================
    # 2. PRÉREQUIS TECHNIQUES
    # ==========================================
    story.append(Paragraph("2. Prérequis Techniques", style_h1))
    
    prereq_data = [
        [
            Paragraph("Composant", style_table_header),
            Paragraph("Version requise", style_table_header),
            Paragraph("Rôle & Justification", style_table_header)
        ],
        [
            Paragraph("<b>WordPress</b>", style_table_cell),
            Paragraph("<b>6.9 ou supérieur</b>", style_table_cell),
            Paragraph("Indispensable : intègre l'Abilities API dans le cœur.", style_table_cell)
        ],
        [
            Paragraph("<b>PHP</b>", style_table_cell),
            Paragraph("7.4 à 8.3+", style_table_cell),
            Paragraph("Recommandé : PHP 8.1 ou 8.2 pour des performances optimales.", style_table_cell)
        ],
        [
            Paragraph("<b>MCP Adapter</b>", style_table_cell),
            Paragraph("Dernière release", style_table_cell),
            Paragraph("Plugin officiel WordPress gérant le transport MCP HTTP/SSE.", style_table_cell)
        ],
        [
            Paragraph("<b>HTTPS</b>", style_table_cell),
            Paragraph("Obligatoire en prod", style_table_cell),
            Paragraph("Requis pour la négociation OAuth 2.1 et la protection des jetons.", style_table_cell)
        ],
        [
            Paragraph("<b>Elementor</b>", style_table_cell),
            Paragraph("3.20+ / Pro facultatif", style_table_cell),
            Paragraph("Requis pour les outils Elementor (pages, kits, popups, Theme Builder).", style_table_cell)
        ]
    ]
    prereq_table = Table(prereq_data, colWidths=[100, 115, 280])
    prereq_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,0), c_primary),
        ('ROWBACKGROUNDS', (0,1), (-1,-1), [colors.white, c_light_bg]),
        ('GRID', (0,0), (-1,-1), 0.5, c_border),
        ('TOPPADDING', (0,0), (-1,-1), 5),
        ('BOTTOMPADDING', (0,0), (-1,-1), 5),
        ('LEFTPADDING', (0,0), (-1,-1), 6),
        ('RIGHTPADDING', (0,0), (-1,-1), 6),
    ]))
    story.append(prereq_table)
    story.append(Spacer(1, 14))

    # ==========================================
    # 3. PROCÉDURE D'INSTALLATION DÉTAILLÉE
    # ==========================================
    story.append(Paragraph("3. Guide d'Installation Pas à Pas", style_h1))
    
    story.append(Paragraph("Étape 1 : Installer le plugin officiel MCP Adapter", style_h2))
    story.append(Paragraph("1. Téléchargez l'archive officielle depuis GitHub :<br/>"
                           "<code>https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip</code>", style_body))
    story.append(Paragraph("2. Connectez-vous à l'administration WordPress (<code>/wp-admin</code>).", style_body))
    story.append(Paragraph("3. Rendez-vous dans <b>Extensions › Ajouter une extension</b>.", style_body))
    story.append(Paragraph("4. Cliquez sur le bouton <b>Téléverser une extension</b> en haut de l'écran.", style_body))
    story.append(Paragraph("5. Choisissez le fichier <code>mcp-adapter.zip</code> et cliquez sur <b>Installer maintenant</b>.", style_body))
    story.append(Paragraph("6. Cliquez sur <b>Activer l'extension</b>.", style_body))

    story.append(Spacer(1, 4))
    story.append(Paragraph("Étape 2 : Installer le plugin Atelier MCP", style_h2))
    story.append(Paragraph("1. Récupérez l'archive de production <code>dist/atelier-mcp.zip</code> (générée via <code>npm run build</code>).", style_body))
    story.append(Paragraph("2. Dans WordPress, retournez dans <b>Extensions › Ajouter une extension › Téléverser une extension</b>.", style_body))
    story.append(Paragraph("3. Choisissez <code>atelier-mcp.zip</code> et cliquez sur <b>Installer maintenant</b>, puis sur <b>Activer l'extension</b>.", style_body))
    story.append(Paragraph("4. <b>Vérification immédiate :</b> Un nouvel onglet <b>Atelier MCP</b> apparaît dans le menu latéral WordPress.", style_body))
    story.append(Paragraph("L'endpoint MCP est immédiatement actif sur : <code>https://&lt;votre-site.com&gt;/wp-json/atelier/mcp</code>", style_body))

    story.append(Spacer(1, 10))

    # ==========================================
    # 4. CONFIGURATION DE L'AUTHENTIFICATION
    # ==========================================
    story.append(Paragraph("4. Modes d'Authentification & Sécurité", style_h1))
    story.append(Paragraph(
        "Atelier MCP intègre son propre gestionnaire d'accès dans le menu <b>Atelier MCP › Connexion</b> :", style_body
    ))

    auth_data = [
        [
            Paragraph("Méthode", style_table_header),
            Paragraph("Usage recommandé", style_table_header),
            Paragraph("Fonctionnement & Configuration", style_table_header)
        ],
        [
            Paragraph("<b>Jeton Personnel (PAT Bearer)</b>", style_table_cell),
            Paragraph("<b>Claude Code CLI</b>, scripts, environnements locaux.", style_table_cell),
            Paragraph("Généré en 1 clic dans <i>Atelier MCP › Connexion</i>. Choix des portées (lecture/écriture) et de la durée. Stocké haché en SHA-256.", style_table_cell)
        ],
        [
            Paragraph("<b>OAuth 2.1 avec PKCE</b>", style_table_cell),
            Paragraph("<b>Claude.ai Web</b>, <b>ChatGPT</b>, <b>Antigravity</b>.", style_table_cell),
            Paragraph("Négociation automatique sans clé d'API via RFC 9728 et RFC 8414. Écran de consentement natif WordPress et rotation des jetons.", style_table_cell)
        ],
        [
            Paragraph("<b>Application Passwords</b>", style_table_cell),
            Paragraph("Rétrocompatibilité WordPress.", style_table_cell),
            Paragraph("Généré dans le profil utilisateur WordPress. Authentification HTTP Basic standard.", style_table_cell)
        ]
    ]
    auth_table = Table(auth_data, colWidths=[110, 130, 255])
    auth_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,0), c_primary),
        ('ROWBACKGROUNDS', (0,1), (-1,-1), [colors.white, c_light_bg]),
        ('GRID', (0,0), (-1,-1), 0.5, c_border),
        ('TOPPADDING', (0,0), (-1,-1), 5),
        ('BOTTOMPADDING', (0,0), (-1,-1), 5),
        ('LEFTPADDING', (0,0), (-1,-1), 6),
        ('RIGHTPADDING', (0,0), (-1,-1), 6),
    ]))
    story.append(auth_table)
    story.append(Spacer(1, 14))

    # ==========================================
    # 5. CONNEXION DES CLIENTS IA
    # ==========================================
    story.append(Paragraph("5. Connexion des Clients IA (Claude, ChatGPT, Antigravity)", style_h1))

    story.append(Paragraph("Connexion avec Claude Code (CLI)", style_h2))
    story.append(Paragraph("Dans votre terminal, exécutez la commande d'enregistrement en fournissant votre jeton PAT :", style_body))

    code_box_1 = [[
        Paragraph("<code>claude mcp add --transport http atelier https://&lt;votre-site.com&gt;/wp-json/atelier/mcp \\<br/>"
                  "  --header \"Authorization: Bearer &lt;votre_jeton_pat&gt;\"</code>", style_code)
    ]]
    t_code_1 = Table(code_box_1, colWidths=[495])
    t_code_1.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), colors.HexColor("#2D3748")),
        ('BOX', (0,0), (-1,-1), 1, colors.HexColor("#1A202C")),
        ('LEFTPADDING', (0,0), (-1,-1), 10),
        ('RIGHTPADDING', (0,0), (-1,-1), 10),
        ('TOPPADDING', (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
    ]))
    story.append(t_code_1)
    story.append(Spacer(1, 8))

    story.append(Paragraph("Connexion avec Claude Desktop", style_h2))
    story.append(Paragraph("Éditez le fichier <code>claude_desktop_config.json</code> et insérez le bloc de configuration :", style_body))

    code_box_2 = [[
        Paragraph(
            "<code>{\n"
            "  \"mcpServers\": {\n"
            "    \"atelier\": {\n"
            "      \"url\": \"https://&lt;votre-site.com&gt;/wp-json/atelier/mcp\",\n"
            "      \"headers\": {\n"
            "        \"Authorization\": \"Bearer &lt;votre_jeton_pat&gt;\"\n"
            "      }\n"
            "    }\n"
            "  }\n"
            "}</code>",
            ParagraphStyle('JsonCode', parent=style_code, textColor=colors.HexColor("#F7FAFC"))
        )
    ]]
    t_code_2 = Table(code_box_2, colWidths=[495])
    t_code_2.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), colors.HexColor("#1A202C")),
        ('BOX', (0,0), (-1,-1), 1, colors.black),
        ('LEFTPADDING', (0,0), (-1,-1), 10),
        ('RIGHTPADDING', (0,0), (-1,-1), 10),
        ('TOPPADDING', (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
    ]))
    story.append(t_code_2)
    story.append(Spacer(1, 8))

    story.append(Paragraph("Connexion avec Claude.ai Web & ChatGPT (OAuth 2.1)", style_h2))
    story.append(Paragraph(
        "Dans l'interface web de Claude.ai ou ChatGPT (mode développeur MCP), ajoutez un connecteur MCP personnalisé "
        "en saisissant uniquement l'adresse : <code>https://&lt;votre-site.com&gt;/wp-json/atelier/mcp</code>.<br/>"
        "Le client lance le flux OAuth 2.1 automatique. Connectez-vous à WordPress et approuvez les autorisations.",
        style_body
    ))

    story.append(Spacer(1, 10))
    story.append(PageBreak())

    # ==========================================
    # 6. RÉPERTOIRE DES 23 OUTILS MCP
    # ==========================================
    story.append(Paragraph("6. Répertoire Complet des 23 Outils MCP", style_h1))
    story.append(Paragraph(
        "Atelier MCP expose 23 outils spécialisés déclarés auprès de l'Abilities API :",
        style_body
    ))

    tools_data = [
        [
            Paragraph("Outil MCP", style_table_header),
            Paragraph("Domaine", style_table_header),
            Paragraph("Rôle & Description de la capacité", style_table_header),
            Paragraph("Mode", style_table_header)
        ],
        [Paragraph("<code>atelier-site-info</code>", style_table_cell_code), Paragraph("Général", style_table_cell), Paragraph("Versions WordPress/Elementor, conteneurs, points de rupture, thème.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-guide</code>", style_table_cell_code), Paragraph("Méthode", style_table_cell), Paragraph("Guide méthodologique officiel, règles d'or et sécurité.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-list-documents</code>", style_table_cell_code), Paragraph("Inspection", style_table_cell), Paragraph("Pages, articles et gabarits modifiables avec éditeur actif.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-get-document</code>", style_table_cell_code), Paragraph("Inspection", style_table_cell), Paragraph("Plan hiérarchique ou arbre Elementor complet (modified_gmt).", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-describe-widget</code>", style_table_cell_code), Paragraph("Inspection", style_table_cell), Paragraph("Noms exacts des contrôles d'un widget ou conteneur du site.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-get-kit</code>", style_table_cell_code), Paragraph("Elementor", style_table_cell), Paragraph("Couleurs et typographies globales du Kit actif.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-audit-document</code>", style_table_cell_code), Paragraph("Qualité", style_table_cell), Paragraph("Audit statique : conteneurs, responsive, titres Hn, images sans alt.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-save-document</code>", style_table_cell_code), Paragraph("Elementor", style_table_cell), Paragraph("Enregistrement sécurisé avec concurrence optimiste (409) et révision.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-create-document</code>", style_table_cell_code), Paragraph("Elementor", style_table_cell), Paragraph("Création de nouvelle page ou template Elementor avec arbre initial.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-list-revisions</code>", style_table_cell_code), Paragraph("Sécurité", style_table_cell), Paragraph("Historique des révisions du document avec auteurs et horodatages.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-restore-revision</code>", style_table_cell_code), Paragraph("Sécurité", style_table_cell), Paragraph("Restauration d'une révision antérieure (rollback instantané).", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-preview-link</code>", style_table_cell_code), Paragraph("Relecture", style_table_cell), Paragraph("Lien de prévisualisation temporaire signé sans compte WordPress.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-upload-media</code>", style_table_cell_code), Paragraph("Médias", style_table_cell), Paragraph("Téléversement d'image Base64 avec texte alternatif obligatoire.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-flush-cache</code>", style_table_cell_code), Paragraph("Maintenance", style_table_cell), Paragraph("Purge et recompilation des styles CSS et fichiers compilés.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-activity-log</code>", style_table_cell_code), Paragraph("Traçabilité", style_table_cell), Paragraph("Journal d'audit de toutes les actions et modifications opérées.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-theme-builder</code>", style_table_cell_code), Paragraph("Elementor Pro", style_table_cell), Paragraph("Gabarits (header, footer, 404) et conditions de ciblage.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-global-widgets</code>", style_table_cell_code), Paragraph("Elementor Pro", style_table_cell), Paragraph("Création et insertion de widgets synchronisés réutilisables.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-popups</code>", style_table_cell_code), Paragraph("Elementor Pro", style_table_cell), Paragraph("Création et déclencheurs de popups (scroll, sortie, timing).", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-gutenberg-blocks</code>", style_table_cell_code), Paragraph("Gutenberg", style_table_cell), Paragraph("Découverte des blocs natifs et schéma détaillé de leurs attributs.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-gutenberg-document</code>", style_table_cell_code), Paragraph("Gutenberg", style_table_cell), Paragraph("Lecture du plan de blocs ou de l'arbre JSON structuré (modified_gmt).", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-gutenberg-save</code>", style_table_cell_code), Paragraph("Gutenberg", style_table_cell), Paragraph("Sauvegarde de blocs avec concurrence optimiste et révisions.", style_table_cell), Paragraph("<b>Écriture</b>", style_table_cell)],
        [Paragraph("<code>atelier-gutenberg-patterns</code>", style_table_cell_code), Paragraph("Gutenberg", style_table_cell), Paragraph("Extraction des compositions de blocs préconçues du thème.", style_table_cell), Paragraph("Lecture", style_table_cell)],
        [Paragraph("<code>atelier-theme-json</code>", style_table_cell_code), Paragraph("Design System", style_table_cell), Paragraph("Palette theme.json, échelles typographiques, largeurs et modèles.", style_table_cell), Paragraph("Lecture", style_table_cell)],
    ]
    tools_table = Table(tools_data, colWidths=[130, 75, 240, 50])
    tools_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,0), c_primary),
        ('ROWBACKGROUNDS', (0,1), (-1,-1), [colors.white, c_light_bg]),
        ('GRID', (0,0), (-1,-1), 0.5, c_border),
        ('TOPPADDING', (0,0), (-1,-1), 3),
        ('BOTTOMPADDING', (0,0), (-1,-1), 3),
        ('LEFTPADDING', (0,0), (-1,-1), 4),
        ('RIGHTPADDING', (0,0), (-1,-1), 4),
    ]))
    story.append(tools_table)
    story.append(Spacer(1, 14))

    # ==========================================
    # 7. SÉCURITÉ & PROTOCOLE DE TRAVAIL
    # ==========================================
    story.append(Paragraph("7. Les Règles d'Or de Sécurité & de Construction", style_h1))
    
    story.append(Paragraph("1. Concurrence optimiste & Jeton de version (modified_gmt)", style_h2))
    story.append(Paragraph(
        "Toute écriture (Elementor ou Gutenberg) requiert la date <code>modified_gmt</code> reçue lors de la dernière lecture. "
        "Si le contenu a été modifié sur le site entre-temps, un refus <b>HTTP 409 Conflict</b> est retourné. Cela empêche l'écrasement involontaire "
        "d'un travail concurrent.",
        style_body
    ))

    story.append(Paragraph("2. Mode simulation préalable (dry_run=true)", style_h2))
    story.append(Paragraph(
        "L'assistant teste sa modification complexe en envoyant <code>dry_run: true</code>. L'arbre est validé, les audits statiques sont exécutés, "
        "et le plan résultant est affiché sans toucher à la base de données.",
        style_body
    ))

    story.append(Paragraph("3. Révisions WordPress automatiques et Rollback", style_h2))
    story.append(Paragraph(
        "Chaque enregistrement génère automatiquement une révision WordPress. L'outil <code>atelier-restore-revision</code> permet de revenir "
        "instantanément à l'état antérieur en cas d'imprévu.",
        style_body
    ))

    story.append(Spacer(1, 10))

    # ==========================================
    # 8. MISES À JOUR & FAQ
    # ==========================================
    story.append(Paragraph("8. Mises à Jour Automatiques & FAQ", style_h1))
    story.append(Paragraph(
        "<b>Mise à jour en 1 clic depuis GitHub :</b><br/>"
        "Atelier MCP intègre son propre système de mise à jour connecté à l'API GitHub Releases. Dès qu'une nouvelle version "
        "est publiée sur le dépôt GitHub, WordPress la détecte et affiche la mise à jour dans <b>Extensions</b> avec son journal des modifications.",
        style_body
    ))

    faq_box = [
        [
            Paragraph("<b>Q : Que faire si je reçois une erreur HTTP 401 Unauthorized ?</b><br/>"
                      "R : Vérifiez que votre jeton PAT est actif dans <i>Atelier MCP › Connexion</i> et que votre serveur web (Apache/Nginx) "
                      "transmet bien l'en-tête <code>Authorization</code> aux scripts PHP (activer <code>SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=$1</code> sur Apache).", style_table_cell)
        ],
        [
            Paragraph("<b>Q : Que faire en cas d'erreur HTTP 409 Conflict ?</b><br/>"
                      "R : C'est la protection anti-écrasement qui fonctionne. Demandez simplement à l'assistant de relire la page avec <code>atelier-get-document</code> avant de renvoyer la mise à jour.", style_table_cell)
        ],
        [
            Paragraph("<b>Q : Pourquoi une alerte indique 'MCP Adapter manquant' ?</b><br/>"
                      "R : Téléversez et activez le plugin officiel <code>mcp-adapter.zip</code> depuis le dépôt GitHub officiel de WordPress.", style_table_cell)
        ]
    ]
    t_faq = Table(faq_box, colWidths=[495])
    t_faq.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), c_light_bg),
        ('GRID', (0,0), (-1,-1), 0.5, c_border),
        ('LEFTPADDING', (0,0), (-1,-1), 8),
        ('RIGHTPADDING', (0,0), (-1,-1), 8),
        ('TOPPADDING', (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
    ]))
    story.append(t_faq)

    # Génération finale du PDF
    doc.build(story, canvasmaker=NumberedCanvas)
    print(f"==> Guide PDF généré avec succès : {PDF_PATH}")

if __name__ == "__main__":
    build_markdown_guide()
    build_pdf_guide()
