<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MERC_Table_Ajax
 *
 * AJAX endpoints y filtros PHP relacionados con la tabla de envíos:
 *   - Filtro: mostrar fecha correcta desde wpcargo_pickup_date_picker.
 *   - AJAX: actualizar estado rápido desde la tabla.
 *   - AJAX: notificar reprogramación al cliente.
 *   - AJAX: reprogramar fecha de envío (cliente).
 *   - AJAX: anular envío (cliente).
 *
 * Movido desde blocksy-child/functions.php al plugin merc-table-customizer.
 */
if ( ! class_exists( 'MERC_Table_Ajax' ) ) {

class MERC_Table_Ajax {

    public function __construct() {
        // Filtro de fecha en tabla
        add_filter( 'wpcargo_customizer_formatted_date', [ $this, 'usar_fecha_meta_primero' ], 10, 2 );

        // AJAX: solo usuarios logueados
        add_action( 'wp_ajax_merc_actualizar_estado_rapido',   [ $this, 'ajax_actualizar_estado' ] );
        add_action( 'wp_ajax_merc_notificar_reprogramacion',   [ $this, 'ajax_notificar_reprogramacion' ] );
        add_action( 'wp_ajax_merc_reprogramar_envio',          [ $this, 'ajax_reprogramar_envio' ] );
        add_action( 'wp_ajax_merc_anular_envio_cliente',       [ $this, 'ajax_anular_envio' ] );
        add_action( 'wp_ajax_merc_delete_shipment',            [ $this, 'ajax_delete_shipment' ] );
    }

    /* ── Filtro: mostrar fecha desde meta, no desde post_date ───────────── */

    public function usar_fecha_meta_primero( string $formatted_date, $original_date ): string {
        global $shipment_id;
        if ( empty( $shipment_id ) ) {
            return $formatted_date;
        }
        $fecha_meta = get_post_meta( $shipment_id, 'wpcargo_pickup_date_picker', true );
        if ( ! empty( $fecha_meta ) && $fecha_meta !== $original_date ) {
            $fecha_obj = DateTime::createFromFormat( 'Y-m-d', $fecha_meta );
            if ( $fecha_obj ) {
                return $fecha_obj->format( 'd/m/Y' );
            }
        }
        return $formatted_date;
    }

    /* ── AJAX: Actualizar estado rápido (con observaciones) ─────────────── */

    public function ajax_actualizar_estado(): void {
        check_ajax_referer( 'merc_actualizar_estado', 'nonce' );

        $shipment_id   = isset( $_POST['shipment_id'] )   ? intval( $_POST['shipment_id'] )                      : 0;
        $nuevo_estado  = isset( $_POST['nuevo_estado'] )  ? sanitize_text_field( $_POST['nuevo_estado'] )         : '';
        $observaciones = isset( $_POST['observaciones'] ) ? sanitize_textarea_field( $_POST['observaciones'] )    : '';

        if ( empty( $shipment_id ) || empty( $nuevo_estado ) ) {
            wp_send_json_error( 'Datos incompletos' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        $estado_anterior = get_post_meta( $shipment_id, 'wpcargo_status', true );

        if ( stripos( $nuevo_estado, 'LISTO PARA SALIR' ) !== false && ! empty( $estado_anterior ) ) {
            update_post_meta( $shipment_id, 'wpcargo_status_anterior', $estado_anterior );
        }

        update_post_meta( $shipment_id, 'wpcargo_status', $nuevo_estado );

        $historial = get_post_meta( $shipment_id, 'wpcargo_shipments_update', true );
        if ( ! is_array( $historial ) ) {
            $historial = [];
        }

        $remarks_final  = ! empty( $observaciones ) ? $observaciones : 'Estado actualizado desde la tabla de pedidos';
        $nuevo_registro = [
            'status'       => $nuevo_estado,
            'date'         => date( 'Y-m-d' ),
            'time'         => date( 'H:i:s' ),
            'updated-name' => wp_get_current_user()->display_name,
            'remarks'      => $remarks_final,
        ];
        array_unshift( $historial, $nuevo_registro );
        update_post_meta( $shipment_id, 'wpcargo_shipments_update', $historial );

        wp_send_json_success( [
            'message'      => 'Estado actualizado correctamente',
            'nuevo_estado' => $nuevo_estado,
            'observaciones' => $remarks_final,
        ] );
    }

    /* ── AJAX: Notificar reprogramación al cliente ───────────────────────── */

    public function ajax_notificar_reprogramacion(): void {
        check_ajax_referer( 'merc_notificar_reprog', 'nonce' );

        $shipment_id     = isset( $_POST['shipment_id'] )     ? intval( $_POST['shipment_id'] )              : 0;
        $shipment_number = isset( $_POST['shipment_number'] ) ? sanitize_text_field( $_POST['shipment_number'] ) : '';

        if ( empty( $shipment_id ) ) {
            wp_send_json_error( 'ID de envío no proporcionado' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        $cliente    = get_userdata( $shipment->post_author );
        if ( ! $cliente ) {
            wp_send_json_error( 'Cliente no encontrado' );
        }

        $cliente_email  = $cliente->user_email;
        $cliente_nombre = $cliente->display_name;
        $asunto         = 'Envío Reprogramado - ' . $shipment_number;

        $mensaje = "
        <html><head><style>
            body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
            .container{max-width:600px;margin:0 auto;padding:20px;background:#f9f9f9}
            .header{background:#f44336;color:white;padding:20px;text-align:center;border-radius:5px 5px 0 0}
            .content{background:white;padding:30px;border-radius:0 0 5px 5px}
            .alert{background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0}
            .btn{display:inline-block;padding:12px 30px;background:#f44336;color:white;text-decoration:none;border-radius:5px;margin:20px 0}
            .footer{text-align:center;color:#666;font-size:12px;margin-top:20px}
        </style></head>
        <body><div class='container'>
            <div class='header'><h2>⏰ Envío Reprogramado</h2></div>
            <div class='content'>
                <p>Hola <strong>{$cliente_nombre}</strong>,</p>
                <p>Tu envío ha sido <strong style='color:#f44336'>REPROGRAMADO</strong>.</p>
                <div class='alert'>
                    <strong>📦 Número de envío:</strong> {$shipment_number}<br>
                    <strong>📅 Fecha y Hora:</strong> " . wp_date( 'd/m/Y H:i' ) . "
                </div>
                <p>Para coordinar nueva fecha, ingresa a tu cuenta y ve a <em>Mis Envíos</em>.</p>
                <p style='text-align:center'><a href='" . site_url() . "' class='btn'>Ir a Mi Cuenta</a></p>
                <p>Saludos,<br>Equipo de MerCourier</p>
            </div>
            <div class='footer'><p>Correo automático, no responder.</p></div>
        </div></body></html>";

        $headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: MerCourier <noreply@mercourier.com>' ];
        $enviado = wp_mail( $cliente_email, $asunto, $mensaje, $headers );

        if ( $enviado ) {
            $historial = get_post_meta( $shipment_id, 'wpcargo_shipments_update', true );
            if ( ! is_array( $historial ) ) $historial = [];
            array_unshift( $historial, [
                'status'       => 'REPROGRAMADO',
                'date'         => date( 'Y-m-d' ),
                'time'         => date( 'H:i:s' ),
                'updated-name' => 'Sistema',
                'remarks'      => "Notificación enviada a {$cliente_nombre} ({$cliente_email})",
            ] );
            update_post_meta( $shipment_id, 'wpcargo_shipments_update', $historial );

            wp_send_json_success( [
                'message' => 'Notificación enviada correctamente',
                'cliente' => $cliente_nombre,
                'email'   => $cliente_email,
            ] );
        } else {
            wp_send_json_error( 'Error al enviar el correo electrónico' );
        }
    }

    /* ── AJAX: Reprogramar fecha de envío (cliente) ──────────────────────── */

    public function ajax_reprogramar_envio(): void {
        check_ajax_referer( 'merc_reprogramar', 'nonce' );

        $shipment_id = isset( $_POST['shipment_id'] ) ? intval( $_POST['shipment_id'] )                  : 0;
        $nueva_fecha = isset( $_POST['nueva_fecha'] ) ? sanitize_text_field( $_POST['nueva_fecha'] )     : '';

        if ( empty( $shipment_id ) || empty( $nueva_fecha ) ) {
            wp_send_json_error( 'Datos incompletos' );
        }

        $fecha_obj = DateTime::createFromFormat( 'd/m/Y', $nueva_fecha );
        if ( ! $fecha_obj ) {
            wp_send_json_error( 'Formato de fecha inválido. Use DD/MM/YYYY' );
        }

        $hoy = new DateTime();
        $hoy->setTime( 0, 0, 0 );
        $fecha_obj->setTime( 0, 0, 0 );
        if ( $fecha_obj <= $hoy ) {
            wp_send_json_error( 'La fecha debe ser posterior a hoy' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debe iniciar sesión' );
        }

        $estado_actual = get_post_meta( $shipment_id, 'wpcargo_status', true );
        if ( ! current_user_can( 'manage_options' ) ) {
            if ( stripos( $estado_actual, 'REPROGRAMADO' ) === false && stripos( $estado_actual, 'RESCHEDULE' ) === false ) {
                wp_send_json_error( 'Solo puede reprogramar envíos marcados como REPROGRAMADO' );
            }
        }

        $fecha_anterior = get_post_meta( $shipment_id, 'wpcargo_pickup_date_picker', true );
        update_post_meta( $shipment_id, 'wpcargo_pickup_date_picker', $nueva_fecha );

        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            call_user_func( [ 'LiteSpeed_Cache_API', 'purge_all' ], 'shipment date updated' );
        }

        $tipo_envio = get_post_meta( $shipment_id, 'wpcargo_type_of_shipment', true )
                   ?: get_post_meta( $shipment_id, 'tipo_envio', true );

        if ( stripos( $tipo_envio, 'AGENCIA' ) !== false || strtolower( $tipo_envio ) === 'express' ) {
            $nuevo_estado = 'RECEPCIONADO';
        } elseif ( stripos( $tipo_envio, 'EMPRENDEDOR' ) !== false || strtolower( $tipo_envio ) === 'normal' ) {
            $nuevo_estado = 'EN BASE MERCOURIER';
        } else {
            $nuevo_estado = 'EN BASE MERCOURIER';
        }

        update_post_meta( $shipment_id, 'wpcargo_status', $nuevo_estado );

        $historial = get_post_meta( $shipment_id, 'wpcargo_shipments_update', true );
        if ( ! is_array( $historial ) ) $historial = [];
        $usuario = wp_get_current_user();
        array_unshift( $historial, [
            'status'       => $nuevo_estado,
            'date'         => date( 'd/m/Y' ),
            'time'         => date( 'H:i:s' ),
            'updated-name' => $usuario->display_name . ' (Cliente)',
            'remarks'      => "Envío reprogramado. Fecha anterior: {$fecha_anterior} → Nueva fecha: {$nueva_fecha}. Estado: {$nuevo_estado}.",
        ] );
        update_post_meta( $shipment_id, 'wpcargo_shipments_update', $historial );

        $admin_email     = get_option( 'admin_email' );
        $shipment_number = get_post_meta( $shipment_id, 'wpcargo_shipment_number', true );
        $asunto          = 'Cliente Reprogramó Envío - ' . $shipment_number;
        $mensaje = "
        <html><head><style>
            body{font-family:Arial,sans-serif;line-height:1.6}
            .container{max-width:600px;margin:0 auto;padding:20px;background:#f9f9f9}
            .header{background:#2196F3;color:white;padding:20px;text-align:center}
            .content{background:white;padding:30px}
            .info{background:#e3f2fd;padding:15px;margin:15px 0;border-left:4px solid #2196F3}
        </style></head>
        <body><div class='container'>
            <div class='header'><h2>📅 Envío Reprogramado por Cliente</h2></div>
            <div class='content'>
                <p>El cliente <strong>{$usuario->display_name}</strong> ha reprogramado un envío.</p>
                <div class='info'>
                    <strong>📦 Número de envío:</strong> {$shipment_number}<br>
                    <strong>👤 Cliente:</strong> {$usuario->display_name} ({$usuario->user_email})<br>
                    <strong>📆 Fecha anterior:</strong> {$fecha_anterior}<br>
                    <strong>📅 Nueva fecha:</strong> <span style='color:#2196F3;font-weight:bold'>{$nueva_fecha}</span><br>
                    <strong>🕐 Fecha de cambio:</strong> " . date( 'd/m/Y H:i' ) . "
                </div>
                <p>Estado cambiado a <strong>{$nuevo_estado}</strong>.</p>
            </div>
        </div></body></html>";
        wp_mail( $admin_email, $asunto, $mensaje, [ 'Content-Type: text/html; charset=UTF-8' ] );

        wp_send_json_success( [
            'message'        => 'Fecha reprogramada exitosamente',
            'nueva_fecha'    => $nueva_fecha,
            'fecha_anterior' => $fecha_anterior,
        ] );
    }

    /* ── AJAX: Anular envío (cliente) ────────────────────────────────────── */

    public function ajax_anular_envio(): void {
        check_ajax_referer( 'merc_anular_envio', 'nonce' );

        $shipment_id    = isset( $_POST['shipment_id'] ) ? intval( $_POST['shipment_id'] )                       : 0;
        $motivo         = isset( $_POST['motivo'] )      ? sanitize_textarea_field( $_POST['motivo'] )           : 'Cliente solicitó anulación';

        if ( empty( $shipment_id ) ) {
            wp_send_json_error( 'ID de envío no proporcionado' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debe iniciar sesión' );
        }

        $current_user_id = get_current_user_id();
        $current_user    = get_user_by( 'id', $current_user_id );
        $estado_actual   = get_post_meta( $shipment_id, 'wpcargo_status', true );

        if ( ! current_user_can( 'manage_options' ) ) {
            if ( stripos( $estado_actual, 'REPROGRAMADO' ) === false && stripos( $estado_actual, 'RESCHEDULE' ) === false ) {
                wp_send_json_error( 'Solo puede anular envíos marcados como REPROGRAMADO' );
            }
            $shipper_id = get_post_meta( $shipment_id, 'registered_shipper', true );
            if ( $shipper_id != $current_user_id ) {
                wp_send_json_error( 'No tiene permisos para anular este envío' );
            }
        }

        update_post_meta( $shipment_id, 'wpcargo_status', 'ANULADO' );

        $historial = get_post_meta( $shipment_id, 'wpcargo_shipments_update', true );
        if ( ! is_array( $historial ) ) $historial = [];
        array_unshift( $historial, [
            'status'       => 'ANULADO',
            'date'         => date( 'd/m/Y' ),
            'time'         => date( 'H:i:s' ),
            'updated-name' => $current_user->display_name . ' (Cliente)',
            'remarks'      => "Envío anulado por el cliente. Estado anterior: {$estado_actual}. Motivo: {$motivo}",
        ] );
        update_post_meta( $shipment_id, 'wpcargo_shipments_update', $historial );

        $admin_email     = get_option( 'admin_email' );
        $shipment_number = get_post_meta( $shipment_id, 'wpcargo_shipment_number', true );
        $asunto          = 'Cliente Anuló Envío - ' . $shipment_number;
        $mensaje = "
        <html><head><style>
            body{font-family:Arial,sans-serif;line-height:1.6}
            .container{max-width:600px;margin:0 auto;padding:20px;background:#f9f9f9}
            .header{background:#f44336;color:white;padding:20px;text-align:center}
            .content{background:white;padding:30px}
            .info{background:#ffebee;padding:15px;margin:15px 0;border-left:4px solid #f44336}
        </style></head>
        <body><div class='container'>
            <div class='header'><h2>❌ Envío Anulado por Cliente</h2></div>
            <div class='content'>
                <div class='info'>
                    <strong>📦 Número de envío:</strong> {$shipment_number}<br>
                    <strong>👤 Cliente:</strong> {$current_user->display_name} ({$current_user->user_email})<br>
                    <strong>📝 Motivo:</strong> {$motivo}<br>
                    <strong>🕐 Fecha de anulación:</strong> " . date( 'd/m/Y H:i' ) . "
                </div>
                <p>Estado cambiado a <strong>ANULADO</strong>.</p>
            </div>
        </div></body></html>";
        wp_mail( $admin_email, $asunto, $mensaje, [ 'Content-Type: text/html; charset=UTF-8' ] );

        wp_send_json_success( [
            'message'     => 'Envío anulado exitosamente',
            'shipment_id' => $shipment_id,
        ] );
    }

    /* ── AJAX: Borrar/Eliminar envío (admin función) ────────────────────── */

    public function ajax_delete_shipment(): void {
        check_ajax_referer( 'merc_delete_shipment', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debe iniciar sesión para esta acción' );
        }

        $shipment_id = isset( $_POST['shipment_id'] ) ? intval( $_POST['shipment_id'] ) : 0;
        if ( empty( $shipment_id ) ) {
            wp_send_json_error( 'ID de envío no especificado' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        // Verificar permisos: solo administradores o propietario del envío
        if ( ! current_user_can( 'manage_options' ) ) {
            $shipper_id = get_post_meta( $shipment_id, 'registered_shipper', true );
            if ( intval( $shipper_id ) !== get_current_user_id() ) {
                wp_send_json_error( 'No tiene permisos para eliminar este envío' );
            }
        }

        $shipment_number = get_post_meta( $shipment_id, 'wpcargo_shipment_number', true );
        $estado_anterior = get_post_meta( $shipment_id, 'wpcargo_status', true );
        $usuario_actual = wp_get_current_user();

        // Agregar al historial antes de eliminar
        $historial = get_post_meta( $shipment_id, 'wpcargo_shipments_update', true );
        if ( ! is_array( $historial ) ) $historial = [];
        array_unshift( $historial, [
            'status'       => 'ELIMINADO',
            'date'         => date( 'd/m/Y' ),
            'time'         => date( 'H:i:s' ),
            'updated-name' => $usuario_actual->display_name . ' (Admin)',
            'remarks'      => "Envío eliminado. Estado anterior: {$estado_anterior}. Eliminado por: {$usuario_actual->display_name}",
        ] );
        update_post_meta( $shipment_id, 'wpcargo_shipments_update', $historial );

        // Cambio de estado a ELIMINADO antes de trash (por si hay restricciones)
        update_post_meta( $shipment_id, 'wpcargo_status', 'ELIMINADO' );

        // Enviar a papelera en lugar de eliminar permanentemente (más seguro)
        $resultado = wp_trash_post( $shipment_id );

        if ( $resultado === false ) {
            wp_send_json_error( 'No se pudo eliminar el envío' );
        }

        // Notificar al propietario del envío (si no es admin quién lo elimina)
        if ( ! current_user_can( 'manage_options' ) ) {
            $admin_email = get_option( 'admin_email' );
            $asunto = 'Importante: Su envío ha sido eliminado - ' . $shipment_number;
            $mensaje = "
            <html><head><style>
                body{font-family:Arial,sans-serif;line-height:1.6}
                .container{max-width:600px;margin:0 auto;padding:20px;background:#f9f9f9}
                .header{background:#f44336;color:white;padding:20px;text-align:center}
                .content{background:white;padding:30px}
                .info{background:#ffebee;padding:15px;margin:15px 0;border-left:4px solid #f44336}
            </style></head>
            <body><div class='container'>
                <div class='header'><h2>🗑️ Envío Eliminado</h2></div>
                <div class='content'>
                    <div class='info'>
                        <strong>📦 Número de envío:</strong> {$shipment_number}<br>
                        <strong>🕐 Fecha:</strong> " . date( 'd/m/Y H:i' ) . "<br>
                        <strong>⚠️ Estado anterior:</strong> {$estado_anterior}
                    </div>
                    <p>El envío ha sido eliminado del sistema. Si esto fue un error, contáctenos.</p>
                </div>
            </div></body></html>";
            wp_mail( $admin_email, $asunto, $mensaje, [ 'Content-Type: text/html; charset=UTF-8' ] );
        }

        // Limpiar caché si existe
        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            call_user_func( [ 'LiteSpeed_Cache_API', 'purge_all' ], 'shipment trashed' );
        }

        wp_send_json_success( [
            'message'     => 'Envío eliminado correctamente',
            'shipment_id' => $shipment_id,
        ] );
    }
}

} // End if ( ! class_exists( 'MERC_Table_Ajax' ) )

if ( class_exists( 'MERC_Table_Ajax' ) ) {
    new MERC_Table_Ajax();
}
