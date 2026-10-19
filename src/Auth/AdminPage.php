<?php
/**
 * Page d'administration WordPress pour la configuration et la gestion des connexions Atelier MCP.
 *
 * @package Atelier
 */

namespace Atelier\Auth;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const MENU_SLUG = 'atelier-mcp-connect';

	public static function hook(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_actions' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Atelier MCP', 'atelier-mcp' ),
			__( 'Atelier MCP', 'atelier-mcp' ),
			'edit_posts',
			self::MENU_SLUG,
			array( self::class, 'render' ),
			'dashicons-superhero-alt',
			80
		);
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['atelier_action'] ?? '' ) );
		if ( empty( $action ) ) {
			return;
		}

		// Création d'un jeton personnel.
		if ( 'create_pat' === $action ) {
			check_admin_referer( 'atelier_create_pat' );

			$name     = sanitize_text_field( wp_unslash( $_POST['pat_name'] ?? '' ) );
			$scope    = sanitize_text_field( wp_unslash( $_POST['pat_scope'] ?? TokenContext::SCOPE_READ ) );
			$validity = sanitize_text_field( wp_unslash( $_POST['pat_validity'] ?? '30' ) );

			if ( empty( $name ) ) {
				$name = sprintf( 'Jeton %s', wp_date( 'Y-m-d H:i' ) );
			}

			$ttl = null;
			if ( '30' === $validity ) {
				$ttl = 30 * DAY_IN_SECONDS;
			} elseif ( '90' === $validity ) {
				$ttl = 90 * DAY_IN_SECONDS;
			} elseif ( '365' === $validity ) {
				$ttl = 365 * DAY_IN_SECONDS;
			}

			$scopes = array( TokenContext::SCOPE_READ );
			if ( 'write' === $scope || 'admin' === $scope ) {
				$scopes[] = TokenContext::SCOPE_WRITE;
			}
			if ( 'admin' === $scope && current_user_can( 'manage_options' ) ) {
				$scopes[] = TokenContext::SCOPE_ADMIN;
			}

			$result = TokenStore::create_personal_token( get_current_user_id(), $name, $scopes, $ttl );

			set_transient( 'atelier_newly_created_pat_' . get_current_user_id(), $result['token'], 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'pat_created' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Révocation d'un jeton.
		if ( 'revoke_token' === $action ) {
			check_admin_referer( 'atelier_revoke_token' );

			$token_id = sanitize_text_field( wp_unslash( $_POST['token_id'] ?? '' ) );
			if ( ! empty( $token_id ) ) {
				TokenStore::revoke_by_id( $token_id );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'revoked' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	public static function render(): void {
		$user_id   = get_current_user_id();
		$new_token = get_transient( 'atelier_newly_created_pat_' . $user_id );
		if ( $new_token ) {
			delete_transient( 'atelier_newly_created_pat_' . $user_id );
		}

		$all_tokens = TokenStore::list_tokens( current_user_can( 'manage_options' ) ? null : $user_id );
		$endpoint   = rest_url( 'atelier/mcp' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atelier MCP — Connexions & Authentification', 'atelier-mcp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connectez Claude (claude.ai, Claude Code), ChatGPT et Antigravity à votre site WordPress.', 'atelier-mcp' ); ?>
			</p>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-success is-dismissible" style="padding: 16px; border-left-color: #00a32a;">
					<h3 style="margin-top: 0;"><?php esc_html_e( '🎉 Votre jeton personnel a été généré !', 'atelier-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Copiez ce jeton maintenant. Pour votre sécurité, il ne sera plus jamais affiché.', 'atelier-mcp' ); ?></p>
					<p>
						<input type="text" readonly value="<?php echo esc_attr( $new_token ); ?>" style="width: 100%; max-width: 600px; font-family: monospace; font-size: 14px; padding: 8px;" onclick="this.select(); document.execCommand('copy'); alert('Jeton copié !');">
					</p>
				</div>
			<?php endif; ?>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 20px;">
				<!-- Bloc Configuration -->
				<div class="postbox" style="padding: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( '📋 Guides de connexion', 'atelier-mcp' ); ?></h2>
					<p><strong><?php esc_html_e( 'Point d\'accès MCP :', 'atelier-mcp' ); ?></strong></p>
					<code><?php echo esc_html( $endpoint ); ?></code>

					<hr style="margin: 20px 0; border: 0; border-top: 1px solid #dcdcde;">

					<h3><?php esc_html_e( '1. Claude Code / CLI (Jeton Bearer)', 'atelier-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Générez un jeton personnel ci-contre et exécutez dans votre terminal :', 'atelier-mcp' ); ?></p>
					<pre style="background: #f6f7f7; padding: 10px; border-radius: 4px; overflow-x: auto;"><code>claude mcp add --transport http atelier <?php echo esc_html( $endpoint ); ?> --header "Authorization: Bearer &lt;votre_jeton&gt;"</code></pre>

					<h3><?php esc_html_e( '2. Claude.ai Web & ChatGPT (OAuth 2.1)', 'atelier-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Renseignez simplement l\'adresse du point d\'accès MCP dans les réglages du connecteur. L\'authentification OAuth 2.1 (PKCE) et l\'écran de consentement se lanceront automatiquement.', 'atelier-mcp' ); ?></p>

					<h3><?php esc_html_e( '3. Google Antigravity', 'atelier-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Ajoutez le serveur dans vos réglages MCP avec l\'URL ci-dessus.', 'atelier-mcp' ); ?></p>
				</div>

				<!-- Bloc Création PAT -->
				<div class="postbox" style="padding: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( '🔑 Générer un jeton personnel d\'accès (PAT)', 'atelier-mcp' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Utile pour Claude Code, Codex en ligne de commande ou vos scripts d\'automatisation.', 'atelier-mcp' ); ?></p>

					<form method="post" action="">
						<?php wp_nonce_field( 'atelier_create_pat' ); ?>
						<input type="hidden" name="atelier_action" value="create_pat">

						<table class="form-table" style="margin-top: 0;">
							<tr>
								<th scope="row"><label for="pat_name"><?php esc_html_e( 'Nom du jeton', 'atelier-mcp' ); ?></label></th>
								<td><input type="text" id="pat_name" name="pat_name" class="regular-text" placeholder="ex. Claude Code sur MacBook" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="pat_scope"><?php esc_html_e( 'Permissions', 'atelier-mcp' ); ?></label></th>
								<td>
									<select id="pat_scope" name="pat_scope">
										<option value="read"><?php esc_html_e( 'Lecture seule (audit & inspection)', 'atelier-mcp' ); ?></option>
										<option value="write" selected><?php esc_html_e( 'Lecture & Écriture (création & modification)', 'atelier-mcp' ); ?></option>
										<?php if ( current_user_can( 'manage_options' ) ) : ?>
											<option value="admin"><?php esc_html_e( 'Administrateur complet', 'atelier-mcp' ); ?></option>
										<?php endif; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="pat_validity"><?php esc_html_e( 'Validité', 'atelier-mcp' ); ?></label></th>
								<td>
									<select id="pat_validity" name="pat_validity">
										<option value="30"><?php esc_html_e( '30 jours', 'atelier-mcp' ); ?></option>
										<option value="90" selected><?php esc_html_e( '90 jours', 'atelier-mcp' ); ?></option>
										<option value="365"><?php esc_html_e( '1 an', 'atelier-mcp' ); ?></option>
										<option value="never"><?php esc_html_e( 'Sans expiration', 'atelier-mcp' ); ?></option>
									</select>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Générer le jeton', 'atelier-mcp' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- Liste des jetons actifs -->
			<div class="postbox" style="margin-top: 24px; padding: 20px;">
				<h2 style="margin-top: 0;"><?php esc_html_e( '🛡️ Jetons actifs & Applications connectées', 'atelier-mcp' ); ?></h2>

				<?php if ( empty( $all_tokens ) ) : ?>
					<p><?php esc_html_e( 'Aucun jeton actif pour le moment.', 'atelier-mcp' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Application / Nom', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Type', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Permissions', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Création', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Expiration', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Dernier usage', 'atelier-mcp' ); ?></th>
								<th><?php esc_html_e( 'Action', 'atelier-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $all_tokens as $token ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $token['name'] ?? $token['client_id'] ?? 'OAuth Client' ); ?></strong>
										<?php if ( ! empty( $token['preview'] ) ) : ?>
											<br><code style="font-size: 11px;"><?php echo esc_html( $token['preview'] ); ?></code>
										<?php endif; ?>
									</td>
									<td>
										<?php echo 'personal' === ( $token['type'] ?? '' ) ? esc_html__( 'Jeton Personnel (PAT)', 'atelier-mcp' ) : esc_html__( 'Session OAuth 2.1', 'atelier-mcp' ); ?>
									</td>
									<td>
										<?php echo esc_html( implode( ', ', (array) ( $token['scopes'] ?? array() ) ) ); ?>
									</td>
									<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $token['created_at'] ) ); ?></td>
									<td>
										<?php echo empty( $token['expires_at'] ) ? esc_html__( 'Jamais', 'atelier-mcp' ) : esc_html( wp_date( 'Y-m-d H:i', (int) $token['expires_at'] ) ); ?>
									</td>
									<td>
										<?php echo empty( $token['last_used_at'] ) ? esc_html__( 'Jamais', 'atelier-mcp' ) : esc_html( wp_date( 'Y-m-d H:i', (int) $token['last_used_at'] ) ); ?>
									</td>
									<td>
										<form method="post" action="" onsubmit="return confirm('Êtes-vous sûr de vouloir révoquer ce jeton ?');">
											<?php wp_nonce_field( 'atelier_revoke_token' ); ?>
											<input type="hidden" name="atelier_action" value="revoke_token">
											<input type="hidden" name="token_id" value="<?php echo esc_attr( $token['id'] ?? '' ); ?>">
											<button type="submit" class="button button-link-delete" style="color: #b32d2e;"><?php esc_html_e( 'Révoquer', 'atelier-mcp' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Section Mises à jour depuis GitHub -->
			<div class="card" style="margin-top: 24px; max-width: 1000px; padding: 20px;">
				<h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Mises à jour du plugin depuis GitHub', 'atelier-mcp' ); ?>
				</h2>
				<p>
					<?php
					printf(
						/* translators: %s: version string */
						esc_html__( 'Version actuellement installée : %s', 'atelier-mcp' ),
						'<strong>' . esc_html( ATELIER_MCP_VERSION ) . '</strong>'
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Pour mettre à jour Atelier MCP lors de nouvelles modifications sans le désinstaller :', 'atelier-mcp' ); ?>
				</p>
				<ol style="margin-left: 20px; line-height: 1.8;">
					<li>
						<?php esc_html_e( 'Téléchargez la dernière archive zip depuis le dépôt :', 'atelier-mcp' ); ?>
						<a href="https://github.com/clyde237/atelier-mcp/archive/refs/heads/main.zip" class="button button-secondary" style="margin-left: 8px;" target="_blank" rel="noopener noreferrer">
							📥 <?php esc_html_e( 'Télécharger atelier-mcp (.zip)', 'atelier-mcp' ); ?>
						</a>
					</li>
					<li>
						<?php esc_html_e( 'Rendez-vous dans', 'atelier-mcp' ); ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=upload' ) ); ?>">
							<strong><?php esc_html_e( 'Extensions › Téléverser une extension', 'atelier-mcp' ); ?></strong>
						</a>
						<?php esc_html_e( 'et sélectionnez l\'archive.', 'atelier-mcp' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Cliquez sur « Remplacer la version actuelle par la version téléversée ». Vos jetons, permissions et réglages sont intégralement conservés !', 'atelier-mcp' ); ?>
					</li>
				</ol>
				<div style="margin-top: 16px; display: flex; gap: 12px; align-items: center;">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?atelier_check_update=1' ), 'atelier_check_update' ) ); ?>" class="button button-primary">
						🔄 <?php esc_html_e( 'Vérifier les mises à jour maintenant', 'atelier-mcp' ); ?>
					</a>
					<a href="https://github.com/clyde237/atelier-mcp" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Voir le dépôt GitHub', 'atelier-mcp' ); ?>
					</a>
					<a href="https://github.com/clyde237/atelier-mcp/releases" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Releases & Changelog', 'atelier-mcp' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
