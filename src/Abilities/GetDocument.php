<?php
/**
 * atelier/get-document : contenu d'un document, en plan lisible ou en arbre Elementor complet.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Documents;
use Atelier\Elementor\Tree;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GetDocument extends Ability {

	public const FORMAT_OUTLINE = 'outline';
	public const FORMAT_FULL    = 'full';

	public function slug(): string {
		return 'get-document';
	}

	public function label(): string {
		return __( 'Lire un document', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Lit un document par son id. Format "outline" (par défaut) : plan indenté, une ligne par élément '
			. '(type#id [disposition] « texte »), plus des statistiques — à utiliser d\'abord. Format "full" : arbre Elementor JSON complet '
			. '(réglages exacts), à demander seulement pour modifier ou inspecter un réglage, éventuellement limité à un élément avec element_id. '
			. 'Renvoie aussi modified_gmt, à conserver : il servira de jeton de version pour les écritures.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'format'     => array(
					'type'    => 'string',
					'enum'    => array( self::FORMAT_OUTLINE, self::FORMAT_FULL ),
					'default' => self::FORMAT_OUTLINE,
				),
				'element_id' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,40}$',
					'description' => 'Avec format "full" : ne renvoyer que cet élément et ses enfants.',
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

		$result = Documents::summary( $post );
		if ( Documents::EDITOR_ELEMENTOR !== $result['editor'] ) {
			$result['note'] = 'Document non construit avec Elementor : lecture Gutenberg et éditeur classique prévue dans une version suivante.';
			return $result;
		}

		$elements          = Documents::elementor_elements( $post );
		$result['stats']   = Tree::stats( $elements );
		$result['settings'] = $this->page_settings( $post->ID );

		$format = $input['format'] ?? self::FORMAT_OUTLINE;
		if ( self::FORMAT_OUTLINE === $format ) {
			$result['outline'] = Tree::outline( $elements );
			return $result;
		}

		if ( ! empty( $input['element_id'] ) ) {
			$element = $this->find_element( $elements, (string) $input['element_id'] );
			if ( null === $element ) {
				return new WP_Error( 'atelier_element_not_found', 'Élément introuvable dans ce document.', array( 'status' => 404 ) );
			}
			$result['element'] = $element;
			return $result;
		}

		$result['elements'] = $elements;
		return $result;
	}

	/** @return array<string, mixed> Réglages de page Elementor tels qu'enregistrés. */
	private function page_settings( int $post_id ): array {
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : array();
	}

	private function find_element( array $elements, string $id ): ?array {
		$found = null;
		Tree::walk(
			$elements,
			static function ( array $element ) use ( $id, &$found ): void {
				if ( null === $found && ( $element['id'] ?? null ) === $id ) {
					$found = $element;
				}
			}
		);
		return $found;
	}
}
