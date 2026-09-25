<?php
/**
 * Motor de conversión de imágenes a WebP.
 *
 * Este es el núcleo del plugin — el que hace el trabajo de verdad.
 * Soporta Imagick y GD, con fallback automático de uno al otro si hay problemas.
 * Preserva la transparencia alfa de los PNGs y permite controlar la calidad (1-100%).
 *
 * @package WebPNativeConverter\Core
 */

namespace WebPNativeConverter\Core;

use WebPNativeConverter\Utils\SystemCheck;
use WebPNativeConverter\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Converter
 */
class Converter {

	/**
	 * Calidad de compresión WebP por defecto.
	 * 82 es el punto dulce donde la imagen visualmente no se nota peor
	 * pero el peso baja un 60-80% respecto a JPEG equivalente.
	 *
	 * @var int
	 */
	protected $quality = 82;

	/**
	 * Constructor. Lee la calidad guardada en opciones, o usa la que le pasen.
	 *
	 * @param int|null $quality Calidad de compresión 1-100. Null = leer de wp_options.
	 */
	public function __construct( $quality = null ) {
		if ( null !== $quality ) {
			$this->set_quality( $quality );
		} else {
			// Leo la calidad guardada por el usuario en los ajustes del plugin.
			$settings      = get_option( 'webp_nc_settings', array() );
			$this->quality = isset( $settings['quality'] ) ? absint( $settings['quality'] ) : 82;
		}
	}

	/**
	 * Establece la calidad asegurando que siempre esté entre 1 y 100.
	 * absint() elimina negativos, min/max ajustan el rango.
	 *
	 * @param int $quality
	 */
	public function set_quality( $quality ) {
		$quality       = absint( $quality );
		$this->quality = min( 100, max( 1, $quality ) );
	}

	/**
	 * @return int Calidad actual configurada.
	 */
	public function get_quality() {
		return $this->quality;
	}

	/**
	 * Convierte una imagen JPG/PNG a WebP.
	 *
	 * Retorna siempre un array con el resultado — nunca lanza excepciones hacia afuera.
	 * Así quien llame a este método puede tomar decisiones basadas en el resultado
	 * sin tener que gestionar try/catch por su cuenta.
	 *
	 * @param string      $source_path      Ruta absoluta del archivo original.
	 * @param string|null $destination_path Ruta del WebP resultante. Si no se indica, se genera en el mismo directorio.
	 * @param int|null    $custom_quality   Calidad puntual para esta llamada (sobreescribe la de instancia).
	 * @return array {
	 *   @type bool   $success
	 *   @type string $source_path
	 *   @type string $webp_path
	 *   @type int    $original_size   Bytes del archivo original.
	 *   @type int    $webp_size       Bytes del archivo WebP resultante.
	 *   @type int    $saved_bytes     Diferencia en bytes (siempre >= 0).
	 *   @type float  $saved_percent   Porcentaje de ahorro.
	 *   @type string $error           Mensaje de error si success === false.
	 *   @type string $engine_used     'imagick' o 'gd'.
	 * }
	 */
	public function convert( $source_path, $destination_path = null, $custom_quality = null ) {
		// Preparo la estructura de resultado con valores por defecto seguros.
		$result = array(
			'success'       => false,
			'source_path'   => $source_path,
			'webp_path'     => '',
			'original_size' => 0,
			'webp_size'     => 0,
			'saved_bytes'   => 0,
			'saved_percent' => 0,
			'error'         => '',
			'engine_used'   => '',
		);

		// Comprobación básica antes de intentar nada con el archivo.
		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			$result['error'] = __( 'El archivo de origen no existe o no tiene permisos de lectura.', 'webp-native-converter' );
			Logger::error( sprintf( 'Conversión fallida: %s (%s)', $result['error'], $source_path ) );
			return $result;
		}

		if ( SystemCheck::path_is_webp( $source_path ) ) {
			$result['error'] = __( 'El archivo ya es WebP; no hace falta convertirlo.', 'webp-native-converter' );
			return $result;
		}

		$original_size           = @filesize( $source_path );
		$result['original_size'] = $original_size;

		// Si no me indican dónde guardar el WebP, lo pongo en el mismo sitio con extensión .webp.
		if ( empty( $destination_path ) ) {
			$destination_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );
		}

		// Si la calidad viene como parámetro puntual la valido, si no uso la de instancia.
		$quality = ( null !== $custom_quality ) ? min( 100, max( 1, absint( $custom_quality ) ) ) : $this->quality;
		$engine  = SystemCheck::get_available_engine();

		$converted = false;

		if ( 'imagick' === $engine ) {
			$converted             = $this->convert_with_imagick( $source_path, $destination_path, $quality );
			$result['engine_used'] = 'imagick';
		} elseif ( 'gd' === $engine ) {
			$converted             = $this->convert_with_gd( $source_path, $destination_path, $quality );
			$result['engine_used'] = 'gd';
		} else {
			// Sin motor disponible — esto ya lo debería haber detectado SystemCheck antes de llegar aquí,
			// pero lo dejo como salvaguarda extra.
			$result['error'] = __( 'No hay disponible ninguna extensión gráfica (Imagick o GD con soporte WebP).', 'webp-native-converter' );
			Logger::error( $result['error'] );
			return $result;
		}

		// Verifico que el archivo resultante existe y no está vacío (0 bytes = algo falló).
		if ( ! $converted || ! file_exists( $destination_path ) || 0 === filesize( $destination_path ) ) {
			$result['error'] = __( 'La conversión finalizó pero el archivo WebP resultante no se generó correctamente.', 'webp-native-converter' );
			Logger::error( sprintf( 'Fallo al generar WebP para: %s', $source_path ) );

			// Si se creó un archivo de 0 bytes lo elimino para no dejar basura en disco.
			if ( file_exists( $destination_path ) && 0 === filesize( $destination_path ) ) {
				@unlink( $destination_path );
			}
			return $result;
		}

		// Cálculo de ahorro — siempre positivo o cero (WebP puede ser más grande en casos extremos).
		$webp_size     = filesize( $destination_path );
		$saved_bytes   = max( 0, $original_size - $webp_size );
		$saved_percent = $original_size > 0 ? round( ( $saved_bytes / $original_size ) * 100, 1 ) : 0;

		$result['success']       = true;
		$result['webp_path']     = $destination_path;
		$result['webp_size']     = $webp_size;
		$result['saved_bytes']   = $saved_bytes;
		$result['saved_percent'] = $saved_percent;

		Logger::info(
			sprintf(
				'Conversión completada [%s]: %s -> %s (Ahorro: %s / %s%%)',
				$result['engine_used'],
				basename( $source_path ),
				basename( $destination_path ),
				size_format( $saved_bytes ),
				$saved_percent
			)
		);

		return $result;
	}

	/**
	 * Conversión con Imagick.
	 *
	 * Puntos importantes:
	 * - Primero configuro alfa y fondo ANTES de cambiar el formato — el orden importa en Imagick.
	 * - webp:method=6 activa la compresión más agresiva de Imagick (más lento pero mejor resultado).
	 * - Si Imagick falla por cualquier motivo, intento el fallback a GD automáticamente.
	 *
	 * @param string $source_path
	 * @param string $destination_path
	 * @param int    $quality
	 * @return bool
	 */
	protected function convert_with_imagick( $source_path, $destination_path, $quality ) {
		try {
			$image = new \Imagick( $source_path );

			// Para imágenes con capas (p.ej. PSDs o GIFs), coalesceImages aplana todo
			// en una sola capa antes de exportar a WebP.
			if ( method_exists( $image, 'coalesceImages' ) ) {
				$image = $image->coalesceImages();
			}

			// CRÍTICO: el canal alfa y el fondo transparente deben configurarse ANTES
			// de cambiar el formato. Si lo hago después, Imagick puede ignorarlos.
			$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
			$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );

			// Ahora sí cambio el formato.
			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $quality );

			// webp:method=6 es el nivel máximo de compresión de Imagick para WebP.
			// No verifico COMPRESSION_ZIP (que no tiene nada que ver con WebP) — simplemente lo aplico.
			$image->setOption( 'webp:method', '6' );

			$written = $image->writeImage( $destination_path );

			// Libero memoria explícitamente — las imágenes grandes pueden consumir cientos de MB.
			$image->clear();
			$image->destroy();

			return (bool) $written;

		} catch ( \Exception $e ) {
			Logger::error( 'Error en Imagick: ' . $e->getMessage() );

			// Fallback: si Imagick falla (versión antigua, imagen corrupta...) intento con GD.
			if ( SystemCheck::has_gd_webp() ) {
				Logger::info( sprintf( 'Usando fallback GD para: %s', basename( $source_path ) ) );
				return $this->convert_with_gd( $source_path, $destination_path, $quality );
			}

			return false;
		}
	}

	/**
	 * Conversión con GD.
	 *
	 * Más sencillo que Imagick pero igualmente robusto para JPEGs y PNGs normales.
	 * Para PNG es importante: paletteToTruecolor convierte paleta indexada a RGB completo
	 * antes de activar el canal alfa — sin eso, las transparencias pueden corromperse.
	 *
	 * @param string $source_path
	 * @param string $destination_path
	 * @param int    $quality
	 * @return bool
	 */
	protected function convert_with_gd( $source_path, $destination_path, $quality ) {
		// getimagesize me da el MIME type real del archivo — más fiable que la extensión.
		$image_info = @getimagesize( $source_path );
		if ( empty( $image_info ) || empty( $image_info['mime'] ) ) {
			return false;
		}

		$mime  = $image_info['mime'];
		$image = null;

		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/pjpeg': // Progressive JPEG — GD lo maneja igual.
				$image = @imagecreatefromjpeg( $source_path );
				break;

			case 'image/png':
				$image = @imagecreatefrompng( $source_path );
				if ( false !== $image ) {
					// Este orden es crítico para preservar la transparencia en PNG:
					// 1. Convertir de paleta indexada a truecolor (necesario para alfa).
					imagepalettetotruecolor( $image );
					// 2. Activar alphablending para composición interna de GD.
					imagealphablending( $image, true );
					// 3. Indicar a GD que guarde el canal alfa al exportar.
					imagesavealpha( $image, true );
				}
				break;

			default:
				// Formato no soportado — no intento convertirlo.
				return false;
		}

		if ( ! $image ) {
			// GD falló al cargar la imagen — posiblemente está corrupta.
			return false;
		}

		$success = @imagewebp( $image, $destination_path, $quality );

		// Libero la memoria del recurso GD explícitamente.
		@imagedestroy( $image );

		return (bool) $success;
	}
}
