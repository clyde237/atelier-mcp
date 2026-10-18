<?php
/**
 * Autoloader PSR-4 minimal pour l'espace de noms Atelier\.
 *
 * Pas de Composer à l'exécution : le plugin est distribué en zip privé et doit
 * fonctionner sans étape de build sur le serveur.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Atelier\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
