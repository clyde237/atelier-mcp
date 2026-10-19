<?php
/**
 * Mécanisme de mise à jour automatique depuis les Releases GitHub.
 *
 * Permet au site client de recevoir les mises à jour sans passer par wordpress.org.
 *
 * @package Atelier
 */

namespace Atelier;

use stdClass;

defined( 'ABSPATH' ) || exit;

final class Updater {

	private const GITHUB_REPO   = 'clyde237/atelier-mcp';
	private const TRANSIENT_KEY = 'atelier_mcp_github_release';
	private const CACHE_TTL     = 43200; // 12 heures

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'check_update' ) );
		add_filter( 'plugins_api', array( self::class, 'plugins_api' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( self::class, 'post_install' ), 10, 3 );
	}

	/**
	 * Intercepte la vérification des mises à jour WordPress.
	 *
	 * @param stdClass|mixed $transient
	 * @return stdClass|mixed
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$plugin_basename = plugin_basename( ATELIER_MCP_FILE );
		$release         = self::get_latest_release();

		if ( ! $release || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );
		$local_version  = ATELIER_MCP_VERSION;

		if ( version_compare( $remote_version, $local_version, '>' ) ) {
			$download_url = self::find_download_url( $release );

			$item = (object) array(
				'id'            => 'atelier-mcp',
				'slug'          => 'atelier-mcp',
				'plugin'        => $plugin_basename,
				'new_version'   => $remote_version,
				'url'           => $release['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
				'package'       => $download_url,
				'tested'        => '7.1',
				'requires'      => '6.9',
				'requires_php'  => '7.4',
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
			);

			$transient->response[ $plugin_basename ] = $item;
		} else {
			$transient->no_update[ $plugin_basename ] = (object) array(
				'id'          => 'atelier-mcp',
				'slug'        => 'atelier-mcp',
				'plugin'      => $plugin_basename,
				'new_version' => $local_version,
				'url'         => 'https://github.com/' . self::GITHUB_REPO,
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Fournit les détails du plugin dans la modale WordPress "Afficher les détails de la version".
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function plugins_api( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'atelier-mcp' !== $args->slug ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( (string) ( $release['tag_name'] ?? ATELIER_MCP_VERSION ), 'v' );
		$changelog      = (string) ( $release['body'] ?? 'Mise à jour d\'Atelier MCP.' );

		return (object) array(
			'name'          => 'Atelier MCP',
			'slug'          => 'atelier-mcp',
			'version'       => $remote_version,
			'author'        => '<a href="https://github.com/clyde237">clyde237</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'requires'      => '6.9',
			'tested'        => '7.1',
			'requires_php'  => '7.4',
			'download_link' => self::find_download_url( $release ),
			'sections'      => array(
				'description' => 'Plugin WordPress serveur MCP pour construire et auditer des pages Elementor et Gutenberg.',
				'changelog'   => wpautop( $changelog ),
			),
		);
	}

	/**
	 * Assure que le dossier décompressé est bien nommé "atelier-mcp".
	 *
	 * @param array|WP_Error $response
	 * @param array          $hook_extra
	 * @param array          $result
	 * @return array|WP_Error
	 */
	public static function post_install( $response, array $hook_extra, array $result ) {
		$plugin_basename = plugin_basename( ATELIER_MCP_FILE );
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $plugin_basename ) {
			return $response;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$correct_destination = WP_PLUGIN_DIR . '/atelier-mcp';
		$current_destination = untrailingslashit( (string) ( $result['destination'] ?? '' ) );

		if ( $current_destination && $current_destination !== $correct_destination ) {
			$wp_filesystem->move( $current_destination, $correct_destination );
			$result['destination'] = $correct_destination;
		}

		return $response;
	}

	/**
	 * Récupère les métadonnées de la dernière release GitHub avec cache transient.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url     = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/Atelier-MCP-' . ATELIER_MCP_VERSION,
		);

		if ( defined( 'ATELIER_GITHUB_TOKEN' ) && ! empty( ATELIER_GITHUB_TOKEN ) ) {
			$headers['Authorization'] = 'Bearer ' . ATELIER_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		set_site_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Détermine l'URL de téléchargement (asset zip ou archive zipball).
	 *
	 * @param array<string, mixed> $release
	 * @return string
	 */
	private static function find_download_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = (string) ( $asset['name'] ?? '' );
				if ( substr( $name, -4 ) === '.zip' && ! empty( $asset['browser_download_url'] ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return (string) ( $release['zipball_url'] ?? '' );
	}
}
