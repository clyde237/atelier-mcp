<?php
/**
 * atelier/guide : guide méthodologique et règles de construction pour modèles IA.
 *
 * Utile pour les clients MCP qui n'injectent pas le prompt initial du serveur
 * dans le contexte de l'assistant (ex: ChatGPT, Claude Desktop ou certains hubs MCP).
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Instructions;

defined( 'ABSPATH' ) || exit;

final class Guide extends Ability {

	public function slug(): string {
		return 'guide';
	}

	public function label(): string {
		return __( 'Consulter le guide méthodologique et les règles de construction', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Fournit la méthode de travail officielle, les règles de construction (Elementor et Gutenberg), '
			. 'le protocole de sécurité anti-écrasement et la liste des outils recommandés selon la tâche à accomplir.';
	}

	public function is_readonly(): bool {
		return true;
	}

	public function is_destructive(): bool {
		return false;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'workflow', 'elementor', 'gutenberg', 'security' ),
					'default'     => 'all',
					'description' => 'Thématique souhaitée : "all" (guide complet), "workflow" (méthode pas à pas), "elementor" (règles conteneurs/widgets), "gutenberg" (règles blocs/theme.json), "security" (concurrence et droits).',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$topic = $input['topic'] ?? 'all';

		$sections = array(
			'workflow'  => array(
				'title' => 'Méthode de travail en 8 étapes',
				'steps' => array(
					'1. Découverte'   => 'Appeler atelier-site-info pour identifier la version de WordPress, les extensions actives, les points de rupture et le thème.',
					'2. Ciblage'      => 'Identifier le document via atelier-list-documents (filtrable par editor=elementor ou editor=gutenberg).',
					'3. Inspection'   => 'Lire le document avec atelier-get-document ou atelier-gutenberg-document. Toujours conserver le champ modified_gmt.',
					'4. Vérification' => 'Ne JAMAIS deviner un nom de réglage : inspecter avec atelier-describe-widget ou atelier-gutenberg-blocks.',
					'5. Cohérence'    => 'Réutiliser les styles existants (atelier-get-kit pour Elementor, atelier-theme-json pour Gutenberg).',
					'6. Simulation'   => 'Tester sa modification avec dry_run=true (aucun impact en base, audit de validité immédiat).',
					'7. Écriture'     => 'Enregistrer avec atelier-save-document ou atelier-gutenberg-save en fournissant obligatoirement modified_gmt.',
					'8. Vérification' => 'Fournir le lien de prévisualisation signé (atelier-preview-link) et vérifier les révisions (atelier-list-revisions).',
				),
			),
			'elementor' => array(
				'title' => 'Règles d\'or de construction Elementor',
				'rules' => array(
					'Conteneurs uniquement'  => 'Utiliser exclusivement les conteneurs (Flexbox et Grille). Les sections/colonnes historiques sont proscrites.',
					'Widgets natifs'         => 'Privilégier les réglages des widgets officiels. Proscrire les widgets HTML bruts ou le CSS inline sauf demande explicite.',
					'Responsive obligatoire' => 'Chaque rangée flex doit prévoir son comportement mobile/tablette (wrap, direction colonne). Adapter hauteurs minimales et tailles de police.',
					'Accessibilité & Titres' => 'Un seul H1 par page, hiérarchie stricte (pas de saut H2 vers H4). Zone cliquable d\'au moins 24px.',
					'Images & Médias'        => 'Téléversement via atelier-upload-media avec texte alternatif (alt_text) obligatoire pour chaque média.',
					'Theme Builder & Popups' => 'Gérer les modèles globaux via atelier-theme-builder (header, footer, single) et popups via atelier-popups.',
				),
			),
			'gutenberg' => array(
				'title' => 'Règles de construction Gutenberg & Thèmes à blocs',
				'rules' => array(
					'Système de design'   => 'Consulter atelier-theme-json avant toute écriture pour respecter la palette de couleurs, typographies et largeurs de conteneurs.',
					'Attributs valides'   => 'Inspecter atelier-gutenberg-blocks avant d\'écrire un bloc pour utiliser ses attributs officiels.',
					'Compositions'        => 'Exploiter les motifs du thème via atelier-gutenberg-patterns avant de reconstruire une mise en page de zéro.',
					'Structure de blocs'  => 'Fournir soit un arbre JSON de blocs (blockName, attrs, innerBlocks), soit le balisage avec délimiteurs de blocs.',
				),
			),
			'security'  => array(
				'title' => 'Sécurité, concurrence et gestion des droits',
				'rules' => array(
					'Concurrence optimiste' => 'Chaque outil d\'écriture exige modified_gmt. Si le document a été modifié entre-temps, un refus HTTP 409 est renvoyé pour éviter l\'écrasement involontaire.',
					'Rollback immédiat'     => 'Chaque sauvegarde crée une révision WordPress. En cas d\'anomalie, utiliser atelier-restore-revision.',
					'Contrôle des portées'  => 'Les jetons Bearer exigent le scope atelier:write pour effectuer des modifications en base de données.',
					'Traçabilité'           => 'Toutes les mutations sont enregistrées dans le journal d\'activité (atelier-activity-log).',
				),
			),
		);

		if ( 'all' !== $topic && isset( $sections[ $topic ] ) ) {
			return array(
				'topic'   => $topic,
				'content' => $sections[ $topic ],
			);
		}

		return array(
			'instructions_summary' => Instructions::text(),
			'sections'             => $sections,
		);
	}
}
