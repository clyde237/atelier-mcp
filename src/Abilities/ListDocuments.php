<?php
/**
 * atelier/list-documents : pages, articles et templates que l'utilisateur peut modifier.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Documents;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class ListDocuments extends Ability {

	private const MAX_PER_PAGE = 100;

	public function slug(): string {
		return 'list-documents';
	}

	public function label(): string {
		return __( 'Lister les documents', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Liste les pages, articles et templates (Elementor, Gutenberg ou éditeur classique) modifiables par l\'utilisateur, '
			. 'du plus récemment modifié au plus ancien. Filtres : type de contenu, éditeur, texte recherché dans le titre, statut. '
			. 'Chaque résultat donne id, titre, type, statut, éditeur, type de template Elementor et modified_gmt.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Type de contenu (page, post, elementor_library…). Par défaut : tous les types gérés.',
				),
				'editor'    => array(
					'type'        => 'string',
					'enum'        => array( Documents::EDITOR_ELEMENTOR, Documents::EDITOR_GUTENBERG, Documents::EDITOR_CLASSIC ),
					'description' => 'Ne garder que les documents faits avec cet éditeur.',
				),
				'search'    => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Texte recherché.',
				),
				'status'    => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
					'default'     => 'any',
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_PER_PAGE,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' );
	}

	public function execute( array $input ) {
		$allowed_types = Documents::post_types();
		$post_types    = $allowed_types;

		if ( ! empty( $input['post_type'] ) ) {
			if ( ! in_array( $input['post_type'], $allowed_types, true ) ) {
				return new WP_Error(
					'atelier_invalid_post_type',
					sprintf( 'Type de contenu non géré. Types disponibles : %s.', implode( ', ', $allowed_types ) ),
					array( 'status' => 400 )
				);
			}
			$post_types = array( $input['post_type'] );
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$args     = array(
			'post_type'              => $post_types,
			'post_status'            => $input['status'] ?? 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'perm'                   => 'editable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}
		if ( Documents::EDITOR_ELEMENTOR === ( $input['editor'] ?? '' ) ) {
			// Filtre côté base pour que la pagination reste juste.
			$args['meta_key']   = '_elementor_edit_mode'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = 'builder'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$query     = new WP_Query( $args );
		$documents = array();
		foreach ( $query->posts as $post ) {
			// Contrôle par document en plus de « perm » : les types personnalisés ont leurs propres droits.
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$summary = Documents::summary( $post );
			if ( ! empty( $input['editor'] ) && $summary['editor'] !== $input['editor'] ) {
				continue;
			}
			$documents[] = $summary;
		}

		return array(
			'documents'   => $documents,
			'page'        => (int) $args['paged'],
			'total_pages' => (int) $query->max_num_pages,
			'total'       => (int) $query->found_posts,
			'post_types'  => $post_types,
		);
	}
}
