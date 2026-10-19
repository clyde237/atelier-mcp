<?php
/**
 * atelier/gutenberg-save : enregistre un document Gutenberg avec concurrence optimiste, dry-run et révisions.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Auth\TokenContext;
use Atelier\Documents;
use Atelier\Gutenberg\BlockManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SaveGutenbergDocument extends Ability {

	public function slug(): string {
		return 'gutenberg-save';
	}

	public function label(): string {
		return __( 'Enregistrer un document Gutenberg', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Enregistre le contenu Gutenberg d\'une page ou d\'un article. '
			. 'Nécessite le modified_gmt issu de la dernière lecture (protection anti-écrasement). '
			. 'Supporte dry_run=true pour simuler sans enregistrer et crée une révision WordPress pour rollback.';
	}

	public function is_readonly(): bool {
		return false;
	}

	public function is_destructive(): bool {
		return false;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document WordPress à mettre à jour.',
				),
				'modified_gmt'    => array(
					'type'        => 'string',
					'description' => 'Date GMT de dernière modification reçue lors de la lecture (jeton anti-concurrence).',
				),
				'blocks'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'Arbre JSON des blocs à enregistrer (sérialisé automatiquement).',
				),
				'raw_content'     => array(
					'type'        => 'string',
					'description' => 'Alternative directe : balisage HTML complet avec délimiteurs de blocs Gutenberg.',
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Si true, analyse et simule la sauvegarde sans modifier la base de données.',
				),
				'create_revision' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Crée automatiquement une révision WordPress préalable pour permettre un retour arrière.',
				),
			),
			'required'             => array( 'id', 'modified_gmt' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		if ( ! $this->can_edit_post( $post_id ) ) {
			return $this->forbidden();
		}

		if ( TokenContext::is_token_authenticated() && ! TokenContext::has_scope( TokenContext::SCOPE_WRITE ) ) {
			return new WP_Error(
				'atelier_insufficient_scope',
				__( 'Le jeton d\'accès ne dispose pas de la portée d\'écriture (atelier:write).', 'atelier-mcp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function execute( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'atelier_document_not_found', __( 'Document introuvable.', 'atelier-mcp' ), array( 'status' => 404 ) );
		}

		// 1. Contrôle de concurrence optimiste (optimistic locking).
		$expected_gmt = trim( (string) ( $input['modified_gmt'] ?? '' ) );
		$actual_gmt   = (string) $post->post_modified_gmt;

		if ( $expected_gmt !== $actual_gmt ) {
			return new WP_Error(
				'atelier_concurrent_modification',
				sprintf(
					/* translators: 1: version fournie, 2: version en base */
					__( 'Le document a été modifié depuis votre dernière lecture. Relecture obligatoire (fourni: %1$s, actuel: %2$s).', 'atelier-mcp' ),
					$expected_gmt,
					$actual_gmt
				),
				array(
					'status'       => 409,
					'expected_gmt' => $expected_gmt,
					'actual_gmt'   => $actual_gmt,
				)
			);
		}

		$blocks      = (array) ( $input['blocks'] ?? array() );
		$raw_content = isset( $input['raw_content'] ) ? (string) $input['raw_content'] : null;

		if ( empty( $blocks ) && null === $raw_content ) {
			return new WP_Error( 'invalid_input', __( 'Vous devez fournir blocks ou raw_content.', 'atelier-mcp' ), array( 'status' => 400 ) );
		}

		// 2. Mode dry-run : simulation sans persistance.
		if ( ! empty( $input['dry_run'] ) ) {
			$preview_blocks = ( null !== $raw_content ) ? BlockManager::parse( $raw_content ) : $blocks;
			return array(
				'dry_run' => true,
				'summary' => Documents::summary( $post ),
				'stats'   => BlockManager::stats( $preview_blocks ),
				'outline' => BlockManager::outline( $preview_blocks ),
				'message' => __( 'Simulation réussie. Aucune modification apportée à la base de données.', 'atelier-mcp' ),
			);
		}

		// 3. Sauvegarde réelle.
		$create_revision = ! isset( $input['create_revision'] ) || (bool) $input['create_revision'];
		return BlockManager::save( $post_id, $blocks, $raw_content, $create_revision );
	}
}
