<?php
/**
 * Almacén local del plugin: ajustes, histórico de métricas, cola de reintentos y
 * registro de cambios de plugins. TODO es local (no consulta la API para esto).
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Almacén local.
 */
class PCM_Store {

	const OPTION    = 'pcm_settings';
	const QUEUE     = 'pcm_queue';
	const EVENTS    = 'pcm_plugin_events';
	const MAX_EVENTS = 50;
	const RETENTION_DAYS = 30;

	/**
	 * Crea la tabla de histórico local.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'pcm_history';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			name VARCHAR(64) NOT NULL,
			status VARCHAR(12) NULL,
			value DOUBLE NULL,
			value_str VARCHAR(255) NULL,
			PRIMARY KEY (id),
			KEY name_ts (name, ts)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Valores por defecto.
	 */
	public static function defaults() {
		if ( ! get_option( self::OPTION ) ) {
			add_option(
				self::OPTION,
				array(
					'url'       => '',
					'token'     => '',
					'agent_id'  => 0,
					'agent'     => '',
					'email'     => '',
					'enabled'   => PCM_Metrics::names(),
					'last_push' => 0,
					'last_error'=> '',
				)
			);
		}
	}

	/**
	 * Ajustes actuales (con defaults).
	 *
	 * @return array
	 */
	public static function settings() {
		$def = array(
			'url'        => '',
			'token'      => '',
			'agent_id'   => 0,
			'agent'      => '',
			'email'      => '',
			'enabled'    => PCM_Metrics::names(),
			'last_push'  => 0,
			'last_error' => '',
		);
		$opt = get_option( self::OPTION, array() );
		return array_merge( $def, is_array( $opt ) ? $opt : array() );
	}

	/**
	 * Guarda ajustes (merge).
	 *
	 * @param array $patch Campos a cambiar.
	 */
	public static function patch( $patch ) {
		$s = self::settings();
		update_option( self::OPTION, array_merge( $s, $patch ) );
	}

	/**
	 * Métricas activas.
	 *
	 * @return string[]
	 */
	public static function enabled() {
		$s = self::settings();
		$enabled = is_array( $s['enabled'] ) ? $s['enabled'] : PCM_Metrics::names();
		return array_values( array_intersect( $enabled, PCM_Metrics::names() ) );
	}

	/**
	 * Marca de conexión activa.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$s = self::settings();
		return ! empty( $s['token'] ) && ! empty( $s['url'] );
	}

	/**
	 * Guarda muestras en el histórico local.
	 *
	 * @param array $samples Lista de [name, ts(epoch), value?, value_str?].
	 */
	public static function save_samples( $samples ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pcm_history';
		foreach ( $samples as $sm ) {
			$wpdb->insert(
				$table,
				array(
					'ts'        => gmdate( 'Y-m-d H:i:s', (int) $sm['ts'] ),
					'name'      => substr( $sm['name'], 0, 64 ),
					'status'    => isset( $sm['status'] ) ? substr( $sm['status'], 0, 12 ) : 'ok',
					'value'     => isset( $sm['value'] ) ? $sm['value'] : null,
					'value_str' => isset( $sm['value_str'] ) ? substr( $sm['value_str'], 0, 255 ) : null,
				),
				array( '%s', '%s', '%s', '%f', '%s' )
			);
		}
	}

	/**
	 * Poda el histórico antiguo.
	 */
	public static function prune() {
		global $wpdb;
		$table = $wpdb->prefix . 'pcm_history';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Serie de una métrica (ascendente) para las gráficas locales.
	 *
	 * @param string $name   Métrica.
	 * @param int    $limit  Máximo de puntos.
	 * @return array [ ['ts'=>..., 'value'=>...], ... ]
	 */
	public static function series( $name, $limit = 60 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pcm_history';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT ts, value FROM {$table} WHERE name=%s AND value IS NOT NULL ORDER BY id DESC LIMIT %d", $name, $limit ),
			ARRAY_A
		);
		return array_reverse( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Última muestra de una métrica.
	 *
	 * @param string $name Métrica.
	 * @return array|null
	 */
	public static function latest( $name ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pcm_history';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT ts, status, value, value_str FROM {$table} WHERE name=%s ORDER BY id DESC LIMIT 1", $name ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	// --- Cola de reintentos -------------------------------------------------

	/**
	 * Encola muestras para reintentar el envío.
	 *
	 * @param array $samples Muestras.
	 */
	public static function enqueue( $samples ) {
		$q = get_option( self::QUEUE, array() );
		$q = is_array( $q ) ? $q : array();
		$q = array_merge( $q, $samples );
		// Tope: no acumular sin fin.
		if ( count( $q ) > 2000 ) {
			$q = array_slice( $q, -2000 );
		}
		update_option( self::QUEUE, $q, false );
	}

	/**
	 * Contenido de la cola.
	 *
	 * @return array
	 */
	public static function queue_all() {
		$q = get_option( self::QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	/**
	 * Vacía la cola.
	 */
	public static function queue_clear() {
		update_option( self::QUEUE, array(), false );
	}

	// --- Cambios de plugins (quién y cuándo) --------------------------------

	/**
	 * Registra activación/desactivación/borrado de un plugin.
	 *
	 * @param string $plugin  Fichero del plugin.
	 * @param bool   $network_wide (multisite).
	 */
	public static function on_plugin_event( $plugin, $network_wide = false ) {
		$action = 'activado';
		if ( 'deactivated_plugin' === current_filter() ) {
			$action = 'desactivado';
		} elseif ( 'deleted_plugin' === current_filter() ) {
			$action = 'borrado';
		}
		self::add_event( $plugin, $action );
	}

	/**
	 * Registra una actualización de plugin/tema (hook upgrader_process_complete).
	 *
	 * @param object $upgrader   Instancia.
	 * @param array  $hook_extra Contexto.
	 */
	public static function on_upgrader( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $p ) {
				self::add_event( $p, 'actualizado' );
			}
		} elseif ( isset( $hook_extra['plugin'] ) ) {
			self::add_event( $hook_extra['plugin'], 'actualizado' );
		}
	}

	/**
	 * Añade un evento al registro local.
	 *
	 * @param string $plugin Fichero del plugin.
	 * @param string $action activado|desactivado|borrado|actualizado.
	 */
	private static function add_event( $plugin, $action ) {
		$user  = wp_get_current_user();
		$who   = ( $user && $user->ID ) ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'sistema';
		$events = get_option( self::EVENTS, array() );
		$events = is_array( $events ) ? $events : array();
		array_unshift(
			$events,
			array(
				'ts'     => time(),
				'user'   => $who,
				'plugin' => (string) $plugin,
				'action' => $action,
			)
		);
		$events = array_slice( $events, 0, self::MAX_EVENTS );
		update_option( self::EVENTS, $events, false );
	}

	/**
	 * Eventos recientes (para la métrica plugins_recent).
	 *
	 * @param int $hours Ventana en horas.
	 * @return array
	 */
	public static function recent_events( $hours = 168 ) {
		$since  = time() - $hours * HOUR_IN_SECONDS;
		$events = get_option( self::EVENTS, array() );
		$out    = array();
		if ( is_array( $events ) ) {
			foreach ( $events as $e ) {
				if ( isset( $e['ts'] ) && $e['ts'] >= $since ) {
					$out[] = $e;
				}
			}
		}
		return $out;
	}
}
