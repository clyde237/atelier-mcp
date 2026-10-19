<?php
/**
 * Gestion du Theme Builder Elementor Pro : conditions d'affichage, popups et widgets globaux.
 *
 * Fonctionne avec Elementor Pro quand il est actif, et prépare/stocke les structures
 * compatibles de manière conforme même sans Elementor Pro.
 *
 * @package Atelier
 */

namespace Atelier\Elementor\Pro;

use Atelier\ActivityLog;
use Atelier\Elementor\Environment;
use Atelier\Elementor\PreviewSigner;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class ThemeBuilderManager {

	public const CPT = 'elementor_library';
	public const META_CONDITIONS = '_elementor_conditions';
	public const OPTION_PRO_CONDITIONS = 'elementor_pro_theme_builder_conditions';

	public const LOCATIONS = array(
		'header'         => 'En-tête de site (Header)',
		'footer'         => 'Pied de page de site (Footer)',
		'single'         => 'Modèle singulier générique (Single)',
		'single-post'    => 'Modèle d\'article unique (Single Post)',
		'single-page'    => 'Modèle de page unique (Single Page)',
		'archive'        => 'Modèle d\'archive / liste (Archive)',
		'search-results' => 'Résultats de recherche (Search Results)',
		'error-404'      => 'Page d\'erreur 404',
		'popup'          => 'Fenêtre modale (Popup)',
		'section'        => 'Bloc réutilisable (Section / Container)',
		'widget'         => 'Widget global (Global Widget)',
	);

	/**
	 * Liste les templates du Theme Builder.
	 *
	 * @param string|null $type Filtre facultatif par type (header, footer, popup, etc.).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_templates( ?string $type = null ): array {
		$args = array(
			'post_type'      => self::CPT,
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $type ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_text_field( $type ),
				),
			);
		}

		$query  = new WP_Query( $args );
		$result = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$result[] = self::format_template( $post );
		}

		return $result;
	}

	/**
	 * Crée un nouveau template Theme Builder (header, footer, popup, single, etc.).
	 *
	 * @param string   $title      Titre du modèle.
	 * @param string   $type       Type de gabarit (header, footer, popup, single, etc.).
	 * @param array    $elements   Arbre Elementor initial (facultatif).
	 * @param string[] $conditions Conditions d'affichage (facultatif).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_template( string $title, string $type, array $elements = array(), array $conditions = array() ) {
		$valid_types = array_keys( self::LOCATIONS );
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error(
				'atelier_invalid_template_type',
				sprintf(
					/* translators: %s: liste des types valides */
					__( 'Type de modèle non reconnu. Types valides : %s.', 'atelier-mcp' ),
					implode( ', ', $valid_types )
				),
				array( 'status' => 400 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $title ),
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'atelier_create_failed', __( 'Échec de la création du template Theme Builder.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, '_elementor_template_type', $type );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

		if ( taxonomy_exists( 'elementor_library_type' ) ) {
			wp_set_object_terms( $post_id, $type, 'elementor_library_type' );
		}

		if ( ! empty( $conditions ) ) {
			self::set_display_conditions( $post_id, $conditions );
		}

		if ( Environment::is_active() ) {
			Environment::plugin()->files_manager->clear_cache();
		}

		ActivityLog::record(
			'create_theme_builder_template',
			$post_id,
			array(
				'type'       => $type,
				'conditions' => $conditions,
			)
		);

		$post = get_post( $post_id );
		return $post ? self::format_template( $post ) : array( 'id' => $post_id );
	}

	/**
	 * Crée un popup Elementor avec ses réglages de déclencheur et conditions.
	 *
	 * @param string               $title      Titre du popup.
	 * @param array<string, mixed> $triggers   Déclencheurs (page_load, scroll, etc.).
	 * @param array<string, mixed> $timing     Règles de ciblage/fréquence.
	 * @param string[]             $conditions Conditions d'affichage.
	 * @param array                $elements   Arbre Elementor initial.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_popup( string $title, array $triggers = array(), array $timing = array(), array $conditions = array(), array $elements = array() ) {
		$template = self::create_template( $title, 'popup', $elements, $conditions );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$popup_id = (int) $template['id'];
		$settings = array_merge( $triggers, $timing );
		if ( ! empty( $settings ) ) {
			update_post_meta( $popup_id, '_elementor_page_settings', $settings );
		}

		ActivityLog::record( 'create_popup', $popup_id );

		$template['triggers'] = array(
			'page_load'   => ! empty( $settings['page_load'] ),
			'scroll'      => ! empty( $settings['scroll'] ),
			'exit_intent' => ! empty( $settings['exit_intent'] ),
			'inactivity'  => ! empty( $settings['inactivity'] ),
		);
		$template['timing']   = array(
			'views_count' => $settings['views_count'] ?? null,
			'devices'     => $settings['devices'] ?? null,
		);

		return $template;
	}

	/**
	 * Définit les conditions d'affichage d'un template du Theme Builder.
	 *
	 * @param int      $template_id ID du template dans elementor_library.
	 * @param string[] $conditions  Ex: ["include/general"], ["include/singular/page", "exclude/singular/page/42"]
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_display_conditions( int $template_id, array $conditions ) {
		$post = get_post( $template_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return new WP_Error( 'atelier_invalid_template', __( 'Le document spécifié n\'est pas un template Elementor Library.', 'atelier-mcp' ), array( 'status' => 404 ) );
		}

		$clean_conditions = array();
		foreach ( $conditions as $cond ) {
			$clean = trim( sanitize_text_field( (string) $cond ) );
			if ( ! empty( $clean ) ) {
				$clean_conditions[] = $clean;
			}
		}

		// 1. Enregistre la méta post individuelle _elementor_conditions.
		update_post_meta( $template_id, self::META_CONDITIONS, $clean_conditions );

		// 2. Synchronise le cache global elementor_pro_theme_builder_conditions.
		self::sync_global_conditions_cache( $template_id, $clean_conditions );

		// 3. Purge les caches Elementor.
		if ( Environment::is_active() ) {
			Environment::plugin()->files_manager->clear_cache();
		}

		// 4. Trace dans le journal d'activité.
		ActivityLog::record(
			'set_display_conditions',
			$template_id,
			array( 'conditions' => $clean_conditions )
		);

		return self::format_template( $post );
	}

	/**
	 * Liste les widgets globaux enregistrés sur le site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_global_widgets(): array {
		$templates = self::get_templates( 'widget' );
		$list      = array();

		foreach ( $templates as $tpl ) {
			$raw_data = get_post_meta( (int) $tpl['id'], '_elementor_data', true );
			$elements = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
			$widget   = ( is_array( $elements ) && ! empty( $elements[0] ) ) ? $elements[0] : null;

			$tpl['widget_type'] = $widget['widgetType'] ?? 'unknown';
			$tpl['usage_code']  = array(
				'elType'     => 'widget',
				'widgetType' => 'global',
				'templateID' => $tpl['id'],
			);
			$list[] = $tpl;
		}

		return $list;
	}

	/**
	 * Crée un widget global réutilisable à partir d'un élément widget.
	 *
	 * @param string               $title
	 * @param array<string, mixed> $widget_element Un élément avec elType=widget, widgetType, settings.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_global_widget( string $title, array $widget_element ) {
		$el_type     = $widget_element['elType'] ?? '';
		$widget_type = $widget_element['widgetType'] ?? '';

		if ( 'widget' !== $el_type || empty( $widget_type ) ) {
			return new WP_Error( 'atelier_invalid_widget', __( 'L\'élément fourni doit être un widget valide avec elType=widget et un widgetType.', 'atelier-mcp' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $title ),
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'atelier_create_failed', __( 'Échec de la création du widget global.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, '_elementor_template_type', 'widget' );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array( $widget_element ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

		if ( taxonomy_exists( 'elementor_library_type' ) ) {
			wp_set_object_terms( $post_id, 'widget', 'elementor_library_type' );
		}

		ActivityLog::record(
			'create_global_widget',
			$post_id,
			array( 'widget_type' => $widget_type )
		);

		return array(
			'id'           => $post_id,
			'title'        => $title,
			'widget_type'  => $widget_type,
			'usage_snippet' => array(
				'elType'     => 'widget',
				'widgetType' => 'global',
				'templateID' => $post_id,
			),
		);
	}

	/**
	 * Liste les popups enregistrés avec leurs réglages de déclenchement.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_popups(): array {
		$popups = self::get_templates( 'popup' );
		foreach ( $popups as &$pop ) {
			$page_settings = get_post_meta( (int) $pop['id'], '_elementor_page_settings', true );
			$settings      = is_array( $page_settings ) ? $page_settings : array();

			$pop['triggers'] = array(
				'page_load'   => ! empty( $settings['page_load'] ),
				'scroll'      => ! empty( $settings['scroll'] ),
				'exit_intent' => ! empty( $settings['exit_intent'] ),
				'inactivity'  => ! empty( $settings['inactivity'] ),
			);
			$pop['timing']   = array(
				'views_count' => $settings['views_count'] ?? null,
				'devices'     => $settings['devices'] ?? null,
			);
		}
		return $popups;
	}

	/**
	 * Formate un template elementor_library pour la sortie API.
	 *
	 * @return array<string, mixed>
	 */
	public static function format_template( WP_Post $post ): array {
		$type       = get_post_meta( $post->ID, '_elementor_template_type', true ) ?: 'section';
		$conditions = get_post_meta( $post->ID, self::META_CONDITIONS, true );

		return array(
			'id'                 => $post->ID,
			'title'              => get_the_title( $post ),
			'template_type'      => $type,
			'type_label'         => self::LOCATIONS[ $type ] ?? $type,
			'status'             => $post->post_status,
			'modified_gmt'       => $post->post_modified_gmt,
			'display_conditions' => is_array( $conditions ) ? array_values( $conditions ) : array(),
			'preview_url'        => PreviewSigner::generate_url( $post->ID ),
		);
	}

	/**
	 * Met à jour le cache global des conditions Theme Builder d'Elementor Pro.
	 *
	 * @param string[] $conditions
	 */
	private static function sync_global_conditions_cache( int $template_id, array $conditions ): void {
		$cache = get_option( self::OPTION_PRO_CONDITIONS, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		if ( empty( $conditions ) ) {
			unset( $cache[ $template_id ] );
		} else {
			$cache[ $template_id ] = $conditions;
		}

		update_option( self::OPTION_PRO_CONDITIONS, $cache, false );
	}
}
