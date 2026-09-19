<?php
/**
 * Orquestador principal del plugin WebP Native Converter.
 *
 * Implementa el patrón Singleton para asegurar que cada módulo
 * se inicializa exactamente una vez, sin instanciaciones duplicadas.
 *
 * @package WebPNativeConverter
 */

namespace WebPNativeConverter;

use WebPNativeConverter\Core\MediaUpload;
use WebPNativeConverter\Core\BatchProcessor;
use WebPNativeConverter\Core\UnusedScanner;
use WebPNativeConverter\Core\MediaQuarantine;
use WebPNativeConverter\Admin\AdminMenu;
use WebPNativeConverter\Admin\MediaColumns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase Plugin — el corazón del plugin.
 * "final" para evitar que alguien la extienda accidentalmente.
 */
final class Plugin {

	/**
	 * La única instancia que debe existir de esta clase.
	 * Si alguien llama a get_instance() dos veces, devuelve la misma.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Módulo de conversión automática en nuevas subidas.
	 *
	 * @var MediaUpload
	 */
	public $media_upload;

	/**
	 * Módulo de procesamiento por lotes vía AJAX.
	 * Lo guardo aquí para que AdminMenu pueda reutilizarlo sin instanciarlo de nuevo.
	 *
	 * @var BatchProcessor
	 */
	public $batch_processor;

	/**
	 * Escáner de imágenes no usadas.
	 *
	 * @var UnusedScanner
	 */
	public $unused_scanner;

	/**
	 * Cuarentena de adjuntos no usados.
	 *
	 * @var MediaQuarantine
	 */
	public $media_quarantine;

	/**
	 * Panel de control en wp-admin.
	 *
	 * @var AdminMenu
	 */
	public $admin_menu;

	/**
	 * Columna extra en la biblioteca de medios.
	 *
	 * @var MediaColumns
	 */
	public $media_columns;

	/**
	 * Punto de acceso al Singleton. Si no existe la instancia, la crea; si existe, la devuelve.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado — nadie puede hacer "new Plugin()" desde fuera.
	 * Eso garantiza que solo existe una instancia en toda la ejecución.
	 */
	private function __construct() {
		$this->init_hooks();
		$this->init_components();
	}

	/**
	 * Registra los hooks globales que no dependen de ningún módulo concreto.
	 */
	private function init_hooks() {
		// Cargar traducciones lo antes posible en el ciclo de vida de WP.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Enlace rápido a "Ajustes" en la fila del plugin en la pantalla de plugins instalados.
		add_filter( 'plugin_action_links_' . WEBP_NC_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Instancia cada módulo del plugin.
	 *
	 * MediaUpload, BatchProcessor, UnusedScanner y MediaQuarantine corren siempre
	 * porque sus endpoints AJAX tienen que estar registrados también en admin-ajax.php.
	 * AdminMenu y MediaColumns solo en el panel de administración.
	 */
	private function init_components() {
		// Estos módulos tienen que estar siempre activos porque los endpoints AJAX
		// se registran en el constructor y WordPress los necesita en admin-ajax.php.
		$this->media_upload     = new MediaUpload();
		$this->batch_processor  = new BatchProcessor();
		$this->unused_scanner   = new UnusedScanner();
		$this->media_quarantine = new MediaQuarantine();

		// El panel y la columna de medios solo en admin.
		// AdminMenu recibe las instancias ya creadas para no duplicar hooks AJAX.
		if ( is_admin() ) {
			$this->admin_menu    = new AdminMenu( $this->batch_processor, $this->unused_scanner, $this->media_quarantine );
			$this->media_columns = new MediaColumns();
		}
	}

	/**
	 * Carga el archivo de traducciones .mo desde /languages/.
	 * Si no existe el archivo, WP simplemente muestra los textos originales — sin errores.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'webp-native-converter',
			false,
			dirname( WEBP_NC_BASENAME ) . '/languages'
		);
	}

	/**
	 * Añade el enlace "Ajustes" a la fila del plugin en la lista de plugins instalados.
	 * Es un detalle pequeño pero hace la UX mucho más cómoda.
	 *
	 * @param array $links Array de enlaces existentes que WP ya pone (Desactivar, etc.).
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$settings_url  = admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG );
		$settings_link = sprintf( '<a href="%s">%s</a>', esc_url( $settings_url ), esc_html__( 'Ajustes', 'webp-native-converter' ) );

		// Lo pongo al principio del array para que aparezca a la izquierda.
		array_unshift( $links, $settings_link );
		return $links;
	}
}
