<?php
/**
 * Recolector de métricas de WordPress (todo local) + tarea programada.
 *
 * Cada métrica se publica con un ESTADO (ok|warn|down) calculado con reglas de
 * WordPress, para que en Pandora Cloud se vea AVISO/MAL (no solo el número) y se
 * disparen eventos/incidencias/avisos.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recolector.
 */
class PCM_Collector {

	/**
	 * Tarea programada: recolecta, guarda en local, envía y late.
	 */
	public static function run_cron() {
		$enabled = PCM_Store::enabled();
		if ( empty( $enabled ) ) {
			return;
		}
		$samples = self::collect( $enabled );
		PCM_Store::save_samples( $samples );
		PCM_Store::prune();

		if ( PCM_Store::is_connected() ) {
			$all = array_merge( PCM_Store::queue_all(), $samples );
			if ( PCM_Client::push( $all ) ) {
				PCM_Store::queue_clear();
			} else {
				PCM_Store::enqueue( $samples );
			}
			PCM_Client::heartbeat();
		}
	}

	/**
	 * Recolecta las métricas indicadas.
	 *
	 * @param string[] $names Métricas.
	 * @return array
	 */
	public static function collect( $names ) {
		$ts  = time();
		$out = array();
		foreach ( $names as $name ) {
			$m = self::one( $name );
			if ( null === $m ) {
				continue;
			}
			$m['name']        = $name;
			$m['ts']          = $ts;
			$m['group']       = 'WordPress';
			$m['description'] = PCM_Metrics::label( $name );
			if ( ! isset( $m['status'] ) ) {
				$m['status'] = 'ok';
			}
			$out[] = $m;
		}
		return $out;
	}

	/**
	 * Recolecta una métrica con su estado.
	 *
	 * @param string $name Métrica.
	 * @return array|null
	 */
	private static function one( $name ) {
		global $wpdb;

		switch ( $name ) {
			case 'wp_version':
				return self::str( get_bloginfo( 'version' ) );

			case 'wp_update_core':
				// WordPress guarda en `updates` una entrada "latest" aunque el sitio esté al día:
				// solo cuenta como pendiente la que trae response=upgrade (versión nueva real).
				$u = get_site_transient( 'update_core' );
				$n = 0;
				if ( is_object( $u ) && ! empty( $u->updates ) ) {
					foreach ( (array) $u->updates as $up ) {
						if ( isset( $up->response ) && 'upgrade' === $up->response ) {
							++$n;
						}
					}
				}
				return self::num( $n, self::bad( $n > 0 ) );

			case 'php_version':
				// AVISO si la rama de PHP ya no tiene soporte (EOL).
				return self::str( PHP_VERSION, version_compare( PHP_VERSION, '8.1', '<' ) ? 'warn' : 'ok' );

			case 'db_version':
				return self::str( $wpdb->db_version() );

			case 'plugins_total':
				return self::num( count( (array) get_plugins() ) );

			case 'plugins_active':
				return self::num( count( (array) get_option( 'active_plugins', array() ) ) );

			case 'plugins_updates':
				$u = get_site_transient( 'update_plugins' );
				$n = ( is_object( $u ) && ! empty( $u->response ) ) ? count( $u->response ) : 0;
				return self::num( $n, self::bad( $n > 0 ) );

			case 'themes_updates':
				$u = get_site_transient( 'update_themes' );
				$n = ( is_object( $u ) && ! empty( $u->response ) ) ? count( $u->response ) : 0;
				return self::num( $n, self::bad( $n > 0 ) );

			case 'plugins_recent':
				// Cambios de plugins en las últimas 24 h: AVISO y con el detalle (quién y cuándo).
				$ev24 = PCM_Store::recent_events( 24 );
				$ev   = PCM_Store::recent_events( 168 );
				$text = '-';
				if ( ! empty( $ev ) ) {
					$parts = array();
					foreach ( array_slice( $ev, 0, 10 ) as $e ) {
						$parts[] = gmdate( 'd/m H:i', (int) $e['ts'] ) . ' ' . $e['action'] . ' ' . $e['plugin'] . ' (' . $e['user'] . ')';
					}
					$text = implode( '; ', $parts );
				}
				return self::str( $text, self::bad( ! empty( $ev24 ) ) );

			case 'posts_published':
				$c = wp_count_posts( 'post' );
				return self::num( isset( $c->publish ) ? (int) $c->publish : 0 );

			case 'posts_draft':
				$c = wp_count_posts( 'post' );
				return self::num( isset( $c->draft ) ? (int) $c->draft : 0 );

			case 'posts_scheduled':
				$c = wp_count_posts( 'post' );
				return self::num( isset( $c->future ) ? (int) $c->future : 0 );

			case 'comments_pending':
				$c = wp_count_comments();
				return self::num( isset( $c->moderated ) ? (int) $c->moderated : 0 );

			case 'comments_spam':
				$c = wp_count_comments();
				return self::num( isset( $c->spam ) ? (int) $c->spam : 0 );

			case 'users_total':
				$c = count_users();
				return self::num( isset( $c['total_users'] ) ? (int) $c['total_users'] : 0 );

			case 'db_size':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura de solo lectura para medir el tamaño de la base de datos.
				$size = $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()' );
				return self::num( (float) $size );

			case 'options_autoload':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura de solo lectura sobre la tabla de opciones de WordPress.
				$size = (float) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on')" );
				// AVISO si el autoload pasa de 1 MB (lastra todas las páginas).
				return self::num( $size, self::bad( $size > 1048576 ) );

			case 'object_cache':
				$type = 'no';
				if ( wp_using_ext_object_cache() ) {
					$type = 'externo';
					global $wp_object_cache;
					if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->cache_type ) ) {
						$type = (string) $wp_object_cache->cache_type;
					} elseif ( class_exists( 'Redis' ) ) {
						$type = 'redis';
					}
				}
				return self::str( $type );

			case 'cron_overdue':
				$cron  = _get_cron_array();
				$now   = time();
				$count = 0;
				if ( is_array( $cron ) ) {
					foreach ( $cron as $ts => $hooks ) {
						if ( $ts < $now - 60 ) {
							$count += count( $hooks );
						}
					}
				}
				return self::num( $count, self::bad( $count > 0 ) );

			case 'php_memory_peak':
				return self::num( (float) memory_get_peak_usage( true ) );

			case 'php_memory_pct':
				$peak  = (float) memory_get_peak_usage( true );
				$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
				if ( $limit <= 0 ) {
					return self::num( 0 );
				}
				$pct = round( $peak / $limit * 100, 1 );
				$st  = 'ok';
				if ( $pct >= 95 ) {
					$st = 'down';
				} elseif ( $pct >= 80 ) {
					$st = 'warn';
				}
				return self::num( $pct, $st );

			case 'ttfb_ms':
				$t    = microtime( true );
				$resp = wp_remote_get(
					home_url( '/' ),
					array(
						'timeout'     => 10,
						'redirection' => 3,
					)
				);
				$ms   = round( ( microtime( true ) - $t ) * 1000, 1 );
				if ( is_wp_error( $resp ) ) {
					return array(
						'value_str' => 'sin respuesta',
						'status'    => 'down',
					);
				}
				$st = 'ok';
				if ( $ms > 3000 ) {
					$st = 'down';
				} elseif ( $ms > 1500 ) {
					$st = 'warn';
				}
				return self::num( $ms, $st );

			case 'php_errors_24h':
				if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
					return null;
				}
				$file = WP_CONTENT_DIR . '/debug.log';
				if ( ! is_readable( $file ) ) {
					return null;
				}
				$count = 0;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Se lee el log línea a línea para no cargarlo entero en memoria.
				$fh = fopen( $file, 'r' );
				if ( $fh ) {
					$since = time() - DAY_IN_SECONDS;
					while ( true ) {
						$line = fgets( $fh );
						if ( false === $line ) {
							break;
						}
						if ( preg_match( '/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $line, $mm ) ) {
							$ts = strtotime( $mm[1] );
							if ( $ts && $ts >= $since ) {
								++$count;
							}
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre del descriptor abierto arriba.
					fclose( $fh );
				}
				return self::num( $count, self::bad( $count > 0 ) );

			case 'xmlrpc_enabled':
				$on = (bool) apply_filters( 'xmlrpc_enabled', true );
				return self::flag( $on, self::bad( $on ) );

			case 'users_can_register':
				$on = (int) get_option( 'users_can_register' );
				return self::flag( $on, self::bad( $on > 0 ) );

			case 'file_edit_disabled':
				$disabled = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
				// AVISO si el editor de ficheros está HABILITADO (riesgo).
				return self::flag( $disabled, self::bad( ! $disabled ) );

			case 'wp_debug':
				$on = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
				return self::flag( $on, self::bad( $on ) );
		}
		return null;
	}

	/**
	 * Estado si la condición «mala» es cierta (warn), si no ok.
	 *
	 * @param bool $is_bad Condición.
	 * @return string
	 */
	private static function bad( $is_bad ) {
		return $is_bad ? 'warn' : 'ok';
	}

	/**
	 * Muestra numérica.
	 *
	 * @param float  $v      Valor.
	 * @param string $status Estado.
	 * @return array
	 */
	private static function num( $v, $status = 'ok' ) {
		return array(
			'value'  => (float) $v,
			'status' => $status,
		);
	}

	/**
	 * Muestra de texto.
	 *
	 * @param string $v      Valor.
	 * @param string $status Estado.
	 * @return array
	 */
	private static function str( $v, $status = 'ok' ) {
		return array(
			'value_str' => (string) $v,
			'status'    => $status,
		);
	}

	/**
	 * Muestra booleana (0/1).
	 *
	 * @param bool   $v      Valor.
	 * @param string $status Estado.
	 * @return array
	 */
	private static function flag( $v, $status = 'ok' ) {
		return array(
			'value'  => $v ? 1.0 : 0.0,
			'status' => $status,
		);
	}
}
