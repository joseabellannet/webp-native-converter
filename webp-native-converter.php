<?php
/**
 * Plugin Name:       WebP Native Converter
 * Plugin URI:        https://github.com/joseabellannet/webp-native-converter
 * Description:       Conversión nativa, local e ilimitada de imágenes (JPG, JPEG, PNG) a formato WebP directamente en el servidor con actualización segura en la base de datos.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jose Antonio Abellán
 * Author URI:        https://joseabellan.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webp-native-converter
 * Domain Path:       /languages
 */

// Seguridad básica — si alguien intenta abrir este archivo directamente desde el navegador, fuera de WordPress, que no vea nada.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Evita un fatal si hay dos copias en /plugins/ (p.ej. la carpeta original y el ZIP de GitHub `webp-native-converter-main`).
if ( defined( 'WEBP_NC_VERSION' ) ) {
	return;
}

// Constantes globales del plugin. Las uso en todos los archivos para no repetir rutas a mano.
define( 'WEBP_NC_VERSION', '1.0.2' );
define( 'WEBP_NC_FILE', __FILE__ );
define( 'WEBP_NC_PATH', plugin_dir_path( __FILE__ ) ); // Ruta absoluta en disco, con trailing slash.
define( 'WEBP_NC_URL', plugin_dir_url( __FILE__ ) );   // URL pública para assets CSS/JS.
define( 'WEBP_NC_BASENAME', plugin_basename( __FILE__ ) ); // Necesario para el enlace "Ajustes" en la lista de plugins.

/**
 * IDs enteros enviados por POST (tras nonce en el caller).
 *
 * @param string $key Clave de $_POST.
 * @return int[]
 */
function webp_nc_get_posted_ids( $key ) {
	if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return array();
	}

	$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return array_values( array_filter( array_map( 'absint', (array) $raw ) ) );
}

/**
 * Entero enviado por POST (tras nonce en el caller).
 *
 * @param string $key     Clave de $_POST.
 * @param int    $default
 * @return int
 */
function webp_nc_get_posted_int( $key, $default = 0 ) {
	if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return absint( $default );
	}

	return absint( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/**
 * Carga el autoloader PSR-4 que mapea clases a archivos automáticamente.
 * Sin esto, habría que hacer require_once manualmente de cada clase — un infierno.
 */
require_once WEBP_NC_PATH . 'includes/class-autoloader.php';

/**
 * Comprueba si el servidor cumple los requisitos mínimos antes de cargar nada más.
 * Si PHP o WordPress están desactualizados, muestro un aviso en el panel y paro aquí.
 *
 * @return bool True si todo está bien, false si hay que abortar.
 */
function webp_nc_check_requirements() {
	$php_min_version = '7.4';
	$wp_min_version  = '6.0';

	// Comprobación de versión de PHP.
	if ( version_compare( PHP_VERSION, $php_min_version, '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $php_min_version ) {
				printf(
					'<div class="notice notice-error"><p><strong>WebP Native Converter:</strong> %s</p></div>',
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						esc_html__( 'Requiere PHP versión %1$s o superior. Versión actual detectada: %2$s.', 'webp-native-converter' ),
						esc_html( $php_min_version ),
						esc_html( PHP_VERSION )
					)
				);
			}
		);
		return false;
	}

	// Comprobación de versión de WordPress.
	global $wp_version;
	if ( version_compare( $wp_version, $wp_min_version, '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $wp_min_version, $wp_version ) {
				printf(
					'<div class="notice notice-error"><p><strong>WebP Native Converter:</strong> %s</p></div>',
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						esc_html__( 'Requiere WordPress versión %1$s o superior. Versión actual detectada: %2$s.', 'webp-native-converter' ),
						esc_html( $wp_min_version ),
						esc_html( $wp_version )
					)
				);
			}
		);
		return false;
	}

	return true;
}

/**
 * Hook de activación: guardo los valores por defecto solo la primera vez.
 * Uso add_option en vez de update_option para no machacar ajustes que ya existan
 * si el usuario reactiva el plugin después de haberlo configurado.
 */
register_activation_hook(
	__FILE__,
	function () {
		$default_settings = array(
			'quality'           => 82,  // 82% es el punto dulce entre calidad y peso para WebP.
			'convert_on_upload' => 1,   // Activado por defecto — cada nueva subida se convierte al vuelo.
			'keep_originals'    => 1,   // Por seguridad mantengo los originales hasta que el usuario decida borrarlos.
		);

		// Si ya existe la opción (p.ej. reactivación), la respeto tal cual.
		if ( false === get_option( 'webp_nc_settings' ) ) {
			add_option( 'webp_nc_settings', $default_settings );
		}

		// Inicializar también las estadísticas globales si no existen.
		if ( false === get_option( 'webp_nc_stats' ) ) {
			add_option( 'webp_nc_stats', array( 'total_converted' => 0, 'total_saved' => 0 ) );
		}
	}
);

/**
 * Hook de desactivación: no borro datos aquí, solo en uninstall.php.
 * Así el usuario puede desactivar/reactivar sin perder su configuración.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		// De momento nada que limpiar al desactivar.
		// Si en el futuro añado cron jobs o transients de larga duración, los eliminaría aquí.
	}
);

/**
 * Punto de entrada principal del plugin, enganchado a plugins_loaded.
 * Primero compruebo requisitos y si todo está bien lanzo el Singleton de Plugin.
 */
function webp_nc_init() {
	// Si el servidor no cumple los mínimos, paro aquí. Los avisos ya los habrá puesto check_requirements().
	if ( ! webp_nc_check_requirements() ) {
		return;
	}

	// Inicializo la clase orquestadora. Usa Singleton para evitar dobles instanciaciones.
	\WebPNativeConverter\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'webp_nc_init' );
