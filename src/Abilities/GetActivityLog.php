<?php
/**
 * atelier/activity-log : consulte le journal des modifications effectuées par l'IA.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GetActivityLog extends Ability {

	public function slug(): string {
		return 'activity-log';
	}

	public function label(): string {
		return __( 'Consulter le journal d\'activité', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Consulte les dernières actions et modifications effectuées sur le site via Atelier MCP '
			. '(enregistrements, créations, restaurations de révisions, téléversements de médias).';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Nombre d\'entrées à récupérer.',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	public function execute( array $input ) {
		$limit   = (int) ( $input['limit'] ?? 20 );
		$entries = ActivityLog::get_entries( $limit );

		return array(
			'total'   => count( $entries ),
			'entries' => $entries,
		);
	}
}
