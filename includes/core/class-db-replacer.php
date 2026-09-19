<?php
/**
 * Reemplazador seguro de referencias en Base de Datos.
 *
 * Aquí es donde se hace la parte más delicada del plugin: actualizar URLs en la base de datos.
 * El reto gordo son las cadenas serializadas de PHP que usan maquetadores como Elementor o Divi.
 * Si reemplazas una URL dentro de una cadena serializada sin recalcular la longitud, el sitio peta.
 * Esta clase lo resuelve deserializando, reemplazando y volviendo a serializar.
 *
 * @package WebPNativeConverter\Core
 */

namespace WebPNativeConverter\Core;

use WebPNativeConverter\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase DbReplacer
 */
class DbReplacer {

	/**
	 * Instancia de wpdb para no tener que usar global cada vez.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Constructor: guardo la referencia a $wpdb globalmente disponible de WordPress.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Actualiza todos los metadatos de un adjunto para reflejar que ahora es un WebP.
	 *
	 * Esto incluye tres cosas:
	 * 1. El meta _wp_attached_file (la ruta relativa del archivo principal).
	 * 2. El post_mime_type en wp_posts (para que WP sepa que ya no es JPEG/PNG).
	 * 3. El meta _wp_attachment_metadata (que contiene los tamaños intermedios).
	 *
	 * NOTA: no actualizo los sizes aquí con regex — eso puede causar doble conversión
	 * si se llama dos veces. Los sizes los actualiza el código que hace la conversión física.
	 *
	 * @param int    $attachment_id     ID del adjunto.
	 * @param string $old_relative_file Ruta anterior relativa a /uploads/ (ej: 2023/04/foto.jpg).
	 * @param string $new_relative_file Ruta nueva relativa a /uploads/ (ej: 2023/04/foto.webp).
	 * @return bool Siempre true (los fallos individuales se logean pero no paran el proceso).
	 */
	public function update_attachment_records( $attachment_id, $old_relative_file, $new_relative_file ) {
		// 1. Actualizar la ruta del archivo principal en postmeta.
		update_post_meta( $attachment_id, '_wp_attached_file', $new_relative_file );

		// 2. Cambiar el MIME type en wp_posts para que la biblioteca de medios lo muestre correctamente.
		$this->wpdb->update(
			$this->wpdb->posts,
			array( 'post_mime_type' => 'image/webp' ),
			array( 'ID' => $attachment_id ),
			array( '%s' ),
			array( '%d' )
		);

		// 3. Actualizar el campo 'file' principal dentro de _wp_attachment_metadata.
		// Los 'sizes' (thumbnails) los actualiza directamente el código de conversión
		// para evitar que la regex doble-convierta nombres que ya terminan en .webp.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			if ( isset( $meta['file'] ) ) {
				$meta['file'] = $new_relative_file;
			}

			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		// Limpiar la caché de WP para este post — necesario para que get_post() devuelva los datos frescos.
		clean_post_cache( $attachment_id );
		return true;
	}

	/**
	 * Busca y reemplaza URLs de JPG/PNG por su equivalente WebP en todo el contenido de la BD.
	 *
	 * Busca en:
	 * - post_content de todos los posts
	 * - Todos los postmeta (incluyendo cadenas serializadas y JSON de maquetadores)
	 *
	 * @param string $old_url URL original (ej: https://miweb.com/uploads/2023/foto.jpg).
	 * @param string $new_url URL nueva    (ej: https://miweb.com/uploads/2023/foto.webp).
	 * @return int Número de registros modificados.
	 */
	public function replace_references( $old_url, $new_url ) {
		// Sanidad básica — si las URLs son iguales o están vacías no hago nada.
		if ( empty( $old_url ) || empty( $new_url ) || $old_url === $new_url ) {
			return 0;
		}

		$modified_count = 0;

		// Construyo el LIKE para la búsqueda. esc_like() escapa los % y _ literales que pueda haber en la URL.
		$like_query = '%' . $this->wpdb->esc_like( $old_url ) . '%';

		// --- 1. Reemplazo en post_content ---
		// Primero busco qué posts contienen la URL para evitar UPDATE de toda la tabla.
		$post_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts} WHERE post_content LIKE %s AND post_status != 'auto-draft'",
				$like_query
			)
		);

		if ( ! empty( $post_ids ) ) {
			foreach ( $post_ids as $pid ) {
				// Obtengo el contenido crudo (sin filtros de WP que podrían alterar las URLs).
				$content     = get_post_field( 'post_content', $pid, 'raw' );
				$new_content = str_replace( $old_url, $new_url, $content );

				// Solo actualizo si hubo cambio real — evita writes innecesarios a la BD.
				if ( $new_content !== $content ) {
					$this->wpdb->update(
						$this->wpdb->posts,
						array( 'post_content' => $new_content ),
						array( 'ID' => $pid ),
						array( '%s' ),
						array( '%d' )
					);

					clean_post_cache( $pid );
					$modified_count++;
				}
			}
		}

		// --- 2. Reemplazo en wp_postmeta (Elementor, Divi, Bricks, campos ACF...) ---
		// Aquí es donde puede haber cadenas serializadas o JSON — replace_data() lo gestiona.
		$meta_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$this->wpdb->postmeta} WHERE meta_value LIKE %s",
				$like_query
			)
		);

		if ( ! empty( $meta_rows ) ) {
			foreach ( $meta_rows as $row ) {
				$meta_value = $row->meta_value;
				$new_value  = $this->replace_data( $meta_value, $old_url, $new_url );

				// Solo actualizo si algo cambió después del procesamiento.
				if ( $new_value !== $meta_value ) {
					$this->wpdb->update(
						$this->wpdb->postmeta,
						array( 'meta_value' => $new_value ),
						array( 'meta_id' => $row->meta_id ),
						array( '%s' ),
						array( '%d' )
					);
					$modified_count++;
				}
			}
		}

		// Si modificamos algo, aprovecho para purgar las cachés de los maquetadores
		// que guardan CSS o HTML compilado con las URLs antiguas.
		if ( $modified_count > 0 ) {
			$this->clear_page_builder_caches();
		}

		return $modified_count;
	}

	/**
	 * Reemplaza una cadena dentro de cualquier tipo de dato:
	 * string plano, serializado PHP, JSON, array u objeto.
	 *
	 * Esta es la función más delicada del plugin. El problema con cadenas serializadas es que
	 * PHP guarda la longitud del string dentro de la serialización (s:23:"...").
	 * Si hago un str_replace directo, la longitud queda incorrecta y unserialize() peta.
	 * La solución: deserializo → reemplazo → serializo de nuevo (PHP recalcula las longitudes).
	 *
	 * @param mixed  $data    Dato a procesar.
	 * @param string $search  Cadena a buscar.
	 * @param string $replace Cadena de reemplazo.
	 * @return mixed Dato con las sustituciones aplicadas.
	 */
	public function replace_data( $data, $search, $replace ) {
		if ( is_string( $data ) ) {

			// ¿Es una cadena serializada? → Deserializo, proceso recursivamente y serializo de nuevo.
			// Esta es la única forma correcta de reemplazar sin corromper la estructura.
			if ( is_serialized( $data ) ) {
				$unserialized = @maybe_unserialize( $data );

				// maybe_unserialize devuelve false si falla, pero 'b:0;' serializa false legítimamente.
				if ( false !== $unserialized || 'b:0;' === $data ) {
					$replaced = $this->replace_recursive( $unserialized, $search, $replace );
					return maybe_serialize( $replaced );
				}
			}

			// ¿Es JSON válido? Maquetadores como Bricks o FSE usan JSON puro, no serialización PHP.
			$json_decoded = json_decode( $data, true );
			if ( null !== $json_decoded && json_last_error() === JSON_ERROR_NONE ) {
				$replaced = $this->replace_recursive( $json_decoded, $search, $replace );
				// JSON_UNESCAPED_SLASHES para no escapar las barras de las URLs.
				return wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			// String plano — reemplazo directo simple.
			return str_replace( $search, $replace, $data );
		}

		// Arrays y objetos los proceso de forma recursiva.
		if ( is_array( $data ) || is_object( $data ) ) {
			return $this->replace_recursive( $data, $search, $replace );
		}

		// Enteros, booleanos, null — los dejo tal cual.
		return $data;
	}

	/**
	 * Recorre arrays y objetos de forma recursiva aplicando replace_data() en cada valor.
	 * Lo separo de replace_data() para evitar recursión circular entre tipos.
	 *
	 * @param mixed  $data
	 * @param string $search
	 * @param string $replace
	 * @return mixed
	 */
	protected function replace_recursive( $data, $search, $replace ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $val ) {
				$data[ $key ] = $this->replace_data( $val, $search, $replace );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $val ) {
				$data->$key = $this->replace_data( $val, $search, $replace );
			}
			return $data;
		}

		return $this->replace_data( $data, $search, $replace );
	}

	/**
	 * Purga las cachés de los principales maquetadores visuales.
	 *
	 * Elementor, Divi y Bricks guardan CSS/HTML compilado con las URLs de las imágenes.
	 * Si no purgo esas cachés, el frontend seguirá sirviendo las URLs antiguas aunque
	 * la base de datos ya esté actualizada.
	 *
	 * Cada maquetador tiene su propia API — compruebo si existe antes de usarla.
	 */
	public function clear_page_builder_caches() {
		// Elementor — usa files_manager para regenerar CSS de widgets.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			} catch ( \Exception $e ) {
				// Si falla no es crítico — solo logeo el aviso.
				Logger::warning( 'No se pudo purgar la caché de Elementor: ' . $e->getMessage() );
			}
		}

		// Divi Builder (Elegant Themes) — tiene su propia función de limpieza de recursos CSS.
		if ( function_exists( 'et_core_page_resource_delete' ) ) {
			try {
				et_core_page_resource_delete( 'all', 'all' );
			} catch ( \Exception $e ) {
				Logger::warning( 'No se pudo purgar la caché de Divi: ' . $e->getMessage() );
			}
		}

		// Bricks Builder — también tiene caché propia.
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'clear_cache' ) ) {
			try {
				\Bricks\Helpers::clear_cache();
			} catch ( \Exception $e ) {
				Logger::warning( 'No se pudo purgar la caché de Bricks: ' . $e->getMessage() );
			}
		}

		// Caché de objetos de WordPress — limpia lo que haya en memoria/Redis/Memcached.
		wp_cache_flush();
	}
}
