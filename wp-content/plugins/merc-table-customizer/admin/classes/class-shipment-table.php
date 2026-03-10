<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MERC_Shipment_Table
 * Reorganiza columnas de la tabla de shipments del frontend WPCargo.
 * El HTML vive en admin/templates/frontend/table-*.tpl.php.
 */
if ( ! class_exists( 'MERC_Shipment_Table' ) ) {

class MERC_Shipment_Table {

	private string $tpl_path;

	public function __construct() {
		$this->tpl_path = MERC_TABLE_PATH . 'admin/templates/frontend/';

		add_action( 'plugins_loaded',              [ $this, 'remove_default_columns' ], 20 );
		add_action( 'wpcfe_shipment_table_header', [ $this, 'custom_header' ],          99 );
		add_action( 'wpcfe_shipment_table_data',   [ $this, 'custom_data' ],            99 );
		add_action( 'wp_footer',                   [ $this, 'enqueue_table_scripts' ],  99 );
		add_action( 'wp_ajax_merc_get_shipment_summary', [ $this, 'ajax_get_shipment_summary' ] );
	}

	/* ── Quitar columnas default ─────────────────────────────────────── */

	public function remove_default_columns(): void {
		remove_action( 'wpcfe_shipment_after_tracking_number_header', 'wpcfe_shipper_receiver_shipment_header_callback', 25 );
		remove_action( 'wpcfe_shipment_after_tracking_number_data',   'wpcfe_shipper_receiver_shipment_data_callback',   25 );
		remove_action( 'wpcfe_shipment_table_header', 'wpcfe_shipment_table_header_type',   25 );
		remove_action( 'wpcfe_shipment_table_data',   'wpcfe_shipment_table_data_type',     25 );
		remove_action( 'wpcfe_shipment_table_header', 'wpcfe_shipment_table_header_status', 25 );
		remove_action( 'wpcfe_shipment_table_data',   'wpcfe_shipment_table_data_status',   25 );
		// Print re-habilitado: se muestra al final de la fila vía wpcfe_shipment_table_data_action
		// Quitar columna "Container" del plugin wpcargo-shipment-container-add-ons
		remove_action( 'wpcfe_shipment_table_header', 'wpcsc_shipment_container_table_header', 10 );
		remove_action( 'wpcfe_shipment_table_data',   'wpcsc_shipment_container_table_data',   10 );
	}

	/* ── Header ──────────────────────────────────────────────────────── */

	public function custom_header(): void {
		$this->render_tpl( 'table-header.tpl.php', [] );
	}

	/* ── Data ────────────────────────────────────────────────────────── */

	public function custom_data( int $shipment_id ): void {
		$tienda      = get_post_meta( $shipment_id, 'wpcargo_tiendaname', true );

		// Si tienda está vacía, usar nombre y apellido del cliente
		if ( empty( $tienda ) ) {
			$cliente_id = get_post_meta( $shipment_id, 'registered_shipper', true );
			if ( ! empty( $cliente_id ) ) {
				$billing_company = get_user_meta( intval( $cliente_id ), 'billing_company', true );
				if ( ! empty( $billing_company ) ) {
					$tienda = $billing_company;
				} else {
					$first_name = get_user_meta( intval( $cliente_id ), 'first_name', true );
					$last_name  = get_user_meta( intval( $cliente_id ), 'last_name', true );
					$nombre_completo = trim( $first_name . ' ' . $last_name );
					if ( ! empty( $nombre_completo ) ) {
						$tienda = $nombre_completo;
					} else {
						$user = get_userdata( intval( $cliente_id ) );
						if ( $user ) {
							$tienda = $user->display_name;
						}
					}
				}
			}
		}

		$action_rows  = function_exists( 'wpcfe_shipment_action_rows' ) ? wpcfe_shipment_action_rows( $shipment_id ) : [];
		$actions_html = ! empty( $action_rows )
			? '<div class="wpcfe-action-row" style="margin-top:6px;">' . implode( ' | ', $action_rows ) . '</div>'
			: '';

		$distrito_recojo  = get_post_meta( $shipment_id, 'wpcargo_distrito_recojo',  true );
		$distrito_destino = get_post_meta( $shipment_id, 'wpcargo_distrito_destino', true )
		                 ?: get_post_meta( $shipment_id, 'wpcargo_destination',       true );

		$fecha = get_post_meta( $shipment_id, 'wpcargo_pickup_date_picker', true )
		      ?: get_post_meta( $shipment_id, 'wpcargo_calendarenvio', true )
		      ?: date( 'd/m/Y', strtotime( get_post_field( 'post_date', $shipment_id ) ) );

		$tipo_html             = $this->render_tipo( get_post_meta( $shipment_id, 'tipo_envio', true ) );
		$cambio_html           = $this->render_cambio( get_post_meta( $shipment_id, 'cambio_producto', true ) );
		$estado                = (string) get_post_meta( $shipment_id, 'wpcargo_status', true );
		
		// Cliente asignado al envío
		$cliente_id = get_post_meta( $shipment_id, 'registered_shipper', true );
		
		// Motorizado de recojo: obtener ID y nombre limpio
		$motorizo_recojo_id    = get_post_meta( $shipment_id, 'wpcargo_motorizo_recojo', true );
		$motorizo_recojo_html  = $this->render_driver( $motorizo_recojo_id );
		
		$motorizo_entrega_html = $this->render_driver( get_post_meta( $shipment_id, 'wpcargo_motorizo_entrega', true ) );

		$this->render_tpl( 'table-row.tpl.php', compact(
			'shipment_id', 'tienda', 'actions_html',
			'distrito_recojo', 'distrito_destino', 'fecha',
			'tipo_html', 'cambio_html', 'estado', 'motorizo_recojo_html', 'motorizo_entrega_html',
			'cliente_id'
		) );
	}

	/* ── Template renderer ───────────────────────────────────────────── */

	private function render_tpl( string $file, array $data ): void {
		// extract() en este scope + include: el template accede a todas las variables.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data );
		include $this->tpl_path . $file;
	}

	/* ── Helpers de render ───────────────────────────────────────────── */

	private function render_tipo( string $tipo ): string {
		$lower = strtolower( trim( $tipo ) );
		if ( $lower === 'express' || stripos( $tipo, 'agencia' ) !== false )
			return '<span style="background:#ff5722;color:white;padding:4px 12px;border-radius:4px;font-weight:bold;font-size:11px;">MERC AGENCIA</span>';
		if ( $lower === 'normal' || stripos( $tipo, 'emprendedor' ) !== false )
			return '<span style="background:#2196f3;color:white;padding:4px 12px;border-radius:4px;font-weight:bold;font-size:11px;">MERC EMPRENDEDOR</span>';
		if ( ! empty( $tipo ) )
			return '<span style="background:#ff9800;color:white;padding:4px 8px;border-radius:4px;font-size:10px;">⚠️ ' . esc_html( $tipo ) . '</span>';
		return '<span style="background:#757575;color:white;padding:4px 8px;border-radius:4px;font-size:10px;">Sin tipo</span>';
	}

	private function render_cambio( string $cambio ): string {
		return $cambio === 'Sí'
			? '<span style="background:#c62828;color:#fff;padding:4px 12px;border-radius:14px;font-weight:bold;font-size:11px;">⚠ SÍ</span>'
			: '<span style="background:#2e7d32;color:#fff;padding:4px 12px;border-radius:14px;font-weight:bold;font-size:11px;">NO</span>';
	}

	private function get_driver_name( $user_id ): string {
		if ( empty( $user_id ) ) return '';
		$nombre = trim( get_user_meta( $user_id, 'first_name', true ) . ' ' . get_user_meta( $user_id, 'last_name', true ) );
		if ( empty( $nombre ) ) {
			$u = get_userdata( $user_id );
			$nombre = $u ? $u->display_name : '';
		}
		return $nombre;
	}

	private function render_driver( $user_id ): string {
		if ( empty( $user_id ) ) return '<span style="color:#999;">-</span>';
		$nombre = $this->get_driver_name( $user_id );
		return ! empty( $nombre ) ? esc_html( $nombre ) : '<span style="color:#999;">-</span>';
	}

	/* ── Enqueue CSS/JS para accordion de tiendas ───────────────────── */

	public function enqueue_table_scripts(): void {
		// Inyectar CSS y JS inline
		?>
		<style>
			/* Estilos para accordion */
			#shipment-history-accordion {
				display: block;
				width: 100%;
			}

			.merc-tienda-card {
				border: 1px solid #ddd;
				border-radius: 6px;
				overflow: hidden;
				box-shadow: 0 2px 4px rgba(0,0,0,0.05);
				margin-bottom: 12px;
			}

			.merc-tienda-card-header {
				background: linear-gradient(135deg, #8e0205 0%, #350000 100%);
				color: white;
				padding: 12px 16px;
				cursor: pointer;
				user-select: none;
				display: flex;
				justify-content: space-between;
				align-items: center;
				font-weight: bold;
				font-size: 14px;
				transition: all 0.3s ease;
			}

			.merc-tienda-card-header:hover {
				background: linear-gradient(135deg, #a10251 0%, #8e0205 100%);
			}

			.merc-tienda-info {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.merc-tienda-checkbox {
				width: 18px;
				height: 18px;
				cursor: pointer;
			}

			.merc-tienda-icon {
				margin-left: auto;
				font-size: 16px;
				transition: transform 0.3s;
			}

			.merc-tienda-card.collapsed .merc-tienda-icon {
				transform: rotate(-90deg);
			}

			.merc-tienda-card-content {
				max-height: 2000px;
				overflow-x: auto;
				overflow-y: hidden;
				-webkit-overflow-scrolling: touch;
				transition: max-height 0.3s ease;
				background: white;
			}

			.merc-tienda-card.collapsed .merc-tienda-card-content {
				max-height: 0;
				overflow: hidden;
			}

			.merc-tienda-card-table {
				width: 100%;
				min-width: 900px;
				border-collapse: collapse;
				margin: 0;
				background: white;
			}

			.merc-tienda-card-table thead {
				background: #f5f5f5;
				border-bottom: 1px solid #ddd;
			}

			.merc-tienda-card-table thead th {
				padding: 10px 12px;
				text-align: left;
				font-weight: 600;
				font-size: 12px;
				color: #333;
				border: none;
			}

			.merc-tienda-card-table tbody tr {
				border-top: 1px solid #eee;
			}

			.merc-tienda-card-table tbody tr:hover {
				background: #f9f9f9;
			}

			.merc-tienda-card-table tbody td {
				padding: 8px 12px;
				vertical-align: middle;
				font-size: 13px;
			}

			/* El .merc-card-select-all usa el patrón Bootstrap form-check-input,
			   thus su posicionamiento es relativo al <th> padre */
			.merc-card-select-all-th {
				width: 32px;
				min-width: 32px;
			}

			/* Responsive: scroll horizontal en móvil */
			@media (max-width: 768px) {
				/* NO overflow-x: hidden aquí – el scroll lo maneja .merc-tienda-card-content */
				.merc-tienda-card-header { font-size: 12px; padding: 8px 10px; }
				.merc-tienda-card-table thead th,
				.merc-tienda-card-table tbody td { padding: 5px 7px; font-size: 11px; white-space: nowrap; }
				.merc-tienda-info strong { max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			}
		</style>
		<script>
		(function($) {
			console.log('🚀 merc-table script loaded');

			let initialized = false;

			/* ── Sync estado de checkboxes globales ─────────────────────── */
			function updateGlobalCheckboxState() {
				var total   = $('.merc-ship-ui').length;
				var checked = $('.merc-ship-ui:checked').length;
				var $gsa    = $('#merc-select-all-global');
				if (!$gsa.length || total === 0) return;
				$gsa.prop('checked', checked === total)
				    .prop('indeterminate', checked > 0 && checked < total);
				// Sync checkboxes de cada card
				$('.merc-tienda-card').each(function() {
					var $c  = $(this);
					var ct  = $c.find('.merc-ship-ui').length;
					var cc  = $c.find('.merc-ship-ui:checked').length;
					$c.find('.merc-card-select-all')
					  .prop('checked', ct > 0 && cc === ct)
					  .prop('indeterminate', cc > 0 && cc < ct);
				});
			}

			/* ── Setup post-accordion: select-all + bulk-print ──────────── */
			function postAccordionSetup() {
				// 1. Checkbox "Seleccionar todos" global sobre el accordion
				if ($('#merc-select-all-global').length === 0) {
					var $gsa = $('<div style="padding:6px 8px 8px;display:flex;align-items:center;gap:8px;">' +
						'<input type="checkbox" id="merc-select-all-global" style="width:16px;height:16px;cursor:pointer;">' +
						'<label for="merc-select-all-global" style="cursor:pointer;margin:0;font-size:13px;font-weight:600;color:#555;">Seleccionar todos los envíos</label>' +
						'</div>');
					$('#shipment-history-accordion').prepend($gsa);
				}

				$('#merc-select-all-global').off('change').on('change', function() {
					var ck = $(this).prop('checked');
					$(this).prop('indeterminate', false);
					$('.merc-card-select-all').prop('checked', ck).prop('indeterminate', false);
					$('.merc-ship-ui').prop('checked', ck);
					$('#shipment-list .wpcfe-shipments').prop('checked', ck);
				});

				// 2. Bulk-print: reemplaza el handler de WPCargo (que usa #shipment-list ya inexistente)
				if (typeof wpcfeAjaxhandler !== 'undefined') {
					$('.wpcfe-bulkprint-wrapper').off('click').on('click', '.wpcfe-bulk-print', function(e) {
						e.preventDefault();
						var printType = $(this).data('type');
						var selected  = [];
						$('.merc-ship-ui:checked').each(function() { selected.push($(this).val()); });
						if (selected.length === 0) { alert('Por favor seleccione al menos un envío'); return; }
						$('body').append('<div class="merc-pdf-spinner" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 30px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.3);z-index:99999;font-size:15px;">Generando PDF...</div>');
						$.ajax({
							type: 'POST', url: wpcfeAjaxhandler.ajaxurl,
							data: { action: 'wpcfe_bulkprint', selectedShipment: selected, printType: printType },
							success: function(r) {
								$('body .merc-pdf-spinner').remove();
								try {
									var d = JSON.parse(r);
									if (d && d.file_url) {
										$('.merc-ship-ui, .wpcfe-shipments, .merc-tienda-checkbox').prop('checked', false).prop('indeterminate', false);
										$('#merc-select-all-global').prop('checked', false).prop('indeterminate', false);
										var a = document.createElement('a');
										a.href = d.file_url;
										a.target = '_blank';
										a.download = (d.file_name || 'etiquetas') + '.pdf';
										document.body.appendChild(a);
										a.click();
										document.body.removeChild(a);
									} else { alert('Error al generar el PDF'); }
								} catch(ex) { alert('Error al procesar la respuesta'); }
							},
							error: function() { $('body .merc-pdf-spinner').remove(); alert('Error de conexión'); }
						});
					});
				}

			}

			function initializeAccordion() {
				if (initialized) return true;

				console.log('🔍 Buscando tabla con data-tienda...');
				
				// Buscar cualquier tabla que tenga filas con .merc-tienda-cell en un TD
				let $table = $('table:has(tbody tr td.merc-tienda-cell)').first();
				
				if (!$table.length) {
					console.log('❌ No encontrada tabla con data-tienda');
					return false;
				}

				console.log('✅ Tabla encontrada:', $table.attr('id') || $table.attr('class'));

				const $tbody = $table.find('tbody');
				const rowCount = $tbody.find('tr').length;
				console.log('📝 Total filas en tabla:', rowCount);

				if (!$tbody.length || rowCount === 0) {
					console.log('❌ tbody vacío o no encontrado');
					return false;
				}

				// Agrupar filas por tienda
				const tiendas = {};
				const orden = [];

				$tbody.find('tr').each(function() {
					const $row = $(this);
					const tienda = $row.find('.merc-tienda-cell').data('tienda') || $row.data('tienda') || '';
					
					if (!tiendas[tienda]) {
						tiendas[tienda] = [];
						orden.push(tienda);
					}
					var $cloned = $row.clone();
					$cloned.find('.wpcfe-shipments').removeClass('wpcfe-shipments').addClass('merc-ship-ui');
					tiendas[tienda].push($cloned);
				});

				console.log('📊 Tiendas agrupadas:', orden.length);
				orden.forEach(function(t) {
					console.log('  - ' + t + ': ' + tiendas[t].length + ' filas');
				});

				// Crear accordion
				const $accordion = $('<div id="shipment-history-accordion"></div>');

				// Obtener cabeceras
				const $headerRow = $table.find('thead tr').first();
				let headerHtml = '';
				if ($headerRow.length) {
					let isFirst = true;
					$headerRow.find('th').each(function() {
						if (isFirst) { isFirst = false; return; } // 1er TH (wpcfe-select-all) se genera por card
						headerHtml += '<th>' + $(this).html() + '</th>';
					});
				}

			// Crear cards SOLO para tiendas válidas (excluyendo "Sin tienda")
			orden.forEach(function(tienda) {
				// Saltar tiendas sin nombre
				if (!tienda || tienda === 'Sin tienda' || tienda === '') {
					console.log('⏭️ Omitiendo tienda vacía:', tienda);
					return;
				}

				const tiendaSlug = tienda.replace(/[^a-z0-9]/gi, '').toLowerCase().substr(0, 10);
				const rowsForTienda = tiendas[tienda];

				// Recopilar shipment IDs específicos de las filas agrupadas
				const shipmentIds = [];
				rowsForTienda.forEach(function($row) {
					// El shipment_id está en data-shipment-id o en el primer TD (merc-tienda-cell) como data-shipment-id
					const shipmentId = $row.find('.merc-tienda-cell').data('shipment-id') || $row.data('shipment-id');
					if (shipmentId) {
						shipmentIds.push(shipmentId);
					}
				});
				
				console.log('📍 Tienda: ' + tienda + ' | Shipment IDs: ' + JSON.stringify(shipmentIds));
				
				// Obtener distritos y motorizados vía AJAX usando los shipment IDs específicos
				const distritos = new Set();
				const motorizados = new Set();
				
				if (shipmentIds.length > 0) {
					// Hacer AJAX para obtener datos agrupados
					$.ajax({
						type: 'POST',
						url: ajaxurl,
						async: false, // Sincrónico para no complicar el flow
						data: {
							action: 'merc_get_shipment_summary',
							shipment_ids: shipmentIds
						},
						success: function(resp) {
							console.log('✅ AJAX SUCCESS para ' + tienda + ':', resp);
							if (resp.success && resp.data) {
								if (resp.data.distritos && resp.data.distritos.length) {
									resp.data.distritos.forEach(function(d) { distritos.add(d); });
								}
								if (resp.data.motorizados && resp.data.motorizados.length) {
									resp.data.motorizados.forEach(function(m) { motorizados.add(m); });
								}
							}
						},
						error: function(err) {
							console.error('❌ AJAX ERROR para ' + tienda + ':', err);
						}
					});
				}
				// Construir información adicional
				let infoAdicional = '';
				if (distritos.size > 0) {
					infoAdicional += '<br><small style="opacity:0.7;">📍 Distritos: ' + Array.from(distritos).join(', ') + '</small>';
				}
				if (motorizados.size > 0) {
					infoAdicional += '<br><small style="opacity:0.7;">🚗 Motorizado(s): ' + Array.from(motorizados).join(', ') + '</small>';
				}

				const $header = $('<div class="merc-tienda-card-header"></div>').html(
					'<div class="merc-tienda-info">' +
					'<strong>' + tienda + '</strong>' +
					'<span style="font-size:11px; opacity:0.8;">(' + rowsForTienda.length + ' envíos)</span>' +
					infoAdicional +
					'</div>' +
					'<span class="merc-tienda-icon">▼</span>'
				);

				// Tabla interna CON headers
				// 1er TH: select-all con el mismo patrón Bootstrap que las filas
				const saId = 'merc-sa-' + tiendaSlug;
				const firstTh = '<th class="merc-card-select-all-th" style="position:relative;width:32px;min-width:32px;padding-left:1.25rem;">'
					+ '<input type="checkbox" class="form-check-input merc-card-select-all" id="' + saId + '" style="position:absolute;margin-top:.25rem;margin-left:-1.25rem;">'
					+ '<label class="form-check-label" for="' + saId + '"></label>'
					+ '</th>';
				const $innerTable = $('<table class="merc-tienda-card-table wpc-shipment-history table table-hover table-sm"><thead><tr>' + firstTh + headerHtml + '</tr></thead><tbody></tbody></table>');
				const $innerTbody = $innerTable.find('tbody');
				
				rowsForTienda.forEach(function($row) {
					$innerTbody.append($row);
				});

				const $content = $('<div class="merc-tienda-card-content"></div>').append($innerTable);

					const $card = $('<div class="merc-tienda-card collapsed"></div>')
						.append($header)
						.append($content);

					$header.on('click', function() {
						$card.toggleClass('collapsed');
					});


					$accordion.append($card);
				});

				// Mantener tabla original OCULTA en el DOM -- WPCargo necesita '#shipment-list'
				// para sus handlers de bulk actions. El accordion se inserta antes de ella.
				$table.before($accordion);
				$table.hide().addClass('merc-accordion-processed').attr('aria-hidden', 'true');

				initialized = true;
				postAccordionSetup();
				console.log('✅ Accordion generado completamente!');
			}

			// Usar MutationObserver para detectar cuando se añade la tabla
			const observerConfig = { childList: true, subtree: true };
			const observer = new MutationObserver(function(mutations) {
				if (!initialized && $('tbody tr td.merc-tienda-cell').length > 0) {
					console.log('👁️ MutationObserver - detectó tabla con .merc-tienda-cell');
					observer.disconnect();
					setTimeout(initializeAccordion, 100);
				}
			});

			// Iniciar observación
			observer.observe(document.body, observerConfig);

			// Intentar inicializar también en document.ready
			$(document).ready(function() {
				console.log('📌 Document ready');
				setTimeout(initializeAccordion, 500);

				// ── Checkbox select-all de cada card (delegado desde document) ──────
				$(document).on('change', '.merc-card-select-all', function() {
					var $cb = $(this);
					var isChecked = $cb.prop('checked');
					$cb.prop('indeterminate', false);
					var $card = $cb.closest('.merc-tienda-card');
					$card.find('.merc-ship-ui').prop('checked', isChecked);
					$card.find('.merc-ship-ui').each(function() {
						$('#shipment-list .wpcfe-shipments[value="' + $(this).val() + '"]').prop('checked', isChecked);
					});
					updateGlobalCheckboxState();
				});

				// ── Sync encabezado al cambiar fila individual ──────────────────────
				$(document).on('change', '.merc-ship-ui', function() {
					var id  = $(this).val();
					var ck  = $(this).prop('checked');
					$('#shipment-list .wpcfe-shipments[value="' + id + '"]').prop('checked', ck);
					var $card = $(this).closest('.merc-tienda-card');
					updateGlobalCheckboxState();
					if (!$card.length) return;
					var total = $card.find('.merc-ship-ui').length;
					var cnt   = $card.find('.merc-ship-ui:checked').length;
					$card.find('.merc-card-select-all')
						.prop('checked', cnt === total && total > 0)
						.prop('indeterminate', cnt > 0 && cnt < total);
				});

				// Print por fila: event delegation desde document (sobrevive al accordion)
				$(document).on('click.mercPrint', '.print-shipment .dropdown-item', function(e) {
					e.preventDefault();
					e.stopImmediatePropagation();
					var shipmentID = $(this).data('id');
					var printType  = $(this).data('type');
					if (!shipmentID || !printType) return;
					if (typeof wpcfeAjaxhandler === 'undefined') return;
					$('body').append('<div class="merc-pdf-spinner" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 30px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.3);z-index:99999;font-size:15px;">Generando PDF...</div>');
					$.ajax({
						type: 'POST', url: wpcfeAjaxhandler.ajaxurl,
						data: { action: 'wpcfe_print_shipment', shipmentID: shipmentID, printType: printType },
						success: function(r) {
							$('body .merc-pdf-spinner').remove();
							try {
								var d = JSON.parse(r);
								if (d && d.file_url) {
									// Usar un enlace temporal para evitar bloqueo de popup-blockers
									var a = document.createElement('a');
									a.href = d.file_url;
									a.target = '_blank';
									a.download = (d.file_name || 'etiqueta') + '.pdf';
									document.body.appendChild(a);
									a.click();
									document.body.removeChild(a);
								} else { alert('Error al generar el PDF'); }
							} catch(ex) { alert('Error al procesar la respuesta del servidor'); }
						},
						error: function() { $('body .merc-pdf-spinner').remove(); alert('Error de conexión al generar el PDF'); }
					});
				});
			});

			// Timeout para limpiar observer si no se usa
			setTimeout(function() {
				if (!initialized && observer) {
					console.log('⏱️ Limpiando observer - timeout');
					observer.disconnect();
				}
			}, 10000);

		})(jQuery);
		</script>
		<?php
	}

	/* ── AJAX: obtener distritos y motorizados para shipments específicos ────────── */

	public function ajax_get_shipment_summary(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$shipment_ids = isset( $_POST['shipment_ids'] ) ? array_map( 'intval', (array) $_POST['shipment_ids'] ) : [];
		if ( empty( $shipment_ids ) ) {
			wp_send_json_error( 'Sin shipment IDs' );
		}


		$distritos = [];
		$motorizados = [];

		// Para cada shipment específico, obtener distrito y motorizado
		foreach ( $shipment_ids as $shipment_id ) {
			$shipment_id = intval( $shipment_id );
			
			$distrito = get_post_meta( $shipment_id, 'wpcargo_distrito_recojo', true );
			
			if ( ! empty( $distrito ) && $distrito !== '-' ) {
				$distritos[] = $distrito;
			}

			$moto_id = get_post_meta( $shipment_id, 'wpcargo_motorizo_recojo', true );
			
			if ( ! empty( $moto_id ) ) {
				$moto_id = intval( $moto_id );
				$first_name = get_user_meta( $moto_id, 'first_name', true );
				$last_name = get_user_meta( $moto_id, 'last_name', true );
				$nombre = trim( $first_name . ' ' . $last_name );
				
				
				if ( empty( $nombre ) ) {
					$u = get_userdata( $moto_id );
					$nombre = $u ? $u->display_name : '';
					error_log( "    Fallback display_name: '{$nombre}'" );
				}
				if ( ! empty( $nombre ) ) {
					$motorizados[] = $nombre;
				}
			}
		}

		// Obtener únicos
		$distritos = array_unique( array_filter( $distritos ) );
		$motorizados = array_unique( array_filter( $motorizados ) );


		wp_send_json_success( [
			'distritos' => array_values( $distritos ),
			'motorizados' => array_values( $motorizados )
		] );
	}
}

} // End if ( ! class_exists( 'MERC_Shipment_Table' ) )

if ( class_exists( 'MERC_Shipment_Table' ) ) {
	new MERC_Shipment_Table();
}

