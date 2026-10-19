<?php
/**
 * Liens d'aperçu signés temporaires pour prévisualiser des brouillons sans authentification.
 *
 * @package Atelier
 */

namespace Atelier\Elementor;

defined( 'ABSPATH' ) || exit;

final class PreviewSigner {

	public const QUERY_PARAM_ID   = 'atelier_preview';
	public const QUERY_PARAM_EXP  = 'atelier_expires';
	public const QUERY_PARAM_SIG  = 'atelier_signature';

	public const DEFAULT_TTL = 86400; // 24 heures.

	public static function hook(): void {
		add_action( 'pre_get_posts', array( self::class, 'allow_draft_query' ) );
		add_filter( 'posts_results', array( self::class, 'bypass_draft_404' ), 10, 2 );
	}

	/**
	 * Génère une URL d'aperçu signée pour un document.
	 */
	public static function generate_url( int $post_id, int $ttl = self::DEFAULT_TTL ): string {
		$expires   = time() + $ttl;
		$signature = self::calculate_signature( $post_id, $expires );
		$permalink = get_permalink( $post_id );

		if ( ! $permalink || is_wp_error( $permalink ) ) {
			$permalink = home_url( '/?p=' . $post_id );
		}

		return add_query_arg(
			array(
				self::QUERY_PARAM_ID  => $post_id,
				self::QUERY_PARAM_EXP => $expires,
				self::QUERY_PARAM_SIG => $signature,
			),
			$permalink
		);
	}

	/**
	 * Vérifie si la requête HTTP courante porte une signature d'aperçu valide.
	 */
	public static function is_valid_preview_request( ?int $target_post_id = null ): bool {
		$post_id   = (int) ( $_GET[ self::QUERY_PARAM_ID ] ?? 0 );
		$expires   = (int) ( $_GET[ self::QUERY_PARAM_EXP ] ?? 0 );
		$signature = (string) ( $_GET[ self::QUERY_PARAM_SIG ] ?? '' );

		if ( $post_id <= 0 || $expires <= 0 || empty( $signature ) ) {
			return false;
		}

		if ( null !== $target_post_id && $post_id !== $target_post_id ) {
			return false;
		}

		if ( time() > $expires ) {
			return false;
		}

		$expected = self::calculate_signature( $post_id, $expires );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Permet à WP_Query de charger un post même s'il est en brouillon.
	 *
	 * @param \WP_Query $query
	 */
	public static function allow_draft_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! self::is_valid_preview_request() ) {
			return;
		}

		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'private' ) );
	}

	/**
	 * Évite la redirection 404 pour un post brouillon si la signature est valide.
	 *
	 * @param \WP_Post[] $posts
	 * @param \WP_Query  $query
	 * @return \WP_Post[]
	 */
	public static function bypass_draft_404( array $posts, \WP_Query $query ): array {
		if ( is_admin() || ! $query->is_main_query() || empty( $posts ) ) {
			return $posts;
		}

		$target_id = (int) ( $_GET[ self::QUERY_PARAM_ID ] ?? 0 );
		if ( ! self::is_valid_preview_request( $target_id ) ) {
			return $posts;
		}

		$first_post = $posts[0] ?? null;
		if ( $first_post && (int) $first_post->ID === $target_id ) {
			$query->is_404 = false;
		}

		return $posts;
	}

	private static function calculate_signature( int $post_id, int $expires ): string {
		$payload = sprintf( 'atelier_preview_%d_%d', $post_id, $expires );
		return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}
}
