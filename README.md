# WebP Native Converter — Especificación Técnica & Documentación

**WebP Native Converter** es un plugin nativo y 100% gratuito para WordPress, diseñado para convertir imágenes (JPG, JPEG, PNG) a formato **WebP** de forma local, ilimitada y permanente.

A diferencia de otras soluciones basadas en suscripciones en la nube o reescrituras temporales en el servidor, este plugin realiza una migración nativa y limpia directamente en el servidor. Actualiza las referencias en la base de datos sin romper cadenas serializadas PHP ni afectar la compatibilidad con maquetadores visuales avanzados.

## 1. Declaración de Autoría y Licencia

* **Desarrollador:** Jose Antonio Abellán

* **Sitio web:** https://joseabellan.net

* **GitHub:** https://github.com/joseabellannet/webp-native-converter

* **Licencia:** GPL-2.0+ (Software Libre e Ilimitado)

* **Filosofía:** Este plugin ha sido creado y cedido gratuitamente a la comunidad de WordPress. No incluye límites de imágenes por mes, ventas cruzadas (*upsells*) ni dependencias de APIS o servidores externos de pago.

## 2. Descargo de Responsabilidad y Avisos de Seguridad (Disclaimer)

> ⚠️ **¡ADVERTENCIA DE SEGURIDAD OBLIGATORIA!**
>
> **REALIZA UNA COPIA DE SEGURIDAD COMPLETA ANTES DE USAR ESTE PLUGIN.**
>
> * **Modificación de archivos y datos:** Este plugin modifica archivos físicos en la carpeta `/uploads/` y actualiza registros directamente en la base de datos (`wp_posts`, `wp_postmeta`, tablas de maquetadores).
>
> * **Exención de responsabilidad:** El desarrollador no se hace responsable de pérdidas de datos, fallos en el servidor o incompatibilidades con temas/plugins de terceros durante el proceso de conversión.
>
> * **Requisito obligatorio:** Se exige realizar una copia de seguridad completa (archivos y base de datos) antes de ejecutar cualquier proceso de conversión por lotes. La interfaz del plugin requerirá la confirmación explícita del usuario mediante una casilla de verificación previa a cualquier acción masiva.

## 3. Requisitos del Sistema

* **WordPress:** 6.0 o superior.

* **PHP:** 7.4 o superior (Recomendado 8.1+).

* **Módulos de PHP requeridos:**

  * Extensión `GD` (con soporte compilado para WebP) **O** extensión `Imagick` (librería ImageMagick con soporte WebP).

  * Extensión `JSON` activada.

* **Permisos de escritura:** Permisos de lectura/escritura en la carpeta `/wp-content/uploads/`.

## 4. Arquitectura de Archivos y Directorios

El plugin sigue el estándar de programación orientada a objetos (OOP) con arquitectura modular, separación de responsabilidades y carga automática PSR-4 mediante Namespaces (`WebPNativeConverter\`).

```
webp-native-converter/
├── webp-native-converter.php        # Bootstrap del plugin, cabeceras de WP y constantes
├── README.md                        # Documentación técnica y especificaciones
├── uninstall.php                    # Limpieza segura durante la desinstalación
├── includes/
│   ├── class-autoloader.php         # Autocargador PSR-4
│   ├── class-plugin.php             # Orquestador principal e inicializador de módulos
│   ├── core/                        # Motor principal de lógica
│   │   ├── class-converter.php      # Compresión y renderizado a WebP (GD/Imagick)
│   │   ├── class-db-replacer.php    # Reemplazo seguro en DB y manejo de cadenas serializadas
│   │   ├── class-media-upload.php   # Interceptación de nuevas subidas a la biblioteca
│   │   └── class-batch-processor.php# Procesamiento asíncrono por lotes (AJAX)
│   ├── admin/                       # Interfaz de usuario (UI/UX)
│   │   ├── class-admin-menu.php     # Panel de control y ajustes en wp-admin
│   │   └── class-media-columns.php  # Badges e información en la Biblioteca de Medios
│   └── utils/                       # Herramientas auxiliares
│       ├── class-system-check.php   # Verificación de librerías y memoria del servidor
│       └── class-logger.php         # Log interno de errores y estado de conversiones
└── assets/                          # Archivos estáticos de la interfaz
    ├── css/
    │   └── admin-style.css          # Estilos del panel de administración
    └── js/
        ├── batch-process.js         # Control AJAX del lote y barra de progreso
        └── preview-slider.js        # Comparador visual (Antes / Después)

```

## 5. Flujos de Trabajo del Motor

### A. Subida de Imágenes Futuras (`class-media-upload.php`)

1. Intercepta el filtro `wp_handle_upload` durante el proceso de carga.

2. Verifica el tipo MIME (`image/jpeg`, `image/png`, `image/jpg`).

3. Procesa el archivo original y los tamaños recortados intermedios del tema (*thumbnails*).

4. Genera las réplicas en WebP usando `class-converter.php`.

5. Valida integridad y actualiza los metadatos del adjunto (`wp_postmeta`).

### B. Conversión por Lotes de Imágenes Existentes (`class-batch-processor.php`)

1. El script cliente (`batch-process.js`) requiere la confirmación previa del backup por parte del usuario.

2. Consulta los adjuntos pendientes vía REST API o endpoint AJAX (`wp_ajax_`).

3. Ejecuta peticiones secuenciales en bloques pequeños (5-10 imágenes por lote):

   * Convierte la imagen física y sus miniaturas.

   * Valida existencia y peso mayor a `0 bytes`.

   * Ejecuta `class-db-replacer.php` para actualizar las referencias en el contenido.

   * Devuelve respuesta JSON con el estado individual (`success`/`error`) y el espacio ahorrado.

### C. Reemplazo Seguro en Base de Datos (`class-db-replacer.php`)

1. **Detección de cadenas serializadas:** Analiza si un registro es un array/objeto PHP serializado (`is_serialized`).

2. **Deserialización segura:** Aplica `maybe_unserialize()`, reemplaza las URLs `.jpg`/`.png` por `.webp`, recalculando la longitud exacta de la cadena, y serializa nuevamente (`maybe_serialize()`).

3. **Limpieza de caché:** Invalida o purga cachés conocidas de maquetadores visuales (Elementor, Bricks Builder, Divi) tras actualizar las referencias.

## 6. Hoja de Ruta del Desarrollo (Sprints)

* \[x\] **Fase 1: Estructura Base y Comprobación del Sistema**

  * Creación de `README.md`, archivo principal, autoloader y `class-system-check.php`.

* \[ \] **Fase 2: Motor de Conversión de Imágenes**

  * Desarrollo de `class-converter.php` soportando `GD` e `Imagick` con control de calidad (1-100%).

* \[ \] **Fase 3: Integración con Nuevas Subidas**

  * Implementación de `class-media-upload.php` para conversión al vuelo.

* \[ \] **Fase 4: Motor de Base de Datos y Seguridad**

  * Desarrollo de `class-db-replacer.php` con manejo de cadenas serializadas.

* \[ \] **Fase 5: Procesador por Lotes AJAX e Interfaz**

  * Construcción de la barra de progreso, alertas de backup obligatorio y `class-batch-processor.php`.

* \[ \] **Fase 6: Panel de Control (UI), Atribución y Herramientas Visuales**

  * Estadísticas de ahorro en el dashboard, firma del autor, comparador visual y columna en la biblioteca de medios.