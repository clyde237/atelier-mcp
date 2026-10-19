<?php
/**
 * atelier/save-document : enregistre un arbre Elementor avec concurrence optimiste, dry-run et révisions.
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

final class SaveDocument extends Ability {

	public function slug(): string {
		return 'save-document';
	}

	public function label(): string {
		return __( 'Enregistrer un document Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Enregistre un arbre d\'éléments Elementor sur une page ou un template. '
			. 'Exige le jeton modified_gmt (obtenu via atelier-get-document) pour éviter d\'écraser des modifications concurrentes. '
			. 'Supporte dry_run=true pour simuler et pré-auditer sans persister. Crée automatiquement une révision WordPress et régénère le cache CSS.';
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
				'id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document WordPress à modifier.',
				),
				'modified_gmt'    => array(
					'type'        => 'string',
					'description' => 'Jeton de version anti-collision (date post_modified_gmt lue lors de atelier-get-document).',
				),
				'elements'        => array(
					'type'        => 'array',
					'description' => 'Arbre Elementor complet (liste de conteneurs de premier niveau et leurs enfants).',
					'items'       => array( 'type' => 'object' ),
				),
				'settings'        => array(
					'type'        => 'object',
					'description' => 'Réglages de page Elementor facultatifs (ex: titre, post_status).',
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Si true, valide et audite l\'arbre sans écrire en base de données.',
				),
				'create_revision' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Si true, crée une révision WordPress avant d\'enregistrer pour permettre un retour arrière.',
				),
			),
			'required'             => array( 'id', 'modified_gmt', 'elements' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		if ( ! $this->can_edit_post( $post_id ) ) {
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
		$post_id = (int) $input['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->forbidden();
		}

		// 1. Concurrence optimiste : vérification du jeton de version modified_gmt.
		$expected_modified = (string) $input['modified_gmt'];
		if ( $post->post_modified_gmt !== $expected_modified ) {
			return new WP_Error(
				'atelier_conflict_modified',
				sprintf(
					/* translators: 1: Date modified sur le serveur, 2: Jeton fourni */
					__( 'Conflit de version : le document a été modifié sur le serveur depuis votre lecture (serveur : %1$s, fourni : %2$s). Relisez la page avec atelier-get-document avant de réappliquer vos modifications.', 'atelier-mcp' ),
					$post->post_modified_gmt,
					$expected_modified
				),
				array( 'status' => 409 )
			);
		}

		$elements = (array) $input['elements'];

		// 2. Pré-audit statique de l'arbre proposé.
		$audit        = new Audit();
		$audit_result = $audit->run( $elements );

		// 3. Mode simulation (dry_run).
		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run'      => true,
				'valid'        => true,
				'id'           => $post->ID,
				'title'        => get_the_title( $post ),
				'modified_gmt' => $post->post_modified_gmt,
				'stats'        => Tree::stats( $elements ),
				'audit'        => $audit_result,
				'message'      => __( 'Simulation réussie (dry_run) : aucune modification n\'a été enregistrée en base de données.', 'atelier-mcp' ),
			);
		}

		if ( ! Environment::is_active() ) {
			return new WP_Error( 'atelier_elementor_inactive', __( 'Elementor n\'est pas actif sur ce site.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		$document = Environment::plugin()->documents->get( $post->ID );
		if ( ! $document ) {
			return new WP_Error( 'atelier_document_error', __( 'Impossible d\'instancier le document Elementor.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		// 4. Création facultative d'une révision WordPress préalable.
		$revision_id = null;
		if ( ! empty( $input['create_revision'] ) ) {
			add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
			$revision_id = wp_save_post_revision( $post->ID );
			remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		}

		// 5. Sauvegarde via l'API Elementor.
		$save_data = array( 'elements' => $elements );
		if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$save_data['settings'] = $input['settings'];
		}

		$saved = $document->save( $save_data );
		if ( ! $saved ) {
			return new WP_Error( 'atelier_save_failed', __( 'Échec de l\'enregistrement par Elementor.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		// 6. Purge du cache CSS d'Elementor.
		Environment::plugin()->files_manager->clear_cache();

		clean_post_cache( $post->ID );
		$fresh_post = get_post( $post->ID );

		// 7. Traçabilité dans le journal d'activité.
		ActivityLog::record(
			'save_document',
			$post->ID,
			array(
				'revision_id'  => is_int( $revision_id ) ? $revision_id : null,
				'stats'        => Tree::stats( $elements ),
				'findings'     => $audit_result['summary'],
			)
		);

		return array(
			'success'      => true,
			'id'           => $fresh_post->ID,
			'title'        => get_the_title( $fresh_post ),
			'modified_gmt' => $fresh_post->post_modified_gmt,
			'link'         => get_permalink( $fresh_post ),
			'preview_url'  => PreviewSigner::generate_url( $fresh_post->ID ),
			'revision_id'  => is_int( $revision_id ) ? $revision_id : null,
			'stats'        => Tree::stats( $elements ),
			'audit'        => $audit_result,
		);
	}
}
