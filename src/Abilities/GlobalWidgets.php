<?php
/**
 * atelier/global-widgets : gère les widgets globaux Elementor Pro.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Auth\TokenContext;
use Atelier\Elementor\Pro\ThemeBuilderManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GlobalWidgets extends Ability {

	public function slug(): string {
		return 'global-widgets';
	}

	public function label(): string {
		return __( 'Gérer les widgets globaux Elementor Pro', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Liste, consulte ou crée des widgets globaux Elementor Pro (action="list", action="get", action="create"). '
			. 'Un widget global permet de partager un même widget (ex: bloc CTA, formulaire, bannière) synchronisé sur plusieurs pages.';
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
				'action'         => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'get', 'create' ),
					'default'     => 'list',
					'description' => 'Action : "list" (lister), "get" (lire un widget global), "create" (créer un widget global).',
				),
				'id'             => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Avec action="get" : ID du widget global.',
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'Avec action="create" : Nom du widget global.',
				),
				'widget_element' => array(
					'type'        => 'object',
					'description' => 'Avec action="create" : Élément widget à convertir en global (elType: "widget", widgetType: "...", settings: {...}).',
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

		if ( 'get' === $action ) {
			return $this->can_edit_post( (int) ( $input['id'] ?? 0 ) ) ? true : $this->forbidden();
		}

		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$action = $input['action'] ?? 'list';

		if ( 'create' === $action ) {
			$title   = sanitize_text_field( (string) ( $input['title'] ?? 'Widget Global' ) );
			$element = (array) ( $input['widget_element'] ?? array() );

			if ( empty( $element ) ) {
				return new WP_Error( 'invalid_input', __( 'widget_element est obligatoire pour create.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}

			return ThemeBuilderManager::create_global_widget( $title, $element );
		}

		if ( 'get' === $action ) {
			$id   = (int) ( $input['id'] ?? 0 );
			$post = get_post( $id );
			if ( ! $post || ThemeBuilderManager::CPT !== $post->post_type ) {
				return $this->forbidden();
			}

			$raw_data = get_post_meta( $id, '_elementor_data', true );
			$elements = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;

			return array(
				'id'            => $post->ID,
				'title'         => get_the_title( $post ),
				'widget'        => ( is_array( $elements ) && ! empty( $elements[0] ) ) ? $elements[0] : null,
				'usage_snippet' => array(
					'elType'     => 'widget',
					'widgetType' => 'global',
					'templateID' => $post->ID,
				),
			);
		}

		// action === 'list'
		$widgets = ThemeBuilderManager::get_global_widgets();
		return array(
			'total'   => count( $widgets ),
			'widgets' => $widgets,
		);
	}
}
