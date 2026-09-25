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
		const $btnStart         = $('#btn-start-batch');
		const $btnStop          = $('#btn-stop-batch');
		const $btnConvertSel    = $('#btn-convert-selected');
		const $btnCancelPreview = $('#btn-cancel-preview');
		const $backupCheck      = $('#webp_nc_backup_check');
		const $progressWrap     = $('#batch-progress-wrapper');
		const $progressBar      = $('#batch-progress-bar');
		const $statusText       = $('#batch-status-text');
		const $percentText      = $('#batch-percent-text');
		const $console          = $('#batch-console');
		const $btnClearLog      = $('#btn-clear-console');
		const $preview          = $('#batch-preview');
		const $previewTable     = $('#batch-preview-table');
		const $previewBody      = $previewTable.find('tbody');
		const $selectAll        = $('#batch-select-all');
		const $previewSummary   = $('#batch-preview-summary');

		let queue             = [];
		let pendingIds        = [];
		let previewItems      = [];
		let totalItems        = 0;
		let processedItems    = 0;
		let totalSavedBytes   = 0;
		let isRunning         = false;
		let isLoadingPreview  = false;
		const batchChunkSize  = 3;
		const previewChunk    = 50;

		// Prevención de recarga accidental: si el proceso está corriendo, avisamos al usuario antes de salir.
		$(window).on('beforeunload', function () {
			if (isRunning) {
				return webpNcData.i18n.leaveWarning;
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
				appendLog(webpNcData.i18n.consoleCleared, 'muted');
			});
		}

		function requireBackup() {
			if ($backupCheck.is(':checked')) {
				return true;
			}
			alert(webpNcData.i18n.confirmBackup);
			$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '0 0 0 2px #ef4444');
			setTimeout(function () {
				$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '');
			}, 2000);
			return false;
		}

		function formatPreviewSummary(count, savedHuman) {
			return webpNcData.i18n.previewSummary
				.replace('%1$d', String(count))
				.replace('%2$s', savedHuman);
		}

		function formatBytes(bytes) {
			if (!bytes || bytes < 1) {
				return '0 B';
			}
			const units = ['B', 'KB', 'MB', 'GB'];
			let i = 0;
			let value = bytes;
			while (value >= 1024 && i < units.length - 1) {
				value /= 1024;
				i++;
			}
			return (i === 0 ? String(value) : value.toFixed(1)) + ' ' + units[i];
		}

		function updatePreviewSelection() {
			const $checks = $previewBody.find('.batch-check');
			const $checked = $checks.filter(':checked');
			let estimated = 0;
			$checked.each(function () {
				estimated += parseInt($(this).data('estimated'), 10) || 0;
			});
			$previewSummary.text(formatPreviewSummary($checked.length, formatBytes(estimated)));
			$selectAll.prop('checked', $checks.length > 0 && $checked.length === $checks.length);
			$btnConvertSel.prop('disabled', $checked.length === 0 || isRunning);
		}

		function renderPreview(items) {
			previewItems = items || [];
			$previewBody.empty();

			previewItems.forEach(function (item) {
				const thumb = item.thumb
					? $('<img>', { src: item.thumb, alt: '', class: 'webp-nc-unused-thumb' })
					: $('<span>', { class: 'webp-nc-unused-thumb webp-nc-unused-thumb-empty dashicons dashicons-format-image' });

				const $check = $('<input>', {
					type: 'checkbox',
					class: 'batch-check',
					value: item.id,
					checked: true
				}).attr('data-estimated', item.estimated || 0);

				const $tr = $('<tr>');
				$tr.append($('<th>', { class: 'check-column' }).append($check));
				$tr.append($('<td>').append(thumb).append($('<strong>').text(item.title || item.filename || ('#' + item.id))));
				$tr.append($('<td>').append($('<code>').text(item.filename || '')));
				$tr.append($('<td>').text(item.size || '—'));
				$tr.append($('<td>').text(item.estimated_h || '—'));
				$previewBody.append($tr);
			});

			$selectAll.prop('checked', previewItems.length > 0);
			$preview.show();
			updatePreviewSelection();
		}

		function resetPreviewUi() {
			isLoadingPreview = false;
			$preview.hide();
			$previewBody.empty();
			previewItems = [];
			pendingIds = [];
			$btnStart.prop('disabled', false).show();
			$btnConvertSel.prop('disabled', false);
		}

		function fetchPreviewChunk(offset) {
			const chunk = pendingIds.slice(offset, offset + previewChunk);
			if (!chunk.length) {
				isLoadingPreview = false;
				$progressWrap.hide();
				renderPreview(previewItems);
				appendLog(webpNcData.i18n.reviewReady, 'normal');
				$statusText.text(webpNcData.i18n.reviewReady);
				return;
			}

			const loaded = Math.min(offset + chunk.length, pendingIds.length);
			const percent = Math.round((loaded / pendingIds.length) * 100);
			$progressBar.css('width', percent + '%');
			$percentText.text(percent + '%');
			$statusText.text(webpNcData.i18n.loadingPreview + ' ' + loaded + '/' + pendingIds.length);

			$.ajax({
				url: webpNcData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'webp_nc_get_pending_preview',
					nonce: webpNcData.nonce,
					ids: chunk
				},
				success: function (response) {
					if (response.success && response.data.items) {
						previewItems = previewItems.concat(response.data.items);
					}
					fetchPreviewChunk(offset + chunk.length);
				},
				error: function () {
					isLoadingPreview = false;
					$btnStart.prop('disabled', false).show();
					appendLog(webpNcData.i18n.previewLoadError, 'error');
				}
			});
		}

		if ($btnStart.length) {
			$btnStart.on('click', function () {
				if (isLoadingPreview || isRunning) {
					return;
				}

				if (!requireBackup()) {
					return;
				}

				isLoadingPreview = true;
				previewItems = [];
				$preview.hide();
				$btnStart.prop('disabled', true);
				$progressWrap.slideDown(200);
				$progressBar.css('width', '4%');
				$percentText.text('0%');
				$statusText.text(webpNcData.i18n.loadingPreview);
				appendLog(webpNcData.i18n.loadingPreview, 'normal');

				$.ajax({
					url: webpNcData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'webp_nc_get_pending_images',
						nonce: webpNcData.nonce
					},
					success: function (response) {
						if (response.success && response.data.ids && response.data.ids.length > 0) {
							pendingIds = response.data.ids;
							appendLog(webpNcData.i18n.foundPending.replace('%d', String(pendingIds.length)), 'normal');
							fetchPreviewChunk(0);
						} else {
							isLoadingPreview = false;
							$btnStart.prop('disabled', false);
							$statusText.text(webpNcData.i18n.noImagesFound);
							appendLog(webpNcData.i18n.noImagesFound, 'muted');
						}
					},
					error: function () {
						isLoadingPreview = false;
						$btnStart.prop('disabled', false);
						appendLog(webpNcData.i18n.queueLoadError, 'error');
					}
				});
			});

			$btnStop.on('click', function () {
				isRunning = false;
				$btnStop.find('.webp-nc-btn-label').text(webpNcData.i18n.stopping);
				$btnStop.prop('disabled', true);
				appendLog(webpNcData.i18n.pausing, 'muted');
			});
		}

		$selectAll.on('change', function () {
			$previewBody.find('.batch-check').prop('checked', $selectAll.is(':checked'));
			updatePreviewSelection();
		});

		$previewBody.on('change', '.batch-check', updatePreviewSelection);

		$btnCancelPreview.on('click', function () {
			if (isRunning) {
				return;
			}
			resetPreviewUi();
			appendLog(webpNcData.i18n.reviewCancelled, 'muted');
		});

		$btnConvertSel.on('click', function () {
			if (isRunning || isLoadingPreview) {
				return;
			}

			if (!requireBackup()) {
				return;
			}

			const selected = $previewBody.find('.batch-check:checked').map(function () {
				return parseInt(this.value, 10);
			}).get();

			if (!selected.length) {
				alert(webpNcData.i18n.selectSome);
				return;
			}

			queue          = selected.slice();
			totalItems     = queue.length;
			processedItems = 0;
			totalSavedBytes = 0;
			isRunning      = true;

			$preview.hide();
			$btnStart.hide();
			$btnStop.show().prop('disabled', false);
			$btnStop.find('.webp-nc-btn-label').text(webpNcData.i18n.pauseLabel);
			$progressWrap.slideDown(200);
			$progressBar.css('width', '0%');
			$percentText.text('0%');
			$statusText.text(webpNcData.i18n.processing);
			appendLog(webpNcData.i18n.startingSelected.replace('%d', String(totalItems)), 'normal');
			processNextBatch();
		});

		// El motor de recursividad que va vaciando la cola.
		function processNextBatch() {
			if (!isRunning) {
				// Si pausaron el proceso de forma explícita.
				$btnStop.find('.webp-nc-btn-label').text(webpNcData.i18n.pauseLabel);
				$btnStop.prop('disabled', false).hide();
				$btnStart.show();
				appendLog(webpNcData.i18n.paused, 'muted');
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
						$statusText.text(
							webpNcData.i18n.progressStatus
								.replace('%1$d', String(processedItems))
								.replace('%2$d', String(totalItems))
						);

						appendLog(
							webpNcData.i18n.batchDone
								.replace('%1$d', String(response.data.processed))
								.replace('%2$s', response.data.saved_human),
							'success'
						);

						if (response.data.errors && response.data.errors.length > 0) {
							response.data.errors.forEach(function (err) {
								appendLog(webpNcData.i18n.batchWarn.replace('%s', err), 'error');
							});
						}

						const remaining = Math.max(0, totalItems - processedItems);
						$('#stat-total-pending').text(remaining);

						// Siguiente iteración recursiva, llamando a la misma función.
						processNextBatch();
					} else {
						// Falló la conversión de este lote, pero seguimos con el resto.
						appendLog(webpNcData.i18n.batchFail.replace('%s', response.data.message), 'error');
						processNextBatch();
					}
				},
				error: function () {
					// Fallo a nivel de conexión o timeout de PHP 504/500. 
					// Nos esperamos 2 segundos y volvemos a intentarlo en lugar de abortar de golpe.
					appendLog(webpNcData.i18n.ajaxRetry, 'error');
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
						$btn.prop('disabled', false).text(webpNcData.i18n.retry);
						alert(response.data.message || webpNcData.i18n.error);
					}
				},
				error: function (xhr) {
					$btn.prop('disabled', false).text(webpNcData.i18n.retry);
					alert(webpNcData.i18n.connectionError.replace('%s', String(xhr.status)));
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
							$btnCleanup.text(webpNcData.i18n.cleanupDone);
							$cleanupStatus.css('color', '#135e96').text(
								response.data.message + (response.data.freed_bytes > 0 ? ' ' + webpNcData.i18n.freedExtra.replace('%s', response.data.freed_human) : '')
							);
						} else {
							$btnCleanup.prop('disabled', false).text(webpNcData.i18n.cleanupLabel);
							$cleanupStatus.css('color', '#d63638').text(response.data.message || webpNcData.i18n.error);
						}
					},
					error: function (xhr) {
						$btnCleanup.prop('disabled', false).text(webpNcData.i18n.cleanupLabel);
						$cleanupStatus.css('color', '#d63638').text(webpNcData.i18n.connectionError.replace('%s', String(xhr.status)));
					}
				});
			});
		}
	});
})(jQuery);
