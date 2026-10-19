<?php
/**
 * atelier/restore-revision : restaure une révision antérieure d'un document Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use Atelier\Auth\TokenContext;
use Atelier\Documents;
use Atelier\Elementor\Environment;
use Atelier\Elementor\PreviewSigner;
use Elementor\Modules\History\Revisions_Manager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RestoreRevision extends Ability {

	public function slug(): string {
		return 'restore-revision';
	}

	public function label(): string {
		return __( 'Restaurer une révision Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Restaure une révision passée d\'un document Elementor (rollback sécurisé). '
			. 'Met à jour les métadonnées Elementor et régénère les caches CSS.';
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
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document parent à restaurer.',
				),
				'revision_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID de la révision à réappliquer (obtenue via atelier-list-revisions).',
				),
			),
			'required'             => array( 'id', 'revision_id' ),
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
		$post_id     = (int) $input['id'];
		$revision_id = (int) $input['revision_id'];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->forbidden();
		}

		// Vérification que la révision appartient bien au post.
		$parent_id = wp_is_post_revision( $revision_id );
		if ( (int) $parent_id !== $post_id ) {
			return new WP_Error(
				'atelier_invalid_revision',
				__( 'La révision spécifiée n\'appartient pas à ce document.', 'atelier-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Restauration via WordPress core puis Revisions_Manager d'Elementor.
		wp_restore_post_revision( $revision_id );

		if ( class_exists( '\Elementor\Modules\History\Revisions_Manager' ) ) {
			Revisions_Manager::restore_revision( $post_id, $revision_id );
		}

		if ( Environment::is_active() ) {
			Environment::plugin()->files_manager->clear_cache();
		}

		clean_post_cache( $post_id );
		$fresh_post = get_post( $post_id );

		// Journal d'activité.
		ActivityLog::record(
			'restore_revision',
			$post_id,
			array( 'revision_id' => $revision_id )
		);

		$summary = Documents::summary( $fresh_post );
		$summary['restored_revision_id'] = $revision_id;
		$summary['preview_url']          = PreviewSigner::generate_url( $post_id );

		return $summary;
	}
}
