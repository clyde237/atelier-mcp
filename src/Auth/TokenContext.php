<?php
/**
 * Contexte de la requête courante authentifiée par jeton Bearer.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class TokenContext {

	public const SCOPE_READ  = 'atelier:read';
	public const SCOPE_WRITE = 'atelier:write';
	public const SCOPE_ADMIN = 'atelier:admin';

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
		if ( in_array( self::SCOPE_ADMIN, $scopes, true ) ) {
			return true;
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
