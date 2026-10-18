<?php
/**
 * Base commune des capacités Atelier : nom, schémas, droits et exécution.
 *
 * Chaque capacité devient un outil MCP. La vérification des droits se fait ici,
 * côté serveur, quel que soit le client.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

abstract class Ability {

	public const NAMESPACE_PREFIX = 'atelier/';
	public const CATEGORY         = 'atelier';

	/** Nom court, sans préfixe : « site-info » devient « atelier/site-info ». */
	abstract public function slug(): string;

	abstract public function label(): string;

	/** Description lue par le modèle : dire quand utiliser l'outil et ce qu'il renvoie. */
	abstract public function description(): string;

	/** @return array<string, mixed> */
	abstract public function input_schema(): array;

	/**
	 * @param array<string, mixed> $input Entrée déjà validée par le schéma.
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function execute( array $input );

	/**
	 * @param array<string, mixed> $input
	 * @return bool|WP_Error
	 */
	abstract public function permission( array $input );

	public function is_readonly(): bool {
		return true;
	}

	public function is_destructive(): bool {
		return false;
	}

	public function name(): string {
		return self::NAMESPACE_PREFIX . $this->slug();
	}

	public function register(): void {
		$input_schema = $this->input_schema();
		// Un client peut appeler un outil sans arguments : WordPress valide alors « null » contre
		// le schéma objet et refuse. La valeur par défaut {} rend l'appel sans arguments valide.
		if ( 'object' === ( $input_schema['type'] ?? '' ) && ! array_key_exists( 'default', $input_schema ) ) {
			$input_schema['default'] = array();
		}

		wp_register_ability(
			$this->name(),
			array(
				'label'               => $this->label(),
				'description'         => $this->description(),
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'execute_callback'    => function ( $input = null ) {
					return $this->execute( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => function ( $input = null ) {
					return $this->permission( is_array( $input ) ? $input : array() );
				},
				'meta'                => array(
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations' => array(
						'readonly'    => $this->is_readonly(),
						'destructive' => $this->is_destructive(),
						'idempotent'  => $this->is_readonly(),
					),
				),
			)
		);
	}

	/**
	 * Refus uniforme : même message que l'objet existe ou non, pour ne pas
	 * révéler l'existence d'un contenu auquel l'utilisateur n'a pas accès.
	 */
	protected function forbidden(): WP_Error {
		return new WP_Error(
			'atelier_forbidden',
			__( 'Accès refusé à cette ressource.', 'atelier-mcp' ),
			array( 'status' => 403 )
		);
	}

	protected function can_edit_post( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}
}
