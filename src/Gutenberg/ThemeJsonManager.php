<?php
/**
 * Gestionnaire du système de design theme.json et des modèles de thèmes à blocs.
 *
 * @package Atelier
 */

namespace Atelier\Gutenberg;

use WP_Block_Template;

defined( 'ABSPATH' ) || exit;

final class ThemeJsonManager {

	/**
	 * Récupère les réglages globaux du thème (couleurs, typographie, espacements, mise en page).
	 *
	 * @param string $section Section demandée ('all', 'colors', 'typography', 'spacing', 'layout').
	 * @return array<string, mixed>
	 */
	public static function get_settings( string $section = 'all' ): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$all = wp_get_global_settings();
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		if ( 'colors' === $section ) {
			$palette = $all['color']['palette']['theme'] ?? $all['color']['palette']['default'] ?? array();
			return array(
				'palette'   => $palette,
				'gradients' => $all['color']['gradients']['theme'] ?? $all['color']['gradients']['default'] ?? array(),
				'custom'    => $all['color']['custom'] ?? true,
			);
		}

		if ( 'typography' === $section ) {
			return array(
				'font_families' => $all['typography']['fontFamilies']['theme'] ?? $all['typography']['fontFamilies']['default'] ?? array(),
				'font_sizes'    => $all['typography']['fontSizes']['theme'] ?? $all['typography']['fontSizes']['default'] ?? array(),
				'fluid'         => $all['typography']['fluid'] ?? false,
			);
		}

		if ( 'layout' === $section ) {
			return array(
				'content_size' => $all['layout']['contentSize'] ?? null,
				'wide_size'    => $all['layout']['wideSize'] ?? null,
			);
		}

		if ( 'spacing' === $section ) {
			return array(
				'spacing_scale' => $all['spacing']['spacingScale'] ?? null,
				'spacing_sizes' => $all['spacing']['spacingSizes']['theme'] ?? $all['spacing']['spacingSizes']['default'] ?? array(),
				'units'         => $all['spacing']['units'] ?? array( 'px', 'em', 'rem', 'vh', 'vw', '%' ),
			);
		}

		return array(
			'colors'     => self::get_settings( 'colors' ),
			'typography' => self::get_settings( 'typography' ),
			'layout'     => self::get_settings( 'layout' ),
			'spacing'    => self::get_settings( 'spacing' ),
		);
	}

	/**
	 * Liste les modèles et parties de modèles de thèmes à blocs.
	 *
	 * @param string $template_type 'wp_template' ou 'wp_template_part'.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_templates( string $template_type = 'wp_template' ): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$templates = get_block_templates( array(), $template_type );
		$result    = array();

		foreach ( $templates as $tpl ) {
			if ( ! $tpl instanceof WP_Block_Template ) {
				continue;
			}

			$result[] = array(
				'id'          => $tpl->id,
				'theme'       => $tpl->theme,
				'slug'        => $tpl->slug,
				'title'       => $tpl->title ?: $tpl->slug,
				'description' => $tpl->description ?: '',
				'type'        => $tpl->type,
				'status'      => $tpl->status,
				'source'      => $tpl->source,
				'is_custom'   => $tpl->is_custom,
			);
		}

		return $result;
	}
}
