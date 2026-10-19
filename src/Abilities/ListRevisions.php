<?php
/**
 * atelier/list-revisions : liste les révisions disponibles pour un document Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Elementor\Environment;
use Elementor\Modules\History\Revisions_Manager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ListRevisions extends Ability {

	public function slug(): string {
		return 'list-revisions';
	}

	public function label(): string {
		return __( 'Lister les révisions d\'un document', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Renvoie l\'historique des révisions d\'un document (ID de révision, auteur, date, type). '
			. 'Permet de trouver le revision_id à fournir à atelier-restore-revision en cas de besoin de rollback.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document.',
				),
				'limit' => array(
					'type'        => 'integer',
					'default'     => 15,
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Nombre maximal de révisions à renvoyer.',
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
		$post_id = (int) $input['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->forbidden();
		}

		$limit = (int) ( $input['limit'] ?? 15 );

		$raw_revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => $limit,
				'order'          => 'DESC',
			)
		);

		$revisions = array();
		foreach ( $raw_revisions as $rev ) {
			$author_name = get_the_author_meta( 'display_name', $rev->post_author );
			$is_autosave = false !== strpos( $rev->post_name, 'autosave' );

			$revisions[] = array(
				'revision_id'  => $rev->ID,
				'date'         => $rev->post_modified,
				'modified_gmt' => $rev->post_modified_gmt,
				'author'       => $author_name ?: 'Inconnu',
				'type'         => $is_autosave ? 'autosave' : 'revision',
			);
		}

		return array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'total'     => count( $revisions ),
			'revisions' => $revisions,
		);
	}
}
