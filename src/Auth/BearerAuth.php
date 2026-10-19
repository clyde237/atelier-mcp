<?php
/**
 * Gestion de l'authentification par jeton Bearer (OAuth et tokens personnels) dans WordPress.
 *
 * Se branche sur determine_current_user et injecte les en-têtes WWW-Authenticate conformes MCP.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class BearerAuth {

	/** @var WP_Error|null */
	private static $auth_error = null;

	public static function hook(): void {
		add_filter( 'determine_current_user', array( self::class, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( self::class, 'handle_rest_auth_errors' ), 20 );
		add_filter( 'rest_post_dispatch', array( self::class, 'add_mcp_auth_headers' ), 10, 3 );
	}

	/**
	 * Extrait le jeton Bearer de l'en-tête Authorization.
	 */
	public static function get_bearer_token(): ?string {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['PHP_AUTH_BEARER'] ) ) {
			$header = 'Bearer ' . (string) $_SERVER['PHP_AUTH_BEARER'];
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( ! empty( $headers['Authorization'] ) ) {
				$header = (string) $headers['Authorization'];
			} elseif ( ! empty( $headers['authorization'] ) ) {
				$header = (string) $headers['authorization'];
			}
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( $headers as $key => $val ) {
				if ( 0 === strcasecmp( (string) $key, 'Authorization' ) ) {
					$header = (string) $val;
					break;
				}
			}
		}

		if ( empty( $header ) ) {
			return null;
		}

		if ( preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Authentifie l'utilisateur WordPress à partir du jeton Bearer s'il est présent.
	 *
	 * @param int|bool $user_id
	 * @return int|bool
	 */
	public static function determine_current_user( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$raw_token = self::get_bearer_token();
		if ( empty( $raw_token ) ) {
			return $user_id;
		}

		$token_data = TokenStore::find_valid_token( $raw_token );
		if ( ! $token_data ) {
			self::$auth_error = new WP_Error(
				'invalid_token',
				__( 'Jeton Bearer invalide ou expiré.', 'atelier-mcp' ),
				array( 'status' => 401 )
			);
			return 0;
		}

		TokenContext::set_current_token( $token_data );
		return (int) $token_data['user_id'];
	}

	/**
	 * Reste en erreur si un token Bearer invalide a été soumis.
	 *
	 * @param mixed $errors
	 * @return mixed
	 */
	public static function handle_rest_auth_errors( $errors ) {
		if ( ! empty( $errors ) ) {
			return $errors;
		}

		if ( null !== self::$auth_error ) {
			return self::$auth_error;
		}

		return $errors;
	}

	/**
	 * Ajoute l'en-tête WWW-Authenticate sur les réponses 401 du serveur MCP.
	 *
	 * @param WP_REST_Response $response
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return WP_REST_Response
	 */
	public static function add_mcp_auth_headers( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		$route = $request->get_route();

		// Cible les requêtes vers le point d'accès Atelier MCP.
		if ( 0 === strpos( $route, '/atelier/mcp' ) ) {
			$status = $response->get_status();
			if ( 401 === $status || 403 === $status ) {
				$metadata_url = rest_url( Server::ROUTE_NAMESPACE . '/oauth-protected-resource' );
				if ( 403 === $status ) {
					$response->header( 'WWW-Authenticate', sprintf( 'Bearer error="insufficient_scope", scope="atelier:read atelier:write", resource_metadata="%s"', esc_url_raw( $metadata_url ) ) );
				} else {
					$response->header( 'WWW-Authenticate', sprintf( 'Bearer resource_metadata="%s"', esc_url_raw( $metadata_url ) ) );
				}
			}
		}

		return $response;
	}
}
