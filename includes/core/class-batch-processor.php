<?php
/**
 * Procesador asíncrono por lotes para conversión masiva de imágenes existentes.
 *
 * Expone tres endpoints AJAX:
 * - webp_nc_get_pending_images: devuelve los IDs de imágenes aún sin convertir.
 * - webp_nc_process_batch: convierte un lote pequeño (3-5 imágenes) por petición.
 * - webp_nc_reset_stats: reinicia los contadores de estadísticas globales.
 *
 * El procesamiento secuencial en lotes pequeños evita timeouts de PHP y el colapso
 * de memoria en servidores compartidos. La lógica de cola vive en el JavaScript.
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
 * Clase BatchProcessor
 */
class BatchProcessor {

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
	 * Constructor: instancia dependencias y registra los endpoints AJAX.
	 *
	 * IMPORTANTE: este constructor se llama desde Plugin::init_components() siempre,
	 * incluso en admin. Así los hooks AJAX quedan disponibles en ambos contextos.
	 * No instanciar esta clase una segunda vez desde AdminMenu — causaría doble registro.
	 */
	public function __construct() {
		$this->converter   = new Converter();
		$this->db_replacer = new DbReplacer();

		// Registro los tres endpoints AJAX (solo para usuarios logueados — wp_ajax_ sin nopriv_).
		add_action( 'wp_ajax_webp_nc_get_pending_images', array( $this, 'ajax_get_pending_images' ) );
		add_action( 'wp_ajax_webp_nc_get_pending_preview', array( $this, 'ajax_get_pending_preview' ) );
		add_action( 'wp_ajax_webp_nc_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_webp_nc_reset_stats', array( $this, 'ajax_reset_stats' ) );
		add_action( 'wp_ajax_webp_nc_cleanup_originals', array( $this, 'ajax_cleanup_originals' ) );
	}

	/**
	 * Obtiene los IDs de todos los adjuntos JPG/PNG que aún no han sido convertidos a WebP.
	 *
	 * Usa LEFT JOIN con postmeta para detectar ausencia del meta '_webp_nc_converted'.
	 * La alternativa con NOT IN (subquery) puede ser lentísima en bibliotecas grandes.
	 *
	 * @return array Array de IDs (integers positivos).
	 */
	public function get_pending_attachment_ids() {
		// Uso $this->wpdb indirectamente — pero BatchProcessor no tiene $wpdb guardado,
		// así que accedo al global directamente aquí. Es el patrón correcto para queries directas.
		$wpdb = $this->db_replacer->wpdb ?? $GLOBALS['wpdb'];

		// Esta query busca adjuntos cuyo meta '_webp_nc_converted' no existe o no es '1'.
		// LEFT JOIN es más eficiente que NOT EXISTS o NOT IN para esta casuística.
		$like_webp = '%' . $wpdb->esc_like( '.webp' );
		$results   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON ( p.ID = pm.post_id AND pm.meta_key = %s )
				LEFT JOIN {$wpdb->postmeta} pf
					ON ( p.ID = pf.post_id AND pf.meta_key = %s )
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type IN ( 'image/jpeg', 'image/jpg', 'image/png' )
				  AND ( pm.meta_value IS NULL OR pm.meta_value != '1' )
				  AND ( pf.meta_value IS NULL OR LOWER( pf.meta_value ) NOT LIKE %s )
				ORDER BY p.ID DESC",
				'_webp_nc_converted',
				'_wp_attached_file',
				$like_webp
			)
		);

		$ids = array_map( 'absint', (array) $results );

		// Por si el mime dice JPEG/PNG pero el archivo o los metadatos ya son WebP.
		return array_values(
			array_filter(
				$ids,
				static function ( $attachment_id ) {
					return $attachment_id && ! SystemCheck::attachment_is_webp( $attachment_id );
				}
			)
		);
	}

	/**
	 * AJAX: devuelve el total y la lista completa de IDs de imágenes pendientes.
	 * El JavaScript recibe esto y construye la cola de procesamiento en el cliente.
	 */
	public function ajax_get_pending_images() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'webp-native-converter' ) ),
				403
			);
			wp_die(); // Garantiza que la ejecución para aquí aunque send_json no haga die en versiones antiguas.
		}

		$ids = $this->get_pending_attachment_ids();

		wp_send_json_success( array(
			'total' => count( $ids ),
			'ids'   => $ids,
		) );
	}

	/**
	 * AJAX: ficha de un lote de pendientes (miniatura, peso y ahorro estimado).
	 * El cliente pide trozos para no timeout en bibliotecas grandes.
	 */
	public function ajax_get_pending_preview() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'webp-native-converter' ) ),
				403
			);
			wp_die();
		}

		$ids = webp_nc_get_posted_ids( 'ids' );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No se han especificado imágenes.', 'webp-native-converter' ) ) );
		}

		if ( count( $ids ) > 60 ) {
			$ids = array_slice( $ids, 0, 60 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		wp_send_json_success(
			array(
				'items' => $this->get_preview_items( $ids ),
			)
		);
	}

	/**
	 * Datos de revisión previa para una lista de adjuntos.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	public function get_preview_items( $ids ) {
		$upload  = wp_upload_dir();
		$basedir = $upload['basedir'];
		$items   = array();

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id || SystemCheck::attachment_is_webp( $attachment_id ) ) {
				continue;
			}

			$post          = get_post( $attachment_id );
			$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$bytes         = $this->collect_attachment_bytes( $attachment_id, $basedir );
			$mime          = $post ? (string) $post->post_mime_type : '';
			$estimated     = $this->estimate_saved_bytes( $bytes, $mime );
			$thumb         = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

			$items[] = array(
				'id'          => $attachment_id,
				'title'       => $post ? $post->post_title : '',
				'filename'    => $attached_file ? wp_basename( $attached_file ) : '',
				'bytes'       => $bytes,
				'size'        => $bytes ? size_format( $bytes ) : '—',
				'estimated'   => $estimated,
				'estimated_h' => $estimated ? size_format( $estimated ) : '—',
				'thumb'       => $thumb ? $thumb : '',
			);
		}

		return $items;
	}

	/**
	 * Peso en disco del original + miniaturas + original_image.
	 *
	 * @param int    $attachment_id
	 * @param string $basedir
	 * @return int
	 */
	protected function collect_attachment_bytes( $attachment_id, $basedir ) {
		$bytes         = 0;
		$seen          = array();
		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$paths         = array();

		if ( $attached_file ) {
			$paths[] = ltrim( $attached_file, '/' );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = $attached_file ? dirname( $attached_file ) : '';
		if ( '.' === $dir ) {
			$dir = '';
		}

		if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$paths[] = ltrim( $meta['file'], '/' );
		}

		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$orig = $meta['original_image'];
			if ( false === strpos( $orig, '/' ) && $dir ) {
				$orig = trailingslashit( $dir ) . $orig;
			}
			$paths[] = ltrim( $orig, '/' );
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}
				$file = $size_info['file'];
				if ( false === strpos( $file, '/' ) && $dir ) {
					$file = trailingslashit( $dir ) . $file;
				}
				$paths[] = ltrim( $file, '/' );
			}
		}

		foreach ( $paths as $relative ) {
			$full = path_join( $basedir, $relative );
			$key  = wp_normalize_path( $full );
			if ( isset( $seen[ $key ] ) || ! file_exists( $full ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$bytes       += (int) @filesize( $full );
		}

		return $bytes;
	}

	/**
	 * Estimación de ahorro WebP (el valor real se calcula al convertir).
	 *
	 * @param int    $bytes
	 * @param string $mime
	 * @return int
	 */
	protected function estimate_saved_bytes( $bytes, $mime ) {
		if ( $bytes < 1 ) {
			return 0;
		}

		$settings = get_option( 'webp_nc_settings', array() );
		$quality  = isset( $settings['quality'] ) ? absint( $settings['quality'] ) : 82;
		$is_png   = ( false !== strpos( strtolower( (string) $mime ), 'png' ) );
		$ratio    = $is_png ? 0.45 : 0.28;
		$ratio   -= ( $quality - 82 ) * 0.004;
		$ratio    = max( 0.08, min( 0.65, $ratio ) );

		return (int) round( $bytes * $ratio );
	}

	/**
	 * AJAX: convierte un lote de adjuntos enviados desde el cliente.
	 *
	 * Por cada adjunto:
	 * 1. Convierte el archivo principal.
	 * 2. Actualiza los metadatos de WP (mime type, ruta).
	 * 3. Reemplaza URLs en posts y postmeta.
	 * 4. Convierte los thumbnails.
	 * 5. Guarda metadatos del plugin (_webp_nc_*).
	 *
	 * Devuelve siempre respuesta JSON (éxito o error) para que el cliente pueda continuar.
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'webp-native-converter' ) ),
				403
			);
			wp_die();
		}

		// Los IDs vienen del cliente — los saneo con absint() uno a uno.
		$ids = webp_nc_get_posted_ids( 'ids' );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No se han especificado imágenes para procesar.', 'webp-native-converter' ) ) );
			wp_die();
		}

		$settings       = get_option( 'webp_nc_settings', array() );
		$keep_originals = ! empty( $settings['keep_originals'] );
		$upload_dir     = wp_upload_dir();

		$batch_saved_bytes = 0;
		$processed_count   = 0;
		$errors            = array();

		foreach ( $ids as $attachment_id ) {
			if ( SystemCheck::attachment_is_webp( $attachment_id ) ) {
				continue;
			}

			$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

			// Si el adjunto no tiene ruta en la BD, algo está muy mal con este registro — lo salto.
			if ( empty( $attached_file ) ) {
				$errors[] = sprintf( 'Adjunto #%d sin _wp_attached_file en la BD — saltado.', $attachment_id );
				continue;
			}

			$full_path = path_join( $upload_dir['basedir'], $attached_file );
			$base_dir  = dirname( $full_path );

			// Si el archivo no existe en disco, lo señalo pero no paro el proceso.
			if ( ! file_exists( $full_path ) ) {
				$errors[] = sprintf( 'Adjunto #%d — archivo no encontrado en disco: %s', $attachment_id, $attached_file );
				continue;
			}

			$attachment_url = wp_get_attachment_url( $attachment_id );
			$old_main_url   = $attachment_url;
			$new_main_url   = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $old_main_url );

			$item_original_bytes = 0;
			$item_webp_bytes     = 0;

			// --- PASO 1: Imagen principal ---
			$main_conv = $this->converter->convert( $full_path );

			if ( ! $main_conv['success'] ) {
				// Si la imagen principal falla, no tiene sentido procesar los thumbs — saltamos.
				$errors[] = sprintf( 'Error convirtiendo adjunto #%d: %s', $attachment_id, $main_conv['error'] );
				continue;
			}

			$item_original_bytes += $main_conv['original_size'];
			$item_webp_bytes     += $main_conv['webp_size'];

			// Actualizar registros de WP para este adjunto.
			$new_attached_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $attached_file );
			$this->db_replacer->update_attachment_records( $attachment_id, $attached_file, $new_attached_file );

			// Reemplazar la URL principal en todos los contenidos.
			$this->db_replacer->replace_references( $old_main_url, $new_main_url );

			if ( ! $keep_originals ) {
				@unlink( $full_path );
			}

			// --- PASO 2: Thumbnails ---
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$url_base = trailingslashit( dirname( $old_main_url ) );

				foreach ( $metadata['sizes'] as $size_name => $size_info ) {
					if ( empty( $size_info['file'] ) ) {
						continue;
					}

					$thumb_file = $size_info['file'];
					$thumb_path = trailingslashit( $base_dir ) . $thumb_file;

					if ( ! file_exists( $thumb_path ) ) {
						// El thumbnail puede no existir si fue borrado manualmente — no es un error.
						continue;
					}

					$thumb_conv = $this->converter->convert( $thumb_path );

					if ( $thumb_conv['success'] ) {
						$item_original_bytes += $thumb_conv['original_size'];
						$item_webp_bytes     += $thumb_conv['webp_size'];

						$old_thumb_url = $url_base . $thumb_file;
						$new_thumb_url = $url_base . basename( $thumb_conv['webp_path'] );

						$metadata['sizes'][ $size_name ]['file']      = basename( $thumb_conv['webp_path'] );
						$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';

						$this->db_replacer->replace_references( $old_thumb_url, $new_thumb_url );

						if ( ! $keep_originals ) {
							@unlink( $thumb_path );
						}
					}
				}

				// Guardo los metadatos actualizados con los nuevos nombres de thumbnails.
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			// --- PASO 3: Guardar metadatos del plugin ---
			$saved_for_item = max( 0, $item_original_bytes - $item_webp_bytes );
			update_post_meta( $attachment_id, '_webp_nc_converted', 1 );
			update_post_meta( $attachment_id, '_webp_nc_original_size', $item_original_bytes );
			update_post_meta( $attachment_id, '_webp_nc_webp_size', $item_webp_bytes );
			update_post_meta( $attachment_id, '_webp_nc_saved_bytes', $saved_for_item );

			$batch_saved_bytes += $saved_for_item;
			$processed_count++;
		} // fin foreach

		// Actualizo las estadísticas globales acumuladas al final del lote.
		if ( $processed_count > 0 ) {
			$stats                    = get_option( 'webp_nc_stats', array( 'total_converted' => 0, 'total_saved' => 0 ) );
			$stats['total_converted'] = (int) ( $stats['total_converted'] ?? 0 ) + $processed_count;
			$stats['total_saved']     = (int) ( $stats['total_saved'] ?? 0 ) + $batch_saved_bytes;
			update_option( 'webp_nc_stats', $stats );
		}

		wp_send_json_success( array(
			'processed'   => $processed_count,
			'saved_bytes' => $batch_saved_bytes,
			'saved_human' => size_format( $batch_saved_bytes ),
			'errors'      => $errors,
		) );
	}

	/**
	 * AJAX: reinicia los contadores de estadísticas a cero.
	 * No toca las imágenes ni la BD — solo resetea los números del dashboard.
	 */
	public function ajax_reset_stats() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permisos insuficientes.', 'webp-native-converter' ) ),
				403
			);
			wp_die();
		}

		update_option( 'webp_nc_stats', array( 'total_converted' => 0, 'total_saved' => 0 ) );

		wp_send_json_success( array( 'message' => __( 'Estadísticas reiniciadas con éxito.', 'webp-native-converter' ) ) );
	}

	/**
	 * AJAX: Busca en los adjuntos convertidos y borra los JPG/PNG originales remanentes.
	 * ¡Atención! Solo borra los que ya tengan versión WebP exitosa.
	 */
	public function ajax_cleanup_originals() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'webp-native-converter' ) ), 403 );
			wp_die();
		}

		global $wpdb;
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Obtenemos solo los IDs que ya sabemos que se convirtieron.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				'_webp_nc_converted'
			)
		);

		if ( empty( $post_ids ) ) {
			wp_send_json_success( array(
				'message' => __( 'No hay imágenes convertidas que limpiar.', 'webp-native-converter' ),
				'freed'   => 0
			) );
		}

		$bytes_freed  = 0;
		$files_deleted = 0;
		$extensions   = array( 'jpg', 'jpeg', 'png' );

		// Helper para intentar borrar un archivo en base a su nombre base (sin extensión)
		$delete_orphans = function( $path_without_ext ) use ( &$bytes_freed, &$files_deleted, $extensions ) {
			foreach ( $extensions as $ext ) {
				$candidate = $path_without_ext . '.' . $ext;
				if ( file_exists( $candidate ) ) {
					$bytes_freed += @filesize( $candidate );
					if ( @unlink( $candidate ) ) {
						$files_deleted++;
					}
				}
			}
		};

		foreach ( $post_ids as $attachment_id ) {
			$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( empty( $attached_file ) ) {
				continue;
			}

			// Normalmente el meta guardado ahora será .webp si se completó el reemplazo.
			$full_path = path_join( $basedir, $attached_file );
			$path_no_ext = preg_replace( '/\.(webp|jpe?g|png)$/i', '', $full_path );

			// 1. Limpiar imagen original principal.
			$delete_orphans( $path_no_ext );

			// 2. Limpiar thumbnails intermedios.
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				$base_dir = dirname( $full_path );
				foreach ( $meta['sizes'] as $size_info ) {
					if ( ! empty( $size_info['file'] ) ) {
						$thumb_path = trailingslashit( $base_dir ) . $size_info['file'];
						$thumb_no_ext = preg_replace( '/\.(webp|jpe?g|png)$/i', '', $thumb_path );
						$delete_orphans( $thumb_no_ext );
					}
				}
			}
		}

		if ( $files_deleted > 0 ) {
			Logger::info( sprintf( 'Limpieza de originales ejecutada: borrados %d archivos huérfanos (Recuperados: %s)', $files_deleted, size_format( $bytes_freed ) ) );
		}

		wp_send_json_success( array(
			'message'       => sprintf( __( 'Se han borrado %d archivos originales.', 'webp-native-converter' ), $files_deleted ),
			'freed_bytes'   => $bytes_freed,
			'freed_human'   => size_format( $bytes_freed ),
			'files_deleted' => $files_deleted,
		) );
	}
}
