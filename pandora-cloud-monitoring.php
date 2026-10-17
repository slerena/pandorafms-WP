<?php
/**
 * Plugin Name:       Pandora Cloud Monitoring
 * Plugin URI:        https://pandorafms.com/
 * Description:       Monitoriza tu WordPress con Pandora Cloud: versiones, actualizaciones, rendimiento, contenido y seguridad. Envía las métricas por API, guarda un histórico local con gráficas y te deja elegir qué vigilar.
 * Version:           1.0.7
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Pandora FMS (info@pandorafms.com)
 * Author URI:        mailto:info@pandorafms.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pandora-cloud-monitoring
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCM_VERSION', '1.0.7' );
define( 'PCM_FILE', __FILE__ );
define( 'PCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCM_URL', plugin_dir_url( __FILE__ ) );

// Cloud Master: con email+contraseña localiza el nodo del tenant (no se pide URL).
if ( ! defined( 'PCM_MASTER_URL' ) ) {
	define( 'PCM_MASTER_URL', 'https://cloudmaster.pandorafms.com' );
}

require_once PCM_DIR . 'includes/class-pcm-metrics.php';
require_once PCM_DIR . 'includes/class-pcm-store.php';
require_once PCM_DIR . 'includes/class-pcm-client.php';
require_once PCM_DIR . 'includes/class-pcm-collector.php';
require_once PCM_DIR . 'includes/class-pcm-admin.php';

// Intervalo propio: cada 5 minutos.
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['pcm_every_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Cada 5 minutos (Pandora Cloud)', 'pandora-cloud-monitoring' ),
		);
		return $schedules;
	}
);

// Recolección + envío.
add_action( 'pcm_cron_push', array( 'PCM_Collector', 'run_cron' ) );

// Rastreo de cambios de plugins (quién y cuándo) para la métrica `plugins_recent`.
add_action( 'activated_plugin', array( 'PCM_Store', 'on_plugin_event' ), 10, 2 );
add_action( 'deactivated_plugin', array( 'PCM_Store', 'on_plugin_event' ), 10, 2 );
add_action( 'deleted_plugin', array( 'PCM_Store', 'on_plugin_event' ), 10, 2 );
add_action( 'upgrader_process_complete', array( 'PCM_Store', 'on_upgrader' ), 10, 2 );

// Administración.
add_action( 'admin_menu', array( 'PCM_Admin', 'menu' ) );
add_action( 'admin_enqueue_scripts', array( 'PCM_Admin', 'assets' ) );
add_action( 'admin_post_pcm_save', array( 'PCM_Admin', 'handle_save' ) );
add_action( 'admin_post_pcm_connect', array( 'PCM_Admin', 'handle_connect' ) );
add_action( 'admin_post_pcm_alias', array( 'PCM_Admin', 'handle_alias' ) );
add_action( 'admin_post_pcm_disconnect', array( 'PCM_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_pcm_run', array( 'PCM_Admin', 'handle_run' ) );

register_activation_hook( __FILE__, 'pcm_activate' );
register_deactivation_hook( __FILE__, 'pcm_deactivate' );

/**
 * Al activar: crea la tabla local, valores por defecto y programa el cron.
 */
function pcm_activate() {
	PCM_Store::install();
	PCM_Store::defaults();
	if ( ! wp_next_scheduled( 'pcm_cron_push' ) ) {
		wp_schedule_event( time() + 60, 'pcm_every_5min', 'pcm_cron_push' );
	}
}

/**
 * Al desactivar: quita el cron (los datos locales se conservan).
 */
function pcm_deactivate() {
	wp_clear_scheduled_hook( 'pcm_cron_push' );
}

/**
 * Migración de la tabla local al actualizar el plugin (añade columnas nuevas).
 */
function pcm_maybe_upgrade() {
	if ( get_option( 'pcm_db_version' ) !== PCM_VERSION ) {
		PCM_Store::install();
		update_option( 'pcm_db_version', PCM_VERSION );
	}
}
add_action( 'admin_init', 'pcm_maybe_upgrade' );
