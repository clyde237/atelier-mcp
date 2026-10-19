<?php
/**
 * atelier/theme-builder : inspecte et gère les templates du Theme Builder et leurs conditions d'affichage.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Auth\TokenContext;
use Atelier\Elementor\Pro\ThemeBuilderManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ThemeBuilder extends Ability {

	public function slug(): string {
		return 'theme-builder';
	}

	public function label(): string {
		return __( 'Gérer le Theme Builder Elementor Pro', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Gère les templates du Theme Builder (en-tête, pied de page, article, archive, popup, 404) '
			. 'et leurs conditions d\'affichage (action="list", action="set_conditions", action="locations"). '
			. 'Permet de cibler précisément où s\'affichent les modèles (ex: ["include/general"], ["include/singular/page"]).';
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
				'action'      => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'create', 'set_conditions', 'locations' ),
					'default'     => 'list',
					'description' => 'Action à exécuter : "list" (lister les modèles), "create" (créer un modèle), "set_conditions" (mettre à jour les conditions), "locations" (syntaxe et emplacements supportés).',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'Avec action="create" : titre du modèle.',
				),
				'type'        => array(
					'type'        => 'string',
					'enum'        => array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'popup', 'section' ),
					'description' => 'Avec action="create" ou "list" : type de template.',
				),
				'elements'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'Avec action="create" : arbre Elementor initial.',
				),
				'template_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Avec action="set_conditions" : ID du template.',
				),
				'conditions'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Avec action="create" ou "set_conditions" : liste des règles (ex: ["include/general"], ["include/singular/page", "exclude/singular/page/42"]).',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		$action = $input['action'] ?? 'list';

		if ( 'create' === $action ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
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

		if ( 'set_conditions' === $action ) {
			$template_id = (int) ( $input['template_id'] ?? 0 );
			if ( ! $this->can_edit_post( $template_id ) ) {
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

		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$action = $input['action'] ?? 'list';

		if ( 'locations' === $action ) {
			return array(
				'locations'        => ThemeBuilderManager::LOCATIONS,
				'condition_syntax' => array(
					'include/general'            => 'Tout le site (Entire Site)',
					'include/singular'           => 'Tous les contenus singuliers',
					'include/singular/page'      => 'Toutes les pages',
					'include/singular/post'      => 'Tous les articles',
					'include/singular/post/{id}' => 'Un article précis par son ID',
					'include/archive'            => 'Toutes les archives',
					'include/archive/author'     => 'Archives des auteurs',
					'exclude/singular/page/{id}' => 'Exclure une page spécifique',
				),
			);
		}

		if ( 'create' === $action ) {
			$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
			$type  = sanitize_text_field( (string) ( $input['type'] ?? 'section' ) );
			if ( empty( $title ) ) {
				return new WP_Error( 'invalid_input', __( 'title est obligatoire pour create.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}
			$elements   = (array) ( $input['elements'] ?? array() );
			$conditions = (array) ( $input['conditions'] ?? array() );
			return ThemeBuilderManager::create_template( $title, $type, $elements, $conditions );
		}

		if ( 'set_conditions' === $action ) {
			$template_id = (int) ( $input['template_id'] ?? 0 );
			$conditions  = (array) ( $input['conditions'] ?? array() );

			if ( $template_id <= 0 ) {
				return new WP_Error( 'invalid_input', __( 'template_id est obligatoire pour set_conditions.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}

			return ThemeBuilderManager::set_display_conditions( $template_id, $conditions );
		}

		// action === 'list'
		$type      = ! empty( $input['type'] ) ? (string) $input['type'] : null;
		$templates = ThemeBuilderManager::get_templates( $type );

		return array(
			'total'     => count( $templates ),
			'templates' => $templates,
		);
	}
}
