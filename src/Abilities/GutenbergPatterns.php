<?php
/**
 * atelier/gutenberg-patterns : liste et inspecte les compositions de blocs Gutenberg (patterns).
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Gutenberg\PatternManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GutenbergPatterns extends Ability {

	public function slug(): string {
		return 'gutenberg-patterns';
	}

	public function label(): string {
		return __( 'Explorer les compositions Gutenberg (patterns)', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Explore les compositions de blocs prédéfinies (action="list", action="get", action="categories"). '
			. 'Permet de découvrir des compositions enregistrées par le thème et d\'en récupérer le balisage exact pour réutilisation.';
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
					'enum'        => array( 'list', 'get', 'categories' ),
					'default'     => 'list',
					'description' => 'Action : "list" (lister les compositions), "get" (lire une composition précise), "categories" (lister les catégories).',
				),
				'name'     => array(
					'type'        => 'string',
					'description' => 'Avec action="get" : nom de la composition (ex: "core/query-standard-posts").',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Avec action="list" : filtrer par catégorie.',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Avec action="list" : filtrer par mot-clé dans le titre ou la description.',
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

		if ( 'categories' === $action ) {
			$categories = PatternManager::get_categories();
			return array(
				'total'      => count( $categories ),
				'categories' => $categories,
			);
		}

		if ( 'get' === $action ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( empty( $name ) ) {
				return new WP_Error( 'invalid_input', __( 'Le paramètre name est requis pour l\'action get.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}

			$pattern = PatternManager::get_pattern( $name );
			if ( ! $pattern ) {
				return new WP_Error( 'atelier_pattern_not_found', sprintf( __( 'Composition non trouvée : %s.', 'atelier-mcp' ), $name ), array( 'status' => 404 ) );
			}

			return $pattern;
		}

		$category = ! empty( $input['category'] ) ? sanitize_text_field( (string) $input['category'] ) : null;
		$search   = ! empty( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : null;
		$patterns = PatternManager::get_patterns( $category, $search );

		return array(
			'total'    => count( $patterns ),
			'patterns' => $patterns,
		);
	}
}
