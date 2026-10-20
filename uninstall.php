<?php
/**
 * Desinstalación: borra los datos locales del plugin.
 *
 * Incluye los nombres antiguos (prefijo pcm_) por si la migración no llegó a
 * ejecutarse.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$pandcm_tables = array( $wpdb->prefix . 'pandcm_history', $wpdb->prefix . 'pcm_history' );
foreach ( $pandcm_tables as $pandcm_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Nombres de tabla propios (constantes).
	$wpdb->query( "DROP TABLE IF EXISTS `{$pandcm_table}`" );
}

$pandcm_options = array(
	'pandcm_settings',
	'pandcm_queue',
	'pandcm_plugin_events',
	'pandcm_db_version',
	'pandcm_legacy_migrated',
	'pcm_settings',
	'pcm_queue',
	'pcm_plugin_events',
	'pcm_db_version',
);
foreach ( $pandcm_options as $pandcm_option ) {
	delete_option( $pandcm_option );
}

wp_clear_scheduled_hook( 'pandcm_cron_push' );
wp_clear_scheduled_hook( 'pcm_cron_push' );
