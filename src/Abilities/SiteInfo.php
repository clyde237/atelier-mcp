<?php
/**
 * atelier/site-info : état du site utile avant toute construction.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Elementor\Environment;

defined( 'ABSPATH' ) || exit;

final class SiteInfo extends Ability {

	public function slug(): string {
		return 'site-info';
	}

	public function label(): string {
		return __( 'Informations du site', 'atelier-mcp' );
	}

	public function description(): string {
		return 'À appeler en premier. Renvoie le nom et l\'URL du site, la version de WordPress, le thème actif, '
			. 'la page d\'accueil, et pour Elementor : versions (gratuit/Pro), expériences actives (conteneurs, éléments atomiques), '
			. 'points de rupture responsive. Les détails serveur (PHP, plugins actifs) ne sont renvoyés qu\'aux administrateurs.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		return current_user_can( 'edit_posts' );
	}

	public function execute( array $input ) {
		$theme = wp_get_theme();
		$info  = array(
			'name'              => get_bloginfo( 'name' ),
			'url'               => home_url( '/' ),
			'language'          => get_locale(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'atelier_version'   => ATELIER_MCP_VERSION,
			'theme'             => array(
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'parent'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
				'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
			),
			'front_page'        => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : null,
			'elementor'         => $this->elementor_info(),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$info['server'] = array(
				'php_version'    => PHP_VERSION,
				'active_plugins' => $this->active_plugins(),
			);
		}

		return $info;
	}

	/** @return array<string, mixed> */
	private function elementor_info(): array {
		if ( ! Environment::is_active() ) {
			return array( 'active' => false );
		}
		return array(
			'active'      => true,
			'version'     => Environment::version(),
			'pro_version' => Environment::pro_version(),
			'experiments' => Environment::experiments(),
			'breakpoints' => Environment::breakpoints(),
		);
	}

	/** @return string[] Noms et versions des plugins actifs. */
	private function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$result = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$result[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		return $result;
	}
}
