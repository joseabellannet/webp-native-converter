<?php
/**
 * Autoloader PSR-4 para WebP Native Converter.
 *
 * Mapea el namespace WebPNativeConverter\ a la estructura de carpetas,
 * siguiendo la convención de nombres de WordPress (class-nombre-clase.php).
 * Sin esto tendría que hacer un require_once por cada clase — ni hablar.
 *
 * @package WebPNativeConverter
 */

namespace WebPNativeConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Autoloader
 */
class Autoloader {

	/**
	 * Prefijo del namespace raíz del plugin.
	 * Todo lo que empiece por esto es nuestro y lo gestionamos nosotros.
	 *
	 * @var string
	 */
	protected static $prefix = 'WebPNativeConverter\\';

	/**
	 * Directorio base donde viven todos los archivos de clase.
	 * Se asigna en register() para poder usar la constante WEBP_NC_PATH.
	 *
	 * @var string
	 */
	protected static $base_dir = '';

	/**
	 * Registra esta función en el stack de SPL.
	 * A partir de aquí, cada vez que PHP no encuentre una clase, pasa por aquí antes de petar.
	 */
	public static function register() {
		// Apunto a includes/ como raíz del namespace — ahí vive todo.
		self::$base_dir = trailingslashit( WEBP_NC_PATH ) . 'includes/';
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Función que SPL invoca automáticamente cuando encuentra una clase desconocida.
	 * Traduce el nombre de clase a la ruta del archivo y lo carga si existe.
	 *
	 * Ejemplo: WebPNativeConverter\Core\Converter → includes/core/class-converter.php
	 *
	 * @param string $class Nombre completamente calificado de la clase.
	 */
	public static function autoload( $class ) {
		// Si la clase no empieza por nuestro prefijo, no es nuestra — dejamos pasar.
		$len = strlen( self::$prefix );
		if ( strncmp( self::$prefix, $class, $len ) !== 0 ) {
			return;
		}

		// Quitamos el prefijo del namespace para quedarnos con la parte relativa.
		$relative_class = substr( $class, $len );

		// Separamos los segmentos de namespace (Core, Admin, Utils...) del nombre de clase.
		$parts      = explode( '\\', $relative_class );
		$class_name = array_pop( $parts ); // El último trozo es el nombre de la clase.

		// Convertimos CamelCase a kebab-case con el prefijo "class-" de WP.
		// Ej: BatchProcessor → class-batch-processor.php
		$file_name = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) . '.php';

		// Los subdirectorios van en minúsculas: Core → core/, Admin → admin/...
		$sub_dirs = ! empty( $parts ) ? strtolower( implode( '/', $parts ) ) . '/' : '';

		$file = self::$base_dir . $sub_dirs . $file_name;

		// Solo cargamos si el archivo existe de verdad — sin excepciones silenciosas.
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Registrar inmediatamente al incluir este archivo.
Autoloader::register();
