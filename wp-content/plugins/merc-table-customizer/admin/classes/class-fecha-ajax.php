<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MERC_Fecha_Ajax
 *
 * Gestiona la corrección de fechas de envío en la tabla del dashboard:
 *   - Encola el script JS que corrige las fechas visualmente en la tabla.
 *   - Provee el endpoint AJAX wp_ajax_merc_obtener_fecha_envio.
 *
 * Movido desde blocksy-child/functions.php para evitar el BOM del tema
 * que corrompía las respuestas AJAX con un carácter ﻿ antes del JSON.
 */
if ( ! class_exists( 'MERC_Fecha_Ajax' ) ) {

class MERC_Fecha_Ajax {

    public function __construct() {
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_merc_obtener_fecha_envio', [ $this, 'ajax_obtener_fecha' ] );
    }

    /* ── Encolar JS con los datos necesarios para la llamada AJAX ─────── */

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            'merc-fecha-ajax',
            MERC_TABLE_URL . 'admin/js/merc-fecha-ajax.js',
            [ 'jquery' ],
            MERC_TABLE_VERSION,
            true   // cargar en footer
        );

        wp_localize_script( 'merc-fecha-ajax', 'mercFechaAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'merc_obtener_fecha' ),
        ] );
    }

    /* ── Endpoint AJAX: devuelve la fecha correcta de un envío ────────── */

    public function ajax_obtener_fecha(): void {
        check_ajax_referer( 'merc_obtener_fecha', 'nonce' );

        $shipment_id = isset( $_POST['shipment_id'] ) ? intval( $_POST['shipment_id'] ) : 0;

        if ( empty( $shipment_id ) ) {
            wp_send_json_error( 'ID de envío no proporcionado' );
        }

        $shipment = get_post( $shipment_id );
        if ( ! $shipment || $shipment->post_type !== 'wpcargo_shipment' ) {
            wp_send_json_error( 'Envío no encontrado' );
        }

        // Prioridad: wpcargo_pickup_date_picker → post_date
        $fecha_meta = get_post_meta( $shipment_id, 'wpcargo_pickup_date_picker', true );

        if ( ! empty( $fecha_meta ) ) {
            $fecha_obj = DateTime::createFromFormat( 'Y-m-d', $fecha_meta );
            $fecha_formateada = $fecha_obj
                ? $fecha_obj->format( 'd/m/Y' )
                : $fecha_meta;
        } else {
            $fecha_obj        = new DateTime( $shipment->post_date );
            $fecha_formateada = $fecha_obj->format( 'd/m/Y' );
        }

        $post_date_obj       = new DateTime( $shipment->post_date );
        $post_date_formatted = $post_date_obj->format( 'd/m/Y' );

        $fue_reprogramada = ! empty( $fecha_meta ) && $fecha_formateada !== $post_date_formatted;

        wp_send_json_success( [
            'fecha_formateada'  => $fecha_formateada,
            'fecha_meta'        => $fecha_meta,
            'post_date'         => $shipment->post_date,
            'post_date_formatted' => $post_date_formatted,
            'fue_reprogramada'  => $fue_reprogramada,
        ] );
    }
}

} // End if ( ! class_exists( 'MERC_Fecha_Ajax' ) )

if ( class_exists( 'MERC_Fecha_Ajax' ) ) {
    new MERC_Fecha_Ajax();
}
