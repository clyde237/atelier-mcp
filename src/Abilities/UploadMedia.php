<?php
/**
 * atelier/upload-media : téléverse un fichier média avec texte alternatif obligatoire.
 *
 * @package Atelier
 */

namespace Atelier\Abilities;

use Atelier\ActivityLog;
use Atelier\Auth\TokenContext;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class UploadMedia extends Ability {

	public const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif' );

	public function slug(): string {
		return 'upload-media';
	}

	public function label(): string {
		return __( 'Téléverser un média', 'atelier-mcp' );
	}

	public function description(): string {
		return 'Téléverse une image ou une icône dans la médiathèque WordPress (format Base64). '
			. 'Exige obligatoirement un texte alternatif (alt_text) pour l\'accessibilité. '
			. 'Renvoie l\'ID de l\'attachement et son URL directe à injecter dans les réglages des widgets Elementor.';
	}

	public function is_readonly(): bool {
		return false;
	}

	public function is_destructive(): bool {
		return false;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filename'       => array(
					'type'        => 'string',
					'pattern'     => '^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|webp|svg|gif)$',
					'description' => 'Nom du fichier avec extension autorisée (jpg, jpeg, png, webp, svg, gif).',
				),
				'content_base64' => array(
					'type'        => 'string',
					'description' => 'Contenu du fichier encodé en base64.',
				),
				'alt_text'       => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Texte alternatif décrivant l\'image (obligatoire pour l\'accessibilité).',
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'Titre du média (facultatif, utilise le nom de fichier par défaut).',
				),
				'caption'        => array(
					'type'        => 'string',
					'description' => 'Légende facultative de l\'image.',
				),
			),
			'required'             => array( 'filename', 'content_base64', 'alt_text' ),
			'additionalProperties' => false,
		);
	}

	public function permission( array $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}

		if ( TokenContext::is_token_authenticated() && ! TokenContext::has_scope( TokenContext::SCOPE_WRITE ) ) {
			return new WP_Error(
				'atelier_insufficient_scope',
				__( 'Le jeton d\'accès ne dispose pas de la portée d\'écriture (atelier:write).', 'atelier-mcp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function execute( array $input ) {
		$filename = sanitize_file_name( (string) $input['filename'] );
		$alt_text = sanitize_text_field( (string) $input['alt_text'] );
		$title    = ! empty( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : pathinfo( $filename, PATHINFO_FILENAME );
		$caption  = ! empty( $input['caption'] ) ? sanitize_text_field( (string) $input['caption'] ) : '';

		// Validation de l'extension.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return new WP_Error( 'atelier_invalid_extension', __( 'Type de fichier non autorisé. Formats acceptés : jpg, jpeg, png, webp, svg, gif.', 'atelier-mcp' ), array( 'status' => 400 ) );
		}

		// Décodage Base64 (en nettoyant un éventuel préfixe data:image/...;base64,).
		$raw_base64 = (string) $input['content_base64'];
		if ( preg_match( '/^data:image\/[a-z0-9\+\-\.]+;base64,(.+)$/is', $raw_base64, $matches ) ) {
			$raw_base64 = $matches[1];
		}

		$raw_data = base64_decode( trim( $raw_base64 ), true );
		if ( false === $raw_data || empty( $raw_data ) ) {
			return new WP_Error( 'atelier_invalid_base64', __( 'Contenu base64 invalide ou vide.', 'atelier-mcp' ), array( 'status' => 400 ) );
		}

		// Sauvegarde du fichier dans le répertoire uploads.
		$upload = wp_upload_bits( $filename, null, $raw_data );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'atelier_upload_error', $upload['error'], array( 'status' => 500 ) );
		}

		$file_path = $upload['file'];
		$file_url  = $upload['url'];
		$file_type = wp_check_filetype( $filename, null );

		// Création de l'attachement WordPress.
		$attachment = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => $title,
			'post_excerpt'   => $caption,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'atelier_attachment_error', __( 'Impossible de créer l\'attachement.', 'atelier-mcp' ), array( 'status' => 500 ) );
		}

		// Génération des métadonnées (miniatures, tailles).
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		// Définition du texte alternatif.
		update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );

		// Journal d'activité.
		ActivityLog::record(
			'upload_media',
			$attach_id,
			array(
				'filename' => $filename,
				'url'      => $file_url,
				'alt'      => $alt_text,
			)
		);

		return array(
			'id'        => $attach_id,
			'url'       => $file_url,
			'title'     => $title,
			'alt'       => $alt_text,
			'mime_type' => $file_type['type'],
			'width'     => $attach_data['width'] ?? null,
			'height'    => $attach_data['height'] ?? null,
		);
	}
}
