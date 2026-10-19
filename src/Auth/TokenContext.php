<?php
/**
 * Contexte de la requête courante authentifiée par jeton Bearer.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class TokenContext {

	public const SCOPE_READ        = 'atelier:read';
	public const SCOPE_WRITE       = 'atelier:write';
	public const SCOPE_ADMIN       = 'atelier:admin';
	public const SCOPE_MCP_TOOLS   = 'mcp:tools';
	public const SCOPE_MCP_RES     = 'mcp:resources';
	public const SCOPE_MCP_PROMPTS = 'mcp:prompts';
	public const SCOPE_MCP         = 'mcp';
	public const SCOPE_OFFLINE     = 'offline_access';

	/**
	 * Liste de toutes les portées reconnues et acceptées par Atelier MCP.
	 *
	 * @return string[]
	 */
	public static function all_supported_scopes(): array {
		return array(
			self::SCOPE_READ,
			self::SCOPE_WRITE,
			self::SCOPE_ADMIN,
			self::SCOPE_MCP_TOOLS,
			self::SCOPE_MCP_RES,
			self::SCOPE_MCP_PROMPTS,
			self::SCOPE_MCP,
			self::SCOPE_OFFLINE,
			'openid',
			'profile',
			'email',
			'read',
			'write',
			'claudeai',
			'claude',
		);
	}

	/** @var array<string, mixed>|null */
	private static $current_token = null;

	/**
	 * @param array<string, mixed>|null $token
	 */
	public static function set_current_token( ?array $token ): void {
		self::$current_token = $token;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_current_token(): ?array {
		return self::$current_token;
	}

	public static function is_token_authenticated(): bool {
		return null !== self::$current_token;
	}

	/**
	 * Vérifie si le jeton courant possède la portée demandée.
	 */
	public static function has_scope( string $scope ): bool {
		if ( null === self::$current_token ) {
			// Non authentifié par token (ex: authentifié par session de cookie WordPress standard ou Basic Auth).
			return true;
		}

		$scopes = self::$current_token['scopes'] ?? array();
		if ( ! is_array( $scopes ) ) {
			return false;
		}

		// La portée admin englobe toutes les portées.
		if ( in_array( self::SCOPE_ADMIN, $scopes, true ) || in_array( 'admin', $scopes, true ) ) {
			return true;
		}

		// Équivalences pour la lecture : atelier:read, mcp:tools, mcp, read, etc.
		if ( self::SCOPE_READ === $scope ) {
			$read_equivalents = array(
				self::SCOPE_READ,
				self::SCOPE_WRITE,
				self::SCOPE_MCP_TOOLS,
				self::SCOPE_MCP,
				'read',
				'write',
			);
			foreach ( $read_equivalents as $eq ) {
				if ( in_array( $eq, $scopes, true ) ) {
					return true;
				}
			}
		}

		// Équivalences pour l'écriture : atelier:write, mcp:tools, mcp, write.
		if ( self::SCOPE_WRITE === $scope ) {
			$write_equivalents = array(
				self::SCOPE_WRITE,
				self::SCOPE_MCP_TOOLS,
				self::SCOPE_MCP,
				'write',
			);
			foreach ( $write_equivalents as $eq ) {
				if ( in_array( $eq, $scopes, true ) ) {
					return true;
				}
			}
		}

		return in_array( $scope, $scopes, true );
	}

	/**
	 * Réinitialise le contexte (utile pour les tests).
	 */
	public static function reset(): void {
		self::$current_token = null;
	}
}
