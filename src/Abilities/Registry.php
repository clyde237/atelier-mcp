<?php
/**
 * Liste des capacités du plugin et enregistrement auprès de l'Abilities API.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var Ability[]|null */
	private static $abilities = null;

	public static function hook(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			Ability::CATEGORY,
			array(
				'label'       => __( 'Atelier', 'atelier-mcp' ),
				'description' => __( 'Outils de construction et de vérification de pages (Elementor, Gutenberg).', 'atelier-mcp' ),
			)
		);
	}

	public static function register_abilities(): void {
		foreach ( self::all() as $ability ) {
			$ability->register();
		}
	}

	/** @return Ability[] */
	public static function all(): array {
		if ( null === self::$abilities ) {
			self::$abilities = array(
				new SiteInfo(),
				new ListDocuments(),
				new GetDocument(),
				new DescribeWidget(),
				new GetKit(),
				new AuditDocument(),
				new SaveDocument(),
				new CreateDocument(),
				new RestoreRevision(),
				new ListRevisions(),
				new FlushCache(),
				new UploadMedia(),
				new PreviewLink(),
				new GetActivityLog(),
				new ThemeBuilder(),
				new GlobalWidgets(),
				new Popups(),
				new GutenbergBlocks(),
				new GetGutenbergDocument(),
				new SaveGutenbergDocument(),
				new GutenbergPatterns(),
				new ThemeJson(),
			);
		}
		return self::$abilities;
	}

	/** @return string[] Noms complets (« atelier/… ») à exposer comme outils MCP. */
	public static function names(): array {
		return array_map(
			static function ( Ability $ability ): string {
				return $ability->name();
			},
			self::all()
		);
	}
}
