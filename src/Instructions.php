<?php
/**
 * Instructions envoyées au modèle à l'ouverture de la session MCP.
 *
 * C'est ici que vit le savoir-faire (règles Elementor, circuit de vérification) :
 * claude.ai web ne charge pas de plugin côté client, tout passe par le serveur.
 *
 * @package Atelier
 */

namespace Atelier;

defined( 'ABSPATH' ) || exit;

final class Instructions {

	public static function text(): string {
		$text = <<<'TXT'
Atelier MCP : outils pour lire, auditer et modifier des pages WordPress construites avec Elementor.

Méthode de travail
1. Commencer par atelier-site-info : versions, éditeur, conteneurs actifs, points de rupture, thème.
2. Trouver la page avec atelier-list-documents, la lire avec atelier-get-document (format "outline" d'abord, "full" seulement si nécessaire). Toujours conserver le modified_gmt renvoyé.
3. Avant d'écrire un réglage Elementor, vérifier son nom exact avec atelier-describe-widget. Ne jamais deviner un nom de réglage.
4. Utiliser atelier-get-kit pour reprendre les couleurs et polices globales du site au lieu de valeurs en dur.
5. Lancer atelier-audit-document avant une modification pour connaître l'état initial.
6. Tester sa modification avec atelier-save-document (en passant dry_run=true d'abord) : cela audite et valide l'arbre sans toucher à la base de données.
7. Si la simulation est concluante, enregistrer définitivement avec atelier-save-document (modified_gmt obligatoire pour éviter les écrasements concurrents). Une révision WordPress est automatiquement créée et le cache CSS est régénéré.
8. En cas de problème ou demande de rollback, utiliser atelier-list-revisions puis atelier-restore-revision.
9. Pour téléverser des images, utiliser atelier-upload-media en fournissant systématiquement un texte alternatif (alt_text).
10. Pour faire relire un brouillon à l'utilisateur sans qu'il ait besoin de se connecter, lui fournir le lien signed généré ou appeler atelier-preview-link.

Règles de construction Elementor
- Conteneurs uniquement (Flexbox, Grille pour les rangées de cartes). Pas de section/colonne.
- Widgets natifs configurés par leurs réglages. Pas de widget HTML ni de CSS personnalisé, sauf demande explicite.
- Responsive obligatoire : pour chaque conteneur en rangée, prévoir l'empilement (flex_direction_tablet / _mobile) ; pour chaque grille, fixer les colonnes tablette et mobile ; réduire les hauteurs minimales et les grandes tailles de police sur mobile.
- Texte lisible : pas de police sous 12px sur mobile ; zone cliquable d'au moins 24px.
- Un seul titre H1 par page, hiérarchie des titres sans saut.

Le thème peut imposer ses propres styles (marges négatives, couleur forcée des paragraphes). Signaler ces écarts à l'utilisateur plutôt que les masquer.
TXT;

		/**
		 * Permet d'adapter les instructions au site (conventions maison, charte).
		 *
		 * @param string $text Instructions par défaut.
		 */
		return (string) apply_filters( 'atelier_mcp_instructions', $text );
	}
}
