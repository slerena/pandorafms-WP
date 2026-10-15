<?php
/**
 * Desinstalación: borra los datos locales del plugin.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pcm_history" ); // phpcs:ignore WordPress.DB
delete_option( 'pcm_settings' );
delete_option( 'pcm_queue' );
delete_option( 'pcm_plugin_events' );

wp_clear_scheduled_hook( 'pcm_cron_push' );
