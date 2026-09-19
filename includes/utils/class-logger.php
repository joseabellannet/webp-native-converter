<?php
/**
 * Logger interno para WebP Native Converter.
 *
 * Necesitaba un sitio donde quedar registros de lo que hace el plugin:
 * conversiones completadas, errores, avisos de caché... Todo va aquí.
 * El archivo de log vive en /uploads/webp-native-converter-logs/ y está protegido con .htaccess.
 *
 * @package WebPNativeConverter\Utils
 */

namespace WebPNativeConverter\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Logger
 */
class Logger {

	/**
	 * Nombre del archivo de log principal.
	 * Se rota automáticamente cuando supera los 5MB para no llenar el disco.
	 *
	 * @var string
	 */
	protected static $log_file = 'webp-native-converter.log';

	/**
	 * Devuelve (y crea si hace falta) el directorio donde guardamos los logs.
	 * Lo pongo dentro de /uploads/ porque WP ya gestiona los permisos de escritura ahí.
	 * El .htaccess y el index.html evitan que alguien acceda desde el navegador.
	 *
	 * @return string Ruta absoluta al directorio de logs.
	 */
	public static function get_log_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'webp-native-converter-logs';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );

			// Bloquear acceso web directo al directorio — no quiero que alguien
			// pueda descargar el log y ver rutas internas del servidor.
			file_put_contents( $dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n" );
			file_put_contents( $dir . '/index.html', '' ); // Doble protección para servidores sin .htaccess.
		}

		return $dir;
	}

	/**
	 * Ruta completa al archivo de log activo.
	 *
	 * @return string
	 */
	public static function get_log_file_path() {
		return trailingslashit( self::get_log_dir() ) . self::$log_file;
	}

	/**
	 * Escribe una línea en el log con timestamp y nivel.
	 *
	 * Si el archivo supera los 5MB lo renombra (rotación) y empieza uno nuevo.
	 * Uso FILE_APPEND | LOCK_EX para evitar problemas con escrituras concurrentes
	 * en caso de que varios procesos intenten logar a la vez.
	 *
	 * @param string $message Texto del mensaje.
	 * @param string $level   Nivel: 'info', 'warning', 'error', 'debug'.
	 * @return bool True si se escribió con éxito.
	 */
	public static function log( $message, $level = 'info' ) {
		$file_path = self::get_log_file_path();
		$timestamp = current_time( 'mysql' );
		$level_tag = strtoupper( $level );

		$formatted = sprintf( "[%s] [%s]: %s\n", $timestamp, $level_tag, $message );

		// Rotación cuando el archivo llega a 5MB.
		// Renombro el existente con timestamp en el nombre para no perder historial.
		if ( file_exists( $file_path ) && filesize( $file_path ) > 5 * 1024 * 1024 ) {
			@rename( $file_path, trailingslashit( self::get_log_dir() ) . 'webp-native-converter-' . gmdate( 'Y-m-d-His' ) . '.log.old' );
		}

		// El @ suprime posibles warnings de permisos — si falla, simplemente retorna false.
		return (bool) @file_put_contents( $file_path, $formatted, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Atajo para mensajes informativos (conversiones exitosas, stats, etc.).
	 *
	 * @param string $message
	 */
	public static function info( $message ) {
		self::log( $message, 'info' );
	}

	/**
	 * Atajo para advertencias no fatales (caché no purgada, thumb no encontrado...).
	 *
	 * @param string $message
	 */
	public static function warning( $message ) {
		self::log( $message, 'warning' );
	}

	/**
	 * Atajo para errores que impiden la conversión de una imagen.
	 *
	 * @param string $message
	 */
	public static function error( $message ) {
		self::log( $message, 'error' );
	}

	/**
	 * Lee las últimas N líneas del log para mostrarlas en el panel.
	 * No leo el archivo entero — en logs grandes sería un desastre de memoria.
	 *
	 * @param int $max_lines Número máximo de líneas a devolver.
	 * @return array Array de strings, una por línea.
	 */
	public static function get_recent_logs( $max_lines = 100 ) {
		$file_path = self::get_log_file_path();

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}

		$lines = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( empty( $lines ) ) {
			return array();
		}

		// Devuelvo solo las últimas N líneas, no todo el archivo.
		return array_slice( $lines, -$max_lines );
	}

	/**
	 * Borra el archivo de log actual.
	 * Lo uso desde el botón "Limpiar consola" en el panel de administración.
	 *
	 * @return bool
	 */
	public static function clear_logs() {
		$file_path = self::get_log_file_path();
		if ( file_exists( $file_path ) ) {
			return @unlink( $file_path );
		}
		return true;
	}
}
