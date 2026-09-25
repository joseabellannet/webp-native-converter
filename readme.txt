=== WebP Native Converter ===
Contributors: joseabellan84
Tags: webp, images, optimize, performance, converter
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convierte JPG y PNG a WebP en el servidor, actualiza la base de datos y permite revisar las imágenes antes de convertir.

== Description ==

WebP Native Converter convierte imágenes JPG, JPEG y PNG a WebP en tu propio servidor. No usa APIs externas ni límites mensuales.

El plugin actualiza las referencias en la base de datos, incluyendo cadenas serializadas de maquetadores. Puedes revisar el listado de pendientes, desmarcar las que no quieras tocar y estimar el ahorro antes de convertir.

Características:

* Conversión local con GD o Imagick (basta con una de las dos).
* Conversión automática al subir y conversión por lotes de la biblioteca.
* Revisión previa: listado con peso actual, ahorro estimado y selección.
* Conservación opcional de los originales JPG/PNG.
* Detector conservador de imágenes no usadas, con cuarentena y restauración.
* Columna de estado en la biblioteca de medios.

El autor es Jose Antonio Abellán. Sitio: https://joseabellan.net

== Installation ==

1. Sube la carpeta del plugin a `/wp-content/plugins/webp-native-converter/`.
2. Activa el plugin desde "Plugins" en WordPress.
3. Ve a "WebP Converter" en el menú de administración.
4. Haz una copia de seguridad de archivos y base de datos antes de convertir.

Si instalas el ZIP descargado de GitHub, la carpeta puede llamarse `webp-native-converter-main`. Renómbrala a `webp-native-converter` o sustituye la carpeta existente para no tener dos copias.

== Frequently Asked Questions ==

= ¿Hace falta Imagick? =

No. Basta con GD compilado con soporte WebP. Imagick es opcional.

= ¿Se pierden las imágenes originales? =

Por defecto se conservan. Puedes borrarlas después con "Eliminar originales conservados".

= ¿El detector de no usadas es exacto? =

No. Si hay duda, la imagen se considera usada. No detecta sliders propios, JS de temas ni CDNs con otra URL. Las candidatas van a cuarentena, no se borran al instante.

= ¿Funciona con WooCommerce, Elementor o Bricks? =

Actualiza URLs en contenido y metadatos, incluidas cadenas serializadas. Revisa siempre un producto o página de prueba después de convertir.

== Changelog ==

= 1.0.2 =
* Listado previo de imágenes a convertir, con ahorro estimado y selección.
* Restaurar todas y eliminar todas en cuarentena.
* Ajustes de seguridad, escape y readme.txt para WordPress.org.

= 1.0.1 =
* Evita un fatal si hay dos copias del plugin instaladas.
* Tarjeta de autor actualizada.

= 1.0.0 =
* Primera versión pública.

== Upgrade Notice ==

= 1.0.2 =
Revisión previa del lote y cumplimiento de requisitos de WordPress.org.
