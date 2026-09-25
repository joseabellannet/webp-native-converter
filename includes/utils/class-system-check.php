<?php
/**
 * Comprobaciones de entorno del servidor para WebP Native Converter.
 *
 * Antes de intentar convertir cualquier imagen, necesito saber con qué cuento:
 * ¿tiene GD con soporte WebP? ¿Imagick? ¿Hay memoria suficiente? ¿Puedo escribir en /uploads/?
 * Todo eso lo resuelvo aquí.
 *
 * @package WebPNativeConverter\Utils
 */

namespace WebPNativeConverter\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase SystemCheck
 */
class SystemCheck {

	/**
	 * Comprueba si GD está disponible Y tiene soporte WebP compilado.
	 * En algunos hostings tienen GD pero sin WebP — este chequeo lo detecta.
	 *
	 * @return bool
	 */
	public static function has_gd_webp() {
		// Primero compruebo que la extensión exista y que gd_info() esté disponible.
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}

		$info = gd_info();

		// Compruebo tanto el flag de gd_info() como la existencia de imagewebp(),
		// porque en PHP 8+ el flag puede no estar pero la función sí existe.
		return ! empty( $info['WebP Support'] ) || function_exists( 'imagewebp' );
	}

	/**
	 * Comprueba si Imagick está disponible Y soporta el formato WEBP.
	 * Imagick puede estar instalado pero compilado sin soporte WebP — raro pero pasa.
	 *
	 * @return bool
	 */
	public static function has_imagick_webp() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		try {
			// queryFormats devuelve array vacío si WEBP no está soportado.
			$formats = \Imagick::queryFormats( 'WEBP' );
			return ! empty( $formats );
		} catch ( \Exception $e ) {
			// Algunos builds de Imagick lanzan excepción en vez de devolver array vacío.
			return false;
		}
	}

	/**
	 * Devuelve el mejor motor disponible para la conversión.
	 * Prefiero Imagick porque maneja mejor los metadatos y la calidad en imágenes complejas.
	 * Si no está disponible, caigo a GD. Si tampoco, retorno 'none' y el plugin se desactiva solo.
	 *
	 * @return string 'imagick', 'gd' o 'none'.
	 */
	public static function get_available_engine() {
		if ( self::has_imagick_webp() ) {
			return 'imagick';
		}

		if ( self::has_gd_webp() ) {
			return 'gd';
		}

		// Sin motor disponible — no puedo convertir nada.
		return 'none';
	}

	/**
	 * Comprueba que podemos escribir en la carpeta de uploads.
	 * Sin esto, todas las conversiones fallarían en silencio.
	 *
	 * @return bool
	 */
	public static function is_upload_dir_writable() {
		$upload_dir = wp_upload_dir();
		return wp_is_writable( $upload_dir['basedir'] );
	}

	/**
	 * Obtiene información sobre el límite de memoria de PHP.
	 * Conversiones de imágenes grandes pueden necesitar 256MB o más.
	 *
	 * @return array Con claves: raw (string), bytes (int), formatted (string), is_low (bool).
	 */
	public static function get_memory_info() {
		$memory_limit = ini_get( 'memory_limit' );
		$bytes        = wp_convert_hr_to_bytes( $memory_limit );

		return array(
			'raw'       => $memory_limit,
			'bytes'     => $bytes,
			'formatted' => size_format( $bytes ),
			// Considero "bajo" cualquier cosa por debajo de 128MB — WebP puede ser exigente.
			'is_low'    => $bytes > 0 && $bytes < ( 128 * 1024 * 1024 ),
		);
	}

	/**
	 * ¿Este adjunto ya es WebP (mime, ruta o metadatos)?
	 * WordPress a veces guarda mime image/jpeg aunque el archivo sea .webp.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function attachment_is_webp( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
		if ( in_array( $mime, array( 'image/webp', 'image/x-webp' ), true ) ) {
			return true;
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( self::path_is_webp( $file ) ) {
			return true;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['file'] ) && self::path_is_webp( $meta['file'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public static function path_is_webp( $path ) {
		return (bool) preg_match( '/\.webp$/i', (string) $path );
	}

	/**
	 * Genera el informe completo del estado del sistema.
	 * Lo uso en el panel de administración y también para decidir si habilito el botón de conversión.
	 *
	 * @return array
	 */
	public static function get_system_status() {
		$engine      = self::get_available_engine();
		$memory_info = self::get_memory_info();
		$writable    = self::is_upload_dir_writable();

		// Solo puedo convertir si tengo motor gráfico Y puedo escribir en disco.
		$can_convert = ( 'none' !== $engine ) && $writable;

		return array(
			'can_convert' => $can_convert,
			'engine'      => $engine,
			'has_gd'      => self::has_gd_webp(),
			'has_imagick' => self::has_imagick_webp(),
			'is_writable' => $writable,
			'memory_info' => $memory_info,
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
		);
	}
}
