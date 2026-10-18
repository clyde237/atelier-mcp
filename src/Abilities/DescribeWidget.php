<?php
/**
 * atelier/describe-widget : noms exacts des réglages d'un widget ou d'un conteneur, pour cette installation.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\Elementor\Environment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class DescribeWidget extends Ability {

	/** Contrôles d'interface qui ne portent aucune valeur. */
	private const UI_ONLY_TYPES = array( 'section', 'tabs', 'tab', 'heading', 'divider', 'raw_html', 'alert', 'notice', 'deprecated_notice', 'button', 'hidden' );

	private const ELEMENT_TYPES = array( 'container', 'section', 'column' );

	private const MAX_OPTIONS = 25;

	public function slug(): string {
		return 'describe-widget';
	}

	public function label(): string {
		return __( 'Décrire un widget Elementor', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Sans "type" : liste les widgets disponibles sur ce site (nom technique, titre, catégories). '
			. 'Avec "type" (nom de widget comme "heading", "button", "image", ou "container") : liste ses réglages avec leur nom exact, '
			. 'type de contrôle, valeur par défaut, options et appareils responsive (le réglage tablette s\'écrit nom_tablet, mobile nom_mobile). '
			. 'Filtrer avec "search" (sous-chaîne du nom ou du libellé) ou "tab" (content, style, advanced, layout) pour réduire la réponse.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'   => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]{1,80}$',
				),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 60,
				),
				'tab'    => array(
					'type' => 'string',
					'enum' => array( 'content', 'style', 'advanced', 'layout' ),
				),
			),
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

		if ( empty( $input['type'] ) ) {
			return array( 'widgets' => $this->list_widgets() );
		}

		$type     = (string) $input['type'];
		$controls = in_array( $type, self::ELEMENT_TYPES, true )
			? Environment::controls_for( $type )
			: Environment::controls_for( 'widget', $type );

		if ( null === $controls ) {
			return new WP_Error( 'atelier_unknown_widget', 'Widget inconnu sur ce site. Appeler describe-widget sans "type" pour la liste.', array( 'status' => 404 ) );
		}

		$described = $this->describe_controls( $controls, $input['search'] ?? '', $input['tab'] ?? '' );
		return array(
			'type'     => $type,
			'count'    => count( $described ),
			'format'   => 'nom | type | défaut | options | appareils',
			'controls' => $described,
		);
	}

	/** @return array<int, array{name: string, title: string, categories: string[]}> */
	private function list_widgets(): array {
		$widgets = array();
		foreach ( Environment::plugin()->widgets_manager->get_widget_types() as $name => $widget ) {
			$widgets[] = array(
				'name'       => (string) $name,
				'title'      => (string) $widget->get_title(),
				'categories' => array_values( (array) $widget->get_categories() ),
			);
		}
		return $widgets;
	}

	/**
	 * Une ligne compacte par réglage ; les variantes responsive (nom_tablet…) sont regroupées sur le réglage de base.
	 *
	 * @param array<string, array<string, mixed>> $controls
	 * @return string[]
	 */
	private function describe_controls( array $controls, string $search, string $tab ): array {
		$devices = array_keys( Environment::breakpoints() );
		$lines   = array();

		foreach ( $controls as $name => $control ) {
			$type = (string) ( $control['type'] ?? '' );
			if ( '' === $type || in_array( $type, self::UI_ONLY_TYPES, true ) ) {
				continue;
			}
			if ( $this->is_device_variant( (string) $name, $devices, $controls ) ) {
				continue;
			}
			if ( '' !== $tab && ( $control['tab'] ?? '' ) !== $tab ) {
				continue;
			}
			$label = isset( $control['label'] ) ? wp_strip_all_tags( (string) $control['label'] ) : '';
			if ( '' !== $search && false === stripos( $name . ' ' . $label, $search ) ) {
				continue;
			}

			$lines[] = implode(
				' | ',
				array(
					$name,
					$type,
					$this->format_default( $control['default'] ?? null ),
					$this->format_options( $control['options'] ?? null ),
					implode( ',', $this->devices_of( (string) $name, $devices, $controls ) ),
				)
			);
		}
		return $lines;
	}

	private function is_device_variant( string $name, array $devices, array $controls ): bool {
		foreach ( $devices as $device ) {
			$suffix = '_' . $device;
			if ( substr( $name, -strlen( $suffix ) ) === $suffix && isset( $controls[ substr( $name, 0, -strlen( $suffix ) ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private function devices_of( string $name, array $devices, array $controls ): array {
		// Elementor 3.4+ : un seul contrôle « is_responsive », les variantes par appareil sont créées par l'éditeur.
		if ( ! empty( $controls[ $name ]['is_responsive'] ) ) {
			$restricted = $controls[ $name ]['responsive']['devices'] ?? null;
			return is_array( $restricted ) ? array_values( array_intersect( $devices, $restricted ) ) : $devices;
		}

		// Mode dupliqué : un contrôle par appareil (nom_tablet, nom_mobile…).
		$found = array();
		foreach ( $devices as $device ) {
			if ( isset( $controls[ $name . '_' . $device ] ) ) {
				$found[] = $device;
			}
		}
		return $found;
	}

	private function format_default( $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		$json = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		return strlen( $json ) > 80 ? substr( $json, 0, 77 ) . '...' : $json;
	}

	private function format_options( $options ): string {
		if ( ! is_array( $options ) || array() === $options ) {
			return '';
		}
		$keys = array_map( 'strval', array_keys( $options ) );
		$more = count( $keys ) > self::MAX_OPTIONS ? ',…' : '';
		return implode( ',', array_slice( $keys, 0, self::MAX_OPTIONS ) ) . $more;
	}
}
