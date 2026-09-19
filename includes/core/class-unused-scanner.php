<?php
/**
 * Escáner conservador de imágenes de la biblioteca que no aparecen referenciadas.
 *
 * Recolecta IDs y rutas usadas en BD + CSS generado/tema y compara contra
 * los adjuntos de imagen. Si hay duda, la imagen se considera usada.
 *
 * El trabajo se parte en lotes AJAX para no timeout en hostings compartidos.
 *
 * @package WebPNativeConverter\Core
 */

namespace WebPNativeConverter\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase UnusedScanner
 */
class UnusedScanner {

	const TRANSIENT_KEY = 'webp_nc_unused_scan';
	const TRANSIENT_TTL = HOUR_IN_SECONDS;
	const HARVEST_SIZE  = 40;
	const COMPARE_SIZE  = 50;
	const CSS_BATCH     = 20;
	const MAX_CSS_FILES = 500;

	/**
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Constructor: registra los endpoints AJAX del escaneo.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		add_action( 'wp_ajax_webp_nc_unused_scan_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_webp_nc_unused_scan_step', array( $this, 'ajax_step' ) );
	}

	/**
	 * AJAX: inicializa el índice y siembra IDs evidentes (logo, featured images…).
	 */
	public function ajax_start() {
		$this->guard_ajax();
		$this->bump_time_limit();

		$state = $this->blank_state();
		$state = $this->seed_known_ids( $state );
		$state['phase'] = 'posts';

		$this->save_state( $state );

		wp_send_json_success( $this->progress_payload( $state, __( 'Analizando contenido de entradas y páginas…', 'webp-native-converter' ) ) );
	}

	/**
	 * AJAX: ejecuta el siguiente lote según la fase actual.
	 */
	public function ajax_step() {
		$this->guard_ajax();
		$this->bump_time_limit();

		$state = $this->get_state();
		if ( empty( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'No hay un escaneo en curso. Vuelve a pulsar Escanear.', 'webp-native-converter' ) ) );
		}

		switch ( $state['phase'] ) {
			case 'posts':
				$state = $this->harvest_posts( $state );
				$message = __( 'Analizando contenido de entradas y páginas…', 'webp-native-converter' );
				break;
			case 'postmeta':
				$state = $this->harvest_postmeta( $state );
				$message = __( 'Analizando metadatos (Elementor, Bricks, ACF…)…', 'webp-native-converter' );
				break;
			case 'options':
				$state = $this->harvest_options( $state );
				$message = __( 'Analizando ajustes, widgets y opciones del sitio…', 'webp-native-converter' );
				break;
			case 'termmeta':
				$state = $this->harvest_termmeta( $state );
				$message = __( 'Analizando imágenes de categorías y términos…', 'webp-native-converter' );
				break;
			case 'usermeta':
				$state = $this->harvest_usermeta( $state );
				$message = __( 'Analizando avatares y metadatos de usuario…', 'webp-native-converter' );
				break;
			case 'css_list':
				$state = $this->list_css_files( $state );
				$message = __( 'Localizando hojas de estilo…', 'webp-native-converter' );
				break;
			case 'css_scan':
				$state = $this->harvest_css_files( $state );
				$message = __( 'Analizando CSS generado y del tema…', 'webp-native-converter' );
				break;
			case 'compare':
				$state = $this->compare_attachments( $state );
				$message = __( 'Comparando con la biblioteca de medios…', 'webp-native-converter' );
				break;
			case 'done':
				$message = __( 'Escaneo completado.', 'webp-native-converter' );
				break;
			default:
				$state['phase'] = 'done';
				$message        = __( 'Escaneo completado.', 'webp-native-converter' );
				break;
		}

		$this->save_state( $state );

		wp_send_json_success( $this->progress_payload( $state, $message ) );
	}

	/**
	 * Estado vacío del escaneo.
	 *
	 * @return array
	 */
	protected function blank_state() {
		return array(
			'phase'                  => 'seed',
			'offset'                 => 0,
			'used_ids'               => array(),
			'used_paths'             => array(),
			'used_filenames'         => array(),
			'unused'                 => array(),
			'css_files'              => array(),
			'attachment_total'       => 0,
			'processed_attachments'  => 0,
			'started'                => time(),
		);
	}

	/**
	 * Siembra IDs que WordPress guarda de forma explícita.
	 *
	 * @param array $state
	 * @return array
	 */
	protected function seed_known_ids( $state ) {
		$site_icon = absint( get_option( 'site_icon' ) );
		if ( $site_icon ) {
			$state = $this->mark_id( $state, $site_icon );
		}

		$logo = absint( get_theme_mod( 'custom_logo' ) );
		if ( $logo ) {
			$state = $this->mark_id( $state, $logo );
		}

		$header_image = get_theme_mod( 'header_image' );
		if ( is_string( $header_image ) && $header_image && 'remove-header' !== $header_image ) {
			$state = $this->ingest_string( $state, $header_image );
		}

		$background_image = get_theme_mod( 'background_image' );
		if ( is_string( $background_image ) && $background_image ) {
			$state = $this->ingest_string( $state, $background_image );
		}

		// Imágenes destacadas de cualquier post type.
		$thumb_ids = $this->wpdb->get_col(
			"SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value REGEXP '^[0-9]+$'"
		);
		foreach ( (array) $thumb_ids as $tid ) {
			$state = $this->mark_id( $state, (int) $tid );
		}

		// Galerías WooCommerce (IDs separados por coma).
		$galleries = $this->wpdb->get_col(
			"SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''"
		);
		foreach ( (array) $galleries as $gallery ) {
			foreach ( explode( ',', (string) $gallery ) as $gid ) {
				$state = $this->mark_id( $state, (int) $gid );
			}
		}

		// Miniaturas de términos (WooCommerce categorías, etc.).
		$term_thumbs = $this->wpdb->get_col(
			"SELECT meta_value FROM {$this->wpdb->termmeta} WHERE meta_key IN ( 'thumbnail_id', '_thumbnail_id' ) AND meta_value REGEXP '^[0-9]+$'"
		);
		foreach ( (array) $term_thumbs as $tid ) {
			$state = $this->mark_id( $state, (int) $tid );
		}

		$custom_css = wp_get_custom_css();
		if ( $custom_css ) {
			$state = $this->ingest_css( $state, $custom_css );
		}

		$state['attachment_total'] = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->posts}
			WHERE post_type = 'attachment'
			  AND post_status IN ( 'inherit', 'private' )
			  AND post_mime_type LIKE 'image/%'"
		);

		return $state;
	}

	/**
	 * Cosecha post_content (no adjuntos: su guid se auto-referenciaría).
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_posts( $state ) {
		$offset = (int) $state['offset'];
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_content FROM {$this->wpdb->posts}
				WHERE post_type != 'attachment'
				  AND post_status != 'auto-draft'
				  AND (
					post_content LIKE %s
					OR post_content LIKE %s
					OR post_content LIKE %s
				  )
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				'%' . $this->wpdb->esc_like( 'uploads' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-image-' ) . '%',
				'%' . $this->wpdb->esc_like( 'attachment_id=' ) . '%',
				self::HARVEST_SIZE,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'postmeta';
			$state['offset'] = 0;
			return $state;
		}

		foreach ( $rows as $row ) {
			$state = $this->ingest_post_content( $state, (string) $row->post_content );
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::HARVEST_SIZE ) {
			$state['phase']  = 'postmeta';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * Cosecha postmeta de maquetadores, ACF y campos de imagen.
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_postmeta( $state ) {
		$offset      = (int) $state['offset'];
		$known       = $this->known_image_meta_keys();
		$blacklist   = $this->self_file_meta_keys();
		$known_ph    = implode( ',', array_fill( 0, count( $known ), '%s' ) );
		$black_ph    = implode( ',', array_fill( 0, count( $blacklist ), '%s' ) );

		$sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$this->wpdb->postmeta}
			WHERE meta_key NOT IN ( {$black_ph} )
			  AND (
				meta_key IN ( {$known_ph} )
				OR meta_value LIKE %s
				OR meta_value LIKE %s
				OR meta_value LIKE %s
			  )
			ORDER BY meta_id ASC
			LIMIT %d OFFSET %d";

		$params = array_merge(
			$blacklist,
			$known,
			array(
				'%' . $this->wpdb->esc_like( '/uploads/' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-image-' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-content/uploads' ) . '%',
				self::HARVEST_SIZE,
				$offset,
			)
		);

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );

		if ( empty( $rows ) ) {
			$state['phase']  = 'options';
			$state['offset'] = 0;
			return $state;
		}

		foreach ( $rows as $row ) {
			$state = $this->ingest_meta_row( $state, $row->meta_key, $row->meta_value );
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::HARVEST_SIZE ) {
			$state['phase']  = 'options';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * Cosecha wp_options (theme_mods, widgets, Bricks/Elementor globales).
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_options( $state ) {
		$offset = (int) $state['offset'];
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$this->wpdb->options}
				WHERE option_name NOT LIKE %s
				  AND option_name NOT LIKE %s
				  AND option_name NOT LIKE %s
				  AND option_name NOT LIKE %s
				  AND (
					option_value LIKE %s
					OR option_value LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s
					OR option_name LIKE %s
				  )
				ORDER BY option_id ASC
				LIMIT %d OFFSET %d",
				$this->wpdb->esc_like( '_transient_' ) . '%',
				$this->wpdb->esc_like( '_site_transient_' ) . '%',
				$this->wpdb->esc_like( 'webp_nc_' ) . '%',
				$this->wpdb->esc_like( '_transient_timeout_' ) . '%',
				'%' . $this->wpdb->esc_like( '/uploads/' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-content/uploads' ) . '%',
				$this->wpdb->esc_like( 'theme_mods_' ) . '%',
				$this->wpdb->esc_like( 'widget_' ) . '%',
				'%' . $this->wpdb->esc_like( 'bricks' ) . '%',
				'%' . $this->wpdb->esc_like( 'elementor' ) . '%',
				'%' . $this->wpdb->esc_like( 'custom_css' ) . '%',
				self::HARVEST_SIZE,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'termmeta';
			$state['offset'] = 0;
			return $state;
		}

		foreach ( $rows as $row ) {
			$state = $this->ingest_meta_row( $state, $row->option_name, $row->option_value );
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::HARVEST_SIZE ) {
			$state['phase']  = 'termmeta';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * Cosecha termmeta.
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_termmeta( $state ) {
		$offset = (int) $state['offset'];
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$this->wpdb->termmeta}
				WHERE meta_value <> ''
				  AND (
					meta_key IN ( 'thumbnail_id', '_thumbnail_id' )
					OR meta_value LIKE %s
					OR meta_value LIKE %s
				  )
				ORDER BY meta_id ASC
				LIMIT %d OFFSET %d",
				'%' . $this->wpdb->esc_like( '/uploads/' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-content/uploads' ) . '%',
				self::HARVEST_SIZE,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'usermeta';
			$state['offset'] = 0;
			return $state;
		}

		foreach ( $rows as $row ) {
			$state = $this->ingest_meta_row( $state, $row->meta_key, $row->meta_value );
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::HARVEST_SIZE ) {
			$state['phase']  = 'usermeta';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * Cosecha usermeta (avatares subidos a la biblioteca).
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_usermeta( $state ) {
		$offset = (int) $state['offset'];
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT umeta_id, meta_key, meta_value FROM {$this->wpdb->usermeta}
				WHERE meta_value <> ''
				  AND (
					meta_value LIKE %s
					OR meta_value LIKE %s
					OR meta_key LIKE %s
					OR meta_key LIKE %s
				  )
				ORDER BY umeta_id ASC
				LIMIT %d OFFSET %d",
				'%' . $this->wpdb->esc_like( '/uploads/' ) . '%',
				'%' . $this->wpdb->esc_like( 'wp-content/uploads' ) . '%',
				'%' . $this->wpdb->esc_like( 'avatar' ) . '%',
				'%' . $this->wpdb->esc_like( 'image' ) . '%',
				self::HARVEST_SIZE,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'css_list';
			$state['offset'] = 0;
			return $state;
		}

		foreach ( $rows as $row ) {
			$state = $this->ingest_meta_row( $state, $row->meta_key, $row->meta_value );
		}

		$state['offset'] = $offset + count( $rows );
		if ( count( $rows ) < self::HARVEST_SIZE ) {
			$state['phase']  = 'css_list';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * Lista ficheros CSS del tema y de /uploads (sin entrar en carpetas año/mes).
	 *
	 * @param array $state
	 * @return array
	 */
	protected function list_css_files( $state ) {
		$files = array();
		$upload_dir = wp_upload_dir();
		$basedir    = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';

		if ( $basedir && is_dir( $basedir ) ) {
			$this->collect_css_files(
				$basedir,
				$files,
				array( 'webp-nc-quarantine', 'webp-native-converter-logs', 'woocommerce_uploads', 'wc-logs' ),
				true
			);
		}

		$stylesheet = get_stylesheet_directory();
		$template   = get_template_directory();
		if ( $stylesheet ) {
			$this->collect_css_files( $stylesheet, $files, array( 'node_modules', 'vendor', '.git' ), false );
		}
		if ( $template && $template !== $stylesheet ) {
			$this->collect_css_files( $template, $files, array( 'node_modules', 'vendor', '.git' ), false );
		}

		$files = array_values( array_unique( $files ) );
		if ( count( $files ) > self::MAX_CSS_FILES ) {
			$files = array_slice( $files, 0, self::MAX_CSS_FILES );
		}

		$state['css_files'] = $files;
		$state['offset']    = 0;
		$state['phase']     = empty( $files ) ? 'compare' : 'css_scan';

		return $state;
	}

	/**
	 * Extrae url(...) de un lote de CSS.
	 *
	 * @param array $state
	 * @return array
	 */
	protected function harvest_css_files( $state ) {
		$files  = isset( $state['css_files'] ) ? (array) $state['css_files'] : array();
		$offset = (int) $state['offset'];
		$chunk  = array_slice( $files, $offset, self::CSS_BATCH );

		if ( empty( $chunk ) ) {
			$state['phase']     = 'compare';
			$state['offset']    = 0;
			$state['css_files'] = array();
			return $state;
		}

		foreach ( $chunk as $path ) {
			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$size = @filesize( $path );
			if ( ! $size || $size > 2 * MB_IN_BYTES ) {
				continue;
			}
			$css = file_get_contents( $path );
			if ( is_string( $css ) && '' !== $css ) {
				$state = $this->ingest_css( $state, $css );
			}
		}

		$state['offset'] = $offset + count( $chunk );
		if ( $state['offset'] >= count( $files ) ) {
			$state['phase']     = 'compare';
			$state['offset']    = 0;
			$state['css_files'] = array();
		}

		return $state;
	}

	/**
	 * Compara adjuntos de imagen contra el índice de usados.
	 *
	 * @param array $state
	 * @return array
	 */
	protected function compare_attachments( $state ) {
		$offset = (int) $state['offset'];
		$ids    = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts}
				WHERE post_type = 'attachment'
				  AND post_status IN ( 'inherit', 'private' )
				  AND post_mime_type LIKE %s
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				'image/%',
				self::COMPARE_SIZE,
				$offset
			)
		);

		if ( empty( $ids ) ) {
			$state['phase']  = 'done';
			$state['offset'] = 0;
			return $state;
		}

		$quarantined = $this->get_quarantined_ids();
		$upload_dir  = wp_upload_dir();
		$basedir     = $upload_dir['basedir'];

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id || isset( $quarantined[ $attachment_id ] ) ) {
				continue;
			}

			if ( $this->attachment_is_used( $state, $attachment_id ) ) {
				continue;
			}

			$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$full_path     = $attached_file ? path_join( $basedir, $attached_file ) : '';
			$bytes         = ( $full_path && file_exists( $full_path ) ) ? (int) @filesize( $full_path ) : 0;
			$post          = get_post( $attachment_id );
			$thumb         = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

			$state['unused'][] = array(
				'id'       => $attachment_id,
				'title'    => $post ? $post->post_title : '',
				'filename' => $attached_file ? wp_basename( $attached_file ) : '',
				'file'     => $attached_file,
				'bytes'    => $bytes,
				'size'     => $bytes ? size_format( $bytes ) : '—',
				'date'     => $post ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '',
				'thumb'    => $thumb ? $thumb : '',
			);
		}

		$state['processed_attachments'] = $offset + count( $ids );
		$state['offset']                = $offset + count( $ids );

		if ( count( $ids ) < self::COMPARE_SIZE ) {
			$state['phase']  = 'done';
			$state['offset'] = 0;
		}

		return $state;
	}

	/**
	 * ¿Este adjunto aparece en el índice de usados?
	 *
	 * @param array $state
	 * @param int   $attachment_id
	 * @return bool
	 */
	protected function attachment_is_used( $state, $attachment_id ) {
		if ( isset( $state['used_ids'][ $attachment_id ] ) ) {
			return true;
		}

		$paths = $this->attachment_relative_paths( $attachment_id );
		foreach ( $paths as $rel ) {
			if ( isset( $state['used_paths'][ $rel ] ) ) {
				return true;
			}
			$base = strtolower( wp_basename( $rel ) );
			if ( $base && isset( $state['used_filenames'][ $base ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rutas relativas (principal + tamaños) de un adjunto.
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	protected function attachment_relative_paths( $attachment_id ) {
		$paths         = array();
		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached_file ) {
			$paths[] = $this->normalize_relative_key( $attached_file );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = $attached_file ? dirname( $attached_file ) : '';
		if ( '.' === $dir ) {
			$dir = '';
		}

		if ( ! empty( $meta['file'] ) ) {
			$paths[] = $this->normalize_relative_key( $meta['file'] );
		}

		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$orig = $meta['original_image'];
			if ( false === strpos( $orig, '/' ) && $dir ) {
				$orig = trailingslashit( $dir ) . $orig;
			}
			$paths[] = $this->normalize_relative_key( $orig );
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
				$paths[] = $this->normalize_relative_key( $file );
			}
		}

		return array_filter( array_unique( $paths ) );
	}

	/**
	 * IDs ya en cuarentena (no deben salir otra vez como no usadas).
	 *
	 * @return array<int,int>
	 */
	protected function get_quarantined_ids() {
		$index = get_option( MediaQuarantine::INDEX_OPTION, array() );
		$out   = array();
		if ( is_array( $index ) ) {
			foreach ( array_keys( $index ) as $id ) {
				$out[ absint( $id ) ] = 1;
			}
		}
		return $out;
	}

	/**
	 * Parsea el HTML/bloques de un post.
	 *
	 * @param array  $state
	 * @param string $content
	 * @return array
	 */
	protected function ingest_post_content( $state, $content ) {
		$state = $this->ingest_string( $state, $content );

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$state = $this->mark_id( $state, (int) $id );
			}
		}

		if ( preg_match_all( '/attachment_id=(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$state = $this->mark_id( $state, (int) $id );
			}
		}

		if ( preg_match_all( '/wp-att-(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$state = $this->mark_id( $state, (int) $id );
			}
		}

		// Comentarios de bloque Gutenberg con contexto de media.
		if ( preg_match_all( '/<!-- wp:(image|cover|media-text|gallery|file|video|audio|post-featured-image)\s+(\{.*?\})\s+/s', $content, $blocks, PREG_SET_ORDER ) ) {
			foreach ( $blocks as $block ) {
				$json = json_decode( $block[2], true );
				if ( is_array( $json ) ) {
					$state = $this->walk_data( $state, $json, $block[1] );
				}
			}
		}

		return $state;
	}

	/**
	 * Ingiere una fila meta/option.
	 *
	 * @param array  $state
	 * @param string $key
	 * @param mixed  $value
	 * @return array
	 */
	protected function ingest_meta_row( $state, $key, $value ) {
		if ( $this->is_self_file_meta( $key ) ) {
			return $state;
		}

		if ( $this->is_image_key( $key ) && is_string( $value ) && ctype_digit( $value ) ) {
			$state = $this->mark_id( $state, (int) $value );
		}

		if ( is_string( $value ) && false !== strpos( $value, ',' ) && $this->is_image_key( $key ) ) {
			$all_digits = true;
			foreach ( explode( ',', $value ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				if ( ! ctype_digit( $part ) ) {
					$all_digits = false;
					break;
				}
				$state = $this->mark_id( $state, (int) $part );
			}
			if ( $all_digits ) {
				return $state;
			}
		}

		return $this->walk_data( $state, $value, $key );
	}

	/**
	 * Recorre string/array/objeto/JSON/serializado extrayendo IDs y rutas.
	 *
	 * @param array  $state
	 * @param mixed  $data
	 * @param string $parent_key
	 * @return array
	 */
	protected function walk_data( $state, $data, $parent_key = '' ) {
		if ( is_array( $data ) ) {
			if ( $this->array_looks_like_media( $data ) ) {
				if ( isset( $data['id'] ) ) {
					$state = $this->mark_id( $state, (int) $data['id'] );
				}
				if ( isset( $data['ID'] ) ) {
					$state = $this->mark_id( $state, (int) $data['ID'] );
				}
				if ( isset( $data['mediaId'] ) ) {
					$state = $this->mark_id( $state, (int) $data['mediaId'] );
				}
			}

			foreach ( $data as $key => $val ) {
				$state = $this->walk_data( $state, $val, is_string( $key ) ? $key : $parent_key );
			}
			return $state;
		}

		if ( is_object( $data ) ) {
			return $this->walk_data( $state, get_object_vars( $data ), $parent_key );
		}

		if ( is_int( $data ) || ( is_float( $data ) && $data == (int) $data ) ) {
			if ( $this->is_image_key( $parent_key ) ) {
				$state = $this->mark_id( $state, (int) $data );
			}
			return $state;
		}

		if ( ! is_string( $data ) || '' === $data ) {
			return $state;
		}

		if ( ctype_digit( $data ) ) {
			if ( $this->is_image_key( $parent_key ) ) {
				$state = $this->mark_id( $state, (int) $data );
			}
			return $state;
		}

		if ( is_serialized( $data ) ) {
			$unserialized = @maybe_unserialize( $data );
			if ( false !== $unserialized || 'b:0;' === $data ) {
				return $this->walk_data( $state, $unserialized, $parent_key );
			}
		}

		$trim = ltrim( $data );
		if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
			$json = json_decode( $data, true );
			if ( JSON_ERROR_NONE === json_last_error() && null !== $json ) {
				return $this->walk_data( $state, $json, $parent_key );
			}
		}

		return $this->ingest_string( $state, $data );
	}

	/**
	 * Extrae rutas /uploads y url() de un string plano.
	 *
	 * @param array  $state
	 * @param string $text
	 * @return array
	 */
	protected function ingest_string( $state, $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $state;
		}

		$text = wp_unslash( $text );
		$text = str_replace( '\\/', '/', $text );

		if ( preg_match_all( '#(?:https?:)?//[^\'"\s\)]+/wp-content/uploads/([^\'"\s\)]+)#i', $text, $m ) ) {
			foreach ( $m[1] as $rel ) {
				$state = $this->mark_path( $state, $rel );
			}
		}

		if ( preg_match_all( '#/wp-content/uploads/([^\'"\s\)]+)#i', $text, $m ) ) {
			foreach ( $m[1] as $rel ) {
				$state = $this->mark_path( $state, $rel );
			}
		}

		if ( preg_match_all( '#(?:^|["\'\s])(\d{4}/\d{2}/[A-Za-z0-9_\-\.\(\)%]+\.(?:jpe?g|png|gif|webp|avif|svg))#i', $text, $m ) ) {
			foreach ( $m[1] as $rel ) {
				$state = $this->mark_path( $state, $rel );
			}
		}

		return $state;
	}

	/**
	 * Extrae url(...) de CSS.
	 *
	 * @param array  $state
	 * @param string $css
	 * @return array
	 */
	protected function ingest_css( $state, $css ) {
		$state = $this->ingest_string( $state, $css );

		if ( ! preg_match_all( '/url\s*\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $css, $m ) ) {
			return $state;
		}

		foreach ( $m[1] as $raw ) {
			$rel = $this->url_to_uploads_relative( $raw );
			if ( $rel ) {
				$state = $this->mark_path( $state, $rel );
				continue;
			}
			$clean = $this->strip_url_noise( $raw );
			$base  = strtolower( wp_basename( $clean ) );
			if ( $base && preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $base ) ) {
				$state['used_filenames'][ $base ] = 1;
			}
		}

		return $state;
	}

	/**
	 * Recorre un directorio recogiendo .css.
	 *
	 * @param string   $root
	 * @param string[] $out
	 * @param string[] $skip_names
	 * @param bool     $skip_year_dirs Si true, no entra en carpetas 2024/ típicas de uploads.
	 */
	protected function collect_css_files( $root, &$out, $skip_names, $skip_year_dirs ) {
		if ( ! is_dir( $root ) ) {
			return;
		}

		try {
			$inner = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
			$filter = new \RecursiveCallbackFilterIterator(
				$inner,
				function ( $current ) use ( $skip_names, $skip_year_dirs ) {
					$name = $current->getFilename();
					if ( $current->isDir() ) {
						if ( in_array( $name, $skip_names, true ) ) {
							return false;
						}
						if ( $skip_year_dirs && preg_match( '/^\d{4}$/', $name ) ) {
							return false;
						}
					}
					return true;
				}
			);

			$iterator = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $iterator as $file ) {
				if ( count( $out ) >= self::MAX_CSS_FILES ) {
					return;
				}
				if ( strtolower( $file->getExtension() ) !== 'css' ) {
					continue;
				}
				$out[] = $file->getPathname();
			}
		} catch ( \Exception $e ) {
			return;
		}
	}

	/**
	 * Convierte una URL o path a ruta relativa dentro de /uploads.
	 *
	 * @param string $url
	 * @return string|null
	 */
	protected function url_to_uploads_relative( $url ) {
		$url = $this->strip_url_noise( $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return null;
		}

		$uploads     = wp_upload_dir();
		$baseurl     = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
		$url_np      = preg_replace( '#^https?:#i', '', $url );
		$baseurl_np  = preg_replace( '#^https?:#i', '', $baseurl );

		if ( $baseurl_np && false !== strpos( $url_np, $baseurl_np ) ) {
			$rel = substr( $url_np, strpos( $url_np, $baseurl_np ) + strlen( $baseurl_np ) );
			return $this->normalize_relative_key( $rel );
		}

		$needle = '/wp-content/uploads/';
		$pos    = stripos( $url, $needle );
		if ( false !== $pos ) {
			return $this->normalize_relative_key( substr( $url, $pos + strlen( $needle ) ) );
		}

		if ( preg_match( '#^\d{4}/\d{2}/.+#', $url ) ) {
			return $this->normalize_relative_key( $url );
		}

		return null;
	}

	/**
	 * @param string $url
	 * @return string
	 */
	protected function strip_url_noise( $url ) {
		$url = trim( $url, " \t\n\r\0\x0B\"'" );
		$url = wp_unslash( $url );
		$url = str_replace( '\\/', '/', $url );
		$url = preg_replace( '/[?#].*$/', '', $url );
		return $url;
	}

	/**
	 * @param string $rel
	 * @return string
	 */
	protected function normalize_relative_key( $rel ) {
		$rel = $this->strip_url_noise( $rel );
		$rel = ltrim( $rel, '/' );
		$rel = rawurldecode( $rel );
		$rel = str_replace( '\\', '/', $rel );
		return strtolower( $rel );
	}

	/**
	 * @param array $state
	 * @param int   $id
	 * @return array
	 */
	protected function mark_id( $state, $id ) {
		$id = absint( $id );
		if ( $id ) {
			$state['used_ids'][ $id ] = 1;
		}
		return $state;
	}

	/**
	 * @param array  $state
	 * @param string $rel
	 * @return array
	 */
	protected function mark_path( $state, $rel ) {
		$rel = $this->normalize_relative_key( $rel );
		if ( '' === $rel ) {
			return $state;
		}
		$state['used_paths'][ $rel ] = 1;
		return $state;
	}

	/**
	 * ¿El array parece un objeto de media de maquetador?
	 *
	 * @param array $arr
	 * @return bool
	 */
	protected function array_looks_like_media( $arr ) {
		$has_id  = isset( $arr['id'] ) || isset( $arr['ID'] ) || isset( $arr['mediaId'] );
		$has_url = isset( $arr['url'] ) || isset( $arr['src'] ) || isset( $arr['file'] );
		$has_ctx = isset( $arr['size'] ) || isset( $arr['sizes'] ) || isset( $arr['source'] ) || isset( $arr['alt'] ) || isset( $arr['width'] );
		return $has_id && ( $has_url || $has_ctx );
	}

	/**
	 * Claves que suelen guardar un attachment ID o un objeto de imagen.
	 *
	 * @param string $key
	 * @return bool
	 */
	protected function is_image_key( $key ) {
		$key = strtolower( (string) $key );
		if ( '' === $key ) {
			return false;
		}

		$needles = array(
			'image',
			'img',
			'thumbnail',
			'thumb',
			'logo',
			'icon',
			'avatar',
			'background',
			'photo',
			'picture',
			'banner',
			'gallery',
			'cover',
			'attachment',
			'poster',
			'featured',
			'hero',
			'og_image',
			'mediaid',
			'_media',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Metas del propio adjunto: si las escaneáramos, todas las imágenes saldrían “usadas”.
	 *
	 * @param string $key
	 * @return bool
	 */
	protected function is_self_file_meta( $key ) {
		if ( 0 === strpos( (string) $key, '_webp_nc_' ) ) {
			return true;
		}
		return in_array(
			$key,
			array(
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_wp_attachment_backup_sizes',
			),
			true
		);
	}

	/**
	 * @return string[]
	 */
	protected function self_file_meta_keys() {
		return array(
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_attachment_backup_sizes',
			'_webp_nc_converted',
			'_webp_nc_original_size',
			'_webp_nc_webp_size',
			'_webp_nc_saved_bytes',
			'_webp_nc_quarantine',
		);
	}

	/**
	 * @return string[]
	 */
	protected function known_image_meta_keys() {
		return array(
			'_thumbnail_id',
			'_product_image_gallery',
			'thumbnail_id',
			'_elementor_data',
			'_elementor_page_settings',
			'_elementor_css',
			'_bricks_page_content',
			'_bricks_page_content_2',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
			'_bricks_template_settings',
		);
	}

	/**
	 * @return array
	 */
	protected function get_state() {
		$state = get_transient( self::TRANSIENT_KEY );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array $state
	 */
	protected function save_state( $state ) {
		set_transient( self::TRANSIENT_KEY, $state, self::TRANSIENT_TTL );
	}

	/**
	 * Payload de progreso para el cliente.
	 *
	 * @param array  $state
	 * @param string $message
	 * @return array
	 */
	protected function progress_payload( $state, $message ) {
		$done = ( 'done' === $state['phase'] );

		return array(
			'done'     => $done,
			'phase'    => $state['phase'],
			'progress' => $this->estimate_progress( $state ),
			'message'  => $message,
			'scanned'  => (int) $state['attachment_total'],
			'unused'   => $done ? array_values( $state['unused'] ) : null,
			'count'    => $done ? count( $state['unused'] ) : 0,
		);
	}

	/**
	 * Progreso aproximado 0-100 según fase.
	 *
	 * @param array $state
	 * @return int
	 */
	protected function estimate_progress( $state ) {
		$map = array(
			'seed'     => 4,
			'posts'    => 12,
			'postmeta' => 32,
			'options'  => 52,
			'termmeta' => 62,
			'usermeta' => 70,
			'css_list' => 75,
			'css_scan' => 82,
			'compare'  => 88,
			'done'     => 100,
		);

		$base = isset( $map[ $state['phase'] ] ) ? $map[ $state['phase'] ] : 0;

		if ( 'compare' === $state['phase'] && ! empty( $state['attachment_total'] ) ) {
			$frac = min( 1, (int) $state['processed_attachments'] / (int) $state['attachment_total'] );
			$base = 88 + (int) round( $frac * 11 );
		}

		if ( 'css_scan' === $state['phase'] && ! empty( $state['css_files'] ) ) {
			$total = count( $state['css_files'] );
			$frac  = min( 1, (int) $state['offset'] / $total );
			$base  = 75 + (int) round( $frac * 12 );
		}

		return min( 100, max( 0, $base ) );
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

	/**
	 * Un lote de cosecha en hosting compartido se puede ir a 30s. Pedimos margen.
	 */
	protected function bump_time_limit() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}
	}
}
