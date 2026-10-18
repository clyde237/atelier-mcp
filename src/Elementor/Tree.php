<?php
/**
 * Lecture d'un arbre Elementor (tableau d'éléments) : parcours, plan lisible, statistiques,
 * valeurs effectives par appareil.
 *
 * @package Atelier
 */

namespace Atelier\Elementor;

defined( 'ABSPATH' ) || exit;

final class Tree {

	private const EXCERPT_LENGTH = 60;

	/** Réglages qui portent le texte visible d'un widget, par ordre de préférence. */
	private const TEXT_SETTINGS = array( 'title', 'text', 'editor', 'description_text', 'title_text', 'html', 'address' );

	/**
	 * Parcourt l'arbre en profondeur ; le visiteur reçoit l'élément, sa profondeur et son parent.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @param callable                         $visitor function( array $element, int $depth, ?array $parent ): void
	 */
	public static function walk( array $elements, callable $visitor, int $depth = 0, ?array $parent = null ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$visitor( $element, $depth, $parent );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $visitor, $depth + 1, $element );
			}
		}
	}

	/** Type lisible : nom du widget pour un widget, sinon type d'élément (container, section…). */
	public static function type_of( array $element ): string {
		$el_type = (string) ( $element['elType'] ?? '' );
		return 'widget' === $el_type ? (string) ( $element['widgetType'] ?? 'widget' ) : $el_type;
	}

	/** @return array<string, mixed> */
	public static function settings_of( array $element ): array {
		return isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
	}

	/**
	 * Plan indenté, une ligne par élément : type#id [disposition] « texte ».
	 *
	 * @param array<int, array<string, mixed>> $elements
	 */
	public static function outline( array $elements ): string {
		$lines = array();
		self::walk(
			$elements,
			static function ( array $element, int $depth ) use ( &$lines ): void {
				$line = str_repeat( '  ', $depth ) . self::type_of( $element ) . '#' . ( $element['id'] ?? '?' );

				$layout = self::layout_summary( $element );
				if ( '' !== $layout ) {
					$line .= ' [' . $layout . ']';
				}

				$text = self::text_excerpt( $element );
				if ( '' !== $text ) {
					$line .= ' « ' . $text . ' »';
				}
				$lines[] = $line;
			}
		);
		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array{total: int, max_depth: int, by_type: array<string, int>}
	 */
	public static function stats( array $elements ): array {
		$by_type   = array();
		$total     = 0;
		$max_depth = 0;
		self::walk(
			$elements,
			static function ( array $element, int $depth ) use ( &$by_type, &$total, &$max_depth ): void {
				$type             = self::type_of( $element );
				$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
				++$total;
				$max_depth = max( $max_depth, $depth );
			}
		);
		arsort( $by_type );
		return array(
			'total'     => $total,
			'max_depth' => $max_depth,
			'by_type'   => $by_type,
		);
	}

	/**
	 * Valeur effective d'un réglage sur un appareil, en suivant l'héritage responsive
	 * puis la valeur par défaut du contrôle.
	 *
	 * @param array<string, mixed>      $settings
	 * @param array<string, mixed>|null $controls
	 * @return mixed|null
	 */
	public static function effective( array $settings, ?array $controls, string $key, string $device = '' ) {
		// Elementor n'enregistre que les réglages modifiés et complète avec les défauts au rendu :
		// à chaque niveau, la valeur saisie puis le défaut du contrôle, avant de remonter d'un niveau.
		foreach ( Environment::inheritance_chain( $device ) as $suffix ) {
			$name = '' === $suffix ? $key : $key . '_' . $suffix;
			if ( self::has_value( $settings[ $name ] ?? null ) ) {
				return $settings[ $name ];
			}
			$default = self::control_default( $controls, $key, $suffix );
			if ( self::has_value( $default ) ) {
				return $default;
			}
		}
		return null;
	}

	/**
	 * Défaut d'un réglage pour un appareil, dans les deux modes d'Elementor : contrôle unique
	 * « is_responsive » (défauts dans tablet_default, mobile_default…) ou un contrôle par appareil.
	 *
	 * @return mixed|null
	 */
	private static function control_default( ?array $controls, string $key, string $suffix ) {
		if ( '' === $suffix ) {
			return $controls[ $key ]['default'] ?? $controls[ $key ]['desktop_default'] ?? null;
		}
		if ( isset( $controls[ $key . '_' . $suffix ] ) ) {
			return $controls[ $key . '_' . $suffix ]['default'] ?? null;
		}
		return $controls[ $key ][ $suffix . '_default' ] ?? null;
	}

	/** Une valeur Elementor « vide » : chaîne vide, ou dimension sans taille. */
	public static function has_value( $value ): bool {
		if ( null === $value || '' === $value || array() === $value ) {
			return false;
		}
		if ( is_array( $value ) && array_key_exists( 'size', $value ) ) {
			return '' !== $value['size'] && null !== $value['size'];
		}
		return true;
	}

	private static function layout_summary( array $element ): string {
		$settings = self::settings_of( $element );
		$el_type  = (string) ( $element['elType'] ?? '' );
		$parts    = array();

		if ( 'container' === $el_type ) {
			if ( 'grid' === ( $settings['container_type'] ?? '' ) ) {
				$parts[] = 'grid ' . self::size_of( $settings['grid_columns_grid'] ?? null, '?' ) . ' col';
			} else {
				$parts[] = 'flex ' . ( $settings['flex_direction'] ?? 'column' );
			}
			if ( ! empty( $settings['content_width'] ) ) {
				$parts[] = $settings['content_width'];
			}
			if ( isset( $settings['width']['size'] ) && '' !== $settings['width']['size'] ) {
				$parts[] = 'w=' . $settings['width']['size'] . ( $settings['width']['unit'] ?? '' );
			}
		} elseif ( 'column' === $el_type && isset( $settings['_column_size'] ) ) {
			$parts[] = $settings['_column_size'] . '%';
		} elseif ( 'section' === $el_type ) {
			$parts[] = 'legacy';
		}

		if ( ! empty( $settings['header_size'] ) && 'heading' === ( $element['widgetType'] ?? '' ) ) {
			$parts[] = $settings['header_size'];
		}
		return implode( ', ', $parts );
	}

	private static function text_excerpt( array $element ): string {
		$settings = self::settings_of( $element );
		foreach ( self::TEXT_SETTINGS as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				return self::shorten( $settings[ $key ] );
			}
		}
		if ( isset( $settings['image']['url'] ) && is_string( $settings['image']['url'] ) ) {
			return 'image ' . self::shorten( wp_basename( $settings['image']['url'] ) );
		}
		return '';
	}

	private static function shorten( string $text ): string {
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		if ( mb_strlen( $plain ) > self::EXCERPT_LENGTH ) {
			$plain = mb_substr( $plain, 0, self::EXCERPT_LENGTH - 1 ) . '…';
		}
		return $plain;
	}

	private static function size_of( $value, string $fallback ): string {
		return is_array( $value ) && isset( $value['size'] ) && '' !== $value['size'] ? (string) $value['size'] : $fallback;
	}
}
