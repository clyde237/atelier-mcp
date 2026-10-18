<?php
/**
 * Accès en lecture à l'installation Elementor : présence, versions, expériences, points de rupture.
 *
 * Toutes les classes Atelier passent par ici pour toucher à Elementor, afin
 * qu'un changement d'API Elementor se corrige à un seul endroit.
 *
 * @package Atelier
 */

namespace Atelier\Elementor;

defined( 'ABSPATH' ) || exit;

final class Environment {

	/** Expériences utiles pour savoir quel type de structure produire. */
	private const TRACKED_EXPERIMENTS = array(
		'container',
		'nested-elements',
		'e_atomic_elements',
		'e_optimized_markup',
		'e_font_icon_svg',
	);

	public static function is_active(): bool {
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance );
	}

	public static function plugin(): \Elementor\Plugin {
		return \Elementor\Plugin::$instance;
	}

	public static function version(): ?string {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	}

	public static function pro_version(): ?string {
		return defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null;
	}

	/** @return array<string, bool> */
	public static function experiments(): array {
		$states      = array();
		$experiments = self::plugin()->experiments;
		foreach ( self::TRACKED_EXPERIMENTS as $feature ) {
			if ( null !== $experiments->get_features( $feature ) ) {
				$states[ $feature ] = (bool) $experiments->is_feature_active( $feature );
			}
		}
		return $states;
	}

	/**
	 * Points de rupture actifs, du plus étroit au plus large, sans « desktop ».
	 *
	 * @return array<string, array{max: int, direction: string}>
	 */
	public static function breakpoints(): array {
		$result = array();
		foreach ( self::plugin()->breakpoints->get_active_breakpoints() as $name => $breakpoint ) {
			$result[ $name ] = array(
				'max'       => (int) $breakpoint->get_value(),
				'direction' => (string) $breakpoint->get_direction(),
			);
		}
		uasort(
			$result,
			static function ( array $a, array $b ): int {
				return $a['max'] <=> $b['max'];
			}
		);
		return $result;
	}

	/**
	 * Ordre d'héritage responsive vu depuis un appareil : mobile hérite de tablette, puis du bureau.
	 * Le bureau est représenté par la chaîne vide (pas de suffixe de réglage).
	 *
	 * @return string[]
	 */
	public static function inheritance_chain( string $device ): array {
		if ( '' === $device ) {
			return array( '' );
		}

		$chain   = array();
		$started = false;
		foreach ( self::breakpoints() as $name => $breakpoint ) {
			if ( $name === $device ) {
				$started = true;
			}
			// Les points « min » (grand écran) n'héritent pas vers le bas : hors de la chaîne.
			if ( $started && 'max' === $breakpoint['direction'] ) {
				$chain[] = $name;
			}
		}
		$chain[] = '';
		return $chain;
	}

	/** Contrôles d'un type d'élément (conteneur, section, colonne) ou de widget ; null si inconnu. */
	public static function controls_for( string $el_type, ?string $widget_type = null ): ?array {
		static $cache = array();

		$key = 'widget' === $el_type ? 'widget:' . $widget_type : $el_type;
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}

		$stack = 'widget' === $el_type
			? self::plugin()->widgets_manager->get_widget_types( (string) $widget_type )
			: self::plugin()->elements_manager->get_element_types( $el_type );

		$cache[ $key ] = $stack ? $stack->get_controls() : null;
		return $cache[ $key ];
	}
}
