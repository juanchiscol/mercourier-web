<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MERC_Shipment_Filters
 *
 * Gestiona todos los filtros del historial de envíos del frontend WPCargo:
 *   - Barra de filtros: Fecha, Marca, Celular destinatario, Motorizado recojo/entrega.
 *   - Query filters: todas las condiciones como EXISTS subqueries en posts_where (sin JOINs extra).
 *   - Rename "Shipments" → "Historial de Envíos".
 */
if ( ! class_exists( 'MERC_Shipment_Filters' ) ) {

class MERC_Shipment_Filters {

    /** Condiciones WHERE acumuladas para inyectar via posts_where. */
    private array $custom_where_conds = [];

    public function __construct() {
        // Quitar el filtro de fecha nativo de WPCargo antes de añadir los propios
        add_action( 'plugins_loaded', [ $this, 'remove_native_filters' ], 20 );

        // ── UI: Ocultar controles AJAX nativos rotos de WPCargo ──────────
        add_action( 'wp_head', [ $this, 'suppress_native_ajax_filters_css' ] );

        // ── UI: Barra de filtros ──────────────────────────────────────────
        add_action( 'wpcfe_after_shipment_filters', [ $this, 'render_date_filter' ],    100 );
        add_action( 'wpcfe_after_shipment_filters', [ $this, 'render_marca_filter' ],   101 );
        add_action( 'wpcfe_after_shipment_filters', [ $this, 'render_celular_filter' ], 102 );
        add_action( 'wpcfe_after_shipment_filters', [ $this, 'render_driver_filters' ], 103 );

        // ── Query: todos los filtros via EXISTS (sin JOINs en WP_Query) ───
        // Preparamos las condiciones en wpcfe_dashboard_arguments y las
        // inyectamos en posts_where para evitar JOINs lentos en meta_query.
        add_filter( 'wpcfe_dashboard_arguments', [ $this, 'prepare_custom_filter_clauses' ], 20 );

        // ── Internacionalización ──────────────────────────────────────────
        add_filter( 'gettext', [ $this, 'rename_shipments_text' ], 20, 3 );

        // ── Fallback para wpcfe_table_header cuando wpccf no está activo ─
        add_filter( 'wpcfe_table_header', [ $this, 'fix_table_header_fallback' ], 5, 2 );
    }

    /* ── Eliminar filtros nativos de WPCargo ────────────────────────────── */

    public function remove_native_filters(): void {
        remove_action( 'wpcfe_after_shipment_filters', 'wpcfe_shipment_created_date_filter_callback', 100 );
        // Ambas variantes del nombre (WPCargo tiene un typo en versiones distintas)
        remove_filter( 'wpcfe_dashboard_arguments', 'wpcfe_shipment_created_date_query_args_callback' );
        remove_filter( 'wpcfe_dashboard_arguments', 'wpcfe_shipment_created_date_quuery_args_callback' );
    }

    /* ── Ocultar controles AJAX nativos rotos (shipper/receiver Select2) ── */

    public function suppress_native_ajax_filters_css(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        ?>
        <style id="merc-hide-native-ajax-filters">
            /* Oculta los selectores AJAX nativos de WPCargo (shipper/receiver)
               que fallan por la dependencia de wpccf_get_field_by_metakey.
               Los reemplazos (Marca y Celular) los provee merc-table-customizer. */
            #wpcfe-filters .shipper-filter,
            #wpcfe-filters .receiver-filter {
                display: none !important;
            }
        </style>
        <?php
    }

    /* ── UI: Filtro por Marca (Nombre de Tienda) ────────────────────────── */

    public function render_marca_filter(): void {
        $marcas       = $this->get_marcas();
        $marca_actual = isset( $_GET['wpcargo_tiendaname'] )
            ? sanitize_text_field( $_GET['wpcargo_tiendaname'] )
            : '';
        ?>
        <div class="form-group wpcfe-filter p-0 mx-1">
            <div class="md-form form-group" style="margin:0;">
                <select name="wpcargo_tiendaname" class="form-control form-control-sm wpcfe-select">
                    <option value="">Todas las marcas</option>
                    <?php foreach ( $marcas as $marca ) : ?>
                        <option value="<?php echo esc_attr( $marca ); ?>" <?php selected( $marca_actual, $marca ); ?>>
                            <?php echo esc_html( $marca ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    /* ── UI: Filtro por Celular del Destinatario ────────────────────────── */

    public function render_celular_filter(): void {
        $celulares      = $this->get_celulares();
        $celular_actual = isset( $_GET['celular_destinatario'] )
            ? sanitize_text_field( $_GET['celular_destinatario'] )
            : '';
        ?>
        <div class="form-group wpcfe-filter p-0 mx-1">
            <div class="md-form form-group" style="margin:0;">
                <select name="celular_destinatario" class="form-control form-control-sm wpcfe-select">
                    <option value="">Todo Celular</option>
                    <?php foreach ( $celulares as $celular ) : ?>
                        <option value="<?php echo esc_attr( $celular ); ?>" <?php selected( $celular_actual, $celular ); ?>>
                            <?php echo esc_html( $celular ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    /* ── UI: Filtro de Fecha de Envío ───────────────────────────────────── */

    public function render_date_filter(): void {
        $today = current_time( 'Y-m-d' );
        $start = isset( $_GET['shipping_date_start'] )
            ? sanitize_text_field( $_GET['shipping_date_start'] )
            : $today;
        $end   = isset( $_GET['shipping_date_end'] )
            ? sanitize_text_field( $_GET['shipping_date_end'] )
            : $today;
        ?>
        <div id="wpcfe-custom-shipping-date" class="form-group wpcfe-filter p-0 mx-1">
            <div class="md-form form-group">
                <strong>Fecha de Envío</strong>
                <input id="shipping_date_start"
                       name="shipping_date_start"
                       type="text"
                       class="form-control daterange_picker start_date px-2 py-1 mx-2"
                       style="width:110px;font-weight:500;"
                       autocomplete="off"
                       value="<?php echo esc_attr( $start ); ?>"
                       placeholder="YYYY-MM-DD" />
                <input id="shipping_date_end"
                       name="shipping_date_end"
                       type="text"
                       class="form-control daterange_picker end_date px-2 py-1 mx-2"
                       style="width:110px;font-weight:500;"
                       autocomplete="off"
                       value="<?php echo esc_attr( $end ); ?>"
                       placeholder="YYYY-MM-DD" />
            </div>
        </div>
        <?php
    }

    /* ── UI: Filtros de Motorizado Recojo y Entrega ─────────────────────── */

    public function render_driver_filters(): void {
        $current_user = wp_get_current_user();
        if ( in_array( 'wpcargo_client', (array) $current_user->roles ) ) {
            return;
        }

        $value_recojo  = isset( $_GET['wpcargo_motorizo_recojo'] )
            ? esc_attr( $_GET['wpcargo_motorizo_recojo'] )
            : '';
        $value_entrega = isset( $_GET['wpcargo_motorizo_entrega'] )
            ? esc_attr( $_GET['wpcargo_motorizo_entrega'] )
            : '';

        $drivers = get_users( [
            'role'    => 'wpcargo_driver',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ] );
        ?>

        <!-- MOTORIZADO RECOJO -->
        <div class="form-group wpcfe-filter p-0 mx-1">
            <div class="md-form form-group" style="margin:0;">
                <select name="wpcargo_motorizo_recojo" class="form-control form-control-sm wpcfe-select">
                    <option value="">Motorizado Recojo...</option>
                    <?php foreach ( $drivers as $driver ) :
                        $nombre = trim(
                            get_user_meta( $driver->ID, 'first_name', true ) . ' ' .
                            get_user_meta( $driver->ID, 'last_name',  true )
                        );
                    ?>
                        <option value="<?php echo esc_attr( $driver->ID ); ?>" <?php selected( $value_recojo, $driver->ID ); ?>>
                            <?php echo esc_html( $nombre ?: $driver->display_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- MOTORIZADO ENTREGA -->
        <div class="form-group wpcfe-filter p-0 mx-1">
            <div class="md-form form-group" style="margin:0;">
                <select name="wpcargo_motorizo_entrega" class="form-control form-control-sm wpcfe-select">
                    <option value="">Motorizado Entrega...</option>
                    <?php foreach ( $drivers as $driver ) :
                        $nombre = trim(
                            get_user_meta( $driver->ID, 'first_name', true ) . ' ' .
                            get_user_meta( $driver->ID, 'last_name',  true )
                        );
                    ?>
                        <option value="<?php echo esc_attr( $driver->ID ); ?>" <?php selected( $value_entrega, $driver->ID ); ?>>
                            <?php echo esc_html( $nombre ?: $driver->display_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php
    }

    /* ── Query: construye EXISTS subqueries e inyecta en posts_where ─────── */
    /*                                                                         */
    /* Cada filtro genera un EXISTS (SELECT 1 FROM wp_postmeta WHERE ...)     */
    /* que MySQL resuelve con el índice meta_key sin JOINs en la query        */
    /* principal de WP_Query. Mucho más rápido que meta_query o post__in.    */

    public function prepare_custom_filter_clauses( array $args ): array {
        global $wpdb;

        $this->custom_where_conds = [];

        unset( $args['date_query'] ); // evitar conflicto con date_query nativo

        // ── Fecha ─────────────────────────────────────────────────────────
        $from = isset( $_GET['shipping_date_start'] )
            ? sanitize_text_field( $_GET['shipping_date_start'] ) : '';
        $to   = isset( $_GET['shipping_date_end'] )
            ? sanitize_text_field( $_GET['shipping_date_end'] )   : '';

        error_log( sprintf(
            '📅 CLASS_SHIPMENT_FILTERS - receive: from=%s, to=%s',
            $from ?: 'EMPTY',
            $to ?: 'EMPTY'
        ));

        if ( empty( $from ) && empty( $to ) ) {
            $from = current_time( 'Y-m-d' );
            $to   = current_time( 'Y-m-d' );
            error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - using today: %s', $from ) );
        }

        $has_from = $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from );
        $has_to   = $to   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to );

        if ( $has_from || $has_to ) {
            // Día único → comparación directa (usa índice en meta_value sin STR_TO_DATE)
            if ( $has_from && $has_to && $from === $to ) {
                $d        = \DateTime::createFromFormat( 'Y-m-d', $from );
                $date_dmy = $d ? $d->format( 'd/m/Y' ) : null;

                error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - SINGLE_DAY: from=%s (dmy=%s)', $from, $date_dmy ?: 'NULL' ) );

                if ( $date_dmy ) {
                    // Buscar AMBOS formatos: ISO (YYYY-MM-DD) o Legacy (DD/MM/YYYY)
                    $date_cond = $wpdb->prepare( 
                        'pm_dt.meta_value = %s OR pm_dt.meta_value = %s', 
                        $from,      // YYYY-MM-DD (nuevo formato)
                        $date_dmy   // DD/MM/YYYY (formato antiguo)
                    );
                } else {
                    $date_cond = $wpdb->prepare(
                        "STR_TO_DATE(pm_dt.meta_value, '%%d/%%m/%%Y') = STR_TO_DATE(%s, '%%Y-%%m-%%d') OR pm_dt.meta_value = %s",
                        $from,
                        $from
                    );
                }
                error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - SINGLE_DAY condition: %s', $date_cond ) );
            } else {
                // Rango de fechas
                error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - RANGE: from=%s, to=%s', $from ?: 'NULL', $to ?: 'NULL' ) );
                
                $parts = [];
                if ( $has_from ) {
                    // Buscar ISO format direct comparison O legacy with STR_TO_DATE
                    $from_dmy = \DateTime::createFromFormat( 'Y-m-d', $from )->format( 'd/m/Y' );
                    $from_cond = $wpdb->prepare(
                        "(pm_dt.meta_value >= %s OR STR_TO_DATE(pm_dt.meta_value, '%%d/%%m/%%Y') >= STR_TO_DATE(%s, '%%Y-%%m-%%d'))",
                        $from,      // YYYY-MM-DD format
                        $from       // para STR_TO_DATE
                    );
                    $parts[] = $from_cond;
                    error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - FROM condition added: %s', $from_cond ) );
                }
                if ( $has_to ) {
                    $to_dmy = \DateTime::createFromFormat( 'Y-m-d', $to )->format( 'd/m/Y' );
                    $to_cond = $wpdb->prepare(
                        "(pm_dt.meta_value <= %s OR STR_TO_DATE(pm_dt.meta_value, '%%d/%%m/%%Y') <= STR_TO_DATE(%s, '%%Y-%%m-%%d'))",
                        $to,        // YYYY-MM-DD format
                        $to         // para STR_TO_DATE
                    );
                    $parts[] = $to_cond;
                    error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - TO condition added: %s', $to_cond ) );
                }
                $date_cond = implode( ' AND ', $parts );
            }

            $keys_in = "'wpcargo_pickup_date_picker','wpcargo_pickup_date','wpcargo_calendarenvio','wpcargo_fecha_envio'";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $final_condition = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm_dt
                WHERE pm_dt.post_id = {$wpdb->posts}.ID
                  AND pm_dt.meta_key IN ({$keys_in})
                  AND ( {$date_cond} )
            )";
            
            error_log( sprintf( '📅 CLASS_SHIPMENT_FILTERS - FINAL_CONDITION: %s', $final_condition ) );
            $this->custom_where_conds[] = $final_condition;
        }

        // ── Marca ─────────────────────────────────────────────────────────
        if ( ! empty( $_GET['wpcargo_tiendaname'] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->custom_where_conds[] = $wpdb->prepare(
                "EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_mc
                    WHERE pm_mc.post_id = {$wpdb->posts}.ID
                      AND pm_mc.meta_key = 'wpcargo_tiendaname'
                      AND pm_mc.meta_value = %s
                )",
                sanitize_text_field( $_GET['wpcargo_tiendaname'] )
            );
        }

        // ── Celular ───────────────────────────────────────────────────────
        if ( ! empty( $_GET['celular_destinatario'] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->custom_where_conds[] = $wpdb->prepare(
                "EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_cl
                    WHERE pm_cl.post_id = {$wpdb->posts}.ID
                      AND pm_cl.meta_key = 'wpcargo_receiver_phone'
                      AND pm_cl.meta_value = %s
                )",
                sanitize_text_field( $_GET['celular_destinatario'] )
            );
        }

        // ── Motorizado Recojo ─────────────────────────────────────────────
        if ( ! empty( $_GET['wpcargo_motorizo_recojo'] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->custom_where_conds[] = $wpdb->prepare(
                "EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_mr
                    WHERE pm_mr.post_id = {$wpdb->posts}.ID
                      AND pm_mr.meta_key = 'wpcargo_motorizo_recojo'
                      AND pm_mr.meta_value = %s
                )",
                intval( $_GET['wpcargo_motorizo_recojo'] )
            );
        }

        // ── Motorizado Entrega ────────────────────────────────────────────
        if ( ! empty( $_GET['wpcargo_motorizo_entrega'] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->custom_where_conds[] = $wpdb->prepare(
                "EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_me
                    WHERE pm_me.post_id = {$wpdb->posts}.ID
                      AND pm_me.meta_key = 'wpcargo_motorizo_entrega'
                      AND pm_me.meta_value = %s
                )",
                intval( $_GET['wpcargo_motorizo_entrega'] )
            );
        }

        if ( ! empty( $this->custom_where_conds ) ) {
            error_log( sprintf(
                '📅 CLASS_SHIPMENT_FILTERS - Adding filter hook (conditions_count=%d): %s',
                count( $this->custom_where_conds ),
                json_encode( $this->custom_where_conds )
            ));
            add_filter( 'posts_where', [ $this, 'inject_custom_where' ], 99, 2 );
        } else {
            error_log( '📅 CLASS_SHIPMENT_FILTERS - NO CONDITIONS to add (empty custom_where_conds)' );
        }

        return $args;
    }

    /**
     * Inyecta las condiciones EXISTS en el WHERE de WP_Query.
     * Se auto-elimina tras la primera ejecución para no afectar otras queries.
     */
    public function inject_custom_where( string $where, \WP_Query $query ): string {
        if ( $query->get( 'post_type' ) !== 'wpcargo_shipment' ) {
            return $where;
        }
        remove_filter( 'posts_where', [ $this, 'inject_custom_where' ], 99 );

        $original_where = $where;
        
        foreach ( $this->custom_where_conds as $cond ) {
            $where .= " AND ({$cond})";
        }

        error_log( sprintf(
            '📅 CLASS_SHIPMENT_FILTERS::inject_custom_where - ORIGINAL_WHERE: %s',
            $original_where
        ));
        
        error_log( sprintf(
            '📅 CLASS_SHIPMENT_FILTERS::inject_custom_where - FINAL_WHERE: %s',
            $where
        ));

        return $where;
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    private function get_marcas(): array {
        $cached = get_transient( 'merc_marcas_list' );
        if ( $cached !== false ) {
            return $cached;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_col( "
            SELECT DISTINCT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key  = 'wpcargo_tiendaname'
              AND meta_value IS NOT NULL
              AND meta_value != ''
            ORDER BY meta_value ASC
        " );
        $marcas = array_unique( array_map( 'trim', $results ) );
        set_transient( 'merc_marcas_list', $marcas, 5 * MINUTE_IN_SECONDS );
        return $marcas;
    }

    private function get_celulares(): array {
        $cached = get_transient( 'merc_celulares_list' );
        if ( $cached !== false ) {
            return $cached;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_col( "
            SELECT DISTINCT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key  = 'wpcargo_receiver_phone'
              AND meta_value IS NOT NULL
              AND meta_value != ''
            ORDER BY meta_value ASC
        " );
        $celulares = array_unique( array_map( 'trim', $results ) );
        set_transient( 'merc_celulares_list', $celulares, 5 * MINUTE_IN_SECONDS );
        return $celulares;
    }

    /* ── Rename "Shipments" → "Historial de Envíos" ─────────────────────── */

    public function rename_shipments_text( string $translated_text, string $text, string $domain ): string {
        if ( $domain !== 'wpcargo-frontend-manager' ) {
            return $translated_text;
        }
        if ( $text === 'Shipments' ) {
            return 'Historial de Envíos';
        }
        if ( strpos( $text, 'Shipments <span' ) !== false ) {
            return str_replace( 'Shipments', 'Historial de Envíos', $text );
        }
        return $translated_text;
    }

    /* ── Fallback para wpcfe_table_header ───────────────────────────────── */
    /* Garantiza que field_key siempre exista aunque wpccf no esté activo.   */

    public function fix_table_header_fallback( array $header_data, string $section ): array {
        if ( ! empty( $header_data['field_key'] ) ) {
            return $header_data;
        }
        $defaults = [
            'shipper'  => [ 'label' => 'Nombre de la Marca', 'field_key' => 'wpcargo_tiendaname' ],
            'receiver' => [ 'label' => 'Celular',            'field_key' => 'wpcargo_receiver_phone' ],
        ];
        return $defaults[ $section ] ?? $header_data;
    }
}

} // End if ( ! class_exists( 'MERC_Shipment_Filters' ) )

if ( class_exists( 'MERC_Shipment_Filters' ) ) {
    new MERC_Shipment_Filters();
}
