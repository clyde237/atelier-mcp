<?php
/**
 * atelier/create-document : crée une nouvelle page ou template Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use Atelier\Auth\TokenContext;
use Atelier\Documents;
use Atelier\Elementor\Audit;
use Atelier\Elementor\Environment;
use Atelier\Elementor\PreviewSigner;
use Atelier\Elementor\Tree;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CreateDocument extends Ability {

	public function slug(): string {
		return 'create-document';
	}

	public function label(): string {
		return __( 'Créer un document Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Crée une nouvelle page, article ou template Elementor. '
			. 'Initialise le mode éditeur Elementor et permet de fournir immédiatement un arbre de conteneurs initial.';
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
				'title'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Titre du document.',
				),
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'wp-page', 'wp-post', 'container', 'section' ),
					'default'     => 'wp-page',
					'description' => 'Type de document Elementor (wp-page par défaut).',
				),
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
					'default'     => 'draft',
					'description' => 'Statut de publication (draft par défaut).',
				),
				'elements' => array(
					'type'        => 'array',
					'default'     => array(),
					'description' => 'Arbre Elementor initial facultatif.',
					'items'       => array( 'type' => 'object' ),
				),
				'settings' => array(
					'type'        => 'object',
					'description' => 'Réglages de page facultatifs.',
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->forbidden();
		}

		$status = $input['status'] ?? 'draft';
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
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

	public function execute( array $input ) {
		if ( ! Environment::is_active() ) {
			return new WP_Error( 'atelier_elementor_inactive', __( 'Elementor n\'est pas actif sur ce site.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		$title    = sanitize_text_field( $input['title'] );
		$doc_type = $input['type'] ?? 'wp-page';
		$status   = $input['status'] ?? 'draft';
		$elements = (array) ( $input['elements'] ?? array() );

		// Création du document via l'API Elementor Documents_Manager.
		$post_data = array(
			'post_title'  => $title,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		);

		$document = Environment::plugin()->documents->create( $doc_type, $post_data );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$post_id = $document->get_main_id();

		// Si un arbre initial est fourni, on le sauvegarde.
		$audit_result = null;
		if ( ! empty( $elements ) ) {
			$audit        = new Audit();
			$audit_result = $audit->run( $elements );

			$save_data = array( 'elements' => $elements );
			if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
				$save_data['settings'] = $input['settings'];
			}

			$document->save( $save_data );
			Environment::plugin()->files_manager->clear_cache();
		}

		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		// Journal d'activité.
		ActivityLog::record(
			'create_document',
			$post_id,
			array(
				'type'     => $doc_type,
				'status'   => $status,
				'elements' => count( $elements ),
			)
		);

		$result = Documents::summary( $post );
		$result['preview_url'] = PreviewSigner::generate_url( $post_id );
		if ( $audit_result ) {
			$result['stats'] = Tree::stats( $elements );
			$result['audit'] = $audit_result;
		}

		return $result;
	}
}
