<?php
/**
 * atelier/preview-link : génère un lien d'aperçu temporaire et signé pour prévisualiser un document sans être connecté.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Elementor\PreviewSigner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PreviewLink extends Ability {

	public function slug(): string {
		return 'preview-link';
	}

	public function label(): string {
		return __( 'Générer un lien d\'aperçu signé', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Génère une URL d\'aperçu signée temporaire (24h par défaut) pour consulter une page, un article ou un brouillon '
			. 'sans nécessiter de connexion à l\'administration WordPress. Pratique pour partager un brouillon avec un tiers.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID du document.',
				),
				'ttl' => array(
					'type'        => 'integer',
					'default'     => 86400,
					'minimum'     => 300,
					'maximum'     => 604800,
					'description' => 'Durée de validité du lien en secondes (86400 = 24h).',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return $this->can_edit_post( (int) ( $input['id'] ?? 0 ) ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$post_id = (int) $input['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->forbidden();
		}

		$ttl        = (int) ( $input['ttl'] ?? PreviewSigner::DEFAULT_TTL );
		$preview_url = PreviewSigner::generate_url( $post_id, $ttl );

		return array(
			'id'          => $post->ID,
			'title'       => get_the_title( $post ),
			'preview_url' => $preview_url,
			'expires_at'  => time() + $ttl,
		);
	}
}
