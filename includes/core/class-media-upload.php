<?php
/**
 * Intercepta nuevas subidas de imágenes y las convierte a WebP automáticamente.
 *
 * El hook wp_generate_attachment_metadata se ejecuta justo después de que WordPress
 * ha subido el archivo Y ha generado todos los thumbnails. Es el momento perfecto
 * para convertir tanto el original como todos los tamaños intermedios.
 *
 * @package WebPNativeConverter\Core
 */

namespace WebPNativeConverter\Core;

use WebPNativeConverter\Utils\Logger;
use WebPNativeConverter\Utils\SystemCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase MediaUpload
 */
class MediaUpload {

	/**
	 * Motor de conversión.
	 *
	 * @var Converter
	 */
	protected $converter;

	/**
	 * Reemplazador de referencias en base de datos.
	 *
	 * @var DbReplacer
	 */
	protected $db_replacer;

	/**
	 * Constructor: instancia dependencias y registra el hook de WP.
	 */
	public function __construct() {
		$this->converter   = new Converter();
		$this->db_replacer = new DbReplacer();

		// Este hook se dispara después de que WP genera los metadatos y thumbnails del adjunto.
		// Es el único momento en que tenemos todos los tamaños disponibles en disco.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_new_upload' ), 10, 2 );
	}

	/**
	 * Procesa la imagen recién subida y todos sus tamaños intermedios.
	 *
	 * Si la opción "convert_on_upload" está desactivada, devolvemos los metadatos sin tocar nada.
	 * IMPORTANTE: solo marcamos _webp_nc_converted si al menos la imagen principal se convirtió bien.
	 *
	 * @param array $metadata      Metadatos generados por WordPress para el adjunto.
	 * @param int   $attachment_id ID del adjunto en wp_posts.
	 * @return array Metadatos actualizados (o los originales si no procesamos nada).
	 */
	public function process_new_upload( $metadata, $attachment_id ) {
		// Si el usuario ha desactivado la conversión automática al subir, no hacemos nada.
		$settings = get_option( 'webp_nc_settings', array() );
		$enabled  = ! empty( $settings['convert_on_upload'] );

		if ( ! $enabled ) {
			return $metadata;
		}

		// Solo procesamos JPG y PNG — ignoramos WebP nativo, SVG, GIF y el resto.
		if ( SystemCheck::attachment_is_webp( $attachment_id ) ) {
			return $metadata;
		}

		$mime          = get_post_mime_type( $attachment_id );
		$allowed_mimes = array( 'image/jpeg', 'image/jpg', 'image/png' );

		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return $metadata;
		}

		$upload_dir    = wp_upload_dir();
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		// Si WP no tiene guardada la ruta del archivo, algo raro pasó durante la subida.
		if ( empty( $attached_file ) ) {
			Logger::warning( sprintf( 'No se encontró _wp_attached_file para el adjunto #%d', $attachment_id ) );
			return $metadata;
		}

		$original_full_path = path_join( $upload_dir['basedir'], $attached_file );
		$base_dir           = dirname( $original_full_path );
		$keep_originals     = ! empty( $settings['keep_originals'] );

		$total_original_bytes = 0;
		$total_webp_bytes     = 0;
		$main_converted       = false; // Flag para saber si el proceso principal tuvo éxito.

		// --- PASO 1: Convertir la imagen original ---
		$main_conversion = $this->converter->convert( $original_full_path );
		if ( $main_conversion['success'] ) {
			$main_converted        = true;
			$total_original_bytes += $main_conversion['original_size'];
			$total_webp_bytes     += $main_conversion['webp_size'];

			$new_attached_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $attached_file );

			// Actualizo los metadatos del adjunto en la BD (mime type, ruta, etc.).
			$this->db_replacer->update_attachment_records( $attachment_id, $attached_file, $new_attached_file );

			// Si no queremos conservar el original, lo borramos.
			// Ojo: solo borro si la conversión fue exitosa — nunca antes.
			if ( ! $keep_originals ) {
				@unlink( $original_full_path );
			}
		} else {
			// Si la imagen principal falla, log del error y devuelvo metadatos sin cambios.
			Logger::error( sprintf( 'Fallo al convertir imagen principal del adjunto #%d: %s', $attachment_id, $main_conversion['error'] ) );
			return $metadata;
		}

		// --- PASO 2: Convertir los thumbnails (tamaños intermedios generados por el tema) ---
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$thumb_path       = trailingslashit( $base_dir ) . $size_info['file'];
				$thumb_conversion = $this->converter->convert( $thumb_path );

				if ( $thumb_conversion['success'] ) {
					$total_original_bytes += $thumb_conversion['original_size'];
					$total_webp_bytes     += $thumb_conversion['webp_size'];

					// Actualizo el nombre del archivo y el mime type en los metadatos.
					$metadata['sizes'][ $size_name ]['file']      = basename( $thumb_conversion['webp_path'] );
					$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';

					if ( ! $keep_originals ) {
						@unlink( $thumb_path );
					}
				} else {
					// Un thumbnail fallido no es fatal — logeo y continúo con el siguiente.
					Logger::warning( sprintf( 'Fallo al convertir thumbnail "%s" del adjunto #%d', $size_name, $attachment_id ) );
				}
			}
		}

		// --- PASO 3: Guardar estadísticas del plugin en postmeta ---
		// Solo marco como convertido si la imagen principal tuvo éxito (que sí lo tuvo, llegamos aquí).
		$saved_bytes = max( 0, $total_original_bytes - $total_webp_bytes );
		update_post_meta( $attachment_id, '_webp_nc_converted', 1 );
		update_post_meta( $attachment_id, '_webp_nc_original_size', $total_original_bytes );
		update_post_meta( $attachment_id, '_webp_nc_webp_size', $total_webp_bytes );
		update_post_meta( $attachment_id, '_webp_nc_saved_bytes', $saved_bytes );

		// Acumulo en las estadísticas globales del plugin.
		$this->update_global_stats( $saved_bytes, 1 );

		// Devuelvo los metadatos modificados — WP los guardará en la BD automáticamente.
		return $metadata;
	}

	/**
	 * Actualiza el contador global de conversiones y espacio ahorrado.
	 * Uso un lock implícito a través del ciclo get/update de WP para evitar condiciones de carrera.
	 *
	 * @param int $saved_bytes Bytes ahorrados en esta conversión.
	 * @param int $count       Número de imágenes procesadas (normalmente 1).
	 */
	protected function update_global_stats( $saved_bytes, $count = 1 ) {
		$stats = get_option(
			'webp_nc_stats',
			array(
				'total_converted' => 0,
				'total_saved'     => 0,
			)
		);

		$stats['total_converted'] = ( isset( $stats['total_converted'] ) ? (int) $stats['total_converted'] : 0 ) + $count;
		$stats['total_saved']     = ( isset( $stats['total_saved'] ) ? (int) $stats['total_saved'] : 0 ) + $saved_bytes;

		update_option( 'webp_nc_stats', $stats );
	}
}
