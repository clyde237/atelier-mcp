<?php
/**
 * Journal d'activité (Audit trail) des modifications effectuées via Atelier MCP.
 *
 * @package Atelier
 */

namespace Atelier;

use Atelier\Auth\TokenContext;

defined( 'ABSPATH' ) || exit;

final class ActivityLog {

	public const OPTION_LOG = 'atelier_activity_log';
	public const MAX_ENTRIES = 100;

	/**
	 * Enregistre une action dans le journal d'activité.
	 *
	 * @param string               $action  Ex: 'save_document', 'create_document', 'restore_revision', 'flush_cache', 'upload_media'.
	 * @param int                  $post_id ID de l'élément concerné (ou 0).
	 * @param array<string, mixed> $details Détails complémentaires (ex: revision_id, stats, filename).
	 */
	public static function record( string $action, int $post_id = 0, array $details = array() ): void {
		$user    = wp_get_current_user();
		$token   = TokenContext::get_current_token();
		$client  = $token['client_id'] ?? ( $token['name'] ?? 'session_wp' );

		$entry = array(
			'id'         => wp_generate_uuid4(),
			'action'     => sanitize_key( $action ),
			'post_id'    => $post_id,
			'post_title' => $post_id > 0 ? get_the_title( $post_id ) : '',
			'user_id'    => $user->ID,
			'user_name'  => $user->display_name ?: 'Inconnu',
			'client_id'  => $client,
			'timestamp'  => time(),
			'details'    => $details,
		);

		$log = self::get_entries();
		array_unshift( $log, $entry );

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries( int $limit = 50 ): array {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_slice( $log, 0, $limit );
	}

	public static function clear(): void {
		delete_option( self::OPTION_LOG );
	}
}
