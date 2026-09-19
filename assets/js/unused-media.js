/**
 * Escáner de imágenes no usadas y acciones de cuarentena.
 *
 * El servidor parte el trabajo en lotes; aquí solo orquestamos start + step
 * hasta que done=true, y luego pintamos la tabla / la lista de cuarentena.
 *
 * @package WebPNativeConverter
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		if (typeof webpNcUnused === 'undefined') {
			return;
		}

		const $btnScan        = $('#btn-unused-scan');
		const $btnQuarantine  = $('#btn-unused-quarantine');
		const $progressWrap   = $('#unused-progress-wrapper');
		const $progressBar    = $('#unused-progress-bar');
		const $statusText     = $('#unused-status-text');
		const $percentText    = $('#unused-percent-text');
		const $toolbar        = $('#unused-toolbar');
		const $selectAll      = $('#unused-select-all');
		const $summary        = $('#unused-summary');
		const $table          = $('#unused-results-table');
		const $tbody          = $table.find('tbody');
		const $emptyHint      = $('#unused-empty-hint');
		const $quarantineList = $('#quarantine-list');
		const $backupCheck    = $('#webp_nc_backup_check');

		let isScanning = false;
		let unusedItems = [];
		const quarantineBtnLabel = $btnQuarantine.text();

		$(window).on('beforeunload', function () {
			if (isScanning) {
				return 'Hay un escaneo de imágenes en curso. Si sales, se interrumpirá.';
			}
		});

		function setProgress(percent, message) {
			$progressWrap.show();
			$progressBar.css('width', percent + '%');
			$percentText.text(percent + '%');
			if (message) {
				$statusText.text(message);
			}
		}

		function post(action, extra) {
			const data = $.extend(
				{
					action: action,
					nonce: webpNcUnused.nonce
				},
				extra || {}
			);

			return $.ajax({
				url: webpNcUnused.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: data
			});
		}

		function sprintfCount(template, n) {
			return template.replace('%d', String(n));
		}

		function updateSelectionState() {
			const selected = $tbody.find('.unused-check:checked').length;
			$btnQuarantine.prop('disabled', selected === 0 || isScanning);
			const total = unusedItems.length;
			$summary.text(
				sprintfCount(webpNcUnused.i18n.foundCount, total) +
				(selected ? ' · ' + sprintfCount(webpNcUnused.i18n.selectedCount, selected) : '')
			);
			$selectAll.prop('checked', total > 0 && selected === total);
		}

		function renderUnused(items) {
			unusedItems = items || [];
			$tbody.empty();

			if (!unusedItems.length) {
				$table.hide();
				$toolbar.hide();
				$emptyHint.show().text(webpNcUnused.i18n.noUnused);
				$('#unused-results').removeClass('has-results');
				$btnQuarantine.prop('disabled', true);
				return;
			}

			$emptyHint.hide();
			$toolbar.show();
			$table.show();
			$('#unused-results').addClass('has-results');

			unusedItems.forEach(function (item) {
				const thumb = item.thumb
					? $('<img>', { src: item.thumb, alt: '', class: 'webp-nc-unused-thumb' })
					: $('<span>', { class: 'webp-nc-unused-thumb webp-nc-unused-thumb-empty dashicons dashicons-format-image' });

				const $tr = $('<tr>');
				const $check = $('<input>', {
					type: 'checkbox',
					class: 'unused-check',
					value: item.id
				});

				$tr.append($('<th>', { class: 'check-column' }).append($check));
				$tr.append($('<td>').append(thumb).append($('<strong>').text(item.title || item.filename || ('#' + item.id))));
				$tr.append($('<td>').append($('<code>').text(item.filename || item.file || '')));
				$tr.append($('<td>').text(item.size || '—'));
				$tr.append($('<td>').text(item.date || ''));
				$tbody.append($tr);
			});

			$selectAll.prop('checked', false);
			updateSelectionState();
		}

		function renderQuarantine(index) {
			const items = index || [];
			$quarantineList.empty();

			if (!items.length) {
				$quarantineList.append(
					$('<p>', { class: 'description', text: webpNcUnused.i18n.emptyQuarantine })
				);
				return;
			}

			items.forEach(function (item) {
				const $row = $('<div>', { class: 'webp-nc-quarantine-row', 'data-id': item.id });
				const meta = [item.filename || '', item.size || '', item.date || '']
					.filter(Boolean)
					.join(' · ');

				$row.append(
					$('<div>', { class: 'webp-nc-quarantine-info' })
						.append($('<strong>').text(item.title || ('#' + item.id)))
						.append($('<span>', { class: 'description', text: meta }))
				);

				const $actions = $('<div>', { class: 'webp-nc-quarantine-actions' });
				$actions.append(
					$('<button>', {
						type: 'button',
						class: 'button button-small btn-quarantine-restore',
						text: webpNcUnused.i18n.restore
					})
				);
				$actions.append(
					$('<button>', {
						type: 'button',
						class: 'button button-small btn-quarantine-purge',
						text: webpNcUnused.i18n.purge
					})
				);
				$row.append($actions);
				$quarantineList.append($row);
			});
		}

		function removeUnusedIds(ids) {
			const remove = {};
			ids.forEach(function (id) {
				remove[parseInt(id, 10)] = true;
			});
			unusedItems = unusedItems.filter(function (item) {
				return !remove[item.id];
			});
			renderUnused(unusedItems);
		}

		function runScanStep() {
			post('webp_nc_unused_scan_step')
				.done(function (response) {
					if (!response || !response.success) {
						finishScanError((response && response.data && response.data.message) || webpNcUnused.i18n.scanError);
						return;
					}

					const data = response.data;
					setProgress(data.progress || 0, data.message);

					if (!data.done) {
						runScanStep();
						return;
					}

					setProgress(100, webpNcUnused.i18n.scanDone);
					renderUnused(data.unused || []);
					finishScan();
				})
				.fail(function (xhr) {
					const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
						? xhr.responseJSON.data.message
						: webpNcUnused.i18n.scanError;
					finishScanError(msg);
				});
		}

		function finishScan() {
			isScanning = false;
			$btnScan.prop('disabled', false);
			updateSelectionState();
		}

		function finishScanError(msg) {
			setProgress(0, msg);
			alert(msg);
			finishScan();
		}

		if ($btnScan.length) {
			$btnScan.on('click', function () {
				if (isScanning) {
					return;
				}

				isScanning = true;
				$btnScan.prop('disabled', true);
				$btnQuarantine.prop('disabled', true);
				setProgress(1, webpNcUnused.i18n.scanning);

				post('webp_nc_unused_scan_start')
					.done(function (response) {
						if (!response || !response.success) {
							finishScanError((response && response.data && response.data.message) || webpNcUnused.i18n.scanError);
							return;
						}
						setProgress(response.data.progress || 5, response.data.message);
						runScanStep();
					})
					.fail(function (xhr) {
						const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
							? xhr.responseJSON.data.message
							: webpNcUnused.i18n.scanError;
						finishScanError(msg);
					});
			});
		}

		$selectAll.on('change', function () {
			$tbody.find('.unused-check').prop('checked', $selectAll.is(':checked'));
			updateSelectionState();
		});

		$tbody.on('change', '.unused-check', updateSelectionState);

		$btnQuarantine.on('click', function () {
			const ids = $tbody.find('.unused-check:checked').map(function () {
				return parseInt(this.value, 10);
			}).get();

			if (!ids.length) {
				alert(webpNcUnused.i18n.selectSome);
				return;
			}

			if (!$backupCheck.is(':checked')) {
				alert(webpNcUnused.i18n.confirmBackup);
				$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '0 0 0 2px #ef4444');
				setTimeout(function () {
					$backupCheck.closest('.webp-nc-warning-card').css('box-shadow', '');
				}, 1800);
				return;
			}

			if (!confirm(webpNcUnused.i18n.confirmQuarantine)) {
				return;
			}

			$btnQuarantine.prop('disabled', true).text(webpNcUnused.i18n.moving);

			post('webp_nc_quarantine_move', { ids: ids })
				.done(function (response) {
					if (!response.success) {
						alert((response.data && response.data.message) || webpNcUnused.i18n.scanError);
						return;
					}
					if (response.data.errors && response.data.errors.length) {
						alert(response.data.errors.join('\n'));
					}
					const movedIds = (response.data.entries || []).map(function (entry) {
						return parseInt(entry.id, 10);
					});
					removeUnusedIds(movedIds);
					renderQuarantine(response.data.index || []);
					$statusText.text(response.data.message || '');
				})
				.fail(function () {
					alert(webpNcUnused.i18n.scanError);
				})
				.always(function () {
					$btnQuarantine.text(quarantineBtnLabel);
					updateSelectionState();
				});
		});

		$quarantineList.on('click', '.btn-quarantine-restore', function () {
			const id = parseInt($(this).closest('.webp-nc-quarantine-row').data('id'), 10);
			if (!id || !confirm(webpNcUnused.i18n.confirmRestore)) {
				return;
			}

			const $row = $(this).closest('.webp-nc-quarantine-row');
			$row.find('button').prop('disabled', true);

			post('webp_nc_quarantine_restore', { id: id })
				.done(function (response) {
					if (!response.success) {
						alert((response.data && response.data.message) || webpNcUnused.i18n.scanError);
						$row.find('button').prop('disabled', false);
						return;
					}
					renderQuarantine(response.data.index || []);
				})
				.fail(function () {
					alert(webpNcUnused.i18n.scanError);
					$row.find('button').prop('disabled', false);
				});
		});

		$quarantineList.on('click', '.btn-quarantine-purge', function () {
			const id = parseInt($(this).closest('.webp-nc-quarantine-row').data('id'), 10);
			if (!id || !confirm(webpNcUnused.i18n.confirmPurge)) {
				return;
			}

			const $row = $(this).closest('.webp-nc-quarantine-row');
			$row.find('button').prop('disabled', true);

			post('webp_nc_quarantine_purge', { id: id })
				.done(function (response) {
					if (!response.success) {
						alert((response.data && response.data.message) || webpNcUnused.i18n.scanError);
						$row.find('button').prop('disabled', false);
						return;
					}
					renderQuarantine(response.data.index || []);
				})
				.fail(function () {
					alert(webpNcUnused.i18n.scanError);
					$row.find('button').prop('disabled', false);
				});
		});

		renderQuarantine(webpNcUnused.quarantine || []);
	});
})(jQuery);
