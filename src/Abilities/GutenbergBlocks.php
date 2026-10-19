<?php
/**
 * atelier/gutenberg-blocks : liste et décrit les types de blocs Gutenberg enregistrés sur le site.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Gutenberg\BlockManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GutenbergBlocks extends Ability {

	public function slug(): string {
		return 'gutenberg-blocks';
	}

	public function label(): string {
		return __( 'Lister et inspecter les types de blocs Gutenberg', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Liste les types de blocs Gutenberg installés ou inspecte leurs attributs exacts (action="list", action="describe"). '
			. 'Permet de vérifier la syntaxe et les options d\'un bloc natif (core/heading, core/columns, etc.) avant écriture.';
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
				'action'   => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'describe' ),
					'default'     => 'list',
					'description' => 'Action : "list" (lister les types de blocs) ou "describe" (inspecter un type de bloc précis).',
				),
				'name'     => array(
					'type'        => 'string',
					'description' => 'Avec action="describe" : nom complet du bloc (ex: "core/heading", "core/columns").',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Avec action="list" : filtrer par catégorie (text, media, design, widgets, theme, embed).',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Avec action="list" : filtrer par mot-clé dans le nom ou la description.',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$action = $input['action'] ?? 'list';

		if ( 'describe' === $action ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( empty( $name ) ) {
				return new WP_Error( 'invalid_input', __( 'Le paramètre name est requis pour l\'action describe.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}

			$described = BlockManager::describe_type( $name );
			if ( ! $described ) {
				return new WP_Error( 'atelier_block_not_found', sprintf( __( 'Type de bloc non trouvé : %s.', 'atelier-mcp' ), $name ), array( 'status' => 404 ) );
			}

			return $described;
		}

		$category = ! empty( $input['category'] ) ? sanitize_text_field( (string) $input['category'] ) : null;
		$search   = ! empty( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : null;
		$types    = BlockManager::list_types( $category, $search );

		return array(
			'total'  => count( $types ),
			'blocks' => $types,
		);
	}
}
