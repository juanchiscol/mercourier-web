<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MERC_Table_UI
 *
 * Interfaz visual de la tabla de envíos:
 *   - Encola merc-table-ui.js (reordenamiento de columnas).
 *   - Inyecta en wp_footer el bloque CSS/JS que agrega:
 *       · Columna Estado editable (SELECT) para admin y motorizado.
 *       · Resaltado de filas por estado (REPROGRAMADO, NO CONTESTA, ANULADO).
 *       · Columna "LISTO PARA SALIR" para motorizado.
 *       · Botones Reprogramar / Anular para cliente.
 *       · Botón Crear Producto para admin en envíos ANULADO.
 *       · Modales de confirmación, reprogramación y anulación.
 *
 * Migrado desde blocksy-child/functions.php → merc-table-customizer.
 */
if ( ! class_exists( 'MERC_Table_UI' ) ) {

class MERC_Table_UI {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_footer',          [ $this, 'render_footer_scripts' ] );
        
        // 🔒 Permitir que admins firmen envíos (firma) como motorizado
        add_filter( 'warcargo_pod_roles_can_sign', [ $this, 'extend_signature_roles' ], 10, 1 );
        add_filter( 'wpcpod_route_allowed_user', [ $this, 'extend_route_allowed_user' ], 10, 1 );
        
        // 🔐 AJAX handler para que admins accedan a formulario de firma
        add_action( 'wp_ajax_show_signaturepad_admin', [ $this, 'show_signaturepad_for_all_roles' ] );
        add_action( 'wp_ajax_nopriv_show_signaturepad_admin', [ $this, 'show_signaturepad_for_all_roles' ] );
    }

    /**
     * Extender roles que pueden firmar envíos (POD Signature)
     * Por defecto solo 'wpcargo_driver', agregamos 'administrator' para admin
     * 
     * Nota: Las capacidades como 'manage_options' no son roles, son capabilities.
     * En WordPress, los admins tienen el rol 'administrator'.
     */
    public function extend_signature_roles( $roles ) {
        if ( ! is_array( $roles ) ) {
            $roles = [];
        }
        
        // Agregar 'administrator' a los roles que pueden firmar
        if ( ! in_array( 'administrator', $roles, true ) ) {
            $roles[] = 'administrator';
        }
        
        return $roles;
    }

    /**
     * Extender usuarios permitidos para routes
     * Verifica quién puede acceder al planificador de rutas y firma
     */
    public function extend_route_allowed_user( $allowed_roles ) {
        if ( ! is_array( $allowed_roles ) ) {
            $allowed_roles = [];
        }
        
        // Agregar 'administrator' a los roles permitidos
        if ( ! in_array( 'administrator', $allowed_roles, true ) ) {
            $allowed_roles[] = 'administrator';
        }
        
        return $allowed_roles;
    }

    /* ── Encolar JS de reordenamiento de columnas ────────────────────── */

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            'merc-table-ui',
            MERC_TABLE_URL . 'admin/js/merc-table-ui.js',
            [ 'jquery' ],
            MERC_TABLE_VERSION,
            true
        );
    }

    /* ── wp_footer: CSS + JS inline para la tabla de envíos ─────────── */

    public function render_footer_scripts(): void {
        global $wpcargo;
        $estados = $wpcargo->status;

        if ( empty( $estados ) ) {
            return;
        }

        $current_user = wp_get_current_user();
        $is_client = in_array( 'wpcargo_client', $current_user->roles )
                  && ! in_array( 'wpcargo_driver', $current_user->roles )
                  && ! current_user_can( 'manage_options' );
        $is_driver = in_array( 'wpcargo_driver', $current_user->roles )
                  && ! current_user_can( 'manage_options' );
        $is_admin  = current_user_can( 'manage_options' );

        $clientes_options_html = '<option value="">-- Selecciona un cliente --</option>';
        $clientes_form = get_users( [ 'role' => 'wpcargo_client' ] );
        foreach ( $clientes_form as $cliente ) {
            $nombre_completo = trim( $cliente->first_name . ' ' . $cliente->last_name );
            $nombre_mostrar  = ! empty( $nombre_completo ) ? $nombre_completo : $cliente->display_name;
            $clientes_options_html .= '<option value="' . $cliente->ID . '">' . esc_html( $nombre_mostrar ) . '</option>';
        }

        $merc_almacen_nonce = wp_create_nonce( 'merc_almacen' );
        $admin_ajax_url     = admin_url( 'admin-ajax.php' );
        ?>
    <style>
    .merc-estado-select {
        padding: 5px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        width: 100%;
        min-width: 150px;
        background: white;
        color: #333;
        font-weight: normal;
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .merc-estado-select:hover {
        border-color: #2196F3;
        box-shadow: 0 0 5px rgba(33, 150, 243, 0.3);
    }
    .merc-estado-select:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    td.shipment-status {
        padding: 8px !important;
    }

    tr.merc-estado-reprogramado {
        background-color: #fff3e0 !important;
        border-left: 5px solid #ff9800 !important;
    }
    tr.merc-estado-reprogramado:hover { background-color: #ffe0b2 !important; }
    tr.merc-estado-reprogramado td { color: #bf360c !important; font-weight: 600 !important; }
    tr.merc-estado-reprogramado td:first-child::before { content: '📅 '; font-size: 16px; margin-right: 5px; }

    tr.merc-estado-no-contesta {
        background-color: #fff9c4 !important;
        border-left: 5px solid #fdd835 !important;
    }
    tr.merc-estado-no-contesta:hover { background-color: #fff59d !important; }
    tr.merc-estado-no-contesta td { color: #f57f17 !important; font-weight: 600 !important; }
    tr.merc-estado-no-contesta td:first-child::before { content: '⚠️ '; font-size: 16px; margin-right: 5px; }

    tr.merc-estado-anulado {
        background-color: #ffcdd2 !important;
        border-left: 5px solid #d32f2f !important;
    }
    tr.merc-estado-anulado:hover { background-color: #ef9a9a !important; }
    tr.merc-estado-anulado td { color: #b71c1c !important; font-weight: 600 !important; }
    tr.merc-estado-anulado td:first-child::before { content: '🗑️ '; font-size: 16px; margin-right: 5px; }

    /* 🔐 ADMIN SIGNATURE BYPASS - Force button visibility */
    button.wpcod_pod_signature {
        display: inline-block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }

    .merc-btn-reprogramar {
        background: #ff5722; color: white; border: none; padding: 8px 16px;
        border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600;
        transition: all 0.3s; display: inline-flex; align-items: center; gap: 5px;
        box-shadow: 0 2px 5px rgba(255, 87, 34, 0.3);
    }
    .merc-btn-reprogramar:hover { background: #e64a19; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(255, 87, 34, 0.4); }
    .merc-btn-reprogramar:active { transform: translateY(0); }

    .merc-modal-reprogram {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6); z-index: 99999; display: flex;
        align-items: center; justify-content: center;
    }
    .merc-modal-reprogram-content {
        background: white; padding: 35px; border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 450px; width: 90%; text-align: center;
    }
    .merc-modal-reprogram-title { font-size: 22px; font-weight: bold; margin-bottom: 20px; color: #ff5722; }
    .merc-modal-reprogram-info { background: #fff3e0; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: left; }
    .merc-modal-reprogram-info p { margin: 5px 0; font-size: 14px; color: #333; }
    .merc-modal-reprogram-label { display: block; font-size: 15px; font-weight: 600; margin-bottom: 10px; color: #555; text-align: left; }
    .merc-modal-reprogram-input { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 15px; margin-bottom: 25px; box-sizing: border-box; transition: border 0.3s; }
    .merc-modal-reprogram-input:focus { outline: none; border-color: #ff5722; }
    .merc-modal-reprogram-buttons { display: flex; gap: 12px; justify-content: center; }
    .merc-modal-reprogram-btn { padding: 12px 35px; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .merc-modal-reprogram-btn-confirmar { background: #4caf50; color: white; }
    .merc-modal-reprogram-btn-confirmar:hover { background: #45a049; }
    .merc-modal-reprogram-btn-cancelar { background: #f44336; color: white; }
    .merc-modal-reprogram-btn-cancelar:hover { background: #da190b; }

    .merc-modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 99999; display: flex; align-items: center; justify-content: center;
    }
    .merc-modal-content { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 500px; width: 90%; text-align: center; }
    .merc-modal-title { font-size: 20px; font-weight: bold; margin-bottom: 15px; color: #333; }
    .merc-modal-message { font-size: 16px; margin-bottom: 25px; color: #666; line-height: 1.5; }
    .merc-modal-buttons { display: flex; gap: 10px; justify-content: center; }
    .merc-modal-btn { padding: 10px 30px; border: none; border-radius: 4px; font-size: 15px; cursor: pointer; transition: all 0.3s; }
    .merc-modal-btn-confirmar { background: #4caf50; color: white; }
    .merc-modal-btn-confirmar:hover { background: #45a049; }
    .merc-modal-btn-cancelar { background: #f44336; color: white; }
    .merc-modal-btn-cancelar:hover { background: #da190b; }
    </style>
    <script>
    jQuery(document).ready(function($) {

        // 🔐 ADMIN & DRIVER SIGNATURE BYPASS - Interceptar clicks en botón de firma para admins y motorizados
        const isAdmin = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
        const isDriver = <?php echo in_array('wpcargo_driver', wp_get_current_user()->roles) ? 'true' : 'false'; ?>;
        const canSign = isAdmin || isDriver;
        
        console.log('🔐 isAdmin:', isAdmin, '| isDriver:', isDriver, '| canSign:', canSign);
        
        if (canSign) {
            
            // Interceptar cualquier click en botones de firma
            $(document).on('click.mercSignature', 'button.wpcod_pod_signature, .show-signaturepad, button[data-target="#wpc_pod_signature-modal"]', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                
                const $row = $(this).closest('tr');
                
                // Obtener ID del envío - esto es todo lo que necesita el backend
                let shipmentID = $(this).data('id') || 
                                 $(this).data('shipment-id') || 
                                 $row.data('shipment-id') ||
                                 $row.attr('id')?.match(/\d+/)?.[0];
                
                console.log('📝 Shipment ID:', shipmentID);
                
                if (!shipmentID) {
                    alert('❌ No se pudo identificar el envío');
                    return false;
                }
                
                // Realizar AJAX para cargar formulario
                $.ajax({
                    type: "POST",
                    data: {
                        action: 'show_signaturepad_admin',
                        sid: shipmentID
                    },
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    beforeSend: function() {
                        console.log('📝 Loading signature form...');
                        $('body').append('<div class="wpcargo-loading">Cargando formulario...</div>');
                    },
                    success: function(response) {
                        console.log('✅ Signature form loaded');
                        $('body .wpcargo-loading').remove();
                        
                        const $modal = $('#wpc_pod_signature-modal');
                        if (!$modal.length) {
                            alert('❌ Modal no encontrado');
                            return;
                        }
                        
                        $modal.find('.modal-body').html(response);
                        
                        // Mostrar modal con Bootstrap
                        if (typeof $ !== 'undefined' && $.fn.modal) {
                            $modal.modal('show');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ Error loading signature form:', error);
                        console.error('📋 Response:', xhr.responseText);
                        $('body .wpcargo-loading').remove();
                        alert('❌ Error: ' + error);
                    }
                });
                
                return false;
            });
        } else {
            console.log('ℹ️ User cannot sign - No signature interception');
        }

        // ── Reordenar columnas ────────────────────────────────────────────
        function reordenarColumnas() {
            var $table = $('#shipment-list');
            if (!$table.length) return;

            function findThIdx($ths, text) {
                var idx = -1;
                $ths.each(function(i) {
                    if ($(this).text().toUpperCase().trim().indexOf(text.toUpperCase()) !== -1) { idx = i; return false; }
                });
                return idx;
            }

            function moveCol(afterText, moveText) {
                var $ths     = $table.find('thead tr:first th');
                var afterIdx = findThIdx($ths, afterText);
                var moveIdx  = findThIdx($ths, moveText);
                if (afterIdx === -1 || moveIdx === -1 || moveIdx === afterIdx + 1) return;
                $ths.eq(moveIdx).insertAfter($ths.eq(afterIdx));
                $table.find('tbody tr').each(function() {
                    var $cells = $(this).find('td');
                    var $mv = $cells.eq(moveIdx), $af = $cells.eq(afterIdx);
                    if ($mv.length && $af.length) $mv.insertAfter($af);
                });
            }

            function moveColToEnd(moveText) {
                var $ths    = $table.find('thead tr:first th');
                var moveIdx = findThIdx($ths, moveText);
                if (moveIdx === -1 || moveIdx === $ths.length - 1) return;
                $table.find('thead tr:first').append($ths.eq(moveIdx));
                $table.find('tbody tr').each(function() {
                    var $cells = $(this).find('td');
                    var $mv = $cells.eq(moveIdx);
                    if ($mv.length) $(this).append($mv);
                });
            }

            // "Estado" ya está en posición correcta (viene del template PHP)

            // "Número de seguimiento" queda al final
            ['Número de seguimiento', 'Seguimiento', 'Tracking Number', 'Número de Tracking'].forEach(function(c) {
                moveColToEnd(c);
            });

            // "Print" queda al final (después de Tracking)
            moveColToEnd('Print');
        }

        reordenarColumnas();
        setTimeout(reordenarColumnas, 800);
        // ──────────────────────────────────────────────────────────────────

        console.log('🔧 Inicializando columna Estado editable');

        const estados         = <?php echo json_encode( $estados ); ?>;
        const AJAX_URL        = <?php echo json_encode( $admin_ajax_url ); ?>;
        const NONCE_ALMACEN   = <?php echo json_encode( $merc_almacen_nonce ); ?>;
        const esCliente       = <?php echo $is_client ? 'true' : 'false'; ?>;
        const esMotorizado    = <?php echo $is_driver ? 'true' : 'false'; ?>;
        const clientesOptionsHtml = <?php echo json_encode( $clientes_options_html ); ?>;

        const estadosMotorizadoInicial      = ['RECOGIDO', 'NO RECOGIDO'];
        const estadosMotorizadoDespuesBase  = ['EN RUTA', 'NO CONTESTA', 'NO RECIBIDO', 'ENTREGADO', 'REPROGRAMADO', 'ANULADO'];

        function mostrarModalConfirmacion(mensaje, onConfirmar, onCancelar) {
            const $modal = $('<div class="merc-modal-overlay">' +
                '<div class="merc-modal-content">' +
                    '<div class="merc-modal-title">⚠️ Confirmar cambio de estado</div>' +
                    '<div class="merc-modal-message">' + mensaje + '</div>' +
                    '<div class="merc-modal-observaciones" style="margin: 20px 0;">' +
                        '<label for="merc-observaciones-input" style="display: block; margin-bottom: 8px; font-weight: bold; color: #333;">📝 Observaciones (opcional):</label>' +
                        '<textarea id="merc-observaciones-input" class="merc-observaciones-textarea" placeholder="Ingrese observaciones adicionales sobre este cambio de estado..." style="width: 100%; min-height: 80px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: Arial, sans-serif; font-size: 14px; resize: vertical;"></textarea>' +
                    '</div>' +
                    '<div class="merc-modal-buttons">' +
                        '<button class="merc-modal-btn merc-modal-btn-confirmar">✓ Sí, cambiar estado</button>' +
                        '<button class="merc-modal-btn merc-modal-btn-cancelar">✗ Cancelar</button>' +
                    '</div>' +
                '</div>' +
            '</div>');
            $('body').append($modal);
            $modal.find('.merc-modal-btn-confirmar').on('click', function() {
                const observaciones = $modal.find('#merc-observaciones-input').val().trim();
                $modal.remove();
                if (onConfirmar) onConfirmar(observaciones);
            });
            $modal.find('.merc-modal-btn-cancelar').on('click', function() {
                $modal.remove();
                if (onCancelar) onCancelar();
            });
            $(document).on('keyup.mercModal', function(e) {
                if (e.key === 'Escape') { $modal.remove(); if (onCancelar) onCancelar(); $(document).off('keyup.mercModal'); }
            });
            setTimeout(function() { $modal.find('#merc-observaciones-input').focus(); }, 100);
        }

        function setRowStateClass($row, estado) {
            if (!$row || !$row.length) return;
            const text = (estado || '').toUpperCase();
            $row.removeClass('merc-estado-reprogramado merc-estado-no-contesta merc-estado-anulado');
            if (text.includes('ANULADO') || text.includes('CANCEL')) { $row.addClass('merc-estado-anulado'); return; }
            if (text.includes('NO CONTESTA'))                         { $row.addClass('merc-estado-no-contesta'); return; }
            if (text.includes('REPROGRAMADO') || text.includes('RESCHEDULE')) { $row.addClass('merc-estado-reprogramado'); return; }
        }

        function resaltarFilasReprogramadas() {
            $('td.shipment-status').each(function() {
                const $estadoCell = $(this);
                let estadoActual  = $estadoCell.text().trim();
                const $select     = $estadoCell.find('.merc-estado-select');
                if ($select.length > 0) { estadoActual = $select.val() || $select.find('option:selected').text().trim(); }
                setRowStateClass($estadoCell.closest('tr'), estadoActual);
            });
        }
        setTimeout(resaltarFilasReprogramadas, 100);

        function addListoParaSalirColumn() {
            if (!esMotorizado) return;
            const $table = $('#shipment-list');
            if ($table.length === 0) return;

            const $thead = $table.find('thead');
            if ($thead.length > 0 && $thead.find('th:contains("LISTO PARA SALIR")').length === 0) {
                const $estadoTh = $thead.find('tr').first().find('th').filter(function() {
                    return $(this).text().toUpperCase().indexOf('ESTADO') !== -1;
                }).first();
                const $newTh = $('<th style="text-align:center;">LISTO PARA SALIR</th>');
                if ($estadoTh.length > 0) { $newTh.insertAfter($estadoTh); }
                else { $thead.find('tr').first().append($newTh); }
            }

            let estadoIndex = -1;
            $thead.find('tr').first().find('th').each(function(idx) {
                if ($(this).text().toUpperCase().indexOf('ESTADO') !== -1) { estadoIndex = idx; return false; }
            });

            $table.find('tbody tr').each(function() {
                const $row     = $(this);
                if ($row.find('td.listo-para-salir-cell').length > 0) return;
                let shipmentId = null;
                const rowId    = $row.attr('id');
                if (rowId && rowId.match(/shipment-(\d+)/)) { shipmentId = rowId.match(/shipment-(\d+)/)[1]; }
                if (!shipmentId) { const $ds = $row.find('[data-shipment-id]').first(); if ($ds.length) shipmentId = $ds.data('shipment-id'); }
                if (!shipmentId) {
                    const $a = $row.find('a[href]').first();
                    if ($a.length) { const href = $a.attr('href'); const m = href.match(/post=(\d+)/) || href.match(/shipment-(\d+)/) || href.match(/(\d{4,})/); if (m) shipmentId = m[1]; }
                }

                const $estadoCell = estadoIndex >= 0 ? $row.find('td').eq(estadoIndex) : null;
                const $newCell    = $('<td class="listo-para-salir-cell" style="text-align:center;">❓</td>');
                if ($estadoCell && $estadoCell.length > 0) { $newCell.insertAfter($estadoCell); }
                else { $row.append($newCell); }

                if (shipmentId) {
                    $.post(AJAX_URL, { action: 'merc_get_shipment_data', shipment_id: shipmentId }, function(resp) {
                        if (!resp || !resp.success) { $newCell.text('❌').css('color', '#e74c3c'); return; }
                        const data         = resp.data || {};
                        const estado_actual = (data.estado_actual || '').toString().toUpperCase().trim();
                        const estado_prev   = (data.estado_prev   || '').toString().trim();
                        const esListoParaSalir = estado_actual.indexOf('LISTO') !== -1 && estado_actual.indexOf('SALIR') !== -1;
                        if (esListoParaSalir) {
                            $newCell.text('✅').css({'color': '#27ae60', 'font-weight': 'bold'});
                            if (estado_prev && estado_prev.length > 0) {
                                const $estadoCells = $row.find('td').filter(function() {
                                    const t = $(this).text().toUpperCase().trim();
                                    return t.indexOf('LISTO') !== -1 && t.indexOf('SALIR') !== -1;
                                }).not('.listo-para-salir-cell');
                                $estadoCells.each(function() {
                                    const $cell   = $(this);
                                    const $select = $cell.find('select');
                                    if ($select.length > 0) {
                                        let encontrado = false;
                                        $select.find('option').each(function() {
                                            if ($(this).text().toUpperCase().trim() === estado_prev.toUpperCase().trim()) {
                                                $select.val($(this).val()); encontrado = true; return false;
                                            }
                                        });
                                        if (!encontrado) {
                                            try {
                                                const $opPrev = $('<option>').val(estado_prev).text(estado_prev);
                                                $select.prepend($opPrev); $select.val(estado_prev);
                                            } catch(e) { $cell.html(estado_prev); }
                                        }
                                    } else { $cell.text(estado_prev); }
                                });
                            }
                        } else { $newCell.text('❌').css('color', '#e74c3c'); }
                    }, 'json').fail(function() { $newCell.text('💥').css('color', '#e67e22'); });
                } else { $newCell.text('❌').css('color', '#e74c3c'); }
            });
        }
        addListoParaSalirColumn();

        function reemplazarEtiquetaShipmentStatus() {
            $('*:not(script):not(style)').contents().filter(function() {
                return this.nodeType === 3 && /ES\s*SHIPMENT\s*STATUS/gi.test(this.nodeValue);
            }).each(function() { this.nodeValue = this.nodeValue.replace(/ES\s*SHIPMENT\s*STATUS/gi, 'ESTADO DEL ENVÍO'); });
            try { document.documentElement.style.setProperty('--wpcargo', '#8e0205'); } catch(e) {}
            $('[id], [class], div, span, p, th, td').each(function() {
                const $el = $(this);
                if ($el.children().length === 0) {
                    const txt = $el.text();
                    if (/ES\s*SHIPMENT\s*STATUS/gi.test(txt)) {
                        $el.text(txt.replace(/ES\s*SHIPMENT\s*STATUS/gi, 'ESTADO DEL ENVÍO'));
                        $el.css({'background-color':'#8e0205','color':'#ffffff','padding':'10px','border-radius':'3px'});
                        const $wrap = $el.closest('.card, .pod-details, .container, .row, .text-center');
                        if ($wrap.length) { $wrap.css({'background-color':'#8e0205','color':'#ffffff'}); }
                    }
                }
            });
        }
        setTimeout(reemplazarEtiquetaShipmentStatus, 300);

        function convertirEstadoASelect() {
            if (esCliente) { console.log('👤 Usuario es cliente - estados solo en modo lectura'); return; }

            let contadorConvertidos = 0;

            $('td.shipment-status').each(function() {
                const $estadoCell = $(this);
                if ($estadoCell.find('.merc-estado-select').length > 0) return;
                const estadoActual = $estadoCell.text().trim();
                const $row         = $estadoCell.closest('tr');
                if (estadoActual.length < 2) return;
                if (estadoActual.toUpperCase().includes('ENTREGADO') || estadoActual.toUpperCase().includes('DELIVERED')) { console.log('⏭️ Saltando estado ENTREGADO:', estadoActual); return; }

                const rowId = $row.attr('id');
                let shipmentId = null;
                if (rowId) { const match = rowId.match(/shipment-(\d+)/); if (match) shipmentId = match[1]; }
                let shipmentNumber = $row.find('td').first().text().trim();
                if (!shipmentNumber) { const $link = $row.find('a').first(); if ($link.length > 0) shipmentNumber = $link.text().trim(); }
                if (!shipmentId) { console.warn('⚠️ No se pudo obtener ID para:', shipmentNumber); return; }

                console.log('📝 Convirtiendo:', shipmentNumber, 'ID:', shipmentId, 'Estado:', estadoActual);

                let estadosFiltrados = estados;
                if (esMotorizado) {
                    const estadoActualUpper = estadoActual.toUpperCase();
                    const estadosAvanzados  = ['EN BASE MERCOURIER','RECEPCIONADO','LISTO PARA SALIR','NO CONTESTA','EN RUTA','NO RECIBIDO','ENTREGADO','REPROGRAMADO','ANULADO'];
                    const esEstadoAvanzado  = estadosAvanzados.some(function(e) { return estadoActualUpper.includes(e); });
                    if (esEstadoAvanzado) {
                        estadosFiltrados = estados.filter(function(e) { const u = e.toUpperCase(); return estadosMotorizadoDespuesBase.some(function(p) { return u.includes(p) || p.includes(u); }); });
                        console.log('🚗 Motorizado - Estado avanzado detectado (' + estadoActual + ') - Mostrando estados posteriores');
                    } else {
                        estadosFiltrados = estados.filter(function(e) { const u = e.toUpperCase(); return estadosMotorizadoInicial.some(function(p) { return u.includes(p) || p.includes(u); }); });
                        console.log('🚗 Motorizado - Estado inicial - Solo RECOGIDO y NO CONTESTA');
                    }
                    estadosFiltrados = estadosFiltrados.filter(function(opt) { return opt.toUpperCase().trim() !== 'LISTO PARA SALIR'; });
                }

                const $select = $('<select class="merc-estado-select" data-shipment-id="' + shipmentId + '" data-shipment-number="' + shipmentNumber + '" style="display:block!important;width:100%;padding:5px 10px;border:1px solid #ddd;border-radius:4px;"></select>');
                let tieneSeleccionado  = false;
                let estadoActualAgregado = false;

                estadosFiltrados.forEach(function(estado) {
                    const selected = estado.toUpperCase() === estadoActual.toUpperCase() ? 'selected' : '';
                    if (selected) { tieneSeleccionado = true; estadoActualAgregado = true; }
                    $select.append('<option value="' + estado + '" ' + selected + '>' + estado + '</option>');
                });

                if (!estadoActualAgregado && estadoActual.length > 0) {
                    console.log('ℹ️ Estado actual no está en filtro - Agregándolo como opción solo lectura:', estadoActual);
                    $select.prepend('<option value="' + estadoActual + '" selected disabled>' + estadoActual + '</option>');
                    tieneSeleccionado = true;
                }
                if (!tieneSeleccionado) {
                    console.log('⚠️ No se encontró coincidencia exacta para:', estadoActual, '- Buscando parcial');
                    $select.find('option').each(function() {
                        const opcionTexto = $(this).text().toUpperCase();
                        if (estadoActual.toUpperCase().includes(opcionTexto) || opcionTexto.includes(estadoActual.toUpperCase())) {
                            $(this).prop('selected', true); console.log('✓ Seleccionado por coincidencia parcial:', $(this).text()); return false;
                        }
                    });
                }

                $estadoCell.removeClass().addClass('shipment-status');
                $estadoCell.html($select);
                contadorConvertidos++;
                setRowStateClass($row, estadoActual);

                $select.on('change', function() {
                    const $this        = $(this);
                    const nuevoEstado  = $this.val();
                    const estadoAnterior = estadoActual;
                    const id           = $this.data('shipment-id');
                    const numero       = $this.data('shipment-number');

                    if (nuevoEstado.toUpperCase().includes('ENTREGADO') || nuevoEstado.toUpperCase() === 'DELIVERED') {
                        console.log('🔀 Estado ENTREGADO seleccionado - Buscando botón FIRMAR');
                        const $rowLocal = $this.closest('tr');
                        let $btnFirmar  = $rowLocal.find('button.wpcod_pod_signature, button[data-target="#wpc_pod_signature-modal"]').first();
                        if ($btnFirmar.length === 0) {
                            $rowLocal.find('button').each(function() {
                                const texto = $(this).text().trim().toUpperCase();
                                if (texto.includes('FIRMAR') || texto.includes('SIGN')) { $btnFirmar = $(this); return false; }
                            });
                        }
                        if ($btnFirmar.length === 0) { alert('❌ No se encontró el botón FIRMAR para este pedido'); $this.val(estadoAnterior); return; }
                        const $modalInfo = $('<div class="merc-modal-overlay" style="z-index: 999998;"><div class="merc-modal-content"><div class="merc-modal-message" style="font-size: 16px; padding: 20px;">📝 Abriendo formulario de firma...</div></div></div>');
                        $('body').append($modalInfo);
                        setTimeout(function() { $modalInfo.remove(); $btnFirmar[0].click(); }, 1000);
                        return;
                    }

                    const esReprogramado = nuevoEstado.toUpperCase().includes('REPROGRAMADO') || nuevoEstado.toUpperCase().includes('RESCHEDULE');

                    mostrarModalConfirmacion(
                        '<strong>Pedido:</strong> ' + numero + '<br><br>' +
                        '<strong>Estado actual:</strong> ' + estadoAnterior + '<br>' +
                        '<strong>Nuevo estado:</strong> ' + nuevoEstado + '<br><br>¿Está seguro de realizar este cambio?',
                        function(observaciones) {
                            console.log('✅ Confirmado - Actualizando estado del pedido #' + numero);
                            $this.prop('disabled', true);
                            $.ajax({
                                type: 'POST', url: AJAX_URL,
                                data: {
                                    action: 'merc_actualizar_estado_rapido',
                                    shipment_id: id, nuevo_estado: nuevoEstado, observaciones: observaciones,
                                    nonce: '<?php echo wp_create_nonce( 'merc_actualizar_estado' ); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        console.log('✅ Estado actualizado correctamente');
                                        setRowStateClass($this.closest('tr'), nuevoEstado);
                                        if (esReprogramado) {
                                            $.ajax({
                                                type: 'POST', url: AJAX_URL,
                                                data: { action: 'merc_notificar_reprogramacion', shipment_id: id, shipment_number: numero, nonce: '<?php echo wp_create_nonce( 'merc_notificar_reprog' ); ?>' },
                                                success: function(notifResp) { if (notifResp.success) console.log('📧 Notificación enviada al cliente:', notifResp.data); else console.warn('⚠️ Error al enviar notificación:', notifResp.data); }
                                            });
                                        }
                                        const colorNotif  = esReprogramado ? '#f44336' : '#4caf50';
                                        const textoNotif  = esReprogramado ? '✓ Estado actualizado a: <strong>' + nuevoEstado + '</strong><br><small>Se ha notificado al cliente</small>' : '✓ Estado actualizado a: <strong>' + nuevoEstado + '</strong>';
                                        const $notif = $('<div style="position: fixed; top: 20px; right: 20px; background: ' + colorNotif + '; color: white; padding: 15px 25px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 999999; font-size: 14px;">' + textoNotif + '</div>');
                                        $('body').append($notif);
                                        setTimeout(function() { $notif.fadeOut(300, function() { $(this).remove(); }); }, 3000);
                                        setTimeout(function() { location.reload(); }, 2000);
                                    } else {
                                        alert('❌ Error: ' + response.data);
                                        $this.val(estadoAnterior);
                                    }
                                    $this.prop('disabled', false);
                                },
                                error: function() { alert('❌ Error de conexión. Por favor intente de nuevo.'); $this.val(estadoAnterior); $this.prop('disabled', false); }
                            });
                        },
                        function() { console.log('❌ Cancelado - Revirtiendo estado'); $this.val(estadoAnterior); }
                    );
                });
            });

            if (contadorConvertidos > 0) console.log('✅ Columna Estado convertida a SELECT -', contadorConvertidos, 'pedidos');
            else console.log('ℹ️ No se encontraron estados para convertir a SELECT');
        }

        setTimeout(convertirEstadoASelect, 1000);
        setTimeout(agregarBotonesReprogramar, 1500);

        setInterval(function() {
            // Funciona tanto con #shipment-list original como con el accordion
            const $tabla = $('#shipment-history-accordion, #shipment-list, table.shipment-list');
            if ($tabla.length === 0) return;
            if ($tabla.find('tbody tr').length === 0 && $tabla.find('td.shipment-status').length === 0) return;
            resaltarFilasReprogramadas();
            const $selects     = $tabla.find('.merc-estado-select');
            const $estadoCells = $('td.shipment-status');
            if ($estadoCells.length > 0 && $selects.length === 0) { console.log('🔄 Convirtiendo estados a SELECT...'); convertirEstadoASelect(); }
            agregarBotonesReprogramar();
        }, 2000);

        function agregarBotonesReprogramar() {
            const esClienteLocal = <?php echo $is_client ? 'true' : 'false'; ?>;
            const esAdmin        = <?php echo $is_admin  ? 'true' : 'false'; ?>;

            if (esClienteLocal) {
                $('tr.merc-estado-reprogramado').each(function() {
                    const $row = $(this);
                    if ($row.find('.merc-btn-reprogramar').length > 0) return;
                    const rowId = $row.attr('id'); if (!rowId) return;
                    const match = rowId.match(/shipment-(\d+)/); if (!match) return;
                    const shipmentId     = match[1];
                    const shipmentNumber = $row.find('td').first().text().trim();
                    const $celdaAcciones = $row.find('td.merc-acciones-cell');
                    const $cont          = $('<div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;"></div>');
                    const $btnReprogramar = $('<button class="merc-btn-reprogramar" data-shipment-id="' + shipmentId + '" data-shipment-number="' + shipmentNumber + '" style="background:#ff5722;color:white;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap;">📅 Reprogramar</button>');
                    $btnReprogramar.on('click', function(e) { e.preventDefault(); mostrarModalReprogramacion(shipmentId, shipmentNumber); });
                    const $btnAnular = $('<button class="merc-btn-anular" data-shipment-id="' + shipmentId + '" data-shipment-number="' + shipmentNumber + '" style="background:#f44336;color:white;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap;">❌ Anular</button>');
                    $btnAnular.on('click', function(e) { e.preventDefault(); mostrarModalAnulacion(shipmentId, shipmentNumber); });
                    $cont.append($btnReprogramar).append($btnAnular);
                    $celdaAcciones.append($cont);
                });
            }

            if (esAdmin) {
                setTimeout(function() {
                    $('tr.merc-estado-anulado').each(function() {
                        const $row = $(this);
                        if ($row.find('.merc-btn-crear-producto').length > 0) return;
                        const rowId = $row.attr('id'); if (!rowId) return;
                        const match = rowId.match(/shipment-(\d+)/); if (!match) return;
                        const shipmentId     = match[1];
                        const shipmentNumber = $row.find('td').first().text().trim();
                        const $celdaAcciones = $row.find('td.merc-acciones-cell');
                        if ($celdaAcciones.length === 0) return;
                        const $tipoCell = $row.find('td[data-tipo-envio]');
                        const tipoEnvio = $tipoCell.length > 0 ? $tipoCell.attr('data-tipo-envio').toLowerCase() : '';
                        if (tipoEnvio.indexOf('full') !== -1 || tipoEnvio.indexOf('fit') !== -1) return;
                        $.post(AJAX_URL, { action: 'merc_get_shipment_data', shipment_id: shipmentId }, function(resp) {
                            const data     = (resp && resp.success) ? (resp.data || {}) : {};
                            const clienteId = data.customer_id || '';
                            crearBotonProducto($row, $celdaAcciones, shipmentId, shipmentNumber, clienteId, data);
                        }, 'json').fail(function() { crearBotonProducto($row, $celdaAcciones, shipmentId, shipmentNumber, '', ''); });
                    });
                }, 500);

                function crearBotonProducto($row, $celdaAcciones, shipmentId, shipmentNumber, clienteId, data) {
                    if ($row.find('.merc-btn-crear-producto').length > 0) return;
                    const $btnCrear = $('<button class="merc-btn-crear-producto" data-shipment-id="' + shipmentId + '" data-shipment-number="' + shipmentNumber + '" style="margin-left:8px;background:#1976d2;color:#fff;padding:6px 10px;border-radius:6px;border:none;cursor:pointer;white-space:nowrap;">🛒 Crear Producto</button>');
                    $btnCrear.on('click', function(e) { e.preventDefault(); mostrarModalProductoDesdeEnvio($(this).data('shipment-id'), clienteId, $row.find('td').first().text().trim()); });
                    $celdaAcciones.append($btnCrear);
                }
            }

            // Manejador para botón BORRAR/DELETE (wpcfe-delete-shipment)
            // Usa event delegation desde document para funcionar también en el accordion.
            // stopImmediatePropagation evita que el handler nativo de WPCargo también se dispare.
            $(document).off('click.mercDelete', '.wpcfe-delete-shipment').on('click.mercDelete', '.wpcfe-delete-shipment', function(e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const $btn = $(this);
                const shipmentId = $btn.data('id');
                if (!shipmentId) { alert('❌ Error: ID del envío no encontrado'); return; }

                // Buscar la fila desde el botón mismo (funciona en accordion y en tabla original)
                const $row = $btn.closest('tr');
                // El número de envío viene del checkbox de la misma fila (data-number)
                const shipmentNumber = $row.find('.wpcfe-shipments, .merc-ship-ui').data('number') || ('Envío #' + shipmentId);

                if (!confirm('⚠️ ¿Estás seguro de que deseas BORRAR el envío ' + shipmentNumber + '?\n\nEsta acción NO se puede deshacer.')) {
                    return;
                }

                $btn.prop('disabled', true).text('Borrando...');

                $.ajax({
                    type: 'POST',
                    url: AJAX_URL,
                    data: {
                        action: 'merc_delete_shipment',
                        shipment_id: shipmentId,
                        nonce: <?php echo json_encode( wp_create_nonce( 'merc_delete_shipment' ) ); ?>
                    },
                    success: function(response) {
                        if (response && response.success) {
                            const $notif = $('<div style="position: fixed; top: 20px; right: 20px; background: #f44336; color: white; padding: 15px 25px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 999999; font-size: 14px;">✓ Envío eliminado correctamente</div>');
                            $('body').append($notif);
                            setTimeout(function() { $notif.fadeOut(300, function() { $(this).remove(); }); }, 3000);
                            // Eliminar la fila del DOM (funciona en accordion y tabla original)
                            if ($row.length) {
                                $row.fadeOut(300, function() { $(this).remove(); });
                            } else {
                                $('#shipment-' + shipmentId).fadeOut(300, function() { $(this).remove(); });
                            }
                        } else {
                            alert('❌ Error: ' + (response.data || 'No se pudo eliminar el envío'));
                            $btn.prop('disabled', false).text('Borrar');
                        }
                    },
                    error: function() {
                        alert('❌ Error de conexión al intentar eliminar el envío');
                        $btn.prop('disabled', false).text('Borrar');
                    }
                });
            });
        }

        function mostrarModalProductoDesdeEnvio(shipmentId, clienteId, shipmentTitle) {
            const modalHTML = `
<div id="modal-producto-envio" class="modal" style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 999999; align-items: center; justify-content: center;">
    <div class="modal-backdrop" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6);"></div>
    <div class="modal-box" style="position: relative; background: white; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #ecf0f1; flex-shrink: 0;">
            <h3 style="margin: 0; font-size: 20px; color: #2c3e50;">📦 Crear Producto en Almacén</h3>
            <button class="modal-close-envio" style="background: none; border: none; font-size: 24px; color: #7f8c8d; cursor: pointer; padding: 0; line-height: 1; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>
        <form id="form-producto-envio" style="overflow-y: auto; flex: 1;">
            <input type="hidden" id="prod-shipment-id" value="${shipmentId}">
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Nombre *</label>
                <input type="text" id="prod-nombre-envio" required placeholder="Nombre del producto" value="${shipmentTitle}" style="width: 100%; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Código de Barras <small>(opcional)</small></label>
                <input type="text" id="prod-codigo-barras-envio" placeholder="Código o SKU" value="SHIP-${shipmentId}" style="width: 100%; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Cliente Asignado *</label>
                <input id="prod-cliente-buscador" list="prod-cliente-datalist" placeholder="Buscar cliente por nombre..." required style="width: 100%; padding: 10px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px; display: block;" />
                <datalist id="prod-cliente-datalist"></datalist>
                <input type="hidden" id="prod-cliente-id" name="cliente_asignado" />
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Cantidad *</label>
                <input type="number" id="prod-cantidad-envio" min="1" required placeholder="0" value="1" style="width: 100%; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Peso (kg) <small>(opcional)</small></label>
                <input type="number" id="prod-peso-envio" min="0" step="0.01" placeholder="0.00" style="width: 100%; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Tipo de Medida <small>(opcional)</small></label>
                <select id="prod-tipo-medida-envio" style="width: 100%; padding: 10px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
                    <option value="">-- Seleccionar --</option>
                    <option value="talla">Talla</option>
                    <option value="color">Color</option>
                    <option value="modelo">Modelo</option>
                    <option value="otro">Otro</option>
                </select>
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Valor de Medida <small>(opcional)</small></label>
                <input type="text" id="prod-valor-medida-envio" placeholder="Ej: S, M, L, XL o 100ml" style="width: 100%; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
            </div>
            <div class="form-group" style="padding: 12px 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px;">Dimensiones (cm) <small>(opcional)</small></label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="number" id="prod-largo-envio" min="0" step="0.1" placeholder="Largo" style="flex: 1; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
                    <span style="color: #7f8c8d;">×</span>
                    <input type="number" id="prod-ancho-envio" min="0" step="0.1" placeholder="Ancho" style="flex: 1; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
                    <span style="color: #7f8c8d;">×</span>
                    <input type="number" id="prod-alto-envio" min="0" step="0.1" placeholder="Alto" style="flex: 1; padding: 10px 12px; border: 2px solid #dfe6e9; border-radius: 6px; font-size: 14px;">
                </div>
            </div>
            <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end; padding: 15px 20px; border-top: 1px solid #ecf0f1; background: #f8f9fa; flex-shrink: 0;">
                <button type="button" class="btn-secondary modal-close-envio" style="background: #6c757d; color: white; padding: 10px 24px; font-size: 14px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Cancelar</button>
                <button type="submit" class="btn-primary" style="background: #3498db; color: white; padding: 10px 24px; font-size: 14px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Guardar</button>
            </div>
        </form>
    </div>
</div>`;
            $('body').append(modalHTML);
            const $modal = $('#modal-producto-envio');
            if (clienteId) $modal.find('#prod-cliente-id').val(clienteId);
            $modal.find('.modal-close-envio, .modal-backdrop').on('click', function() { $modal.remove(); });
            $modal.find('#form-producto-envio').on('submit', function(e) {
                e.preventDefault();
                const datos = {
                    action: 'merc_guardar_producto', nonce: NONCE_ALMACEN, id: 0,
                    nombre: $('#prod-nombre-envio').val(), codigo_barras: $('#prod-codigo-barras-envio').val(),
                    cliente_asignado: $('#prod-cliente-id').val(), cantidad: parseInt($('#prod-cantidad-envio').val()) || 0,
                    peso: parseFloat($('#prod-peso-envio').val()) || 0, tipo_medida: $('#prod-tipo-medida-envio').val(),
                    valor_medida: $('#prod-valor-medida-envio').val(), largo: parseFloat($('#prod-largo-envio').val()) || 0,
                    ancho: parseFloat($('#prod-ancho-envio').val()) || 0, alto: parseFloat($('#prod-alto-envio').val()) || 0,
                    shipment_id: shipmentId
                };
                $.post(AJAX_URL, datos, function(r) {
                    if (r.success) { alert('✅ Producto creado exitosamente en el almacén'); $modal.remove(); location.reload(); }
                    else { alert('❌ Error: ' + (r.data || 'Error desconocido')); }
                }).fail(function() { alert('❌ Error de red al crear el producto'); });
            });
        }

        function mostrarModalReprogramacion(shipmentId, shipmentNumber) {
            function formatearFechaAMostrar(fechaISO) {
                if (!fechaISO) return '';
                const partes = fechaISO.split('-');
                if (partes.length !== 3) return fechaISO;
                return partes[2] + '/' + partes[1] + '/' + partes[0];
            }

            const $row             = $('#shipment-' + shipmentId);
            const fechaActualMostrar = $row.find('td').eq(4).text().trim();
            const tomorrow         = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
            const minDate          = tomorrow.toISOString().split('T')[0];
            const minDateMostrar   = formatearFechaAMostrar(minDate);

            const $modal = $('<div class="merc-modal-reprogram">' +
                '<div class="merc-modal-reprogram-content">' +
                    '<div class="merc-modal-reprogram-title">📅 Reprogramar Envío</div>' +
                    '<div class="merc-modal-reprogram-info">' +
                        '<p><strong>📦 Número de envío:</strong> ' + shipmentNumber + '</p>' +
                        '<p><strong>📆 Fecha actual:</strong> ' + fechaActualMostrar + '</p>' +
                        '<p style="font-size: 12px; color: #666; margin-top: 8px;">📌 Fecha mínima disponible: ' + minDateMostrar + '</p>' +
                    '</div>' +
                    '<label class="merc-modal-reprogram-label">Seleccione la nueva fecha de envío:</label>' +
                    '<input type="date" class="merc-modal-reprogram-input" id="merc-nueva-fecha" min="' + minDate + '" required>' +
                    '<div class="merc-modal-reprogram-buttons">' +
                        '<button class="merc-modal-reprogram-btn merc-modal-reprogram-btn-confirmar">✓ Confirmar</button>' +
                        '<button class="merc-modal-reprogram-btn merc-modal-reprogram-btn-cancelar">✗ Cancelar</button>' +
                    '</div>' +
                '</div>' +
            '</div>');
            $('body').append($modal);

            $modal.find('.merc-modal-reprogram-btn-confirmar').on('click', function() {
                const nuevaFechaISO       = $('#merc-nueva-fecha').val();
                if (!nuevaFechaISO) { alert('Por favor seleccione una fecha'); return; }
                const nuevaFechaDDMMYYYY  = formatearFechaAMostrar(nuevaFechaISO);
                if (!confirm('¿Confirmar reprogramación para el ' + nuevaFechaDDMMYYYY + '?')) return;
                $(this).prop('disabled', true).text('Guardando...');
                $.ajax({
                    type: 'POST', url: AJAX_URL,
                    data: { action: 'merc_reprogramar_envio', shipment_id: shipmentId, nueva_fecha: nuevaFechaDDMMYYYY, nonce: '<?php echo wp_create_nonce( 'merc_reprogramar' ); ?>' },
                    success: function(response) {
                        if (response.success) {
                            $modal.remove();
                            $(document).trigger('merc-fecha-reprogramada', [shipmentId]);
                            const $notif = $('<div style="position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 15px 25px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 999999; font-size: 14px;">✓ Fecha reprogramada exitosamente<br><small>Nueva fecha: ' + nuevaFechaDDMMYYYY + '</small></div>');
                            $('body').append($notif);
                            setTimeout(function() { $notif.fadeOut(300, function() { $(this).remove(); }); }, 3000);
                            setTimeout(function() { window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime(); }, 2000);
                        } else {
                            alert('❌ Error: ' + (response.data || 'No se pudo reprogramar'));
                            $modal.find('.merc-modal-reprogram-btn-confirmar').prop('disabled', false).text('✓ Confirmar');
                        }
                    },
                    error: function() { alert('❌ Error de conexión'); $modal.find('.merc-modal-reprogram-btn-confirmar').prop('disabled', false).text('✓ Confirmar'); }
                });
            });
            $modal.find('.merc-modal-reprogram-btn-cancelar').on('click', function() { $modal.remove(); });
            $(document).on('keyup.mercModalReprogram', function(e) { if (e.key === 'Escape') { $modal.remove(); $(document).off('keyup.mercModalReprogram'); } });
        }

        function mostrarModalAnulacion(shipmentId, shipmentNumber) {
            const $modal = $('<div class="merc-modal-anular" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:999999;">' +
                '<div class="merc-modal-anular-content" style="background:#fff;border-radius:12px;padding:25px;max-width:500px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
                    '<div style="font-size:20px;font-weight:bold;margin-bottom:20px;color:#d32f2f;text-align:center;">❌ Anular Envío</div>' +
                    '<div style="margin-bottom:15px;">' +
                        '<p><strong>📦 Número de envío:</strong> ' + shipmentNumber + '</p>' +
                        '<p style="color:#d32f2f;font-weight:bold;">⚠️ ¿Está seguro que desea anular este envío?</p>' +
                        '<p style="font-size:13px;color:#666;">Esta acción no se puede deshacer.</p>' +
                    '</div>' +
                    '<label style="display:block;margin-bottom:8px;font-weight:600;color:#555;">Motivo de anulación (opcional):</label>' +
                    '<textarea id="merc-motivo-anulacion" rows="3" placeholder="Describe el motivo..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;margin-bottom:15px;box-sizing:border-box;"></textarea>' +
                    '<div style="display:flex;gap:10px;justify-content:center;">' +
                        '<button class="merc-modal-anular-btn-confirmar" style="padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:bold;background:#d32f2f;color:#fff;">✓ Confirmar Anulación</button>' +
                        '<button class="merc-modal-anular-btn-cancelar" style="padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:bold;background:#757575;color:#fff;">✗ Cancelar</button>' +
                    '</div>' +
                '</div>' +
            '</div>');
            $('body').append($modal);

            $modal.find('.merc-modal-anular-btn-confirmar').on('click', function() {
                const motivo = $('#merc-motivo-anulacion').val().trim();
                if (!confirm('⚠️ ¿Confirmar la anulación del envío ' + shipmentNumber + '?')) return;
                $(this).prop('disabled', true).text('Anulando...');
                $.ajax({
                    type: 'POST', url: AJAX_URL,
                    data: { action: 'merc_anular_envio_cliente', shipment_id: shipmentId, motivo: motivo, nonce: '<?php echo wp_create_nonce( 'merc_anular_envio' ); ?>' },
                    success: function(response) {
                        if (response.success) {
                            $modal.remove();
                            const $notif = $('<div style="position: fixed; top: 20px; right: 20px; background: #f44336; color: white; padding: 15px 25px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 999999; font-size: 14px;">✓ Envío anulado exitosamente</div>');
                            $('body').append($notif);
                            setTimeout(function() { $notif.fadeOut(300, function() { $(this).remove(); }); }, 3000);
                            setTimeout(function() { window.location.reload(); }, 2000);
                        } else {
                            alert('❌ Error: ' + (response.data || 'No se pudo anular'));
                            $modal.find('.merc-modal-anular-btn-confirmar').prop('disabled', false).text('✓ Confirmar Anulación');
                        }
                    },
                    error: function() { alert('❌ Error de conexión'); $modal.find('.merc-modal-anular-btn-confirmar').prop('disabled', false).text('✓ Confirmar Anulación'); }
                });
            });
            $modal.find('.merc-modal-anular-btn-cancelar').on('click', function() { $modal.remove(); });
            $(document).on('keyup.mercModalAnular', function(e) { if (e.key === 'Escape') { $modal.remove(); $(document).off('keyup.mercModalAnular'); } });
        }
    });
    </script>
        <?php
    }

    /**
     * Manejador AJAX para cargar el formulario de firma del plugin POD
     * Usa el template original del plugin pero permitiendo acceso a admins
     */
    public function show_signaturepad_for_all_roles() {
        global $wpcargo; // ✅ Obtener objeto global del plugin WPCargo
        
        error_log( '=== DEBUG: show_signaturepad_for_all_roles() INICIADO ===' );
        error_log( 'DEBUG: $wpcargo disponible: ' . ( isset( $wpcargo ) && $wpcargo ? 'YES' : 'NO' ) );
        
        // Configurar error handler personalizado para capturar todos los errores
        $error_handler_backup = set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
            error_log( "DEBUG: Error capturado - [$errno] $errstr en $errfile:$errline" );
            return true; // Suprimir el error
        });
        
        try {
            // ✅ Validar permisos - puede ser admin o motorizado
            $user = wp_get_current_user();
            error_log( 'DEBUG: Usuario actual: ' . $user->user_login . ' | Roles: ' . implode( ',', $user->roles ) );
            
            $can_manage = current_user_can( 'manage_options' );
            $is_driver = in_array( 'wpcargo_driver', $user->roles );
            
            error_log( 'DEBUG: can_manage_options=' . ( $can_manage ? 'YES' : 'NO' ) . ' | is_driver=' . ( $is_driver ? 'YES' : 'NO' ) );
            
            $can_access = $can_manage || $is_driver;
            
            if ( ! $can_access ) {
                error_log( 'DEBUG: Acceso denegado - usuario sin permisos' );
                restore_error_handler();
                wp_die( 'Acceso denegado' );
            }

            // ✅ Obtener ID del envío
            $shipment_id = isset( $_POST['sid'] ) ? intval( $_POST['sid'] ) : 0;
            error_log( 'DEBUG: shipment_id recibido: ' . $shipment_id );
            
            // Obtener datos adicionales del shipper y receiver si se envían
            $pod_shipper = isset( $_POST['pod_shipper'] ) ? sanitize_text_field( $_POST['pod_shipper'] ) : '';
            $pod_receiver = isset( $_POST['pod_receiver'] ) ? sanitize_text_field( $_POST['pod_receiver'] ) : '';
            error_log( 'DEBUG: pod_shipper recibido: ' . ( $pod_shipper ? $pod_shipper : 'EMPTY' ) );
            error_log( 'DEBUG: pod_receiver recibido: ' . ( $pod_receiver ? $pod_receiver : 'EMPTY' ) );
            
            if ( ! $shipment_id ) {
                error_log( 'DEBUG: ID inválido - shipment_id es 0' );
                restore_error_handler();
                wp_die( 'ID inválido' );
            }

            // ✅ Verificar que exista el envío
            $shipment = get_post( $shipment_id );
            error_log( 'DEBUG: Shipment encontrado: ' . ( $shipment ? 'YES' : 'NO' ) );
            if ( $shipment ) {
                error_log( 'DEBUG: Shipment post_type: ' . $shipment->post_type );
                error_log( 'DEBUG: Shipment title: ' . $shipment->post_title );
            }
            
            if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
                error_log( 'DEBUG: Envío no encontrado o tipo incorrecto' );
                restore_error_handler();
                wp_die( 'Envío no encontrado' );
            }

            // ✅ Verificar que el plugin POD esté activo
            $pod_path_exists = defined( 'WPCARGO_POD_PATH' );
            error_log( 'DEBUG: WPCARGO_POD_PATH definido: ' . ( $pod_path_exists ? 'YES' : 'NO' ) );
            
            if ( ! $pod_path_exists ) {
                error_log( 'DEBUG: Plugin POD no disponible' );
                restore_error_handler();
                wp_die( 'Plugin POD no disponible' );
            }

            error_log( 'DEBUG: WPCARGO_POD_PATH = ' . WPCARGO_POD_PATH );

            // ✅ Cargar funciones necesarias del plugin POD
            error_log( '--- DEBUG: Cargando funciones del plugin POD ---' );
            
            if ( ! function_exists( 'wpcpod_signature_field_list' ) ) {
                $functions_file = WPCARGO_POD_PATH . 'templates/functions.php';
                error_log( 'DEBUG: Intentando cargar: ' . $functions_file );
                if ( file_exists( $functions_file ) ) {
                    require_once( $functions_file );
                    error_log( 'DEBUG: ✓ wpcpod_signature_field_list cargada' );
                } else {
                    error_log( 'DEBUG: ✗ Archivo no existe: ' . $functions_file );
                }
            } else {
                error_log( 'DEBUG: ✓ wpcpod_signature_field_list ya existe' );
            }
            
            if ( ! function_exists( 'wpcargo_history_order' ) ) {
                $admin_functions_file = WPCARGO_POD_PATH . 'admin/includes/functions.php';
                error_log( 'DEBUG: Intentando cargar: ' . $admin_functions_file );
                if ( file_exists( $admin_functions_file ) ) {
                    require_once( $admin_functions_file );
                    error_log( 'DEBUG: ✓ wpcargo_history_order cargada' );
                } else {
                    error_log( 'DEBUG: ✗ Archivo no existe: ' . $admin_functions_file );
                }
            } else {
                error_log( 'DEBUG: ✓ wpcargo_history_order ya existe' );
            }

            // ✅ Preparar variables para el template
            error_log( '--- DEBUG: Preparando variables para el template ---' );
            $get_sid = $shipment_id;
            $get_pod_img = get_post_meta( $get_sid, 'wpcargo-pod-image', true );
            $pod_signature = get_post_meta( $get_sid, 'wpcargo-pod-signature', true );
            
            error_log( 'DEBUG: get_pod_img = ' . ( $get_pod_img ? 'YES' : 'EMPTY' ) );
            error_log( 'DEBUG: pod_signature = ' . ( $pod_signature ? 'YES' : 'EMPTY' ) );
            
            $shipment_update = maybe_unserialize( get_post_meta( $get_sid, 'wpcargo_shipments_update', true ) );
            $is_shipment_update_array = is_array( $shipment_update );
            error_log( 'DEBUG: shipment_update es array: ' . ( $is_shipment_update_array ? 'YES' : 'NO' ) );
            
            if ( $is_shipment_update_array && function_exists( 'wpcargo_history_order' ) ) {
                $history = wpcargo_history_order( $shipment_update );
                $shipment_update = isset( $history[0] ) ? $history[0] : array();
                error_log( 'DEBUG: wpcargo_history_order aplicada' );
            } else {
                error_log( 'DEBUG: wpcargo_history_order NO aplicada' );
                $shipment_update = array();
            }

            // ✅ Obtener estado del envío para el header
            $wpcargo_get_status = get_post_meta( $get_sid, 'wpcargo_status', true );
            error_log( 'DEBUG: wpcargo_get_status = ' . ( $wpcargo_get_status ? $wpcargo_get_status : 'EMPTY' ) );
            
            // ✅ Cargar opciones del plugin POD para shipper y receiver
            $options = get_option( 'wpcargo_pod_option_settings' );
            $shipper_selected_option = array();
            $receiver_selected_option = array();
            
            if ( ! empty( $options ) && array_key_exists( 'shipper_fields', $options ) ) {
                $shipper_selected_option = $options['shipper_fields'];
                error_log( 'DEBUG: shipper_selected_option cargada: ' . count( $shipper_selected_option ) . ' campos' );
            } else {
                error_log( 'DEBUG: shipper_selected_option vacía' );
            }
            
            if ( ! empty( $options ) && array_key_exists( 'receiver_fields', $options ) ) {
                $receiver_selected_option = $options['receiver_fields'];
                error_log( 'DEBUG: receiver_selected_option cargada: ' . count( $receiver_selected_option ) . ' campos' );
            } else {
                error_log( 'DEBUG: receiver_selected_option vacía' );
            }

            // ✅ Cargar el template original del plugin POD
            error_log( '--- DEBUG: Cargando templates del plugin POD ---' );
            ob_start();
            
            // Detectar si tiene custom fields plugin
            $has_cf_plugin = is_plugin_active( 'wpcargo-custom-field-addons/wpcargo-custom-field.php' );
            error_log( 'DEBUG: Custom Fields addon activo: ' . ( $has_cf_plugin ? 'YES' : 'NO' ) );
            
            // Incluir el template del header (ahora con $wpcargo disponible)
            if ( $has_cf_plugin ) {
                $header_file = WPCARGO_POD_PATH . 'templates/wpc-pod-sign-header-cf.tpl.php';
                error_log( 'DEBUG: Intentando cargar header CF: ' . $header_file );
            } else {
                $header_file = WPCARGO_POD_PATH . 'templates/wpc-pod-sign-header.tpl.php';
                error_log( 'DEBUG: Intentando cargar header: ' . $header_file );
            }
            
            if ( file_exists( $header_file ) ) {
                @include( $header_file );
                error_log( 'DEBUG: ✓ Header incluido' );
            } else {
                error_log( 'DEBUG: ✗ Header no existe: ' . $header_file );
            }
            
            // Incluir el form de firma
            $sig_form_file = WPCARGO_POD_PATH . 'templates/wpc-pod-signature-form.tpl.php';
            error_log( 'DEBUG: Intentando cargar signature form: ' . $sig_form_file );
            if ( file_exists( $sig_form_file ) ) {
                @include( $sig_form_file );
                error_log( 'DEBUG: ✓ Signature form incluido' );
            } else {
                error_log( 'DEBUG: ✗ Signature form no existe' );
            }
            
            // Incluir imágenes y resto del formulario
            $sign_file = WPCARGO_POD_PATH . 'templates/wpc-pod-sign.tpl.php';
            error_log( 'DEBUG: Intentando cargar sign template: ' . $sign_file );
            if ( file_exists( $sign_file ) ) {
                @include( $sign_file );
                error_log( 'DEBUG: ✓ Sign template incluido' );
            } else {
                error_log( 'DEBUG: ✗ Sign template no existe' );
            }
            
            $html = ob_get_clean();
            error_log( 'DEBUG: HTML final length: ' . strlen( $html ) );
            error_log( '=== DEBUG: show_signaturepad_for_all_roles() COMPLETADO ===' );
            
            // Restaurar error handler
            restore_error_handler();
            
            echo $html;
            wp_die();
            
        } catch ( \Exception $e ) {
            error_log( 'DEBUG: EXCEPTION: ' . $e->getMessage() );
            restore_error_handler();
            ob_end_clean();
            echo '<p>Error: ' . esc_html( $e->getMessage() ) . '</p>';
            wp_die();
        }
    }
}

} // End if ( ! class_exists( 'MERC_Table_UI' ) )

if ( class_exists( 'MERC_Table_UI' ) ) {
    new MERC_Table_UI();
}