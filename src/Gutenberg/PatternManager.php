<?php
/**
 * Gestionnaire des compositions de blocs (Patterns) Gutenberg.
 *
 * @package Atelier
 */

namespace Atelier\Gutenberg;

use WP_Block_Pattern_Categories_Registry;
use WP_Block_Patterns_Registry;

defined( 'ABSPATH' ) || exit;

final class PatternManager {

	/**
	 * Liste les compositions (patterns) disponibles sur le site.
	 *
	 * @param string|null $category Filtre par catégorie.
	 * @param string|null $search   Terme de recherche libre.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_patterns( ?string $category = null, ?string $search = null ): array {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$patterns = $registry->get_all_registered();
		$result   = array();

		$search = ! empty( $search ) ? strtolower( trim( $search ) ) : null;

		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$categories = is_array( $pattern['categories'] ?? null ) ? $pattern['categories'] : array();
			if ( ! empty( $category ) && ! in_array( $category, $categories, true ) ) {
				continue;
			}

			$name        = (string) ( $pattern['name'] ?? '' );
			$title       = (string) ( $pattern['title'] ?? $name );
			$description = (string) ( $pattern['description'] ?? '' );

			if ( $search ) {
				$haystack = strtolower( "{$name} {$title} {$description}" );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$result[] = array(
				'name'          => $name,
				'title'         => $title,
				'description'   => $description,
				'categories'    => $categories,
				'block_types'   => $pattern['blockTypes'] ?? array(),
				'viewport_width' => $pattern['viewportWidth'] ?? null,
			);
		}

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcmp( $a['title'], $b['title'] );
			}
		);

		return $result;
	}

	/**
	 * Récupère le détail d'une composition spécifique avec son contenu de blocs.
	 *
	 * @param string $name Nom de la composition (ex: 'core/query-standard-posts').
	 * @return array<string, mixed>|null
	 */
	public static function get_pattern( string $name ): ?array {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( $name );

		if ( ! is_array( $pattern ) ) {
			return null;
		}

		$content = (string) ( $pattern['content'] ?? '' );
		$blocks  = BlockManager::parse( $content );

		return array(
			'name'          => $pattern['name'] ?? $name,
			'title'         => $pattern['title'] ?? $name,
			'description'   => $pattern['description'] ?? '',
			'categories'    => $pattern['categories'] ?? array(),
			'block_types'   => $pattern['blockTypes'] ?? array(),
			'content'       => $content,
			'stats'         => BlockManager::stats( $blocks ),
			'outline'       => BlockManager::outline( $blocks ),
		);
	}

	/**
	 * Liste les catégories de compositions enregistrées.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_categories(): array {
		if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
			return array();
		}

		$registry   = WP_Block_Pattern_Categories_Registry::get_instance();
		$categories = $registry->get_all_registered();
		$result     = array();

		foreach ( $categories as $cat ) {
			if ( is_array( $cat ) ) {
				$result[] = array(
					'name'        => (string) ( $cat['name'] ?? '' ),
					'label'       => (string) ( $cat['label'] ?? '' ),
					'description' => (string) ( $cat['description'] ?? '' ),
				);
			}
		}

		return $result;
	}
}
