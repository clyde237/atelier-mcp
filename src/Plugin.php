<?php
/**
 * Point d'entrée : branche les capacités et le serveur MCP sur les hooks WordPress.
 *
 * @package Atelier
 */

namespace Atelier;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Auth\BearerAuth::hook();
		Auth\Server::hook();
		Auth\AdminPage::hook();

		add_action( 'plugins_loaded', array( self::class, 'on_plugins_loaded' ) );
	}

	public static function on_plugins_loaded(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_missing_abilities_api' ) );
			return;
		}

		Abilities\Registry::hook();

		if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_missing_mcp_adapter' ) );
			return;
		}

		Server::hook();
	}

	public static function notice_missing_abilities_api(): void {
		self::admin_notice( __( 'Atelier MCP nécessite WordPress 6.9 ou plus récent (Abilities API).', 'atelier-mcp' ) );
	}

	public static function notice_missing_mcp_adapter(): void {
		self::admin_notice( __( 'Atelier MCP nécessite le plugin « MCP Adapter » (github.com/WordPress/mcp-adapter). Installez-le et activez-le.', 'atelier-mcp' ) );
	}

	private static function admin_notice( string $message ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
