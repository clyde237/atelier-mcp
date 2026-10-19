<?php
/**
 * Règles communes sur les documents WordPress : types gérés, éditeur utilisé, résumé.
 *
 * @package Atelier
 */

namespace Atelier;

use Atelier\Elementor\Environment;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Documents {

	public const EDITOR_ELEMENTOR = 'elementor';
	public const EDITOR_GUTENBERG = 'gutenberg';
	public const EDITOR_CLASSIC   = 'classic';

	/** Types de contenus listés : pages, articles, types activés pour Elementor, bibliothèque Elementor. */
	public static function post_types(): array {
		$types = array( 'page', 'post' );
		$types = array_merge( $types, get_post_types_by_support( 'elementor' ) );
		if ( post_type_exists( 'elementor_library' ) ) {
			$types[] = 'elementor_library';
		}
		$types = array_values( array_unique( array_filter( $types, 'post_type_exists' ) ) );

		/**
		 * Types de contenus visibles par Atelier (ex. mises en page d'un thème).
		 *
		 * @param string[] $types
		 */
		return (array) apply_filters( 'atelier_mcp_post_types', $types );
	}

	public static function editor_of( WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return self::EDITOR_ELEMENTOR;
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			return self::EDITOR_GUTENBERG;
		}
		return self::EDITOR_CLASSIC;
	}

	/**
	 * Type de document Elementor (wp-page, header, footer, section…), ou null.
	 */
	public static function elementor_template_type( WP_Post $post ): ?string {
		$type = get_post_meta( $post->ID, '_elementor_template_type', true );
		return is_string( $type ) && '' !== $type ? $type : null;
	}

	/**
	 * Résumé commun à toutes les réponses. « modified_gmt » sert de jeton de version
	 * pour refuser plus tard une écriture faite sur une version périmée.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary( WP_Post $post ): array {
		$summary = array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post ),
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'modified_gmt' => $post->post_modified_gmt,
			'link'         => get_permalink( $post ),
			'editor'       => self::editor_of( $post ),
		);

		$template_type = self::elementor_template_type( $post );
		if ( null !== $template_type ) {
			$summary['elementor_template_type'] = $template_type;
		}

		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			$summary['is_front_page'] = true;
		}

		$summary['preview_url'] = Elementor\PreviewSigner::generate_url( $post->ID );
		return $summary;
	}

	/**
	 * Arbre Elementor du document, via l'API d'Elementor quand elle est disponible.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function elementor_elements( WP_Post $post ): array {
		if ( Environment::is_active() ) {
			$document = Environment::plugin()->documents->get( $post->ID );
			if ( $document ) {
				$elements = $document->get_elements_data();
				return is_array( $elements ) ? $elements : array();
			}
		}

		// Elementor désactivé : lecture directe de la méta pour pouvoir quand même auditer.
		$raw      = get_post_meta( $post->ID, '_elementor_data', true );
		$elements = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $elements ) ? $elements : array();
	}
}
