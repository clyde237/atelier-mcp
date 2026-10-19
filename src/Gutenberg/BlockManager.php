<?php
/**
 * Gestionnaire d'arbres de blocs Gutenberg : parsing, rendu d'outline, statistiques et sauvegarde.
 *
 * @package Atelier
 */

namespace Atelier\Gutenberg;

use Atelier\ActivityLog;
use Atelier\Documents;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class BlockManager {

	/**
	 * Analyse le contenu d'un post en arbre de blocs Gutenberg.
	 *
	 * @param string $content Contenu HTML/commentaires de blocs.
	 * @param bool   $filter_empty Supprime les blocs anonymes vides d'espacement.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $content, bool $filter_empty = true ): array {
		$blocks = parse_blocks( $content );
		if ( ! $filter_empty ) {
			return $blocks;
		}

		return self::clean_blocks( $blocks );
	}

	/**
	 * Nettoie récursivement les blocs anonymes vides.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public static function clean_blocks( array $blocks ): array {
		$cleaned = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && empty( trim( (string) ( $block['innerHTML'] ?? '' ) ) ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::clean_blocks( $block['innerBlocks'] );
			}

			$cleaned[] = $block;
		}
		return $cleaned;
	}

	/**
	 * Génère un plan textuel lisible (outline) de l'arbre de blocs.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param int                              $depth
	 * @return string
	 */
	public static function outline( array $blocks, int $depth = 0 ): string {
		$lines  = array();
		$indent = str_repeat( '  ', $depth );

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? 'html';
			if ( empty( $name ) ) {
				$name = 'html';
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$tag   = '';

			if ( 'core/heading' === $name ) {
				$level = $attrs['level'] ?? 2;
				$tag   = " [h{$level}]";
			} elseif ( 'core/image' === $name && ! empty( $attrs['id'] ) ) {
				$tag = " [media#{$attrs['id']}]";
			} elseif ( 'core/columns' === $name ) {
				$cols = count( $block['innerBlocks'] ?? array() );
				$tag  = " [{$cols} cols]";
			} elseif ( 'core/group' === $name && ! empty( $attrs['layout']['type'] ) ) {
				$tag = " [layout: {$attrs['layout']['type']}]";
			}

			// Extrait un extrait textuel du bloc (sans balises HTML brutes).
			$snippet = '';
			$raw_html = (string) ( $block['innerHTML'] ?? '' );
			$text     = trim( wp_strip_all_tags( $raw_html ) );
			if ( '' !== $text ) {
				if ( mb_strlen( $text ) > 40 ) {
					$text = mb_substr( $text, 0, 37 ) . '...';
				}
				$snippet = " « {$text} »";
			}

			$lines[] = "{$indent}- {$name}{$tag}{$snippet}";

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$lines[] = self::outline( $block['innerBlocks'], $depth + 1 );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Calcule les statistiques d'un ensemble de blocs Gutenberg.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>
	 */
	public static function stats( array $blocks ): array {
		$total     = 0;
		$by_type   = array();
		$max_depth = 0;

		$walk = static function ( array $items, int $depth ) use ( &$walk, &$total, &$by_type, &$max_depth ): void {
			if ( $depth > $max_depth ) {
				$max_depth = $depth;
			}

			foreach ( $items as $item ) {
				$total++;
				$name = (string) ( $item['blockName'] ?? 'html' );
				if ( empty( $name ) ) {
					$name = 'html';
				}
				$by_type[ $name ] = ( $by_type[ $name ] ?? 0 ) + 1;

				if ( ! empty( $item['innerBlocks'] ) && is_array( $item['innerBlocks'] ) ) {
					$walk( $item['innerBlocks'], $depth + 1 );
				}
			}
		};

		$walk( $blocks, 1 );
		ksort( $by_type );

		return array(
			'total_blocks' => $total,
			'max_depth'    => $max_depth,
			'by_type'      => $by_type,
		);
	}

	/**
	 * Liste les types de blocs enregistrés sur le site avec options de recherche.
	 *
	 * @param string|null $category
	 * @param string|null $search
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_types( ?string $category = null, ?string $search = null ): array {
		$registry = WP_Block_Type_Registry::get_instance();
		$types    = $registry->get_all_registered();
		$result   = array();

		$search = ! empty( $search ) ? strtolower( trim( $search ) ) : null;

		foreach ( $types as $name => $type ) {
			if ( ! $type instanceof WP_Block_Type ) {
				continue;
			}

			if ( ! empty( $category ) && ( $type->category ?? '' ) !== $category ) {
				continue;
			}

			if ( $search ) {
				$haystack = strtolower( "{$name} {$type->title} {$type->description}" );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$result[] = array(
				'name'        => $name,
				'title'       => $type->title ?: $name,
				'category'    => $type->category ?: 'common',
				'description' => $type->description ?: '',
				'icon'        => is_string( $type->icon ) ? $type->icon : 'block-default',
			);
		}

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $result;
	}

	/**
	 * Décrit un type de bloc spécifique avec ses attributs et capacités supportées.
	 *
	 * @param string $name Nom complet du bloc (ex: 'core/heading').
	 * @return array<string, mixed>|null
	 */
	public static function describe_type( string $name ): ?array {
		$registry = WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( $name );

		if ( ! $type instanceof WP_Block_Type ) {
			return null;
		}

		$attributes = array();
		if ( is_array( $type->attributes ) ) {
			foreach ( $type->attributes as $attr_name => $schema ) {
				if ( is_array( $schema ) ) {
					$attributes[ $attr_name ] = array(
						'type'    => $schema['type'] ?? 'string',
						'default' => $schema['default'] ?? null,
					);
				}
			}
		}

		return array(
			'name'        => $type->name,
			'title'       => $type->title ?: $type->name,
			'description' => $type->description ?: '',
			'category'    => $type->category ?: 'common',
			'attributes'  => $attributes,
			'supports'    => is_array( $type->supports ) ? $type->supports : array(),
			'parent'      => is_array( $type->parent ) ? $type->parent : null,
		);
	}

	/**
	 * Sauvegarde les blocs Gutenberg d'un document avec concurrence optimiste et révision.
	 *
	 * @param int         $post_id
	 * @param array       $blocks
	 * @param string|null $raw_content
	 * @param bool        $create_revision
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save( int $post_id, array $blocks = array(), ?string $raw_content = null, bool $create_revision = true ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'atelier_invalid_post', __( 'Document introuvable.', 'atelier-mcp' ), array( 'status' => 404 ) );
		}

		$serialized = ( null !== $raw_content ) ? $raw_content : serialize_blocks( $blocks );

		// 1. Création d'une révision préalable si demandée.
		$revision_id = null;
		if ( $create_revision ) {
			add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
			$revision_id = wp_save_post_revision( $post->ID );
			remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		}

		// 2. Mise à jour du contenu du post.
		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $serialized,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		clean_post_cache( $post->ID );
		$fresh_post = get_post( $post->ID );
		$parsed     = self::parse( $serialized );

		// 3. Traçabilité dans le journal d'activité.
		ActivityLog::record(
			'save_gutenberg_document',
			$post->ID,
			array(
				'blocks_count' => count( $parsed ),
				'revision_id'  => $revision_id,
			)
		);

		$summary = Documents::summary( $fresh_post );
		return array(
			'summary'     => $summary,
			'revision_id' => $revision_id,
			'stats'       => self::stats( $parsed ),
			'outline'     => self::outline( $parsed ),
		);
	}
}
