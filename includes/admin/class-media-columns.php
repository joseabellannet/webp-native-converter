<?php
/**
 * Columnas e indicadores visuales en la Biblioteca de Medios de WordPress.
 *
 * Muestra el estado de conversión, peso original vs WebP, porcentaje de ahorro
 * y botón para procesar de forma individual.
 *
 * @package WebPNativeConverter\Admin
 */

namespace WebPNativeConverter\Admin;

use WebPNativeConverter\Core\Converter;
use WebPNativeConverter\Core\DbReplacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase MediaColumns
 */
class MediaColumns {

	/**
	 * Constructor: registra los hooks necesarios en la biblioteca de medios.
	 * Solo añado columna en list view (upload.php) — en el modal de medios no aparece.
	 */
	public function __construct() {
		add_filter( 'manage_media_columns', array( $this, 'add_column_header' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column_content' ), 10, 2 );
		add_action( 'wp_ajax_webp_nc_convert_single', array( $this, 'ajax_convert_single' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_assets' ) );
	}

	/**
	 * Encola scripts específicos si estamos en la vista de lista de la biblioteca de medios.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_media_assets( $hook_suffix ) {
		if ( 'upload.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'webp-nc-admin-css',
			WEBP_NC_URL . 'assets/css/admin-style.css',
			array(),
			WEBP_NC_VERSION
		);

		wp_enqueue_script(
			'webp-nc-media-column-js',
			WEBP_NC_URL . 'assets/js/batch-process.js',
			array( 'jquery' ),
			WEBP_NC_VERSION,
			true
		);

		wp_localize_script(
			'webp-nc-media-column-js',
			'webpNcData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'webp_nc_admin_nonce' ),
				'i18n'    => array(
					'processing'      => __( 'Convirtiendo...', 'webp-native-converter' ),
					'success'         => __( '¡Convertido!', 'webp-native-converter' ),
					'error'           => __( 'Error', 'webp-native-converter' ),
					'retry'           => __( 'Reintentar', 'webp-native-converter' ),
					'connectionError' => __( 'Error de conexión o fallo interno de PHP (%s). Revisa los logs.', 'webp-native-converter' ),
				),
			)
		);
	}

	/**
	 * Agrega la cabecera de la columna WebP en la tabla de medios.
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_column_header( $columns ) {
		$columns['webp_nc_status'] = __( 'WebP Native', 'webp-native-converter' );
		return $columns;
	}

	/**
	 * Renderiza la celda de estado para cada imagen.
	 *
	 * @param string $column_name
	 * @param int    $attachment_id
	 */
	public function render_column_content( $column_name, $attachment_id ) {
		if ( 'webp_nc_status' !== $column_name ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		$is_image = in_array( $mime, array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' ), true );

		if ( ! $is_image ) {
			echo '<span class="text-muted">—</span>';
			return;
		}

		$is_converted = get_post_meta( $attachment_id, '_webp_nc_converted', true );

		if ( $is_converted || 'image/webp' === $mime ) {
			$orig_size  = absint( get_post_meta( $attachment_id, '_webp_nc_original_size', true ) );
			$webp_size  = absint( get_post_meta( $attachment_id, '_webp_nc_webp_size', true ) );
			$saved_byte = absint( get_post_meta( $attachment_id, '_webp_nc_saved_bytes', true ) );

			echo '<div class="webp-nc-col-badge webp-nc-badge-success">';
			echo '<span class="dashicons dashicons-yes"></span> <strong>WebP</strong>';
			echo '</div>';

			if ( $orig_size > 0 && $webp_size > 0 ) {
				$saved_pct = round( ( $saved_byte / $orig_size ) * 100 );
				echo '<div class="webp-nc-col-details">';
				printf( '<small>%s &rarr; %s</small><br>', esc_html( size_format( $orig_size ) ), esc_html( size_format( $webp_size ) ) );
				printf( '<span class="webp-nc-saving-tag">-%d%% ahorro</span>', esc_html( $saved_pct ) );
				echo '</div>';
			}
		} else {
			echo '<div class="webp-nc-col-badge webp-nc-badge-pending">';
			echo '<span class="dashicons dashicons-clock"></span> ' . esc_html__( 'Pendiente', 'webp-native-converter' );
			echo '</div>';

			printf(
				'<button type="button" class="button button-small webp-nc-quick-convert" data-id="%s">%s</button>',
				esc_attr( (string) absint( $attachment_id ) ),
				esc_html__( 'Convertir a WebP', 'webp-native-converter' )
			);
		}
	}

	/**
	 * AJAX: Conversión individual inmediata desde el botón de la columna de medios.
	 *
	 * Convierte la imagen principal y todos sus thumbnails, actualiza la BD
	 * y devuelve las estadísticas de ahorro para actualizar la UI sin recargar.
	 */
	public function ajax_convert_single() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		// Doble verificación de permisos — nonce + capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'webp-native-converter' ) ), 403 );
			wp_die(); // Garantiza que la ejecución para aquí en cualquier versión de WP.
		}

		$attachment_id = webp_nc_get_posted_int( 'attachment_id' );
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de imagen inválido.', 'webp-native-converter' ) ) );
			wp_die();
		}

		$upload_dir    = wp_upload_dir();
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( empty( $attached_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Archivo no encontrado en la base de datos.', 'webp-native-converter' ) ) );
			wp_die();
		}

		$converter   = new Converter();
		$db_replacer = new DbReplacer();
		$settings    = get_option( 'webp_nc_settings', array() );
		$keep_orig   = ! empty( $settings['keep_originals'] );

		$full_path = path_join( $upload_dir['basedir'], $attached_file );
		$base_dir  = dirname( $full_path );

		// Compruebo que el archivo realmente existe en disco antes de intentar nada.
		if ( ! file_exists( $full_path ) ) {
			wp_send_json_error( array( 'message' => __( 'El archivo de imagen no existe en disco.', 'webp-native-converter' ) ) );
			wp_die();
		}

		$old_url = wp_get_attachment_url( $attachment_id );
		$new_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $old_url );

		// Convertir imagen principal.
		$res = $converter->convert( $full_path );
		if ( ! $res['success'] ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
			wp_die();
		}

		$total_orig = $res['original_size'];
		$total_webp = $res['webp_size'];

		// Actualizar metadatos del adjunto y referencias en contenidos.
		$new_attached_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $attached_file );
		$db_replacer->update_attachment_records( $attachment_id, $attached_file, $new_attached_file );
		$db_replacer->replace_references( $old_url, $new_url );

		if ( ! $keep_orig ) {
			@unlink( $full_path );
		}

		// Convertir thumbnails — un fallo en un thumb no cancela el proceso completo.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$url_base = trailingslashit( dirname( $old_url ) );
			foreach ( $meta['sizes'] as $s_name => $s_info ) {
				if ( empty( $s_info['file'] ) ) {
					continue;
				}
				$thumb_p = trailingslashit( $base_dir ) . $s_info['file'];
				if ( file_exists( $thumb_p ) ) {
					$thumb_conv = $converter->convert( $thumb_p );
					if ( $thumb_conv['success'] ) {
						$total_orig += $thumb_conv['original_size'];
						$total_webp += $thumb_conv['webp_size'];
						$meta['sizes'][ $s_name ]['file']      = basename( $thumb_conv['webp_path'] );
						$meta['sizes'][ $s_name ]['mime-type'] = 'image/webp';
						$db_replacer->replace_references( $url_base . $s_info['file'], $url_base . basename( $thumb_conv['webp_path'] ) );

						if ( ! $keep_orig ) {
							@unlink( $thumb_p );
						}
					}
				}
			}
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		// Guardar estadísticas del plugin para esta imagen.
		$saved = max( 0, $total_orig - $total_webp );
		update_post_meta( $attachment_id, '_webp_nc_converted', 1 );
		update_post_meta( $attachment_id, '_webp_nc_original_size', $total_orig );
		update_post_meta( $attachment_id, '_webp_nc_webp_size', $total_webp );
		update_post_meta( $attachment_id, '_webp_nc_saved_bytes', $saved );

		// Devolver los datos de ahorro al cliente para actualizar la celda de la tabla sin reload.
		wp_send_json_success( array(
			'original_formatted' => size_format( $total_orig ),
			'webp_formatted'     => size_format( $total_webp ),
			'saved_percent'      => $total_orig > 0 ? round( ( $saved / $total_orig ) * 100 ) : 0,
		) );
	}
}
