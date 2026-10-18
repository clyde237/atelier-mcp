<?php
/**
 * Contrôles statiques d'un arbre Elementor : structure ancienne, code personnalisé,
 * responsive, accessibilité. Lecture seule, aucune modification.
 *
 * @package Atelier
 */

namespace Atelier\Elementor;

defined( 'ABSPATH' ) || exit;

final class Audit {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	/** Taille de police minimale lisible sur mobile, en px. */
	private const MIN_MOBILE_FONT_PX = 12;

	/** Largeur au-delà de laquelle une valeur fixe déborde d'un téléphone, en px. */
	private const MOBILE_MAX_WIDTH_PX = 767;

	/** Hauteur minimale au-delà de laquelle un bloc remplit plus d'un écran de téléphone, en px. */
	private const MOBILE_TALL_PX = 700;

	private const MAX_FINDINGS = 200;

	/** @var array<int, array<string, string>> */
	private $findings = array();

	/** @var string Appareil le plus étroit actif (« mobile » en général). */
	private $narrowest_device = '';

	/** @var string[] */
	private $heading_levels = array();

	/**
	 * @param array<int, array<string, mixed>> $elements
	 * @return array{summary: array<string, int>, findings: array<int, array<string, string>>, truncated: bool}
	 */
	public function run( array $elements ): array {
		$devices                = array_keys( Environment::breakpoints() );
		$this->narrowest_device = $devices[0] ?? '';
		$this->findings         = array();
		$this->heading_levels   = array();

		$legacy = 0;
		Tree::walk(
			$elements,
			function ( array $element ) use ( &$legacy ): void {
				$el_type = (string) ( $element['elType'] ?? '' );
				if ( 'section' === $el_type || 'column' === $el_type ) {
					++$legacy;
				}
				$this->check_element( $element );
			}
		);

		if ( $legacy > 0 ) {
			array_unshift(
				$this->findings,
				$this->finding( self::WARNING, 'legacy-layout', '', sprintf( '%d sections/colonnes anciennes : convertir en conteneurs Flexbox/Grille.', $legacy ) )
			);
		}
		$this->check_headings();

		$summary = array(
			self::ERROR   => 0,
			self::WARNING => 0,
			self::INFO    => 0,
		);
		foreach ( $this->findings as $finding ) {
			++$summary[ $finding['severity'] ];
		}

		return array(
			'summary'   => $summary,
			'findings'  => array_slice( $this->findings, 0, self::MAX_FINDINGS ),
			'truncated' => count( $this->findings ) > self::MAX_FINDINGS,
		);
	}

	private function check_element( array $element ): void {
		$settings    = Tree::settings_of( $element );
		$el_type     = (string) ( $element['elType'] ?? '' );
		$widget_type = 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : null;
		$id          = (string) ( $element['id'] ?? '' );
		$controls    = Environment::is_active() ? Environment::controls_for( $el_type, $widget_type ) : null;

		$this->check_custom_code( $id, $widget_type, $settings );
		$this->check_mobile_fonts( $id, $settings, $controls );

		if ( 'container' === $el_type ) {
			$this->check_container( $id, $element, $settings, $controls );
		}
		$this->check_fixed_width( $id, $settings, $controls );

		if ( 'heading' === $widget_type ) {
			$this->heading_levels[] = (string) ( $settings['header_size'] ?? 'h2' );
		}
		if ( 'image' === $widget_type ) {
			$this->check_image_alt( $id, $settings );
		}
		if ( 'button' === $widget_type ) {
			$this->check_link( $id, $settings['link']['url'] ?? '' );
		}
	}

	private function check_custom_code( string $id, ?string $widget_type, array $settings ): void {
		if ( 'html' === $widget_type ) {
			$this->add( self::WARNING, 'html-widget', $id, 'Widget HTML : contenu non modifiable dans Elementor. Remplacer par des widgets natifs.' );
		}
		if ( ! empty( $settings['custom_css'] ) ) {
			$this->add( self::WARNING, 'custom-css', $id, 'CSS personnalisé sur l\'élément : le rendu dépend de code hors réglages.' );
		}
		$classes = trim( (string) ( $settings['_css_classes'] ?? $settings['css_classes'] ?? '' ) );
		if ( '' !== $classes ) {
			$this->add( self::INFO, 'css-classes', $id, sprintf( 'Classes CSS « %s » : le style vient peut-être d\'une feuille externe (thème, snippet).', $classes ) );
		}
	}

	private function check_container( string $id, array $element, array $settings, ?array $controls ): void {
		$device   = $this->narrowest_device;
		$children = is_array( $element['elements'] ?? null ) ? count( $element['elements'] ) : 0;

		if ( 'grid' === ( $settings['container_type'] ?? '' ) ) {
			$columns = Tree::effective( $settings, $controls, 'grid_columns_grid', $device );
			$count   = is_array( $columns ) ? (int) ( $columns['size'] ?? 0 ) : 0;
			if ( $count > 1 && $children > 1 ) {
				$this->add( self::WARNING, 'grid-mobile-columns', $id, sprintf( 'Grille à %d colonnes sur %s : prévoir 1 colonne (grid_columns_grid_%s).', $count, $device, $device ) );
			}
		} else {
			$direction = (string) Tree::effective( $settings, $controls, 'flex_direction', $device );
			$wrap      = (string) Tree::effective( $settings, $controls, 'flex_wrap', $device );
			if ( 0 === strpos( $direction, 'row' ) && 'wrap' !== $wrap && $children > 1 ) {
				$this->add( self::WARNING, 'row-not-stacking', $id, sprintf( 'Conteneur en rangée sur %s sans retour à la ligne : régler flex_direction_%s sur "column".', $device, $device ) );
			}
		}

		$min_height = Tree::effective( $settings, $controls, 'min_height', $device );
		if ( is_array( $min_height ) && 'px' === ( $min_height['unit'] ?? '' ) && (float) ( $min_height['size'] ?? 0 ) > self::MOBILE_TALL_PX ) {
			$this->add( self::INFO, 'tall-on-mobile', $id, sprintf( 'Hauteur minimale de %spx sur %s.', $min_height['size'], $device ) );
		}
	}

	private function check_fixed_width( string $id, array $settings, ?array $controls ): void {
		// boxed_width n'est pas contrôlé : c'est une largeur maximale, elle ne déborde pas.
		foreach ( array( '_element_custom_width', 'width' ) as $key ) {
			$width = Tree::effective( $settings, $controls, $key, $this->narrowest_device );
			if ( is_array( $width ) && 'px' === ( $width['unit'] ?? '' ) && (float) ( $width['size'] ?? 0 ) > self::MOBILE_MAX_WIDTH_PX ) {
				$this->add( self::WARNING, 'fixed-width', $id, sprintf( 'Largeur fixe %s=%spx sur %s : déborde de l\'écran.', $key, $width['size'], $this->narrowest_device ) );
			}
		}
	}

	private function check_mobile_fonts( string $id, array $settings, ?array $controls ): void {
		// Réglages de taille de police présents, ramenés à leur nom de base (sans suffixe d'appareil).
		$bases = array();
		foreach ( array_keys( $settings ) as $key ) {
			if ( preg_match( '/^(.*typography_font_size)(_[a-z_]+)?$/', (string) $key, $match ) ) {
				$bases[ $match[1] ] = true;
			}
		}

		foreach ( array_keys( $bases ) as $key ) {
			$size = Tree::effective( $settings, $controls, $key, $this->narrowest_device );
			if ( is_array( $size ) && 'px' === ( $size['unit'] ?? 'px' ) && '' !== ( $size['size'] ?? '' ) && (float) $size['size'] < self::MIN_MOBILE_FONT_PX ) {
				$this->add( self::WARNING, 'small-font-mobile', $id, sprintf( 'Police de %spx sur %s (minimum conseillé %dpx).', $size['size'], $this->narrowest_device, self::MIN_MOBILE_FONT_PX ) );
			}
		}
	}

	private function check_image_alt( string $id, array $settings ): void {
		$alt = trim( (string) ( $settings['image']['alt'] ?? '' ) );
		if ( '' === $alt && ! empty( $settings['image']['id'] ) ) {
			$alt = trim( (string) get_post_meta( (int) $settings['image']['id'], '_wp_attachment_image_alt', true ) );
		}
		if ( '' === $alt ) {
			$this->add( self::WARNING, 'image-alt', $id, 'Image sans texte alternatif.' );
		}
	}

	private function check_link( string $id, $url ): void {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url ) {
			$this->add( self::WARNING, 'empty-link', $id, 'Bouton sans lien.' );
		}
	}

	private function check_headings(): void {
		$levels = array_map(
			static function ( string $tag ): int {
				return preg_match( '/^h([1-6])$/', $tag, $m ) ? (int) $m[1] : 0;
			},
			$this->heading_levels
		);
		$levels = array_values( array_filter( $levels ) );

		$h1 = count( array_keys( $levels, 1, true ) );
		if ( $h1 > 1 ) {
			$this->add( self::WARNING, 'multiple-h1', '', sprintf( '%d titres H1 : un seul par page.', $h1 ) );
		}
		$previous = 0;
		foreach ( $levels as $level ) {
			if ( $previous > 0 && $level > $previous + 1 ) {
				$this->add( self::INFO, 'heading-skip', '', sprintf( 'Saut de niveau de titre : H%d puis H%d.', $previous, $level ) );
			}
			$previous = $level;
		}
	}

	private function add( string $severity, string $rule, string $element_id, string $message ): void {
		$this->findings[] = $this->finding( $severity, $rule, $element_id, $message );
	}

	/** @return array<string, string> */
	private function finding( string $severity, string $rule, string $element_id, string $message ): array {
		return array(
			'severity' => $severity,
			'rule'     => $rule,
			'element'  => $element_id,
			'message'  => $message,
		);
	}
}
