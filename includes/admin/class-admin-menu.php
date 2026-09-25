<?php
/**
 * Panel de Administración y Configuración para WebP Native Converter.
 *
 * Renderiza el panel de control, tarjetas de estadísticas, advertencias de backup,
 * consola de procesamiento por lotes y comparador visual.
 *
 * @package WebPNativeConverter\Admin
 */

namespace WebPNativeConverter\Admin;

use WebPNativeConverter\Utils\SystemCheck;
use WebPNativeConverter\Core\BatchProcessor;
use WebPNativeConverter\Core\UnusedScanner;
use WebPNativeConverter\Core\MediaQuarantine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase AdminMenu
 */
class AdminMenu {

	/**
	 * Slug del menú de administración. Lo uso como constante para no escribirlo a mano en varios sitios.
	 */
	const MENU_SLUG = 'webp-native-converter';

	/**
	 * Instancia del procesador por lotes, inyectada desde Plugin::init_components().
	 * No la creo aquí para no registrar los hooks AJAX por segunda vez.
	 *
	 * @var BatchProcessor
	 */
	protected $batch_processor;

	/**
	 * Escáner de imágenes no usadas.
	 *
	 * @var UnusedScanner
	 */
	protected $unused_scanner;

	/**
	 * Gestor de cuarentena de adjuntos.
	 *
	 * @var MediaQuarantine
	 */
	protected $media_quarantine;

	/**
	 * Constructor.
	 * Recibe las instancias ya creadas — patrón de inyección de dependencia.
	 * Así evito que los hooks wp_ajax_* queden registrados dos veces.
	 *
	 * @param BatchProcessor   $batch_processor   Instancia compartida desde Plugin.
	 * @param UnusedScanner    $unused_scanner    Escáner de no usadas.
	 * @param MediaQuarantine  $media_quarantine  Cuarentena de archivos.
	 */
	public function __construct( BatchProcessor $batch_processor, UnusedScanner $unused_scanner, MediaQuarantine $media_quarantine ) {
		$this->batch_processor  = $batch_processor;
		$this->unused_scanner   = $unused_scanner;
		$this->media_quarantine = $media_quarantine;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_webp_nc_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Registra la página de administración del plugin en el menú de WordPress.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'WebP Converter', 'webp-native-converter' ),
			__( 'WebP Converter', 'webp-native-converter' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-images-alt2',
			68
		);
	}

	/**
	 * Encola estilos CSS y scripts JS solo en la página del plugin.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'webp-nc-admin-css',
			WEBP_NC_URL . 'assets/css/admin-style.css',
			array(),
			WEBP_NC_VERSION
		);

		wp_enqueue_script(
			'webp-nc-preview-slider',
			WEBP_NC_URL . 'assets/js/preview-slider.js',
			array( 'jquery' ),
			WEBP_NC_VERSION,
			true
		);

		wp_enqueue_script(
			'webp-nc-batch-js',
			WEBP_NC_URL . 'assets/js/batch-process.js',
			array( 'jquery' ),
			WEBP_NC_VERSION,
			true
		);

		// Localizar variables para AJAX
		$nonce = wp_create_nonce( 'webp_nc_admin_nonce' );

		wp_localize_script(
			'webp-nc-batch-js',
			'webpNcData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => $nonce,
				'i18n'    => array(
					'confirmBackup'    => __( 'Por favor, confirma que dispones de una copia de seguridad antes de continuar.', 'webp-native-converter' ),
					'processing'       => __( 'Procesando imágenes...', 'webp-native-converter' ),
					'completed'        => __( '¡Conversión por lotes completada con éxito!', 'webp-native-converter' ),
					'noImagesFound'    => __( 'No se encontraron imágenes pendientes de conversión.', 'webp-native-converter' ),
					'errorProcessing'  => __( 'Se produjeron errores durante el procesamiento.', 'webp-native-converter' ),
					'stopping'         => __( 'Deteniendo proceso...', 'webp-native-converter' ),
					'loadingPreview'   => __( 'Cargando listado de imágenes pendientes…', 'webp-native-converter' ),
					'reviewReady'      => __( 'Revisa el listado, desmarca las que no quieras convertir y pulsa Convertir seleccionadas.', 'webp-native-converter' ),
					'selectSome'       => __( 'Selecciona al menos una imagen.', 'webp-native-converter' ),
					'previewSummary'   => __( '%1$d seleccionadas · ahorro estimado %2$s', 'webp-native-converter' ),
					'foundPending'     => __( 'Encontradas %d imágenes pendientes.', 'webp-native-converter' ),
					'confirmCleanup'   => __( '¿Estás seguro de que quieres borrar de forma permanente todas las imágenes JPG y PNG que ya han sido convertidas a WebP? Esta acción no se puede deshacer y los archivos se eliminarán del disco duro.', 'webp-native-converter' ),
					'cleaning'         => __( 'Limpiando archivos...', 'webp-native-converter' ),
					'consoleCleared'   => __( 'Consola reiniciada.', 'webp-native-converter' ),
					'previewLoadError' => __( 'Error de conexión al cargar el listado de imágenes.', 'webp-native-converter' ),
					'queueLoadError'   => __( 'Error de conexión al obtener la cola de imágenes. Revisa tu internet o los logs de error de PHP.', 'webp-native-converter' ),
					'pausing'          => __( 'Pausando el procesamiento por solicitud del usuario...', 'webp-native-converter' ),
					'reviewCancelled'  => __( 'Revisión cancelada.', 'webp-native-converter' ),
					'pauseLabel'       => __( 'Pausar', 'webp-native-converter' ),
					'paused'           => __( 'Proceso en pausa. Puedes reanudarlo cuando desees sin perder el progreso (si no recargas la página).', 'webp-native-converter' ),
					'startingSelected' => __( 'Iniciando conversión de %d imágenes seleccionadas.', 'webp-native-converter' ),
					'progressStatus'   => __( 'Procesadas %1$d de %2$d imágenes...', 'webp-native-converter' ),
					'batchDone'        => __( 'Lote procesado: %1$d imágenes optimizadas (Ahorro: %2$s).', 'webp-native-converter' ),
					'batchWarn'        => __( 'Advertencia: %s', 'webp-native-converter' ),
					'batchFail'        => __( 'Fallo en el lote: %s', 'webp-native-converter' ),
					'ajaxRetry'        => __( 'Error AJAX al procesar el lote actual. Reintentando en 2 segundos...', 'webp-native-converter' ),
					'retry'            => __( 'Reintentar', 'webp-native-converter' ),
					'connectionError'  => __( 'Error de conexión o fallo interno de PHP (%s). Revisa los logs.', 'webp-native-converter' ),
					'cleanupDone'      => __( 'Completado', 'webp-native-converter' ),
					'cleanupLabel'     => __( 'Eliminar originales conservados', 'webp-native-converter' ),
					'freedExtra'       => __( '(Has recuperado %s)', 'webp-native-converter' ),
					'leaveWarning'     => __( 'Tienes una conversión masiva en progreso. Si sales de esta página, el proceso se detendrá.', 'webp-native-converter' ),
					'error'            => __( 'Error', 'webp-native-converter' ),
				),
			)
		);

		wp_enqueue_script(
			'webp-nc-unused-js',
			WEBP_NC_URL . 'assets/js/unused-media.js',
			array( 'jquery' ),
			WEBP_NC_VERSION,
			true
		);

		wp_localize_script(
			'webp-nc-unused-js',
			'webpNcUnused',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => $nonce,
				'quarantine' => array_values( $this->media_quarantine->get_index() ),
				'i18n'       => array(
					'confirmBackup'     => __( 'Por favor, confirma que dispones de una copia de seguridad antes de continuar.', 'webp-native-converter' ),
					'scanning'          => __( 'Escaneando la biblioteca…', 'webp-native-converter' ),
					'scanDone'          => __( 'Escaneo completado.', 'webp-native-converter' ),
					'noUnused'          => __( 'No se han encontrado imágenes candidatas a no usadas.', 'webp-native-converter' ),
					'scanError'         => __( 'Error durante el escaneo. Revisa los logs o vuelve a intentarlo.', 'webp-native-converter' ),
					'selectSome'        => __( 'Selecciona al menos una imagen.', 'webp-native-converter' ),
					'confirmQuarantine' => __( 'Las imágenes seleccionadas se moverán a una carpeta temporal (cuarentena). Podrás restaurarlas después. ¿Continuar?', 'webp-native-converter' ),
					'confirmRestore'    => __( '¿Restaurar esta imagen a la biblioteca de medios?', 'webp-native-converter' ),
					'confirmPurge'      => __( 'Esta acción borra la imagen de forma PERMANENTE (archivos y registro). No se puede deshacer. ¿Continuar?', 'webp-native-converter' ),
					'confirmRestoreAll' => __( '¿Restaurar las %d imágenes de cuarentena a la biblioteca?', 'webp-native-converter' ),
					'confirmPurgeAll'   => __( 'Esta acción borra PERMANENTEMENTE las %d imágenes de cuarentena (archivos y registro). No se puede deshacer. ¿Continuar?', 'webp-native-converter' ),
					'moving'            => __( 'Moviendo a cuarentena…', 'webp-native-converter' ),
					'restoringAll'      => __( 'Restaurando…', 'webp-native-converter' ),
					'purgingAll'        => __( 'Eliminando…', 'webp-native-converter' ),
					'emptyQuarantine'   => __( 'La cuarentena está vacía.', 'webp-native-converter' ),
					'restore'           => __( 'Restaurar', 'webp-native-converter' ),
					'purge'             => __( 'Eliminar definitivo', 'webp-native-converter' ),
					'restoreAll'        => __( 'Restaurar todas', 'webp-native-converter' ),
					'purgeAll'          => __( 'Eliminar todas', 'webp-native-converter' ),
					'selectedCount'     => __( '%d seleccionadas', 'webp-native-converter' ),
					'foundCount'        => __( '%d candidatas a no usadas', 'webp-native-converter' ),
					'leaveScanWarning'  => __( 'Hay un escaneo de imágenes en curso. Si sales, se interrumpirá.', 'webp-native-converter' ),
				),
			)
		);
	}

	/**
	 * Procesa y guarda los ajustes de configuración.
	 */
	public function handle_save_settings() {
		check_admin_referer( 'webp_nc_save_settings_action', 'webp_nc_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acceso denegado.', 'webp-native-converter' ) );
		}

		$quality           = min( 100, max( 1, webp_nc_get_posted_int( 'quality', 82 ) ) );
		$convert_on_upload = isset( $_POST['convert_on_upload'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$keep_originals    = isset( $_POST['keep_originals'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$settings = array(
			'quality'           => $quality,
			'convert_on_upload' => $convert_on_upload,
			'keep_originals'    => $keep_originals,
		);

		update_option( 'webp_nc_settings', $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renderiza el panel de administración.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acceso denegado.', 'webp-native-converter' ) );
		}

		$system_status = SystemCheck::get_system_status();
		$settings      = get_option(
			'webp_nc_settings',
			array(
				'quality'           => 82,
				'convert_on_upload' => 1,
				'keep_originals'    => 1,
			)
		);
		$stats = get_option(
			'webp_nc_stats',
			array(
				'total_converted' => 0,
				'total_saved'     => 0,
			)
		);

		// Uso la instancia inyectada — no creo una nueva para no duplicar hooks AJAX.
		$pending_ids   = $this->batch_processor->get_pending_attachment_ids();
		$total_pending = count( $pending_ids );
		?>
		<div class="wrap webp-nc-dashboard">
			<!-- Cabecera y Título -->
			<header class="webp-nc-header">
				<div class="webp-nc-title-area">
					<h1>
						<span class="dashicons dashicons-images-alt2"></span>
						WebP Native Converter
					</h1>
					<span class="webp-nc-version-badge">v<?php echo esc_html( WEBP_NC_VERSION ); ?></span>
					<span class="webp-nc-free-badge"><?php esc_html_e( '100% Libre & Ilimitado', 'webp-native-converter' ); ?></span>
				</div>
				<p class="webp-nc-subtitle">
					<?php esc_html_e( 'Optimización y migración nativa a WebP directamente en tu servidor. Sin límites mensuales ni costes ocultos.', 'webp-native-converter' ); ?>
				</p>
			</header>

			<?php
			$settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
			if ( 'true' === $settings_updated ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Ajustes guardados correctamente.', 'webp-native-converter' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Tarjetas de Estadísticas Principales -->
			<div class="webp-nc-stats-grid">
				<div class="webp-nc-stat-card">
					<div class="webp-nc-stat-icon dashicons dashicons-yes-alt"></div>
					<div class="webp-nc-stat-content">
						<span class="webp-nc-stat-value" id="stat-total-converted"><?php echo esc_html( number_format_i18n( $stats['total_converted'] ) ); ?></span>
						<span class="webp-nc-stat-label"><?php esc_html_e( 'Imágenes Convertidas', 'webp-native-converter' ); ?></span>
					</div>
				</div>

				<div class="webp-nc-stat-card">
					<div class="webp-nc-stat-icon dashicons dashicons-chart-area"></div>
					<div class="webp-nc-stat-content">
						<span class="webp-nc-stat-value" id="stat-total-saved"><?php echo esc_html( size_format( $stats['total_saved'] ) ); ?></span>
						<span class="webp-nc-stat-label"><?php esc_html_e( 'Espacio en Disco Ahorrado', 'webp-native-converter' ); ?></span>
					</div>
				</div>

				<div class="webp-nc-stat-card">
					<div class="webp-nc-stat-icon dashicons dashicons-clock"></div>
					<div class="webp-nc-stat-content">
						<span class="webp-nc-stat-value" id="stat-total-pending"><?php echo esc_html( number_format_i18n( $total_pending ) ); ?></span>
						<span class="webp-nc-stat-label"><?php esc_html_e( 'Imágenes Pendientes', 'webp-native-converter' ); ?></span>
					</div>
				</div>

				<div class="webp-nc-stat-card">
					<div class="webp-nc-stat-icon dashicons dashicons-admin-generic"></div>
					<div class="webp-nc-stat-content">
						<span class="webp-nc-stat-value"><?php echo esc_html( strtoupper( $system_status['engine'] ) ); ?></span>
						<span class="webp-nc-stat-label"><?php esc_html_e( 'Motor Gráfico Activo', 'webp-native-converter' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Grid Principal: Columna Izquierda (Acción) + Columna Derecha (Ajustes y Entorno) -->
			<div class="webp-nc-main-layout">

				<!-- COLUMNA PRINCIPAL -->
				<div class="webp-nc-main-col">

					<!-- CAJA DE ADVERTENCIA Y CONFIRMACIÓN DE COPIA DE SEGURIDAD -->
					<div class="webp-nc-card webp-nc-warning-card">
						<div class="webp-nc-alert-header">
							<span class="dashicons dashicons-warning"></span>
							<h3><?php esc_html_e( 'Copia de Seguridad Obligatoria', 'webp-native-converter' ); ?></h3>
						</div>
						<p>
							<?php esc_html_e( 'Este proceso convertirá imágenes en /uploads/ y actualizará de manera definitiva las referencias en la base de datos (entradas, metadatos y constructores visuales). Antes de proceder, es indispensable contar con un respaldo completo.', 'webp-native-converter' ); ?>
						</p>
						<label class="webp-nc-checkbox-label webp-nc-backup-checkbox">
							<input type="checkbox" id="webp_nc_backup_check" value="1">
							<strong><?php esc_html_e( 'Confirmo que he realizado una copia de seguridad completa de los archivos y la base de datos.', 'webp-native-converter' ); ?></strong>
						</label>
					</div>

					<!-- PROCESADOR POR LOTES -->
					<div class="webp-nc-card webp-nc-batch-card">
						<h3>
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Conversión Masiva por Lotes', 'webp-native-converter' ); ?>
						</h3>
						<p>
							<?php esc_html_e( 'Convierte toda tu biblioteca de medios existente a WebP de forma fluida y sin sobrecargar la memoria del servidor.', 'webp-native-converter' ); ?>
						</p>

						<div class="webp-nc-batch-controls">
							<button type="button" class="button button-primary button-hero" id="btn-start-batch" <?php disabled( ! $system_status['can_convert'] ); ?>>
								<span class="dashicons dashicons-controls-play"></span>
								<span class="webp-nc-btn-label"><?php esc_html_e( 'Comenzar Conversión por Lotes', 'webp-native-converter' ); ?></span>
							</button>

							<button type="button" class="button button-secondary button-hero" id="btn-stop-batch" style="display: none;">
								<span class="dashicons dashicons-controls-pause"></span>
								<span class="webp-nc-btn-label"><?php esc_html_e( 'Pausar', 'webp-native-converter' ); ?></span>
							</button>
						</div>

						<div id="batch-preview" class="webp-nc-batch-preview" style="display: none;">
							<p class="description">
								<?php esc_html_e( 'El ahorro es una estimación (el real se calcula al convertir). Todas vienen marcadas; desmarca las que no quieras tocar.', 'webp-native-converter' ); ?>
							</p>
							<div class="webp-nc-unused-toolbar">
								<label>
									<input type="checkbox" id="batch-select-all" checked>
									<?php esc_html_e( 'Seleccionar todas', 'webp-native-converter' ); ?>
								</label>
								<span id="batch-preview-summary" class="description"></span>
							</div>
							<div class="webp-nc-unused-table-wrap has-results">
								<table class="widefat striped webp-nc-unused-table" id="batch-preview-table">
									<thead>
										<tr>
											<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Seleccionar', 'webp-native-converter' ); ?></span></td>
											<th><?php esc_html_e( 'Imagen', 'webp-native-converter' ); ?></th>
											<th><?php esc_html_e( 'Archivo', 'webp-native-converter' ); ?></th>
											<th><?php esc_html_e( 'Peso actual', 'webp-native-converter' ); ?></th>
											<th><?php esc_html_e( 'Ahorro estimado', 'webp-native-converter' ); ?></th>
										</tr>
									</thead>
									<tbody></tbody>
								</table>
							</div>
							<div class="webp-nc-batch-controls">
								<button type="button" class="button button-primary" id="btn-convert-selected">
									<span class="dashicons dashicons-yes"></span>
									<span class="webp-nc-btn-label"><?php esc_html_e( 'Convertir seleccionadas', 'webp-native-converter' ); ?></span>
								</button>
								<button type="button" class="button" id="btn-cancel-preview">
									<?php esc_html_e( 'Cancelar', 'webp-native-converter' ); ?>
								</button>
							</div>
						</div>

						<!-- Barra de progreso -->
						<div class="webp-nc-progress-wrapper" id="batch-progress-wrapper" style="display: none;">
							<div class="webp-nc-progress-bar-container">
								<div class="webp-nc-progress-bar" id="batch-progress-bar" style="width: 0%;"></div>
							</div>
							<div class="webp-nc-progress-status">
								<span id="batch-status-text"><?php esc_html_e( 'Iniciando...', 'webp-native-converter' ); ?></span>
								<span id="batch-percent-text">0%</span>
							</div>
						</div>

						<!-- Terminal / Consola de eventos -->
						<div class="webp-nc-console-wrapper">
							<div class="webp-nc-console-header">
								<span><?php esc_html_e( 'Registro en tiempo real', 'webp-native-converter' ); ?></span>
								<button type="button" class="button button-small" id="btn-clear-console"><?php esc_html_e( 'Limpiar consola', 'webp-native-converter' ); ?></button>
							</div>
							<div class="webp-nc-console" id="batch-console">
								<div class="webp-nc-console-line text-muted"><?php esc_html_e( 'Listo para iniciar el proceso.', 'webp-native-converter' ); ?></div>
							</div>
						</div>
					</div>

					<!-- DETECTOR DE IMÁGENES NO USADAS -->
					<div class="webp-nc-card webp-nc-unused-card">
						<h3>
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Imágenes no usadas', 'webp-native-converter' ); ?>
						</h3>
						<p>
							<?php esc_html_e( 'Busca adjuntos de la biblioteca que no aparecen referenciados en contenido, metadatos, CSS generado (Elementor/Bricks) ni en el tema activo. El listado es una propuesta: nunca es 100% fiable.', 'webp-native-converter' ); ?>
						</p>

						<div class="webp-nc-unused-disclaimer">
							<span class="dashicons dashicons-info"></span>
							<div>
								<strong><?php esc_html_e( 'Si hay duda, se considera usada.', 'webp-native-converter' ); ?></strong>
								<?php esc_html_e( 'No se detectan tablas propias de sliders, JS de temas ni CDNs con otra URL. Las imágenes no se borran: se mueven a /uploads/webp-nc-quarantine/ y se pueden restaurar.', 'webp-native-converter' ); ?>
							</div>
						</div>

						<div class="webp-nc-batch-controls">
							<button type="button" class="button button-primary" id="btn-unused-scan">
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Escanear biblioteca', 'webp-native-converter' ); ?>
							</button>
							<button type="button" class="button button-secondary" id="btn-unused-quarantine" disabled>
								<span class="dashicons dashicons-migrate"></span>
								<?php esc_html_e( 'Mover seleccionadas a cuarentena', 'webp-native-converter' ); ?>
							</button>
						</div>

						<div class="webp-nc-progress-wrapper" id="unused-progress-wrapper" style="display: none;">
							<div class="webp-nc-progress-bar-container">
								<div class="webp-nc-progress-bar" id="unused-progress-bar" style="width: 0%;"></div>
							</div>
							<div class="webp-nc-progress-status">
								<span id="unused-status-text"><?php esc_html_e( 'Iniciando…', 'webp-native-converter' ); ?></span>
								<span id="unused-percent-text">0%</span>
							</div>
						</div>

						<div id="unused-toolbar" class="webp-nc-unused-toolbar" style="display: none;">
							<label>
								<input type="checkbox" id="unused-select-all">
								<?php esc_html_e( 'Seleccionar todas', 'webp-native-converter' ); ?>
							</label>
							<span id="unused-summary" class="description"></span>
						</div>

						<div id="unused-results" class="webp-nc-unused-table-wrap">
							<p class="description" id="unused-empty-hint"><?php esc_html_e( 'Pulsa “Escanear biblioteca” para obtener candidatas.', 'webp-native-converter' ); ?></p>
							<table class="widefat striped webp-nc-unused-table" id="unused-results-table" style="display: none;">
								<thead>
									<tr>
										<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Seleccionar', 'webp-native-converter' ); ?></span></td>
										<th><?php esc_html_e( 'Imagen', 'webp-native-converter' ); ?></th>
										<th><?php esc_html_e( 'Archivo', 'webp-native-converter' ); ?></th>
										<th><?php esc_html_e( 'Peso', 'webp-native-converter' ); ?></th>
										<th><?php esc_html_e( 'Fecha', 'webp-native-converter' ); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>

						<hr class="webp-nc-unused-divider">

						<h3>
							<span class="dashicons dashicons-portfolio"></span>
							<?php esc_html_e( 'Cuarentena', 'webp-native-converter' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'Archivos movidos fuera de la biblioteca. Restaurar los devuelve a su ruta original. Eliminar borra disco y registro.', 'webp-native-converter' ); ?>
						</p>
						<div class="webp-nc-quarantine-toolbar" id="quarantine-toolbar">
							<button type="button" class="button" id="btn-quarantine-restore-all" disabled>
								<?php esc_html_e( 'Restaurar todas', 'webp-native-converter' ); ?>
							</button>
							<button type="button" class="button" id="btn-quarantine-purge-all" disabled>
								<?php esc_html_e( 'Eliminar todas', 'webp-native-converter' ); ?>
							</button>
						</div>
						<div id="quarantine-list" class="webp-nc-quarantine-list"></div>
					</div>

					<!-- COMPARADOR VISUAL (PREVIEW SLIDER) -->
					<div class="webp-nc-card webp-nc-preview-card">
						<h3>
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( 'Comparador Visual (Antes / Después)', 'webp-native-converter' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'Comprueba la fidelidad visual y compresión del formato WebP frente a formatos tradicionales.', 'webp-native-converter' ); ?>
						</p>

						<div class="webp-nc-slider-container" id="webp-preview-slider">
							<div class="webp-nc-slider-image webp-nc-image-after">
								<!-- Simulación de WebP optimizado -->
								<div class="webp-nc-demo-visual webp-nc-demo-webp">
									<div class="webp-nc-badge-tag tag-webp">WebP (Optimizado - 78% menos)</div>
								</div>
							</div>
							<div class="webp-nc-slider-image webp-nc-image-before">
								<!-- Simulación de imagen original -->
								<div class="webp-nc-demo-visual webp-nc-demo-orig">
									<div class="webp-nc-badge-tag tag-orig">Original (JPEG/PNG)</div>
								</div>
							</div>
							<div class="webp-nc-slider-handle">
								<span class="dashicons dashicons-leftright"></span>
							</div>
						</div>
					</div>

				</div>

				<!-- COLUMNA LATERAL (AJUSTES Y ENTORNO) -->
				<div class="webp-nc-sidebar-col">

					<!-- CONFIGURACIÓN DEL MOTOR -->
					<div class="webp-nc-card">
						<h3>
							<span class="dashicons dashicons-admin-settings"></span>
							<?php esc_html_e( 'Configuración de Conversión', 'webp-native-converter' ); ?>
						</h3>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="webp_nc_save_settings">
							<?php wp_nonce_field( 'webp_nc_save_settings_action', 'webp_nc_settings_nonce' ); ?>

							<!-- Calidad de compresión -->
							<div class="webp-nc-field-group">
								<label for="webp-quality-range">
									<strong><?php esc_html_e( 'Calidad de compresión:', 'webp-native-converter' ); ?></strong>
									<span id="quality-val-badge" class="badge"><?php echo esc_html( $settings['quality'] ); ?>%</span>
								</label>
								<input type="range" id="webp-quality-range" name="quality" min="1" max="100" value="<?php echo esc_attr( $settings['quality'] ); ?>" class="webp-nc-range" oninput="document.getElementById('quality-val-badge').innerText = this.value + '%';">
								<p class="description">
									<?php esc_html_e( '80-85% ofrece el equilibrio idóneo entre nitidez visual y máxima reducción de peso.', 'webp-native-converter' ); ?>
								</p>
							</div>

							<hr>

							<!-- Opciones booleanas -->
							<div class="webp-nc-field-group">
								<label class="webp-nc-checkbox-label">
									<input type="checkbox" name="convert_on_upload" value="1" <?php checked( 1, $settings['convert_on_upload'] ); ?>>
									<?php esc_html_e( 'Convertir automáticamente al subir nuevas imágenes', 'webp-native-converter' ); ?>
								</label>
							</div>

							<div class="webp-nc-field-group">
								<label class="webp-nc-checkbox-label">
									<input type="checkbox" name="keep_originals" value="1" <?php checked( 1, $settings['keep_originals'] ); ?>>
									<?php esc_html_e( 'Conservar archivos JPG/PNG originales en disco', 'webp-native-converter' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Desmarcar para liberar espacio eliminando el archivo original una vez convertido a WebP.', 'webp-native-converter' ); ?>
								</p>
							</div>

							<div class="webp-nc-submit-area">
								<?php submit_button( __( 'Guardar Configuración', 'webp-native-converter' ), 'primary', 'submit', false ); ?>
							</div>
						</form>
					</div>

					<!-- LIBERADOR DE ESPACIO -->
					<div class="webp-nc-card webp-nc-cleanup-card">
						<h3>
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Liberador de Espacio', 'webp-native-converter' ); ?>
						</h3>
						<p class="description" style="margin-bottom: 15px;">
							<?php esc_html_e( 'Si decidiste conservar los archivos originales pero ahora necesitas liberar espacio en el disco, pulsa el botón para buscar y eliminar las imágenes JPG/PNG que ya se convirtieron a WebP con éxito.', 'webp-native-converter' ); ?>
						</p>
						<button type="button" class="button button-secondary" id="btn-cleanup-originals" style="color: #d63638; border-color: #d63638;">
							<?php esc_html_e( 'Eliminar originales conservados', 'webp-native-converter' ); ?>
						</button>
						<p id="cleanup-status" style="margin-top: 10px; font-weight: 600; display: none;"></p>
					</div>

					<!-- ESTADO DEL SISTEMA -->
					<div class="webp-nc-card webp-nc-system-card">
						<h3>
							<span class="dashicons dashicons-dashboard"></span>
							<?php esc_html_e( 'Estado del Servidor', 'webp-native-converter' ); ?>
						</h3>

						<ul class="webp-nc-sysinfo-list">
							<li>
								<span><?php esc_html_e( 'PHP Version:', 'webp-native-converter' ); ?></span>
								<strong><?php echo esc_html( $system_status['php_version'] ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( 'Límite de Memoria:', 'webp-native-converter' ); ?></span>
								<strong><?php echo esc_html( $system_status['memory_info']['formatted'] ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( 'Librería Imagick (WebP):', 'webp-native-converter' ); ?></span>
								<?php if ( $system_status['has_imagick'] ) : ?>
									<span class="badge badge-success"><?php esc_html_e( 'Disponible', 'webp-native-converter' ); ?></span>
								<?php else : ?>
									<span class="badge badge-secondary"><?php esc_html_e( 'Opcional — no instalada', 'webp-native-converter' ); ?></span>
								<?php endif; ?>
							</li>
							<li>
								<span><?php esc_html_e( 'Librería GD (WebP):', 'webp-native-converter' ); ?></span>
								<?php if ( $system_status['has_gd'] ) : ?>
									<span class="badge badge-success"><?php esc_html_e( 'Disponible', 'webp-native-converter' ); ?></span>
								<?php else : ?>
									<span class="badge badge-danger"><?php esc_html_e( 'No detectada', 'webp-native-converter' ); ?></span>
								<?php endif; ?>
							</li>
							<li>
								<span><?php esc_html_e( 'Escritura en /uploads/:', 'webp-native-converter' ); ?></span>
								<?php if ( $system_status['is_writable'] ) : ?>
									<span class="badge badge-success"><?php esc_html_e( 'Correcto', 'webp-native-converter' ); ?></span>
								<?php else : ?>
									<span class="badge badge-danger"><?php esc_html_e( 'Sin permisos', 'webp-native-converter' ); ?></span>
								<?php endif; ?>
							</li>
						</ul>
					</div>

					<!-- ATRIBUCIÓN Y CRÉDITOS DEL AUTOR -->
					<div class="webp-nc-card webp-nc-author-card">
						<div class="webp-nc-author-avatar">
							<span class="dashicons dashicons-heart"></span>
						</div>
						<h4><?php esc_html_e( 'Desarrollado con cariño por', 'webp-native-converter' ); ?></h4>
						<p class="webp-nc-author-name">Jose Antonio Abellán</p>
						<p class="webp-nc-author-role">
							<?php esc_html_e( 'Consultor SEO y desarrollador WordPress especializado en SEO local.', 'webp-native-converter' ); ?>
						</p>
						<p class="webp-nc-author-desc">
							<?php esc_html_e( 'Plugin 100% gratuito cedido a la comunidad de WordPress.', 'webp-native-converter' ); ?>
							<br>
							<?php esc_html_e( 'Sin modelos freemium, suscripciones ni límites mensuales.', 'webp-native-converter' ); ?>
						</p>
						<div class="webp-nc-author-links">
							<a href="https://joseabellan.net" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<span class="dashicons dashicons-admin-site"></span>
								<?php esc_html_e( 'Conoce mis servicios', 'webp-native-converter' ); ?>
							</a>
							<a href="https://github.com/joseabellannet" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<span class="dashicons dashicons-external"></span>
								<?php esc_html_e( 'GitHub', 'webp-native-converter' ); ?>
							</a>
						</div>
					</div>

				</div>

			</div>
		</div>
		<?php
	}
}
