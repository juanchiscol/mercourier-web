<?php
/**
 * Plugin Name: DHV Courier – Table Customizer
 * Plugin URI:  https://dhvcourier.com
 * Description: Reorganiza y personaliza las columnas de la tabla de shipments del frontend de WPCargo.
 * Version:     1.2.5
 * Author:      DHV Courier
 * Text Domain: merc-table-customizer
 * Requires PHP: 8.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MERC_TABLE_VERSION', '1.2.5' );
define( 'MERC_TABLE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MERC_TABLE_URL',     plugin_dir_url( __FILE__ ) );

require_once MERC_TABLE_PATH . 'includes/functions.php';
require_once MERC_TABLE_PATH . 'admin/classes/class-shipment-table.php';
require_once MERC_TABLE_PATH . 'admin/classes/class-shipment-filters.php';
require_once MERC_TABLE_PATH . 'admin/classes/class-fecha-ajax.php';
require_once MERC_TABLE_PATH . 'admin/classes/class-table-ajax.php';
require_once MERC_TABLE_PATH . 'admin/classes/class-table-ui.php';