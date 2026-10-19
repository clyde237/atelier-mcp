<?php
/**
 * Serveur MCP dédié, exposé sur /wp-json/atelier/mcp.
 *
 * Un serveur dédié plutôt que le serveur par défaut de MCP Adapter : les outils
 * apparaissent directement sous leur nom (pas via « execute-ability »), et la
 * description du serveur sert d'instructions MCP pour le modèle.
 *
 * @package Atelier
 */

namespace Atelier;

use Atelier\Abilities\Registry;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'ABSPATH' ) || exit;

final class Server {

	public const ID              = 'atelier';
	public const ROUTE_NAMESPACE = 'atelier';
	public const ROUTE           = 'mcp';

	/** Droit minimal pour ouvrir une session MCP. Chaque outil vérifie ensuite ses propres droits. */
	public const TRANSPORT_CAPABILITY = 'edit_posts';

	public static function hook(): void {
		add_action( 'mcp_adapter_init', array( self::class, 'create' ) );
	}

	public static function create( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Atelier MCP',
			Instructions::text(),
			ATELIER_MCP_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			Registry::names(),
			array(),
			array(),
			array( self::class, 'transport_permission' )
		);

		if ( is_wp_error( $result ) ) {
			// Pas d'exception avalée : l'échec de création du serveur doit se voir dans les journaux.
			error_log( 'Atelier MCP : création du serveur impossible : ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** @return bool|\WP_Error */
	public static function transport_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'atelier_unauthenticated', __( 'Authentification requise.', 'atelier-mcp' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( self::TRANSPORT_CAPABILITY ) ) {
			return new \WP_Error( 'atelier_forbidden', __( 'Droits insuffisants pour utiliser Atelier MCP.', 'atelier-mcp' ), array( 'status' => 403 ) );
		}
		if ( Auth\TokenContext::is_token_authenticated() && ! Auth\TokenContext::has_scope( Auth\TokenContext::SCOPE_READ ) ) {
			return new \WP_Error( 'atelier_insufficient_scope', __( 'Le jeton d\'accès ne dispose pas de la portée minimale requise (atelier:read).', 'atelier-mcp' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
