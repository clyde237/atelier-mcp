<?php
/**
 * Écran d'autorisation et de consentement OAuth 2.1 pour Atelier MCP.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class ConsentScreen {

	public const CODE_TTL = 300; // 5 minutes.

	/**
	 * Affiche ou traite la requête d'autorisation OAuth.
	 */
	public static function handle(): void {
		// 1. Récupération des paramètres de la requête (support GET et POST).
		$client_id             = sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ?? '' ) );
		$redirect_uri          = esc_url_raw( wp_unslash( $_REQUEST['redirect_uri'] ?? '' ) );
		$response_type         = sanitize_text_field( wp_unslash( $_REQUEST['response_type'] ?? '' ) );
		$scope                 = sanitize_text_field( wp_unslash( $_REQUEST['scope'] ?? 'atelier:read atelier:write' ) );
		$state                 = sanitize_text_field( wp_unslash( $_REQUEST['state'] ?? '' ) );
		$code_challenge        = sanitize_text_field( wp_unslash( $_REQUEST['code_challenge'] ?? '' ) );
		$code_challenge_method = sanitize_text_field( wp_unslash( $_REQUEST['code_challenge_method'] ?? '' ) );

		// 2. Vérification de l'authentification WordPress de l'utilisateur.
		if ( ! is_user_logged_in() ) {
			$current_url = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// 3. Validation du droit d'accès minimal (edit_posts).
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'Votre compte WordPress n\'a pas les droits suffisants (éditer des articles ou pages) pour autoriser un client Atelier MCP.', 'atelier-mcp' ),
				esc_html__( 'Accès refusé', 'atelier-mcp' ),
				array( 'response' => 403 )
			);
		}

		// 4. Validation du client.
		$client = ClientStore::get_client( $client_id, $redirect_uri );
		if ( ! $client ) {
			wp_die(
				esc_html__( 'Client OAuth inconnu ou non autorisé.', 'atelier-mcp' ),
				esc_html__( 'Erreur client OAuth', 'atelier-mcp' ),
				array( 'response' => 400 )
			);
		}

		// 5. Validation de la redirect_uri.
		if ( ! ClientStore::validate_redirect_uri( $client, $redirect_uri ) ) {
			wp_die(
				esc_html__( 'Adresse de redirection (redirect_uri) non autorisée pour ce client.', 'atelier-mcp' ),
				esc_html__( 'Erreur de redirection', 'atelier-mcp' ),
				array( 'response' => 400 )
			);
		}

		// 6. Validation OAuth 2.1 & PKCE obligatoire.
		if ( 'code' !== $response_type ) {
			self::redirect_error( $redirect_uri, 'unsupported_response_type', 'Seul response_type=code est pris en charge (OAuth 2.1).', $state );
		}

		if ( empty( $code_challenge ) || 'S256' !== $code_challenge_method ) {
			self::redirect_error( $redirect_uri, 'invalid_request', 'PKCE avec code_challenge_method=S256 est obligatoire (OAuth 2.1).', $state );
		}

		// 7. Traitement de la réponse au formulaire (POST).
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'atelier_oauth_consent' );

			$action = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? '' ) );
			if ( 'deny' === $action ) {
				self::redirect_error( $redirect_uri, 'access_denied', 'L\'utilisateur a refusé l\'accès.', $state );
			}

			if ( 'approve' === $action ) {
				$code      = bin2hex( random_bytes( 24 ) );
				$code_hash = hash( 'sha256', $code );

				$requested_scopes = array_filter( explode( ' ', $scope ) );
				$granted_scopes   = array_unique(
					array_merge(
						array( TokenContext::SCOPE_READ, TokenContext::SCOPE_WRITE, TokenContext::SCOPE_MCP_TOOLS ),
						$requested_scopes
					)
				);

				$code_data = array(
					'client_id'      => $client['client_id'] ?? $client_id,
					'user_id'        => get_current_user_id(),
					'redirect_uri'   => $redirect_uri,
					'scopes'         => $granted_scopes,
					'code_challenge' => $code_challenge,
					'created_at'     => time(),
				);

				set_transient( 'atelier_oauth_code_' . $code_hash, $code_data, self::CODE_TTL );

				$callback_url = add_query_arg(
					array_filter(
						array(
							'code'  => $code,
							'state' => $state,
						)
					),
					$redirect_uri
				);

				wp_redirect( $callback_url );
				exit;
			}
		}

		// 8. Affichage de la page de consentement.
		self::render_consent_page( $client, $scope, $redirect_uri, $state, $code_challenge, $code_challenge_method );
		exit;
	}

	private static function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$target = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => $description,
					'state'             => $state,
				)
			),
			$redirect_uri
		);
		wp_redirect( $target );
		exit;
	}

	private static function render_consent_page( array $client, string $scope, string $redirect_uri, string $state, string $code_challenge = '', string $code_challenge_method = '' ): void {
		$user         = wp_get_current_user();
		$client_name  = esc_html( $client['client_name'] ?? 'Assistant IA' );
		$requested_scopes = array_filter( explode( ' ', $scope ) );

		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Autorisation d\'accès — Atelier MCP', 'atelier-mcp' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f0f0f1;
					color: #3c434a;
					display: flex;
					align-items: center;
					justify-content: center;
					min-height: 100vh;
					margin: 0;
					padding: 20px;
					box-sizing: border-box;
				}
				.consent-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					box-shadow: 0 1px 3px rgba(0,0,0,0.04);
					max-width: 480px;
					width: 100%;
					border-radius: 8px;
					padding: 32px;
					box-sizing: border-box;
				}
				.logo-header {
					text-align: center;
					margin-bottom: 24px;
				}
				.logo-header h1 {
					font-size: 20px;
					margin: 8px 0 0;
					color: #1d2327;
				}
				.user-badge {
					background: #f6f7f7;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					padding: 10px 14px;
					margin-bottom: 20px;
					font-size: 13px;
				}
				.scopes-list {
					background: #fdfdfd;
					border: 1px solid #e2e4e7;
					border-radius: 6px;
					padding: 14px;
					margin: 18px 0;
					list-style: none;
				}
				.scopes-list li {
					padding: 6px 0;
					font-size: 14px;
					display: flex;
					align-items: center;
				}
				.scopes-list li::before {
					content: "✓";
					color: #2271b1;
					font-weight: bold;
					margin-right: 10px;
				}
				.actions {
					display: flex;
					gap: 12px;
					margin-top: 24px;
				}
				.btn {
					flex: 1;
					padding: 12px;
					border-radius: 4px;
					font-size: 14px;
					font-weight: 600;
					cursor: pointer;
					text-align: center;
					text-decoration: none;
					border: 1px solid transparent;
				}
				.btn-primary {
					background: #2271b1;
					color: #fff;
				}
				.btn-primary:hover {
					background: #135e96;
				}
				.btn-secondary {
					background: #f6f7f7;
					border-color: #dcdcde;
					color: #3c434a;
				}
				.btn-secondary:hover {
					background: #f0f0f1;
				}
			</style>
		</head>
		<body>
			<div class="consent-card">
				<div class="logo-header">
					<span style="font-size: 36px;">🤖</span>
					<h1><?php esc_html_e( 'Connexion Atelier MCP', 'atelier-mcp' ); ?></h1>
				</div>

				<p>
					<strong><?php echo $client_name; ?></strong> <?php esc_html_e( 'souhaite se connecter à votre site WordPress via le serveur Atelier MCP.', 'atelier-mcp' ); ?>
				</p>

				<div class="user-badge">
					<?php esc_html_e( 'Connecté en tant que :', 'atelier-mcp' ); ?>
					<strong><?php echo esc_html( $user->display_name ); ?></strong> (<?php echo esc_html( $user->user_email ); ?>)
				</div>

				<p style="font-size: 13px; color: #646970;">
					<?php esc_html_e( 'Cette application pourra exécuter les actions suivantes selon vos droits :', 'atelier-mcp' ); ?>
				</p>

				<ul class="scopes-list">
					<li><?php esc_html_e( 'Exécuter les outils MCP Atelier (audit, pages, widgets Elementor, Gutenberg)', 'atelier-mcp' ); ?></li>
					<?php if ( empty( $requested_scopes ) || in_array( TokenContext::SCOPE_READ, $requested_scopes, true ) || in_array( 'read', $requested_scopes, true ) || in_array( TokenContext::SCOPE_ADMIN, $requested_scopes, true ) ) : ?>
						<li><?php esc_html_e( 'Lire et auditer les pages et documents WordPress / Elementor', 'atelier-mcp' ); ?></li>
					<?php endif; ?>
					<?php if ( empty( $requested_scopes ) || in_array( TokenContext::SCOPE_WRITE, $requested_scopes, true ) || in_array( 'write', $requested_scopes, true ) || in_array( TokenContext::SCOPE_ADMIN, $requested_scopes, true ) ) : ?>
						<li><?php esc_html_e( 'Créer et modifier des pages et templates', 'atelier-mcp' ); ?></li>
					<?php endif; ?>
					<?php if ( in_array( TokenContext::SCOPE_ADMIN, $requested_scopes, true ) || in_array( 'admin', $requested_scopes, true ) ) : ?>
						<li><?php esc_html_e( 'Consulter les réglages avancés et informations système', 'atelier-mcp' ); ?></li>
					<?php endif; ?>
				</ul>

				<form method="post">
					<?php wp_nonce_field( 'atelier_oauth_consent' ); ?>
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ?? '' ); ?>" />
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>" />
					<input type="hidden" name="response_type" value="code" />
					<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>" />
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>" />
					<div class="actions">
						<button type="submit" name="action_type" value="deny" class="btn btn-secondary">
							<?php esc_html_e( 'Refuser', 'atelier-mcp' ); ?>
						</button>
						<button type="submit" name="action_type" value="approve" class="btn btn-primary">
							<?php esc_html_e( 'Autoriser l\'accès', 'atelier-mcp' ); ?>
						</button>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php
	}
}
