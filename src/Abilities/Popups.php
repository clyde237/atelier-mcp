<?php
/**
 * atelier/popups : gère les popups Elementor Pro et leurs réglages de déclenchement.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use Atelier\Auth\TokenContext;
use Atelier\Elementor\Environment;
use Atelier\Elementor\Pro\ThemeBuilderManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Popups extends Ability {

	public function slug(): string {
		return 'popups';
	}

	public function label(): string {
		return __( 'Gérer les popups Elementor Pro', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Liste ou configure les popups Elementor Pro (action="list", action="configure"). '
			. 'Permet de régler les déclencheurs (chargement de page, défilement, intention de sortie, inactivité).';
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
				'action'     => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'create', 'configure' ),
					'default'     => 'list',
					'description' => 'Action : "list" (lister les popups), "create" (créer un popup), "configure" (régler déclencheurs et conditions).',
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Avec action="create" : titre du popup.',
				),
				'popup_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Avec action="configure" : ID du popup.',
				),
				'triggers'   => array(
					'type'        => 'object',
					'description' => 'Déclencheurs : { page_load: bool, page_load_delay: int, scroll: bool, scroll_direction: "down"|"up", exit_intent: bool }.',
				),
				'timing'     => array(
					'type'        => 'object',
					'description' => 'Règles avancées de minutage : { views_count: int, times_count: int }.',
				),
				'conditions' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Conditions d\'affichage (ex: ["include/general"]).',
				),
				'elements'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'Avec action="create" : arbre Elementor initial.',
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

		if ( 'configure' === $action ) {
			$popup_id = (int) ( $input['popup_id'] ?? 0 );
			if ( ! $this->can_edit_post( $popup_id ) ) {
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

		if ( 'create' === $action ) {
			$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
			if ( empty( $title ) ) {
				return new WP_Error( 'invalid_input', __( 'title est obligatoire pour create.', 'atelier-mcp' ), array( 'status' => 400 ) );
			}
			$triggers   = (array) ( $input['triggers'] ?? array() );
			$timing     = (array) ( $input['timing'] ?? array() );
			$conditions = (array) ( $input['conditions'] ?? array() );
			$elements   = (array) ( $input['elements'] ?? array() );

			return ThemeBuilderManager::create_popup( $title, $triggers, $timing, $conditions, $elements );
		}

		if ( 'configure' === $action ) {
			$popup_id = (int) ( $input['popup_id'] ?? 0 );
			$post     = get_post( $popup_id );

			if ( ! $post || ThemeBuilderManager::CPT !== $post->post_type ) {
				return new WP_Error( 'atelier_invalid_popup', __( 'ID de popup invalide.', 'atelier-mcp' ), array( 'status' => 404 ) );
			}

			$settings = get_post_meta( $popup_id, '_elementor_page_settings', true );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			if ( ! empty( $input['triggers'] ) && is_array( $input['triggers'] ) ) {
				$settings = array_merge( $settings, $input['triggers'] );
			}
			if ( ! empty( $input['timing'] ) && is_array( $input['timing'] ) ) {
				$settings = array_merge( $settings, $input['timing'] );
			}

			update_post_meta( $popup_id, '_elementor_page_settings', $settings );

			if ( isset( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
				ThemeBuilderManager::set_display_conditions( $popup_id, $input['conditions'] );
			}

			ActivityLog::record( 'configure_popup', $popup_id );

			return array(
				'id'       => $popup_id,
				'title'    => get_the_title( $post ),
				'settings' => $settings,
				'message'  => __( 'Réglages du popup mis à jour avec succès.', 'atelier-mcp' ),
			);
		}

		// action === 'list'
		$popups = ThemeBuilderManager::get_popups();
		return array(
			'total'  => count( $popups ),
			'popups' => $popups,
		);
	}
}
