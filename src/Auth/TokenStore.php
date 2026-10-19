<?php
/**
 * Gestion du stockage et du cycle de vie des jetons d'accès (OAuth et personnels).
 *
 * Tous les jetons sont hachés en SHA-256 avant stockage : aucun secret en clair en base.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class TokenStore {

	public const OPTION_TOKENS         = 'atelier_oauth_tokens';
	public const OPTION_REFRESH_TOKENS = 'atelier_oauth_refresh_tokens';

	/** Durée de validité d'un access token OAuth (1 heure par défaut). */
	public const ACCESS_TOKEN_TTL = 3600;

	/** Durée de validité d'un refresh token (30 jours par défaut). */
	public const REFRESH_TOKEN_TTL = 2592000;

	/**
	 * Hache un jeton avec SHA-256 pour le stockage et la comparaison sécurisée.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Génère une chaîne aléatoire cryptographiquement sûre.
	 */
	public static function generate_random_token( int $bytes = 32 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Crée un jeton d'accès OAuth avec son jeton de rafraîchissement associé.
	 *
	 * @param int      $user_id
	 * @param string   $client_id
	 * @param string[] $scopes
	 * @param int      $ttl
	 * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
	 */
	public static function create_oauth_tokens( int $user_id, string $client_id, array $scopes, int $ttl = self::ACCESS_TOKEN_TTL ): array {
		$raw_access_token  = 'at_live_' . self::generate_random_token( 32 );
		$raw_refresh_token = 'at_rf_' . self::generate_random_token( 32 );

		$now        = time();
		$expires_at = $now + $ttl;
		$rf_expires = $now + self::REFRESH_TOKEN_TTL;

		$token_hash = self::hash_token( $raw_access_token );
		$rf_hash    = self::hash_token( $raw_refresh_token );

		$token_data = array(
			'id'           => wp_generate_uuid4(),
			'user_id'      => $user_id,
			'client_id'    => $client_id,
			'scopes'       => array_values( array_unique( $scopes ) ),
			'type'         => 'oauth',
			'created_at'   => $now,
			'expires_at'   => $expires_at,
			'last_used_at' => null,
			'refresh_hash' => $rf_hash,
		);

		$rf_data = array(
			'user_id'    => $user_id,
			'client_id'  => $client_id,
			'scopes'     => $token_data['scopes'],
			'token_hash' => $token_hash,
			'created_at' => $now,
			'expires_at' => $rf_expires,
		);

		// Sauvegarde du jeton d'accès.
		$tokens                = self::get_all_tokens();
		$tokens[ $token_hash ] = $token_data;
		self::save_tokens( $tokens );

		// Sauvegarde du jeton de rafraîchissement.
		$refresh_tokens             = self::get_all_refresh_tokens();
		$refresh_tokens[ $rf_hash ] = $rf_data;
		self::save_refresh_tokens( $refresh_tokens );

		return array(
			'access_token'  => $raw_access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => $ttl,
			'refresh_token' => $raw_refresh_token,
			'scope'         => implode( ' ', $token_data['scopes'] ),
		);
	}

	/**
	 * Échange un jeton de rafraîchissement contre un nouveau couple access_token / refresh_token (rotation obligatoire).
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}|null
	 */
	public static function rotate_refresh_token( string $raw_refresh_token, string $client_id ): ?array {
		$rf_hash        = self::hash_token( $raw_refresh_token );
		$refresh_tokens = self::get_all_refresh_tokens();

		if ( ! isset( $refresh_tokens[ $rf_hash ] ) ) {
			return null;
		}

		$rf_data = $refresh_tokens[ $rf_hash ];

		// Vérification d'expiration du refresh token.
		if ( time() > (int) $rf_data['expires_at'] ) {
			unset( $refresh_tokens[ $rf_hash ] );
			self::save_refresh_tokens( $refresh_tokens );
			return null;
		}

		// Vérification du client_id.
		if ( ! hash_equals( (string) $rf_data['client_id'], $client_id ) ) {
			return null;
		}

		// Révocation de l'ancien refresh token et de l'ancien access token associé.
		unset( $refresh_tokens[ $rf_hash ] );
		self::save_refresh_tokens( $refresh_tokens );

		if ( ! empty( $rf_data['token_hash'] ) ) {
			self::revoke_token_by_hash( (string) $rf_data['token_hash'] );
		}

		// Émission de nouveaux jetons.
		return self::create_oauth_tokens(
			(int) $rf_data['user_id'],
			$client_id,
			(array) $rf_data['scopes']
		);
	}

	/**
	 * Crée un jeton d'accès personnel (Personal Access Token - PAT).
	 *
	 * @param int      $user_id
	 * @param string   $name
	 * @param string[] $scopes
	 * @param int|null $ttl Durée en secondes, ou null pour sans expiration.
	 * @return array{token: string, data: array<string, mixed>}
	 */
	public static function create_personal_token( int $user_id, string $name, array $scopes, ?int $ttl = null ): array {
		$raw_token  = 'at_pat_' . self::generate_random_token( 32 );
		$token_hash = self::hash_token( $raw_token );
		$now        = time();
		$expires_at = $ttl ? ( $now + $ttl ) : null;

		$token_data = array(
			'id'           => wp_generate_uuid4(),
			'name'         => sanitize_text_field( $name ),
			'user_id'      => $user_id,
			'client_id'    => 'personal',
			'scopes'       => array_values( array_unique( $scopes ) ),
			'type'         => 'personal',
			'preview'      => substr( $raw_token, 0, 11 ) . '...' . substr( $raw_token, -4 ),
			'created_at'   => $now,
			'expires_at'   => $expires_at,
			'last_used_at' => null,
		);

		$tokens                = self::get_all_tokens();
		$tokens[ $token_hash ] = $token_data;
		self::save_tokens( $tokens );

		return array(
			'token' => $raw_token,
			'data'  => $token_data,
		);
	}

	/**
	 * Recherche un jeton valide (non expiré).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_valid_token( string $raw_token ): ?array {
		if ( empty( $raw_token ) ) {
			return null;
		}

		$token_hash = self::hash_token( $raw_token );
		$tokens     = self::get_all_tokens();

		if ( ! isset( $tokens[ $token_hash ] ) ) {
			return null;
		}

		$data = $tokens[ $token_hash ];

		// Vérification de l'expiration si définie.
		if ( null !== $data['expires_at'] && time() > (int) $data['expires_at'] ) {
			unset( $tokens[ $token_hash ] );
			self::save_tokens( $tokens );
			return null;
		}

		// Met à jour la date de dernière utilisation sans spammer la DB (toutes les 5 minutes au plus).
		$now = time();
		if ( empty( $data['last_used_at'] ) || ( $now - (int) $data['last_used_at'] > 300 ) ) {
			$tokens[ $token_hash ]['last_used_at'] = $now;
			self::save_tokens( $tokens );
		}

		return $data;
	}

	/**
	 * Révoque un jeton par sa valeur brute (access token ou refresh token).
	 */
	public static function revoke_raw( string $raw_token ): bool {
		$hash = self::hash_token( $raw_token );

		$tokens = self::get_all_tokens();
		if ( isset( $tokens[ $hash ] ) ) {
			$rf_hash = $tokens[ $hash ]['refresh_hash'] ?? null;
			unset( $tokens[ $hash ] );
			self::save_tokens( $tokens );

			if ( $rf_hash ) {
				$refresh_tokens = self::get_all_refresh_tokens();
				unset( $refresh_tokens[ $rf_hash ] );
				self::save_refresh_tokens( $refresh_tokens );
			}
			return true;
		}

		$refresh_tokens = self::get_all_refresh_tokens();
		if ( isset( $refresh_tokens[ $hash ] ) ) {
			$access_hash = $refresh_tokens[ $hash ]['token_hash'] ?? null;
			unset( $refresh_tokens[ $hash ] );
			self::save_refresh_tokens( $refresh_tokens );

			if ( $access_hash && isset( $tokens[ $access_hash ] ) ) {
				unset( $tokens[ $access_hash ] );
				self::save_tokens( $tokens );
			}
			return true;
		}

		return false;
	}

	/**
	 * Révoque un jeton par son ID interne UUID.
	 */
	public static function revoke_by_id( string $token_id ): bool {
		$tokens = self::get_all_tokens();
		foreach ( $tokens as $hash => $data ) {
			if ( ( $data['id'] ?? '' ) === $token_id ) {
				$rf_hash = $data['refresh_hash'] ?? null;
				unset( $tokens[ $hash ] );
				self::save_tokens( $tokens );

				if ( $rf_hash ) {
					$refresh_tokens = self::get_all_refresh_tokens();
					unset( $refresh_tokens[ $rf_hash ] );
					self::save_refresh_tokens( $refresh_tokens );
				}
				return true;
			}
		}
		return false;
	}

	public static function revoke_token_by_hash( string $token_hash ): void {
		$tokens = self::get_all_tokens();
		if ( isset( $tokens[ $token_hash ] ) ) {
			unset( $tokens[ $token_hash ] );
			self::save_tokens( $tokens );
		}
	}

	/**
	 * Liste tous les jetons actifs (personnels et oauth) pour un utilisateur ou globalement.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_tokens( ?int $user_id = null ): array {
		$tokens = self::get_all_tokens();
		$now    = time();
		$list   = array();

		foreach ( $tokens as $hash => $data ) {
			if ( null !== $data['expires_at'] && $now > (int) $data['expires_at'] ) {
				continue;
			}
			if ( null !== $user_id && (int) $data['user_id'] !== $user_id ) {
				continue;
			}
			$list[] = $data;
		}

		return $list;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_all_tokens(): array {
		$tokens = get_option( self::OPTION_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * @param array<string, array<string, mixed>> $tokens
	 */
	private static function save_tokens( array $tokens ): void {
		update_option( self::OPTION_TOKENS, $tokens, false );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_all_refresh_tokens(): array {
		$rf = get_option( self::OPTION_REFRESH_TOKENS, array() );
		return is_array( $rf ) ? $rf : array();
	}

	/**
	 * @param array<string, array<string, mixed>> $refresh_tokens
	 */
	private static function save_refresh_tokens( array $refresh_tokens ): void {
		update_option( self::OPTION_REFRESH_TOKENS, $refresh_tokens, false );
	}
}
