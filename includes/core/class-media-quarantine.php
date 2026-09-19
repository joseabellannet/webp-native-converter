<?php
/**
 * Cuarentena de adjuntos no usados.
 *
 * Mueve los archivos físicos a /uploads/webp-nc-quarantine/{id}/, pasa el
 * adjunto a la papelera de WordPress y permite restaurar o borrar del todo.
 * No usa wp_delete_attachment hasta el purgado definitivo.
 *
 * @package WebPNativeConverter\Core
 */

namespace WebPNativeConverter\Core;

use WebPNativeConverter\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase MediaQuarantine
 */
class MediaQuarantine {

	const FOLDER        = 'webp-nc-quarantine';
	const META_KEY      = '_webp_nc_quarantine';
	const INDEX_OPTION  = 'webp_nc_quarantine_index';

	/**
	 * True mientras este objeto está ejecutando un purgado propio.
	 * Evita que el filtro pre_delete_attachment bloquee wp_delete_attachment().
	 *
	 * @var bool
	 */
	protected $purging = false;

	/**
	 * Constructor: registra endpoints AJAX y protege adjuntos en cuarentena.
	 */
	public function __construct() {
		add_action( 'wp_ajax_webp_nc_quarantine_move', array( $this, 'ajax_move' ) );
		add_action( 'wp_ajax_webp_nc_quarantine_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_webp_nc_quarantine_purge', array( $this, 'ajax_purge' ) );
		add_action( 'wp_ajax_webp_nc_quarantine_list', array( $this, 'ajax_list' ) );

		// WP vacía la papelera a los EMPTY_TRASH_DAYS (o de inmediato si vale 0).
		// Sin esto, un adjunto en cuarentena se borraría del todo y no habría restore.
		add_filter( 'pre_delete_attachment', array( $this, 'protect_quarantined' ), 10, 3 );
	}

	/**
	 * Impide que WordPress borre un adjunto que está en nuestra cuarentena.
	 *
	 * @param mixed    $check        Null para continuar; otro valor cancela el borrado.
	 * @param \WP_Post $post         Adjunto.
	 * @param bool     $force_delete
	 * @return mixed
	 */
	public function protect_quarantined( $check, $post, $force_delete ) {
		unset( $force_delete );

		if ( null !== $check || $this->purging ) {
			return $check;
		}

		$id = ( $post instanceof \WP_Post ) ? (int) $post->ID : 0;
		if ( $id && $this->is_quarantined( $id ) ) {
			return false;
		}

		return $check;
	}

	/**
	 * AJAX: mueve adjuntos seleccionados a cuarentena.
	 */
	public function ajax_move() {
		$this->guard_ajax();

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No se han seleccionado imágenes.', 'webp-native-converter' ) ) );
		}

		$moved   = 0;
		$bytes   = 0;
		$errors  = array();
		$entries = array();

		foreach ( $ids as $attachment_id ) {
			$result = $this->quarantine_attachment( $attachment_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '#%d: %s', $attachment_id, $result->get_error_message() );
				continue;
			}
			$moved++;
			$bytes += (int) $result['bytes'];
			$entries[] = $result['index'];
		}

		if ( $moved > 0 ) {
			Logger::info( sprintf( 'Cuarentena: %d adjuntos movidos (%s).', $moved, size_format( $bytes ) ) );
		}

		wp_send_json_success(
			array(
				'moved'   => $moved,
				'bytes'   => $bytes,
				'freed'   => size_format( $bytes ),
				'errors'  => $errors,
				'entries' => $entries,
				'index'   => array_values( $this->get_index() ),
				'message' => sprintf(
					/* translators: 1: number of files, 2: human size */
					__( 'Se han movido %1$d imágenes a cuarentena (%2$s).', 'webp-native-converter' ),
					$moved,
					size_format( $bytes )
				),
			)
		);
	}

	/**
	 * AJAX: restaura un adjunto desde cuarentena.
	 */
	public function ajax_restore() {
		$this->guard_ajax();

		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de adjunto no válido.', 'webp-native-converter' ) ) );
		}

		if ( ! $this->is_quarantined( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Esta imagen no está en cuarentena.', 'webp-native-converter' ) ) );
		}

		$result = $this->restore_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::info( sprintf( 'Cuarentena: adjunto #%d restaurado.', $attachment_id ) );

		wp_send_json_success(
			array(
				'id'      => $attachment_id,
				'index'   => array_values( $this->get_index() ),
				'message' => sprintf( __( 'Imagen #%d restaurada a la biblioteca.', 'webp-native-converter' ), $attachment_id ),
			)
		);
	}

	/**
	 * AJAX: borra definitivamente archivos + registro del adjunto.
	 */
	public function ajax_purge() {
		$this->guard_ajax();

		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de adjunto no válido.', 'webp-native-converter' ) ) );
		}

		if ( ! $this->is_quarantined( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Esta imagen no está en cuarentena.', 'webp-native-converter' ) ) );
		}

		$result = $this->purge_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Logger::info( sprintf( 'Cuarentena: adjunto #%d eliminado de forma permanente.', $attachment_id ) );

		wp_send_json_success(
			array(
				'id'      => $attachment_id,
				'index'   => array_values( $this->get_index() ),
				'message' => sprintf( __( 'Imagen #%d eliminada de forma permanente.', 'webp-native-converter' ), $attachment_id ),
			)
		);
	}

	/**
	 * AJAX: lista el índice de cuarentena (por si el cliente lo recarga).
	 */
	public function ajax_list() {
		$this->guard_ajax();
		wp_send_json_success( array( 'index' => array_values( $this->get_index() ) ) );
	}

	/**
	 * Mueve un adjunto a cuarentena.
	 *
	 * @param int $attachment_id
	 * @return array|\WP_Error
	 */
	public function quarantine_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$post          = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'invalid', __( 'El adjunto no existe.', 'webp-native-converter' ) );
		}

		if ( get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return new \WP_Error( 'already', __( 'Esta imagen ya está en cuarentena.', 'webp-native-converter' ) );
		}

		$dir = $this->ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$upload     = wp_upload_dir();
		$basedir    = $upload['basedir'];
		$item_dir   = trailingslashit( $dir ) . $attachment_id;
		$files      = $this->collect_attachment_files( $attachment_id );
		$moved      = array();
		$bytes      = 0;

		if ( ! wp_mkdir_p( $item_dir ) ) {
			return new \WP_Error( 'mkdir', __( 'No se pudo crear la carpeta de cuarentena del adjunto.', 'webp-native-converter' ) );
		}

		foreach ( $files as $relative ) {
			$source = $this->absolute_under_uploads( $relative, $basedir );
			if ( ! $source || ! file_exists( $source ) ) {
				continue;
			}

			$dest_rel = self::FOLDER . '/' . $attachment_id . '/' . wp_basename( $relative );
			$dest     = $this->absolute_under_uploads( $dest_rel, $basedir );
			if ( ! $dest ) {
				continue;
			}

			$size = (int) @filesize( $source );
			if ( ! $this->move_file( $source, $dest ) ) {
				$this->rollback_moves( $moved, $basedir );
				return new \WP_Error(
					'move',
					sprintf( __( 'No se pudo mover el archivo %s.', 'webp-native-converter' ), $relative )
				);
			}

			$bytes += $size;
			$moved[] = array(
				'original'   => $relative,
				'quarantine' => $dest_rel,
			);
		}

		$previous_status = $post->post_status ? $post->post_status : 'inherit';
		if ( 'trash' === $previous_status ) {
			$previous_status = 'inherit';
		}

		$meta_payload = array(
			'files'           => $moved,
			'attached_file'   => get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'mime'            => $post->post_mime_type,
			'bytes'           => $bytes,
			'quarantined_at'  => time(),
			'previous_status' => $previous_status,
		);

		update_post_meta( $attachment_id, self::META_KEY, $meta_payload );

		// wp_trash_post() borra del todo si EMPTY_TRASH_DAYS = 0. Solo cambiamos el status.
		$trashed = wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_status' => 'trash',
			),
			true
		);
		if ( is_wp_error( $trashed ) || ! $trashed ) {
			$this->rollback_moves( $moved, $basedir );
			delete_post_meta( $attachment_id, self::META_KEY );
			return new \WP_Error( 'trash', __( 'No se pudo ocultar el adjunto de la biblioteca.', 'webp-native-converter' ) );
		}

		$index_entry = array(
			'id'             => $attachment_id,
			'title'          => $post->post_title ? $post->post_title : wp_basename( (string) $meta_payload['attached_file'] ),
			'filename'       => wp_basename( (string) $meta_payload['attached_file'] ),
			'bytes'          => $bytes,
			'size'           => $bytes ? size_format( $bytes ) : '—',
			'files'          => count( $moved ),
			'quarantined_at' => $meta_payload['quarantined_at'],
			'date'           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meta_payload['quarantined_at'] ),
		);

		$this->upsert_index( $attachment_id, $index_entry );

		return array(
			'bytes' => $bytes,
			'index' => $index_entry,
		);
	}

	/**
	 * Restaura archivos y saca el adjunto de la papelera.
	 *
	 * @param int $attachment_id
	 * @return true|\WP_Error
	 */
	public function restore_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$payload       = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( empty( $payload ) || empty( $payload['files'] ) ) {
			// Puede estar en el índice pero sin meta: intentamos reconstruir desde disco.
			$payload = $this->payload_from_disk( $attachment_id );
			if ( empty( $payload['files'] ) ) {
				return new \WP_Error( 'missing', __( 'No hay datos de cuarentena para este adjunto.', 'webp-native-converter' ) );
			}
		}

		$upload  = wp_upload_dir();
		$basedir = $upload['basedir'];

		foreach ( $payload['files'] as $map ) {
			$original   = isset( $map['original'] ) ? $map['original'] : '';
			$quarantine = isset( $map['quarantine'] ) ? $map['quarantine'] : '';
			if ( ! $original || ! $quarantine ) {
				continue;
			}

			$source = $this->absolute_under_uploads( $quarantine, $basedir );
			$dest   = $this->absolute_under_uploads( $original, $basedir );
			if ( ! $source || ! $dest || ! file_exists( $source ) ) {
				continue;
			}

			wp_mkdir_p( dirname( $dest ) );
			if ( ! $this->move_file( $source, $dest ) ) {
				return new \WP_Error(
					'move',
					sprintf( __( 'No se pudo restaurar el archivo %s.', 'webp-native-converter' ), $original )
				);
			}
		}

		$previous = ( ! empty( $payload['previous_status'] ) && 'trash' !== $payload['previous_status'] )
			? $payload['previous_status']
			: 'inherit';

		delete_post_meta( $attachment_id, self::META_KEY );
		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_status' => $previous,
			)
		);
		$this->remove_from_index( $attachment_id );
		$this->maybe_remove_item_dir( $attachment_id );

		clean_post_cache( $attachment_id );
		return true;
	}

	/**
	 * Borra archivos de cuarentena y el registro del adjunto.
	 *
	 * @param int $attachment_id
	 * @return true|\WP_Error
	 */
	public function purge_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$payload       = get_post_meta( $attachment_id, self::META_KEY, true );
		$upload        = wp_upload_dir();
		$basedir       = $upload['basedir'];

		if ( is_array( $payload ) && ! empty( $payload['files'] ) ) {
			foreach ( $payload['files'] as $map ) {
				if ( empty( $map['quarantine'] ) ) {
					continue;
				}
				$path = $this->absolute_under_uploads( $map['quarantine'], $basedir );
				if ( $path && is_file( $path ) ) {
					@unlink( $path );
				}
			}
		}

		$this->maybe_remove_item_dir( $attachment_id );
		$this->remove_from_index( $attachment_id );

		$this->purging = true;
		$deleted       = wp_delete_attachment( $attachment_id, true );
		$this->purging = false;

		if ( ! $deleted ) {
			return new \WP_Error( 'delete', __( 'No se pudo eliminar el adjunto de la base de datos.', 'webp-native-converter' ) );
		}

		return true;
	}

	/**
	 * Índice de cuarentena para la UI.
	 *
	 * @return array
	 */
	public function get_index() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Ruta absoluta de la carpeta de cuarentena.
	 *
	 * @return string
	 */
	public function get_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::FOLDER;
	}

	/**
	 * Crea la carpeta de cuarentena con protecciones web.
	 *
	 * @return string|\WP_Error
	 */
	public function ensure_dir() {
		$dir = $this->get_dir();

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new \WP_Error( 'mkdir', __( 'No se pudo crear la carpeta de cuarentena en /uploads/.', 'webp-native-converter' ) );
			}
			file_put_contents( trailingslashit( $dir ) . '.htaccess', "Order Deny,Allow\nDeny from all\n" );
			file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Recoge rutas relativas de archivo principal + tamaños.
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	protected function collect_attachment_files( $attachment_id ) {
		$paths         = array();
		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
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

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * rename() con fallback copy+unlink (sistemas de ficheros distintos).
	 *
	 * @param string $source
	 * @param string $dest
	 * @return bool
	 */
	protected function move_file( $source, $dest ) {
		if ( @rename( $source, $dest ) ) {
			return true;
		}
		if ( @copy( $source, $dest ) ) {
			@unlink( $source );
			return file_exists( $dest );
		}
		return false;
	}

	/**
	 * Devuelve archivos a su sitio si el lote de cuarentena falla a medias.
	 *
	 * @param array  $moved
	 * @param string $basedir
	 */
	protected function rollback_moves( $moved, $basedir ) {
		foreach ( $moved as $map ) {
			$src  = $this->absolute_under_uploads( $map['quarantine'], $basedir );
			$dest = $this->absolute_under_uploads( $map['original'], $basedir );
			if ( $src && $dest && file_exists( $src ) ) {
				wp_mkdir_p( dirname( $dest ) );
				$this->move_file( $src, $dest );
			}
		}
	}

	/**
	 * ¿Este adjunto está en cuarentena (meta o índice)?
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	protected function is_quarantined( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		if ( get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return true;
		}

		$index = $this->get_index();
		return isset( $index[ $attachment_id ] ) || isset( $index[ (string) $attachment_id ] );
	}

	/**
	 * Resuelve una ruta relativa a absoluta solo si queda dentro de /uploads.
	 *
	 * @param string $relative
	 * @param string $basedir
	 * @return string Ruta absoluta o vacía si es inválida.
	 */
	protected function absolute_under_uploads( $relative, $basedir ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );
		if ( '' === $relative || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			return '';
		}

		$full = wp_normalize_path( path_join( $basedir, $relative ) );
		$base = wp_normalize_path( trailingslashit( $basedir ) );

		if ( 0 !== strpos( $full, $base ) ) {
			return '';
		}

		return $full;
	}

	/**
	 * @param int   $attachment_id
	 * @param array $entry
	 */
	protected function upsert_index( $attachment_id, $entry ) {
		$index = $this->get_index();
		$index[ (int) $attachment_id ] = $entry;
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * @param int $attachment_id
	 */
	protected function remove_from_index( $attachment_id ) {
		$index = $this->get_index();
		unset( $index[ $attachment_id ], $index[ (string) $attachment_id ] );
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Intenta vaciar y borrar webp-nc-quarantine/{id}/.
	 *
	 * @param int $attachment_id
	 */
	protected function maybe_remove_item_dir( $attachment_id ) {
		$dir = trailingslashit( $this->get_dir() ) . absint( $attachment_id );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $dir ) . '*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}

		@rmdir( $dir );
	}

	/**
	 * Reconstruye el mapa de archivos si el meta se perdió pero la carpeta existe.
	 *
	 * @param int $attachment_id
	 * @return array
	 */
	protected function payload_from_disk( $attachment_id ) {
		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$dir           = trailingslashit( $this->get_dir() ) . $attachment_id;
		$files         = array();

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $path ) {
				if ( ! is_file( $path ) ) {
					continue;
				}
				$base = wp_basename( $path );
				$orig = $attached_file ? trailingslashit( dirname( $attached_file ) ) . $base : $base;
				if ( '.' === dirname( $attached_file ) ) {
					$orig = $base;
				}
				$files[] = array(
					'original'   => ltrim( $orig, './' ),
					'quarantine' => self::FOLDER . '/' . $attachment_id . '/' . $base,
				);
			}
		}

		return array( 'files' => $files );
	}

	/**
	 * Nonce + capability.
	 */
	protected function guard_ajax() {
		check_ajax_referer( 'webp_nc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'webp-native-converter' ) ),
				403
			);
		}
	}
}
