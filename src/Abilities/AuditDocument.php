<?php
/**
 * atelier/audit-document : contrôles statiques d'une page Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Documents;
use Atelier\Elementor\Audit;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AuditDocument extends Ability {

	public function slug(): string {
		return 'audit-document';
	}

	public function label(): string {
		return __( 'Auditer un document', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Contrôle une page Elementor sans la modifier : sections/colonnes anciennes, widgets HTML et CSS personnalisé, '
			. 'responsive sur l\'appareil le plus étroit (rangées qui ne s\'empilent pas, grilles à plusieurs colonnes, largeurs fixes, '
			. 'polices trop petites, hauteurs excessives), titres (H1 multiples, sauts de niveau), images sans texte alternatif, boutons sans lien. '
			. 'Chaque constat donne sévérité, règle, id de l\'élément et message. Ne remplace pas une vérification visuelle : '
			. 'proposer à l\'utilisateur d\'ouvrir la page sur téléphone et tablette.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return $this->can_edit_post( (int) ( $input['id'] ?? 0 ) ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->forbidden();
		}

		$summary = Documents::summary( $post );
		if ( Documents::EDITOR_ELEMENTOR !== $summary['editor'] ) {
			return new WP_Error( 'atelier_not_elementor', 'Ce document n\'est pas construit avec Elementor.', array( 'status' => 409 ) );
		}

		$audit = ( new Audit() )->run( Documents::elementor_elements( $post ) );
		return array_merge( $summary, $audit );
	}
}
