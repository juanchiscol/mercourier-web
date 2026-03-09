<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helpers globales del plugin merc-table-customizer.
 */

/**
 * Carga un template del plugin.
 *
 * @param string $file  Ruta relativa desde admin/templates/  (ej. 'frontend/table-row.tpl.php')
 * @param array  $data  Variables a inyectar en el template.
 */
function mtc_include_template( string $file, array $data = [] ): void {
	$path = MERC_TABLE_PATH . "admin/templates/{$file}";
	if ( ! file_exists( $path ) ) {
		wp_die( "Plantilla no encontrada: {$file}" );
	}
	if ( $data ) {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	}
	include $path;
}