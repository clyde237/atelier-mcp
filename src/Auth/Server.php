<?php
/**
 * Serveur d'autorisation OAuth 2.1 pour Atelier MCP.
 *
 * Découverte RFC 9728 / RFC 8414, PKCE S256 obligatoire, DCR RFC 7591, révocation RFC 7009.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Server {

	public const ROUTE_NAMESPACE = 'atelier/oauth';

	public static function hook(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'handle_well_known_rewrites' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'handle_cors' ), 10, 4 );
	}

	/**
	 * Intercepte les requêtes directes vers /.well-known/... si elles atteignent WordPress.
	 */
	public static function handle_well_known_rewrites(): void {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$path        = untrailingslashit( $path );

		if ( preg_match( '#/\.well-known/oauth-protected-resource$#', $path ) ) {
			self::send_json_response( self::get_protected_resource_metadata() );
		}

		if ( preg_match( '#/\.well-known/(oauth-authorization-server|openid-configuration)$#', $path ) ) {
			self::send_json_response( self::get_authorization_server_metadata() );
		}
	}

	public static function register_routes(): void {
		// RFC 9728 : Métadonnées de la ressource protégée.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_protected_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/oauth-protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_protected_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);

		// RFC 8414 : Métadonnées du serveur d'autorisation.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_authorization_server_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/oauth-authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_authorization_server_metadata' ),
				'permission_callback' => '__return_true',
			)
		);

		// Point d'entrée d'autorisation (écran de consentement).
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/authorize',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'rest_authorize' ),
				'permission_callback' => '__return_true',
			)
		);

		// Échange de code et rafraîchissement de jetons.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_token' ),
				'permission_callback' => '__return_true',
			)
		);

		// Révocation de jetons (RFC 7009).
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		// Enregistrement dynamique de clients DCR (RFC 7591).
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_register' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_protected_resource_metadata(): WP_REST_Response {
		return new WP_REST_Response( self::get_protected_resource_metadata() );
	}

	public static function rest_authorization_server_metadata(): WP_REST_Response {
		return new WP_REST_Response( self::get_authorization_server_metadata() );
	}

	public static function rest_authorize(): void {
		ConsentScreen::handle();
		exit;
	}

	public static function rest_token( WP_REST_Request $request ) {
		$params     = $request->get_params();
		$grant_type = $params['grant_type'] ?? '';

		if ( 'authorization_code' === $grant_type ) {
			return self::handle_authorization_code_grant( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::handle_refresh_token_grant( $params );
		}

		return new WP_Error(
			'unsupported_grant_type',
			__( 'grant_type non pris en charge. Seuls authorization_code et refresh_token sont supportés (OAuth 2.1).', 'atelier-mcp' ),
			array( 'status' => 400 )
		);
	}

	private static function handle_authorization_code_grant( array $params ) {
		$code          = (string) ( $params['code'] ?? '' );
		$client_id     = (string) ( $params['client_id'] ?? '' );
		$redirect_uri  = (string) ( $params['redirect_uri'] ?? '' );
		$code_verifier = (string) ( $params['code_verifier'] ?? '' );

		if ( empty( $code ) || empty( $client_id ) || empty( $code_verifier ) ) {
			return new WP_Error( 'invalid_request', 'code, client_id et code_verifier sont obligatoires.', array( 'status' => 400 ) );
		}

		$code_hash = hash( 'sha256', $code );
		$code_key  = 'atelier_oauth_code_' . $code_hash;
		$code_data = get_transient( $code_key );

		// Règle OAuth : consommation unique immédiate du code d'autorisation.
		delete_transient( $code_key );

		if ( ! is_array( $code_data ) ) {
			return new WP_Error( 'invalid_grant', 'Code d\'autorisation expiré ou invalide.', array( 'status' => 400 ) );
		}

		// Vérification du client_id.
		if ( ! hash_equals( (string) $code_data['client_id'], $client_id ) ) {
			return new WP_Error( 'invalid_grant', 'client_id ne correspond pas au code d\'autorisation émis.', array( 'status' => 400 ) );
		}

		// Vérification de la redirect_uri (tolérance sur le slash terminal).
		if ( ! empty( $code_data['redirect_uri'] ) && ! empty( $redirect_uri ) && untrailingslashit( (string) $code_data['redirect_uri'] ) !== untrailingslashit( $redirect_uri ) ) {
			return new WP_Error( 'invalid_grant', 'redirect_uri ne correspond pas.', array( 'status' => 400 ) );
		}

		// Vérification PKCE (S256 obligatoire en OAuth 2.1).
		$computed_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $code_data['code_challenge'], $computed_challenge ) ) {
			return new WP_Error( 'invalid_grant', 'code_verifier PKCE invalide.', array( 'status' => 400 ) );
		}

		$user_id = (int) $code_data['user_id'];
		$scopes  = (array) ( $code_data['scopes'] ?? array() );
		if ( empty( $scopes ) ) {
			$scopes = array( TokenContext::SCOPE_READ, TokenContext::SCOPE_WRITE );
		} elseif ( ! in_array( TokenContext::SCOPE_READ, $scopes, true ) ) {
			$scopes[] = TokenContext::SCOPE_READ;
		}

		$tokens = TokenStore::create_oauth_tokens( $user_id, $client_id, $scopes );
		return new WP_REST_Response( $tokens, 200 );
	}

	private static function handle_refresh_token_grant( array $params ) {
		$refresh_token = (string) ( $params['refresh_token'] ?? '' );
		$client_id     = (string) ( $params['client_id'] ?? '' );

		if ( empty( $refresh_token ) || empty( $client_id ) ) {
			return new WP_Error( 'invalid_request', 'refresh_token et client_id sont obligatoires.', array( 'status' => 400 ) );
		}

		$new_tokens = TokenStore::rotate_refresh_token( $refresh_token, $client_id );
		if ( ! $new_tokens ) {
			return new WP_Error( 'invalid_grant', 'refresh_token invalide ou expiré.', array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $new_tokens, 200 );
	}

	public static function rest_revoke( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) ( $request->get_param( 'token' ) ?? '' );
		if ( ! empty( $token ) ) {
			TokenStore::revoke_raw( $token );
		}
		return new WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	public static function rest_register( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$result = ClientStore::register_dynamic_client( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Document de métadonnées de la ressource protégée (RFC 9728).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_protected_resource_metadata(): array {
		$mcp_url     = untrailingslashit( rest_url( 'atelier/mcp' ) );
		$auth_server = untrailingslashit( site_url() );

		// Tolérance : si le client demande une resource explicite avec slash terminal correspondant.
		$requested_resource = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '';
		$resource = ( ! empty( $requested_resource ) && untrailingslashit( $requested_resource ) === $mcp_url )
			? $requested_resource
			: $mcp_url;

		return array(
			'resource'              => $resource,
			'authorization_servers' => array( $auth_server ),
			'scopes_supported'      => array(
				TokenContext::SCOPE_READ,
				TokenContext::SCOPE_WRITE,
				TokenContext::SCOPE_ADMIN,
			),
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation'   => admin_url( 'admin.php?page=atelier-mcp-connect' ),
		);
	}

	/**
	 * Document de métadonnées du serveur d'autorisation (RFC 8414).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_authorization_server_metadata(): array {
		$site_url = untrailingslashit( site_url() );
		return array(
			'issuer'                                => $site_url,
			'authorization_endpoint'                => rest_url( self::ROUTE_NAMESPACE . '/authorize' ),
			'token_endpoint'                        => rest_url( self::ROUTE_NAMESPACE . '/token' ),
			'registration_endpoint'                 => rest_url( self::ROUTE_NAMESPACE . '/register' ),
			'revocation_endpoint'                   => rest_url( self::ROUTE_NAMESPACE . '/revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'scopes_supported'                      => array(
				TokenContext::SCOPE_READ,
				TokenContext::SCOPE_WRITE,
				TokenContext::SCOPE_ADMIN,
			),
		);
	}

	/**
	 * Assure les en-têtes CORS pour toutes les requêtes OAuth et MCP (support navigateurs et clients web).
	 *
	 * @param bool             $served
	 * @param WP_REST_Response $result
	 * @param WP_REST_Request  $request
	 * @param WP_REST_Server   $server
	 * @return bool
	 */
	public static function handle_cors( $served, $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/atelier' ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, X-WP-Nonce, Mcp-Session-Id' );
		header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id' );

		if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 204 );
			return true;
		}

		return $served;
	}

	private static function send_json_response( array $data ): void {
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With' );
		if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 204 );
			exit;
		}
		echo (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
