jQuery(document).ready(function ($) {

    // Solo actuar en la página del dashboard con tabla de envíos
    if ($('tr[id^="shipment-"]').length === 0 && $('table tbody tr').length === 0) {
        return;
    }

    // Caché local para no repetir llamadas por el mismo envío
    var fechasActualizadas = {};

    function corregirFechasEnTabla() {
        var $rows = $('tr[id^="shipment-"]');
        if ($rows.length === 0) return;

        $rows.each(function () {
            var $row      = $(this);
            var rowId     = $row.attr('id');
            var match     = rowId ? rowId.match(/shipment-(\d+)/) : null;
            if (!match) return;

            var shipmentId = match[1];
            if (fechasActualizadas[shipmentId]) return;

            // Buscar la primera celda cuyo texto parezca una fecha
            var $fechaCelda = null;
            $row.find('td').each(function () {
                var texto = $(this).text().trim();
                if (/\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2}/.test(texto)) {
                    if (!$fechaCelda) {
                        $fechaCelda = $(this);
                    }
                }
            });

            if (!$fechaCelda || $fechaCelda.length === 0) return;

            fechasActualizadas[shipmentId] = 'procesando';

            $.ajax({
                type: 'POST',
                url:  mercFechaAjax.ajaxurl,
                data: {
                    action:      'merc_obtener_fecha_envio',
                    shipment_id: shipmentId,
                    nonce:       mercFechaAjax.nonce
                },
                success: function (response) {
                    if (response.success && response.data.fecha_formateada) {
                        var fechaActual = $fechaCelda.text().trim();
                        var fechaNueva  = response.data.fecha_formateada;

                        if (fechaActual !== fechaNueva) {
                            if (response.data.fue_reprogramada) {
                                $fechaCelda.html(
                                    '<strong style="color:#2196F3;" title="Fecha reprogramada por el cliente">'
                                    + fechaNueva + '</strong>'
                                );
                            } else {
                                $fechaCelda.text(fechaNueva);
                            }
                        }
                        fechasActualizadas[shipmentId] = fechaNueva;
                    }
                },
                error: function () {
                    delete fechasActualizadas[shipmentId];
                }
            });
        });
    }

    // Dos pasadas para cubrir rendering tardío de la tabla
    setTimeout(corregirFechasEnTabla, 1000);
    setTimeout(function () {
        fechasActualizadas = {};
        corregirFechasEnTabla();
    }, 3000);

    // Reaccionar a reprogramaciones hechas desde el modal del cliente
    $(document).on('merc-fecha-reprogramada', function (e, shipmentId) {
        delete fechasActualizadas[shipmentId];
        setTimeout(corregirFechasEnTabla, 500);
    });
});