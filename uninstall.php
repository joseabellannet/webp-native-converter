<?php
/**
 * Limpieza a fondo al desinstalar el plugin.
 *
 * Se ejecuta automáticamente cuando el usuario borra el plugin desde la administración.
 * Es importante no dejar basura en la base de datos ni en el disco, así que limpiamos
 * todo lo que generamos (opciones, logs, etc.).
 *
 * @package WebPNativeConverter
 */

// Medida de seguridad: solo ejecutar si WP está desinstalando el plugin de verdad.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Limpieza de opciones de la base de datos (ajustes y estadísticas).
delete_option( 'webp_nc_settings' );
delete_option( 'webp_nc_stats' );

// 2. Limpieza de transients (por si acaso los llego a usar en el futuro, no cuesta nada).
delete_transient( 'webp_nc_system_status' );
delete_transient( 'webp_nc_batch_queue' );
delete_transient( 'webp_nc_unused_scan' );

// Las imágenes en cuarentena (wp-content/uploads/webp-nc-quarantine/) y el índice
// webp_nc_quarantine_index NO se tocan: son archivos del usuario, no basura del plugin.

// 3. Limpieza de archivos físicos (los logs de errores).
$upload_dir = wp_upload_dir();
$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'webp-native-converter-logs';

if ( is_dir( $log_dir ) ) {
	// Me traigo todo lo que termine en .log y lo borro.
	$log_files = glob( $log_dir . '/*.log' );
	if ( ! empty( $log_files ) ) {
		foreach ( $log_files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
	
	// Intento borrar la carpeta (si quedaron el .htaccess o index.html, fallará el rmdir, pero no es crítico).
	@unlink( $log_dir . '/.htaccess' );
	@unlink( $log_dir . '/index.html' );
	@rmdir( $log_dir );
}
