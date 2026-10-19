<?php
/**
 * atelier/flush-cache : purge et régénère les fichiers CSS et caches Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use Atelier\Auth\TokenContext;
use Atelier\Elementor\Environment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class FlushCache extends Ability {

	public function slug(): string {
		return 'flush-cache';
	}

	public function label(): string {
		return __( 'Vider les caches Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Purge les fichiers CSS compilés et les caches de rendu Elementor. '
			. 'Utile après des modifications de kits de styles ou si l\'affichage en front-office ne reflète pas les changements.';
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
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
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

	public function execute( array $input ) {
		if ( ! Environment::is_active() ) {
			return new WP_Error( 'atelier_elementor_inactive', __( 'Elementor n\'est pas actif sur ce site.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		// Purge du cache des fichiers CSS Elementor.
		Environment::plugin()->files_manager->clear_cache();

		// Journal d'activité.
		ActivityLog::record( 'flush_cache', 0 );

		return array(
			'success' => true,
			'message' => __( 'Les fichiers CSS et caches Elementor ont été purgés avec succès.', 'atelier-mcp' ),
		);
	}
}
