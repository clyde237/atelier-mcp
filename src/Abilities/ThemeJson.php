<?php
/**
 * atelier/theme-json : consulte les réglages de design theme.json et les modèles de thèmes à blocs.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Gutenberg\ThemeJsonManager;

defined( 'ABSPATH' ) || exit;

final class ThemeJson extends Ability {

	public function slug(): string {
		return 'theme-json';
	}

	public function label(): string {
		return __( 'Consulter le système de design theme.json', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Lit la configuration du système de design theme.json : palette de couleurs, polices et échelles typographiques, '
			. 'règles d\'espacement, largeurs de mise en page (content / wide), et modèles de thèmes à blocs.';
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
				'section' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'colors', 'typography', 'spacing', 'layout', 'templates', 'template_parts' ),
					'default'     => 'all',
					'description' => 'Section demandée : "all", "colors", "typography", "spacing", "layout", "templates" (modèles), "template_parts" (parties de modèles).',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$section = $input['section'] ?? 'all';

		if ( 'templates' === $section ) {
			$templates = ThemeJsonManager::get_templates( 'wp_template' );
			return array(
				'total'     => count( $templates ),
				'templates' => $templates,
			);
		}

		if ( 'template_parts' === $section ) {
			$parts = ThemeJsonManager::get_templates( 'wp_template_part' );
			return array(
				'total'          => count( $parts ),
				'template_parts' => $parts,
			);
		}

		return ThemeJsonManager::get_settings( $section );
	}
}
