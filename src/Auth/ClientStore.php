<?php
/**
 * Gestion et résolution des clients OAuth 2.1 : DCR (RFC 7591), CIMD et clients prédéfinis.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class ClientStore {

	public const OPTION_CLIENTS = 'atelier_oauth_clients';
	public const TRANSIENT_CIMD_PREFIX = 'atelier_cimd_';

	/**
	 * Clients pré-enregistrés par défaut (Claude, ChatGPT, Antigravity).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function predefined_clients(): array {
		return array(
			'claude-web'  => array(
				'client_id'                  => 'claude-web',
				'client_name'                => 'Claude.ai Web',
				'redirect_uris'              => array(
					'https://claude.ai/api/mcp/auth_callback',
					'https://claude.com/api/mcp/auth_callback',
					'https://claude.ai/oauth/callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'claude'      => array(
				'client_id'                  => 'claude',
				'client_name'                => 'Claude',
				'redirect_uris'              => array(
					'https://claude.ai/api/mcp/auth_callback',
					'https://claude.com/api/mcp/auth_callback',
					'https://claude.ai/oauth/callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'claude.ai'   => array(
				'client_id'                  => 'claude.ai',
				'client_name'                => 'Claude.ai',
				'redirect_uris'              => array(
					'https://claude.ai/api/mcp/auth_callback',
					'https://claude.com/api/mcp/auth_callback',
					'https://claude.ai/oauth/callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'https://claude.ai' => array(
				'client_id'                  => 'https://claude.ai',
				'client_name'                => 'Claude.ai',
				'redirect_uris'              => array(
					'https://claude.ai/api/mcp/auth_callback',
					'https://claude.com/api/mcp/auth_callback',
					'https://claude.ai/oauth/callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'chatgpt'     => array(
				'client_id'                  => 'chatgpt',
				'client_name'                => 'ChatGPT',
				'redirect_uris'              => array(
					'https://chatgpt.com/connector_platform_oauth_redirect',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'antigravity' => array(
				'client_id'                  => 'antigravity',
				'client_name'                => 'Google Antigravity',
				'redirect_uris'              => array(
					'https://antigravity.google/oauth-callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
			),
			'claude-cli'  => array(
				'client_id'                  => 'claude-cli',
				'client_name'                => 'Claude Code / CLI',
				'redirect_uris'              => array(
					'http://localhost/callback',
					'http://127.0.0.1/callback',
				),
				'token_endpoint_auth_method' => 'none',
				'type'                       => 'predefined',
				'allow_loopback_ports'       => true,
			),
		);
	}

	/**
	 * Résout un client par son client_id.
	 *
	 * @param string      $client_id
	 * @param string|null $redirect_uri Optionnel, permet d'autoriser automatiquement Claude.ai
	 * @return array<string, mixed>|null
	 */
	public static function get_client( string $client_id, ?string $redirect_uri = null ): ?array {
		if ( empty( $client_id ) ) {
			return null;
		}

		// 1. Vérification des clients prédéfinis.
		$predefined = self::predefined_clients();
		if ( isset( $predefined[ $client_id ] ) ) {
			return $predefined[ $client_id ];
		}

		// 2. Vérification des clients enregistrés dynamiquement (DCR) ou manuellement en DB.
		$stored_clients = self::get_stored_clients();
		if ( isset( $stored_clients[ $client_id ] ) ) {
			return $stored_clients[ $client_id ];
		}

		// 3. Résolution par document de métadonnées CIMD (si client_id est une URL HTTPS).
		if ( 0 === strpos( $client_id, 'https://' ) ) {
			$cimd = self::resolve_cimd_client( $client_id );
			if ( $cimd ) {
				return $cimd;
			}
		}

		// 4. Tolérance pour Claude.ai si le redirect_uri pointe vers les serveurs officiels d'Anthropic.
		if ( ! empty( $redirect_uri ) ) {
			$parsed = parse_url( $redirect_uri );
			$host   = strtolower( $parsed['host'] ?? '' );
			if ( 'claude.ai' === $host || 'claude.com' === $host ) {
				return array(
					'client_id'                  => $client_id,
					'client_name'                => 'Claude.ai',
					'redirect_uris'              => array(
						'https://claude.ai/api/mcp/auth_callback',
						'https://claude.com/api/mcp/auth_callback',
						'https://claude.ai/oauth/callback',
						$redirect_uri,
					),
					'token_endpoint_auth_method' => 'none',
					'type'                       => 'predefined',
				);
			}
		}

		return null;
	}

	/**
	 * Enregistre un client dynamiquement (DCR RFC 7591).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function register_dynamic_client( array $payload ) {
		$client_name   = sanitize_text_field( $payload['client_name'] ?? 'Assistant IA' );
		$redirect_uris = (array) ( $payload['redirect_uris'] ?? array() );

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error( 'invalid_client_metadata', 'redirect_uris est obligatoire.', array( 'status' => 400 ) );
		}

		$valid_redirects = array();
		foreach ( $redirect_uris as $uri ) {
			$clean_uri = esc_url_raw( (string) $uri );
			if ( self::is_valid_redirect_uri( $clean_uri ) ) {
				$valid_redirects[] = $clean_uri;
			}
		}

		if ( empty( $valid_redirects ) ) {
			return new \WP_Error( 'invalid_redirect_uri', 'Aucune redirect_uri valide fournie.', array( 'status' => 400 ) );
		}

		$client_id = 'at_client_' . bin2hex( random_bytes( 16 ) );
		$auth_method = $payload['token_endpoint_auth_method'] ?? 'none';

		$client_data = array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $valid_redirects,
			'token_endpoint_auth_method' => $auth_method,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'type'                       => 'dynamic',
			'created_at'                 => time(),
		);

		// Si le client demande client_secret_post, générer un secret.
		if ( 'client_secret_post' === $auth_method ) {
			$client_data['client_secret'] = 'at_sec_' . bin2hex( random_bytes( 24 ) );
		}

		$clients                = self::get_stored_clients();
		$clients[ $client_id ] = $client_data;
		self::save_stored_clients( $clients );

		return $client_data;
	}

	/**
	 * Enregistre ou met à jour un client manuellement depuis l'admin.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function save_manual_client( string $client_id, array $data ): void {
		$clients = self::get_stored_clients();
		$clients[ $client_id ] = array_merge(
			array(
				'client_id'                  => $client_id,
				'client_name'                => sanitize_text_field( $data['client_name'] ?? $client_id ),
				'redirect_uris'              => (array) ( $data['redirect_uris'] ?? array() ),
				'token_endpoint_auth_method' => $data['token_endpoint_auth_method'] ?? 'none',
				'type'                       => 'manual',
				'created_at'                 => time(),
			),
			$data
		);
		self::save_stored_clients( $clients );
	}

	/**
	 * Supprime un client enregistré.
	 */
	public static function delete_client( string $client_id ): void {
		$clients = self::get_stored_clients();
		if ( isset( $clients[ $client_id ] ) ) {
			unset( $clients[ $client_id ] );
			self::save_stored_clients( $clients );
		}
	}

	/**
	 * Valide si la redirect_uri demandée correspond à l'une des redirect_uris du client.
	 */
	public static function validate_redirect_uri( array $client, string $requested_uri ): bool {
		$allowed_uris = (array) ( $client['redirect_uris'] ?? array() );
		$clean_req    = untrailingslashit( $requested_uri );

		// 1. Exact match (avec tolérance slash terminal).
		foreach ( $allowed_uris as $allowed ) {
			if ( untrailingslashit( (string) $allowed ) === $clean_req ) {
				return true;
			}
		}

		// 2. Loopback matching pour clients locaux (RFC 8252 : port variable sur localhost).
		if ( ! empty( $client['allow_loopback_ports'] ) || 'dynamic' === ( $client['type'] ?? '' ) ) {
			if ( self::matches_loopback( $requested_uri, $allowed_uris ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Résolution d'un Client ID Metadata Document (CIMD).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolve_cimd_client( string $cimd_url ): ?array {
		$cache_key = self::TRANSIENT_CIMD_PREFIX . md5( $cimd_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$cimd_url,
			array(
				'timeout'    => 5,
				'sslverify'  => true,
				'user-agent' => 'Atelier-MCP-OAuth-Server/' . ATELIER_MCP_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		// Spécification CIMD : Le document DOIT contenir client_id égal à l'URL.
		if ( ( $body['client_id'] ?? '' ) !== $cimd_url ) {
			return null;
		}

		$client = array(
			'client_id'                  => $cimd_url,
			'client_name'                => sanitize_text_field( $body['client_name'] ?? parse_url( $cimd_url, PHP_URL_HOST ) ),
			'client_uri'                 => esc_url_raw( $body['client_uri'] ?? '' ),
			'logo_uri'                   => esc_url_raw( $body['logo_uri'] ?? '' ),
			'redirect_uris'              => array_filter( (array) ( $body['redirect_uris'] ?? array() ), 'is_string' ),
			'token_endpoint_auth_method' => $body['token_endpoint_auth_method'] ?? 'none',
			'type'                       => 'cimd',
		);

		// Cache pendant 1 heure.
		set_transient( $cache_key, $client, 3600 );
		return $client;
	}

	private static function matches_loopback( string $requested_uri, array $allowed_uris ): bool {
		$req_parsed = parse_url( $requested_uri );
		if ( ! $req_parsed || ! isset( $req_parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( $req_parsed['host'] );
		if ( 'localhost' !== $host && '127.0.0.1' !== $host && '[::1]' !== $host ) {
			return false;
		}

		$req_path = $req_parsed['path'] ?? '';

		foreach ( $allowed_uris as $allowed ) {
			$allowed_parsed = parse_url( $allowed );
			if ( ! $allowed_parsed ) {
				continue;
			}
			$allowed_host = strtolower( $allowed_parsed['host'] ?? '' );
			if ( $allowed_host === $host ) {
				$allowed_path = $allowed_parsed['path'] ?? '';
				if ( $allowed_path === $req_path ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function is_valid_redirect_uri( string $uri ): bool {
		$parsed = parse_url( $uri );
		if ( ! $parsed || empty( $parsed['scheme'] ) ) {
			return false;
		}

		// HTTPS obligatoire pour les URLs distantes. HTTP autorisé uniquement pour localhost / 127.0.0.1.
		if ( 'https' === $parsed['scheme'] ) {
			return true;
		}

		if ( 'http' === $parsed['scheme'] ) {
			$host = strtolower( $parsed['host'] ?? '' );
			return 'localhost' === $host || '127.0.0.1' === $host || '[::1]' === $host;
		}

		return false;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_stored_clients(): array {
		$clients = get_option( self::OPTION_CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * @param array<string, array<string, mixed>> $clients
	 */
	private static function save_stored_clients( array $clients ): void {
		update_option( self::OPTION_CLIENTS, $clients, false );
	}
}
