/**
 * Comparador visual interactivo (Antes / Después)
 *
 * Este script se encarga de que la barra del comparador visual en el panel de control
 * siga el ratón o el dedo en pantallas táctiles sin que se sienta tosco.
 *
 * @package WebPNativeConverter
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		const $slider = $('#webp-preview-slider');
		
		// Si no estamos en la página del dashboard, nos salimos rápido.
		if (!$slider.length) {
			return;
		}

		const $beforeImage = $slider.find('.webp-nc-image-before');
		const $handle = $slider.find('.webp-nc-slider-handle');
		let isDragging = false;

		// Lógica principal: calcula el porcentaje de posición y mueve la imagen y el manejador.
		function updateSlider(pageX) {
			const offset = $slider.offset();
			const width = $slider.outerWidth();
			let posX = pageX - offset.left;

			// Ponemos límites para que no se salga por los lados.
			if (posX < 0) {
				posX = 0;
			} else if (posX > width) {
				posX = width;
			}

			const percentage = (posX / width) * 100;
			$beforeImage.css('width', percentage + '%');
			$handle.css('left', percentage + '%');
		}

		// Eventos de arrastre: empezamos al presionar...
		$handle.on('mousedown touchstart', function (e) {
			isDragging = true;
			e.preventDefault(); // Evitamos que el navegador haga drag&drop nativo.
		});

		// ... y soltamos al levantar el clic.
		$(window).on('mouseup touchend', function () {
			isDragging = false;
		});

		// Movimiento continuo mientras mantenemos presionado.
		$(window).on('mousemove touchmove', function (e) {
			if (!isDragging) {
				return;
			}
			// Soporte tanto para ratón como para móvil (touch).
			const pageX = e.type === 'touchmove' ? e.originalEvent.touches[0].pageX : e.pageX;
			updateSlider(pageX);
		});

		// Un pequeño detalle UX: permitir clic directo en cualquier punto del contenedor
		// para saltar a esa posición sin tener que arrastrar.
		$slider.on('click', function (e) {
			if ($(e.target).closest('.webp-nc-slider-handle').length) {
				return; // Si el clic fue en el manejador, no hacemos nada (ya lo maneja mousedown).
			}
			updateSlider(e.pageX);
		});
	});
})(jQuery);
