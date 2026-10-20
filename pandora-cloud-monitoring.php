<?php
/**
 * Plugin Name:       Pandora Cloud Monitoring
 * Plugin URI:        https://github.com/slerena/pandorafms-WP
 * Description:       Monitoriza tu WordPress con Pandora Cloud: versiones, actualizaciones, rendimiento, contenido y seguridad. Envía las métricas por API, guarda un histórico local con gráficas y te deja elegir qué vigilar.
 * Version:           1.0.12
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pandora FMS (info@pandorafms.com)
 * Author URI:        https://pandorafms.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pandora-cloud-monitoring
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PANDCM_VERSION', '1.0.12' );
define( 'PANDCM_FILE', __FILE__ );
define( 'PANDCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PANDCM_URL', plugin_dir_url( __FILE__ ) );

// Cloud Master: con email+contraseña localiza el nodo del tenant (no se pide URL).
if ( ! defined( 'PANDCM_MASTER_URL' ) ) {
	define( 'PANDCM_MASTER_URL', 'https://cloudmaster.pandorafms.com' );
}

require_once PANDCM_DIR . 'includes/class-pandcm-metrics.php';
require_once PANDCM_DIR . 'includes/class-pandcm-store.php';
require_once PANDCM_DIR . 'includes/class-pandcm-client.php';
require_once PANDCM_DIR . 'includes/class-pandcm-collector.php';
require_once PANDCM_DIR . 'includes/class-pandcm-admin.php';

// Intervalo propio: cada 5 minutos.
add_filter(
	// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- La monitorización exige recolección cada 5 minutos.
	'cron_schedules',
	function ( $schedules ) {
		$schedules['pandcm_every_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Cada 5 minutos (Pandora Cloud)', 'pandora-cloud-monitoring' ),
		);
		return $schedules;
	}
);

// Recolección + envío.
add_action( 'pandcm_cron_push', array( 'PANDCM_Collector', 'run_cron' ) );

// Rastreo de cambios de plugins (quién y cuándo) para la métrica `plugins_recent`.
add_action( 'activated_plugin', array( 'PANDCM_Store', 'on_plugin_event' ) );
add_action( 'deactivated_plugin', array( 'PANDCM_Store', 'on_plugin_event' ) );
add_action( 'deleted_plugin', array( 'PANDCM_Store', 'on_plugin_event' ) );
add_action( 'upgrader_process_complete', array( 'PANDCM_Store', 'on_upgrader' ), 10, 2 );

// Administración.
add_action( 'admin_menu', array( 'PANDCM_Admin', 'menu' ) );
add_action( 'admin_enqueue_scripts', array( 'PANDCM_Admin', 'assets' ) );
add_action( 'admin_post_pandcm_save', array( 'PANDCM_Admin', 'handle_save' ) );
add_action( 'admin_post_pandcm_connect', array( 'PANDCM_Admin', 'handle_connect' ) );
add_action( 'admin_post_pandcm_alias', array( 'PANDCM_Admin', 'handle_alias' ) );
add_action( 'admin_post_pandcm_disconnect', array( 'PANDCM_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_pandcm_run', array( 'PANDCM_Admin', 'handle_run' ) );

register_activation_hook( __FILE__, 'pandcm_activate' );
register_deactivation_hook( __FILE__, 'pandcm_deactivate' );

/**
 * Al activar: crea la tabla local, valores por defecto y programa el cron.
 */
function pandcm_activate() {
	PANDCM_Store::install();
	PANDCM_Store::defaults();
	update_option( 'pandcm_db_version', PANDCM_VERSION );
	if ( ! wp_next_scheduled( 'pandcm_cron_push' ) ) {
		wp_schedule_event( time() + 60, 'pandcm_every_5min', 'pandcm_cron_push' );
	}
}

/**
 * Al desactivar: quita el cron (los datos locales se conservan).
 */
function pandcm_deactivate() {
	wp_clear_scheduled_hook( 'pandcm_cron_push' );
}

/**
 * Migración de instalaciones anteriores (prefijo pcm_ → pandcm_).
 *
 * Renombra opciones, tabla de histórico y evento de cron. Idempotente: se
 * ejecuta una sola vez (marca `pandcm_legacy_migrated`).
 */
function pandcm_migrate_legacy() {
	if ( get_option( 'pandcm_legacy_migrated' ) ) {
		return;
	}
	global $wpdb;

	// Opciones. Los nombres antiguos van literales: son legacy.
	$map = array(
		'pcm_settings'      => 'pandcm_settings',
		'pcm_queue'         => 'pandcm_queue',
		'pcm_plugin_events' => 'pandcm_plugin_events',
	);
	foreach ( $map as $old => $new ) {
		$value = get_option( $old, null );
		if ( null !== $value && false === get_option( $new, false ) ) {
			update_option( $new, $value );
		}
		delete_option( $old );
	}

	// Versión de esquema.
	$prev = get_option( 'pcm_db_version', '' );
	if ( '' !== $prev && ! get_option( 'pandcm_db_version' ) ) {
		update_option( 'pandcm_db_version', $prev );
	}
	delete_option( 'pcm_db_version' );

	// Tabla de histórico: crear la nueva y copiar (CREATE/INSERT/DROP funcionan
	// igual en MySQL/MariaDB y en el integrador de SQLite).
	$old_table = $wpdb->prefix . 'pcm_history';
	$new_table = $wpdb->prefix . 'pandcm_history';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migración puntual de una tabla propia (nombre constante).
	$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migración puntual de una tabla propia (nombre constante).
	$has_old  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
	$table_ok = true;
	if ( $has_old ) {
		if ( ! $has_new ) {
			PANDCM_Store::install();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migración puntual de una tabla propia (%i).
		$n_old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migración puntual de una tabla propia (%i).
		$n_new = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new_table ) );
		if ( $n_old > 0 && 0 === $n_new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migración puntual de tablas propias (%i).
			$copied = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ts,name,status,value,value_str) SELECT ts,name,status,value,value_str FROM %i', $new_table, $old_table ) );
			if ( false === $copied ) {
				$table_ok = false;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migración puntual de una tabla propia (%i).
				$n_new = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new_table ) );
			}
		}
		if ( $table_ok && $n_new >= $n_old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migración puntual de una tabla propia (%i).
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) );
		}
	}

	// Cron.
	wp_clear_scheduled_hook( 'pcm_cron_push' );
	if ( ! wp_next_scheduled( 'pandcm_cron_push' ) ) {
		wp_schedule_event( time() + 60, 'pandcm_every_5min', 'pandcm_cron_push' );
	}

	// Si el renombrado falló, no marcamos: se reintenta en la siguiente carga.
	if ( $table_ok ) {
		update_option( 'pandcm_legacy_migrated', 1 );
	}
}
add_action( 'plugins_loaded', 'pandcm_migrate_legacy' );

/**
 * Migración de la tabla local al actualizar el plugin (añade columnas nuevas).
 */
function pandcm_maybe_upgrade() {
	$prev = (string) get_option( 'pandcm_db_version', '0' );
	if ( PANDCM_VERSION === $prev ) {
		return;
	}
	PANDCM_Store::install();
	// Activa las métricas añadidas en esta versión (las desactivadas se respetan).
	PANDCM_Store::enable_metrics_since( $prev );
	update_option( 'pandcm_db_version', PANDCM_VERSION );
}
add_action( 'admin_init', 'pandcm_maybe_upgrade' );
