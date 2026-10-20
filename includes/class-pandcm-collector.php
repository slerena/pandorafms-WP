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
class PANDCM_Collector {

	/**
	 * Tarea programada: recolecta, guarda en local, envía y late.
	 */
	public static function run_cron() {
		$enabled = PANDCM_Store::enabled();
		if ( empty( $enabled ) ) {
			return;
		}
		$samples = self::collect( $enabled );
		PANDCM_Store::save_samples( $samples );
		PANDCM_Store::prune();

		if ( PANDCM_Store::is_connected() ) {
			$all = array_merge( PANDCM_Store::queue_all(), $samples );
			if ( PANDCM_Client::push( $all ) ) {
				PANDCM_Store::queue_clear();
			} else {
				PANDCM_Store::enqueue( $samples );
			}
			PANDCM_Client::heartbeat();
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
			$m['description'] = PANDCM_Metrics::label( $name );
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
				$ev24 = PANDCM_Store::recent_events( 24 );
				$ev   = PANDCM_Store::recent_events( 168 );
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

			case 'seo_sitemap':
				return self::collect_sitemap();

			case 'seo_robots':
				return self::collect_robots();

			case 'xmlrpc_enabled':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del core de WordPress.
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
	 * Sitemap: localizable, XML válido y con al menos una entrada.
	 *
	 * Prueba primero el/los `Sitemap:` declarados en robots.txt y, si no hay,
	 * las rutas habituales (core de WordPress, Yoast/Rank Math, genérica).
	 *
	 * @return array
	 */
	private static function collect_sitemap() {
		$candidates = array();

		list( $code, $body, $err ) = self::fetch( home_url( '/robots.txt' ) );
		if ( ! $err && 200 === $code && preg_match_all( '/^\s*sitemap\s*:\s*(\S+)/im', $body, $m ) ) {
			$candidates = array_slice( $m[1], 0, 3 );
		}
		$candidates[] = home_url( '/wp-sitemap.xml' );      // WordPress 5.5+.
		$candidates[] = home_url( '/sitemap_index.xml' );   // Yoast / Rank Math.
		$candidates[] = home_url( '/sitemap.xml' );

		foreach ( array_unique( $candidates ) as $url ) {
			list( $scode, $sbody, $serr ) = self::fetch( $url );
			if ( $serr || 200 !== $scode ) {
				continue;
			}
			$is_index = ( false !== stripos( $sbody, '<sitemapindex' ) );
			$is_set   = ( false !== stripos( $sbody, '<urlset' ) );
			if ( ! $is_index && ! $is_set ) {
				continue; // Devolvió HTML: no es un sitemap.
			}
			$n = (int) preg_match_all( '/<loc>\s*[^<\s]+\s*<\/loc>/i', $sbody );
			if ( $n < 1 ) {
				continue;
			}
			return self::num( $n, 'ok' );
		}

		return self::num( 0, 'warn' );
	}

	/**
	 * Robots.txt: existe, no bloquea el sitio, declara el sitemap y permite a
	 * los rastreadores de IA (GEO). Puntuación 0-100.
	 *
	 * @return array
	 */
	private static function collect_robots() {
		list( $code, $body, $err ) = self::fetch( home_url( '/robots.txt' ) );
		if ( $err || 200 !== $code || '' === trim( $body ) ) {
			return self::num( 0, 'warn' );
		}

		$groups   = self::robots_groups( $body );
		$sitemaps = self::robots_sitemaps( $body );
		$valid    = ! empty( $groups );
		$star     = isset( $groups['*'] ) ? $groups['*'] : array();
		$blocked  = $valid && self::robots_blocked( '/', $star );

		$score = 0;
		if ( $valid ) {
			$score += 40; // robots.txt real (declara al menos un User-agent).
		}
		if ( ! $blocked ) {
			$score += 20; // No cierra el sitio a los buscadores.
		}
		if ( ! empty( $sitemaps ) ) {
			$score += 20; // Declara el sitemap.
		}

		// GEO: que los agentes de IA de búsqueda/citación puedan leer el sitio
		// (los crawlers de solo entrenamiento pueden bloquearse sin afectar a GEO).
		$ai     = array( 'oai-searchbot', 'chatgpt-user', 'perplexitybot', 'perplexity-user', 'claude-searchbot', 'claude-user', 'google-extended', 'duckassistbot' );
		$nblock = 0;
		foreach ( $ai as $ua ) {
			$rules = isset( $groups[ $ua ] ) ? $groups[ $ua ] : $star;
			if ( self::robots_blocked( '/', $rules ) ) {
				++$nblock;
			}
		}
		if ( 0 === $nblock ) {
			$score += 20;
		} elseif ( $nblock < count( $ai ) ) {
			$score += 10;
		}

		return self::num( $score, ( $score < 80 || $blocked ) ? 'warn' : 'ok' );
	}

	/**
	 * Directivas de robots.txt agrupadas por agente.
	 *
	 * @param string $txt Contenido.
	 * @return array agente => [ [tipo, ruta], ... ]
	 */
	private static function robots_groups( $txt ) {
		$groups = array();
		$agents = array();
		$ruled  = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $txt ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}
			if ( preg_match( '/^user-agent\s*:\s*(.*)$/i', $line, $m ) ) {
				if ( $ruled ) {
					$agents = array();
					$ruled  = false;
				}
				$agents[] = strtolower( trim( $m[1] ) );
				continue;
			}
			if ( preg_match( '/^(allow|disallow)\s*:\s*(.*)$/i', $line, $m ) ) {
				$type = strtolower( $m[1] );
				$path = trim( $m[2] );
				foreach ( $agents as $ua ) {
					$groups[ $ua ][] = array( $type, $path );
				}
				$ruled = true;
			}
		}
		return $groups;
	}

	/**
	 * Directivas `Sitemap:` de robots.txt.
	 *
	 * @param string $txt Contenido.
	 * @return string[]
	 */
	private static function robots_sitemaps( $txt ) {
		if ( preg_match_all( '/^\s*sitemap\s*:\s*(\S+)/im', $txt, $m ) ) {
			return $m[1];
		}
		return array();
	}

	/**
	 * ¿Una ruta está bloqueada por un conjunto de reglas? (gana la más específica)
	 *
	 * @param string $path  Ruta.
	 * @param array  $rules Reglas [tipo, patrón].
	 * @return bool
	 */
	private static function robots_blocked( $path, $rules ) {
		$best    = -1;
		$blocked = false;
		foreach ( (array) $rules as $rule ) {
			$type = $rule[0];
			$pat  = $rule[1];
			if ( '' === $pat ) {
				continue; // Disallow: (vacío) = permitir todo.
			}
			$re = '#^' . str_replace( '\*', '.*', preg_quote( $pat, '#' ) ) . '#';
			if ( ! preg_match( $re, $path ) ) {
				continue;
			}
			$len = strlen( $pat );
			if ( $len > $best || ( $len === $best && 'allow' === $type ) ) {
				$best    = $len;
				$blocked = ( 'disallow' === $type );
			}
		}
		return $blocked;
	}

	/**
	 * GET a una URL del propio sitio.
	 *
	 * @param string $url URL.
	 * @return array [código HTTP, cuerpo, error(bool)]
	 */
	private static function fetch( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'PandoraCloudMonitoring/' . PANDCM_VERSION . ' (' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 0, '', true );
		}
		return array( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ), false );
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
