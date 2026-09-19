/**
 * Control del procesador por lotes AJAX y conversión individual en la biblioteca.
 *
 * Este archivo gestiona la cola de imágenes. En lugar de petar el servidor pidiendo
 * que procese 10.000 imágenes de golpe, pedimos los IDs al servidor, armamos la cola
 * en memoria (aquí en JS) y procesamos en paquetes pequeños de 3 en 3.
 *
 * @package WebPNativeConverter
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// ==========================================
		// 1. CONVERSIÓN MASIVA POR LOTES (DASHBOARD)
		// ==========================================
		const $btnStart       = $('#btn-start-batch');
		const $btnStop        = $('#btn-stop-batch');
		const $backupCheck    = $('#webp_nc_backup_check');
		const $progressWrap   = $('#batch-progress-wrapper');
		const $progressBar    = $('#batch-progress-bar');
		const $statusText     = $('#batch-status-text');
		const $percentText    = $('#batch-percent-text');
		const $console        = $('#batch-console');
		const $btnClearLog    = $('#btn-clear-console');

		let queue             = [];
		let totalItems        = 0;
		let processedItems    = 0;
		let totalSavedBytes   = 0;
		let isRunning         = false;
		const batchChunkSize  = 3; // Lotes de 3. Un número seguro para no dar timeouts en hostings baratos.

		// Prevención de recarga accidental: si el proceso está corriendo, avisamos al usuario antes de salir.
		$(window).on('beforeunload', function () {
			if (isRunning) {
				return "Tienes una conversión masiva en progreso. Si sales de esta página, el proceso se detendrá y la cola actual se perderá en la memoria. ¿Seguro que quieres salir?";
			}
		});

		// Helper rápido para escribir líneas en la pseudo-consola del panel.
		function appendLog(message, type = 'normal') {
			if (!$console.length) {
				return;
			}
			const now = new Date();
			const timeStr = now.toLocaleTimeString();
			let cssClass = 'webp-nc-console-line';

			if (type === 'success') {
				cssClass += ' log-success';
			} else if (type === 'error') {
				cssClass += ' log-error';
			} else if (type === 'muted') {
				cssClass += ' text-muted';
			}

			const $line = $('<div>', { class: cssClass, text: `[${timeStr}] ${message}` });
			$console.append($line);
			// Mantenemos el scroll pegado abajo del todo
			$console.scrollTop($console[0].scrollHeight);
		}

		if ($btnClearLog.length) {
			$btnClearLog.on('click', function () {
				$console.empty();
				appendLog('Consola reiniciada.', 'muted');
			});
		}

		if ($btnStart.length) {
			$btnStart.on('click', function () {
				// Cuidado aquí: fuerzo a que marquen el checkbox de copia de seguridad.
				// Si no lo marcan, les pongo el recuadro en rojo unos segundos.
				if (!$backupCheck.is(':checked')) {
					alert(webpNcData.i18n.confirmBackup);
					$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '0 0 0 2px #ef4444');
					setTimeout(function () {
						$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '');
					}, 2000);
					return;
				}

				isRunning = true;
				$btnStart.hide();
				$btnStop.show();
				$progressWrap.slideDown(200);

				appendLog('Consultando imágenes pendientes en la base de datos...', 'normal');
				$statusText.text(webpNcData.i18n.processing);

				// Petición inicial: le pedimos a PHP que nos dé TODOS los IDs pendientes.
				$.ajax({
					url: webpNcData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'webp_nc_get_pending_images',
						nonce: webpNcData.nonce
					},
					success: function (response) {
						if (response.success && response.data.ids.length > 0) {
							// Guardamos la cola en memoria para empezar a consumirla
							queue          = response.data.ids;
							totalItems     = queue.length;
							processedItems = 0;

							appendLog(`Encontradas ${totalItems} imágenes para procesar.`, 'normal');
							processNextBatch();
						} else {
							// Todo estaba al día.
							isRunning = false;
							$btnStart.show();
							$btnStop.hide();
							$statusText.text(webpNcData.i18n.noImagesFound);
							appendLog(webpNcData.i18n.noImagesFound, 'muted');
						}
					},
					error: function () {
						isRunning = false;
						$btnStart.show();
						$btnStop.hide();
						appendLog('Error de conexión al obtener la cola de imágenes. Revisa tu internet o los logs de error de PHP.', 'error');
					}
				});
			});

			$btnStop.on('click', function () {
				isRunning = false;
				$btnStop.text(webpNcData.i18n.stopping).prop('disabled', true);
				appendLog('Pausando el procesamiento por solicitud del usuario...', 'muted');
			});
		}

		// El motor de recursividad que va vaciando la cola.
		function processNextBatch() {
			if (!isRunning) {
				// Si pausaron el proceso de forma explícita.
				$btnStop.text('Pausar').prop('disabled', false).hide();
				$btnStart.show();
				appendLog('Proceso en pausa. Puedes reanudarlo cuando desees sin perder el progreso (si no recargas la página).', 'muted');
				return;
			}

			if (queue.length === 0) {
				// Ya no quedan más IDs. ¡Victoria!
				isRunning = false;
				$btnStart.show();
				$btnStop.hide();
				$progressBar.css('width', '100%');
				$percentText.text('100%');
				$statusText.text(webpNcData.i18n.completed);
				appendLog(webpNcData.i18n.completed, 'success');

				$('#stat-total-pending').text('0');
				return;
			}

			// Sacamos de la cola los siguientes X elementos (chunk). Modifica la variable 'queue' original.
			const currentBatch = queue.splice(0, batchChunkSize);

			$.ajax({
				url: webpNcData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'webp_nc_process_batch',
					nonce: webpNcData.nonce,
					ids: currentBatch
				},
				success: function (response) {
					if (response.success) {
						processedItems  += response.data.processed;
						totalSavedBytes += response.data.saved_bytes;

						// Actualizamos visuales
						const percent = Math.min(100, Math.round((processedItems / totalItems) * 100));
						$progressBar.css('width', percent + '%');
						$percentText.text(percent + '%');
						$statusText.text(`Procesadas ${processedItems} de ${totalItems} imágenes...`);

						appendLog(`Lote procesado: ${response.data.processed} imágenes optimizadas (Ahorro: ${response.data.saved_human}).`, 'success');

						// Si el servidor encontró cosas raras pero no palmó (ej: faltaban los thumbnails), nos lo dice.
						if (response.data.errors && response.data.errors.length > 0) {
							response.data.errors.forEach(function (err) {
								appendLog(`Advertencia: ${err}`, 'error');
							});
						}

						const remaining = Math.max(0, totalItems - processedItems);
						$('#stat-total-pending').text(remaining);

						// Siguiente iteración recursiva, llamando a la misma función.
						processNextBatch();
					} else {
						// Falló la conversión de este lote, pero seguimos con el resto.
						appendLog(`Fallo en el lote: ${response.data.message}`, 'error');
						processNextBatch();
					}
				},
				error: function () {
					// Fallo a nivel de conexión o timeout de PHP 504/500. 
					// Nos esperamos 2 segundos y volvemos a intentarlo en lugar de abortar de golpe.
					appendLog('Error AJAX al procesar el lote actual. Reintentando en 2 segundos...', 'error');
					setTimeout(processNextBatch, 2000);
				}
			});
		}

		// ==========================================
		// 2. CONVERSIÓN INDIVIDUAL EN LISTA DE MEDIOS
		// ==========================================
		$(document).on('click', '.webp-nc-quick-convert', function (e) {
			e.preventDefault();
			const $btn = $(this);
			const attachmentId = $btn.data('id');
			const $container = $btn.closest('td');

			$btn.prop('disabled', true).text(webpNcData.i18n.processing);

			// Petición directa y simple, un archivo a la vez.
			$.ajax({
				url: webpNcData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'webp_nc_convert_single',
					nonce: webpNcData.nonce,
					attachment_id: attachmentId
				},
				success: function (response) {
					if (response.success) {
						// Todo guay, renderizamos la info de ahorro directamente cambiando el botón 
						// por el badge de éxito (así evitamos recargar la tabla entera de WP).
						$container.html(`
							<div class="webp-nc-col-badge webp-nc-badge-success">
								<span class="dashicons dashicons-yes"></span> <strong>WebP</strong>
							</div>
							<div class="webp-nc-col-details">
								<small>${response.data.original_formatted} &rarr; ${response.data.webp_formatted}</small><br>
								<span class="webp-nc-saving-tag">-${response.data.saved_percent}% ahorro</span>
							</div>
						`);
					} else {
						// Si algo falla, dejamos el botón habilitado por si quieren reintentar.
						$btn.prop('disabled', false).text('Reintentar');
						alert(response.data.message || webpNcData.i18n.error);
					}
				},
				error: function (xhr) {
					$btn.prop('disabled', false).text('Reintentar');
					alert('Error de conexión o fallo interno de PHP (' + xhr.status + '). Revisa los logs.');
				}
			});
		});

		// ==========================================
		// 3. LIBERADOR DE ESPACIO (DASHBOARD)
		// ==========================================
		const $btnCleanup = $('#btn-cleanup-originals');
		const $cleanupStatus = $('#cleanup-status');

		if ($btnCleanup.length) {
			$btnCleanup.on('click', function () {
				if (!confirm(webpNcData.i18n.confirmCleanup)) {
					return;
				}

				$btnCleanup.prop('disabled', true).text(webpNcData.i18n.cleaning);
				$cleanupStatus.css('color', '#646970').text(webpNcData.i18n.cleaning).show();

				$.ajax({
					url: webpNcData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'webp_nc_cleanup_originals',
						nonce: webpNcData.nonce
					},
					success: function (response) {
						if (response.success) {
							$btnCleanup.text('Completado');
							$cleanupStatus.css('color', '#135e96').text(
								response.data.message + (response.data.freed_bytes > 0 ? ` (Has recuperado ${response.data.freed_human})` : '')
							);
						} else {
							$btnCleanup.prop('disabled', false).text('Eliminar originales conservados');
							$cleanupStatus.css('color', '#d63638').text(response.data.message || webpNcData.i18n.error);
						}
					},
					error: function (xhr) {
						$btnCleanup.prop('disabled', false).text('Eliminar originales conservados');
						$cleanupStatus.css('color', '#d63638').text('Error AJAX (' + xhr.status + '). Revisa la consola o los logs.');
					}
				});
			});
		}
	});
})(jQuery);
