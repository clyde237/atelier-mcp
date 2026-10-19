<?php
/**
 * atelier/gutenberg-document : lit un document WordPress sous forme de plan ou d'arbre de blocs Gutenberg.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Documents;
use Atelier\Gutenberg\BlockManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GetGutenbergDocument extends Ability {

	public function slug(): string {
		return 'gutenberg-document';
	}

	public function label(): string {
		return __( 'Lire un document Gutenberg (plan ou blocs)', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Lit le contenu d\'une page ou d\'un article Gutenberg sous forme de plan textuel synthétique (format="outline"), '
			. 'd\'arbre JSON structuré de blocs (format="blocks"), ou de balisage brut (format="raw"). Conserve toujours modified_gmt.';
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
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document WordPress à lire.',
				),
				'format' => array(
					'type'        => 'string',
					'enum'        => array( 'outline', 'blocks', 'raw' ),
					'default'     => 'outline',
					'description' => 'Format de sortie : "outline" (plan textuel hiérarchique), "blocks" (arbre JSON des blocs), "raw" (code source brut).',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		return $this->can_edit_post( $post_id ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'atelier_document_not_found', __( 'Document introuvable.', 'atelier-mcp' ), array( 'status' => 404 ) );
		}

		$format  = $input['format'] ?? 'outline';
		$raw     = (string) $post->post_content;
		$blocks  = BlockManager::parse( $raw );
		$summary = Documents::summary( $post );

		$response = array(
			'summary'      => $summary,
			'modified_gmt' => $post->post_modified_gmt,
			'stats'        => BlockManager::stats( $blocks ),
		);

		if ( 'raw' === $format ) {
			$response['raw_content'] = $raw;
		} elseif ( 'blocks' === $format ) {
			$response['blocks'] = $blocks;
		} else {
			$response['outline'] = BlockManager::outline( $blocks );
		}

		return $response;
	}
}
