<?php
/**
 * atelier/get-kit : couleurs, polices et réglages de mise en page globaux Elementor.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Elementor\Environment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GetKit extends Ability {

	/** Réglages du kit utiles pour construire une page cohérente. */
	private const LAYOUT_KEYS = array( 'container_width', 'container_padding', 'space_between_widgets', 'page_title_selector', 'default_generic_fonts' );

	private const TYPOGRAPHY_KEYS = array( 'typography_font_family', 'typography_font_size', 'typography_font_weight', 'typography_line_height', 'typography_letter_spacing', 'typography_text_transform' );

	public function slug(): string {
		return 'get-kit';
	}

	public function label(): string {
		return __( 'Styles globaux Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Renvoie les couleurs et typographies globales du site (kit Elementor) avec leur _id, et la largeur de conteneur par défaut. '
			. 'Pour utiliser une couleur globale dans un widget, mettre dans ses réglages "__globals__": {"title_color": "globals/colors?id=<_id>"} ; '
			. 'pour une typographie : "__globals__": {"typography_typography": "globals/typography?id=<_id>"}.';
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
		if ( ! Environment::is_active() ) {
			return new WP_Error( 'atelier_elementor_inactive', 'Elementor n\'est pas actif sur ce site.', array( 'status' => 409 ) );
		}

		$kit_id   = (int) Environment::plugin()->kits_manager->get_active_id();
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : array();

		$layout = array();
		foreach ( self::LAYOUT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$layout[ $key ] = $settings[ $key ];
			}
		}

		return array(
			'kit_id'      => $kit_id,
			'colors'      => array_merge( $this->colors( $settings['system_colors'] ?? array() ), $this->colors( $settings['custom_colors'] ?? array() ) ),
			'typography'  => array_merge( $this->typography( $settings['system_typography'] ?? array() ), $this->typography( $settings['custom_typography'] ?? array() ) ),
			'layout'      => $layout,
			'breakpoints' => Environment::breakpoints(),
		);
	}

	/** @return array<int, array{_id: string, title: string, color: string}> */
	private function colors( $items ): array {
		$colors = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) && isset( $item['_id'] ) ) {
				$colors[] = array(
					'_id'   => (string) $item['_id'],
					'title' => (string) ( $item['title'] ?? '' ),
					'color' => (string) ( $item['color'] ?? '' ),
				);
			}
		}
		return $colors;
	}

	/** @return array<int, array<string, mixed>> */
	private function typography( $items ): array {
		$fonts = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['_id'] ) ) {
				continue;
			}
			$font = array(
				'_id'   => (string) $item['_id'],
				'title' => (string) ( $item['title'] ?? '' ),
			);
			foreach ( self::TYPOGRAPHY_KEYS as $key ) {
				if ( isset( $item[ $key ] ) && '' !== $item[ $key ] ) {
					$font[ $key ] = $item[ $key ];
				}
			}
			$fonts[] = $font;
		}
		return $fonts;
	}
}
