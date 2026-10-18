<?php
/**
 * Catálogo de métricas que el plugin puede recolectar.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catálogo de métricas (mismo conjunto que usa la plataforma en /v1/agent/config).
 */
class PCM_Metrics {

	/**
	 * Todas las métricas: clave => [etiqueta, tipo, unidad].
	 *
	 * Tipo: number | string  (la plataforma decide generic_data / generic_data_string).
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'wp_version'         => array( 'Versión de WordPress', 'string', '' ),
			'wp_update_core'     => array( 'Actualización de core pendiente', 'number', '' ),
			'php_version'        => array( 'Versión de PHP', 'string', '' ),
			'db_version'         => array( 'Versión de MySQL/MariaDB', 'string', '' ),
			'plugins_total'      => array( 'Plugins instalados', 'number', '' ),
			'plugins_active'     => array( 'Plugins activos', 'number', '' ),
			'plugins_updates'    => array( 'Plugins con actualización', 'number', '' ),
			'themes_updates'     => array( 'Temas con actualización', 'number', '' ),
			'plugins_recent'     => array( 'Plugins instalados/actualizados recientemente (y quién)', 'string', '' ),
			'posts_published'    => array( 'Entradas publicadas', 'number', '' ),
			'posts_draft'        => array( 'Borradores', 'number', '' ),
			'posts_scheduled'    => array( 'Programadas', 'number', '' ),
			'comments_pending'   => array( 'Comentarios pendientes', 'number', '' ),
			'comments_spam'      => array( 'Comentarios spam', 'number', '' ),
			'users_total'        => array( 'Usuarios', 'number', '' ),
			'db_size'            => array( 'Tamaño de la base de datos', 'number', 'bytes' ),
			'options_autoload'   => array( 'Autoload de wp_options', 'number', 'bytes' ),
			'object_cache'       => array( 'Caché de objetos (tipo + hit ratio)', 'string', '' ),
			'cron_overdue'       => array( 'Tareas WP-Cron vencidas', 'number', '' ),
			'php_memory_peak'    => array( 'Pico de memoria PHP', 'number', 'bytes' ),
			'php_memory_pct'     => array( 'Uso de memoria PHP', 'number', '%', '1.0.5' ),
			'ttfb_ms'            => array( 'Tiempo de respuesta (TTFB)', 'number', 'ms' ),
			'php_errors_24h'     => array( 'Errores PHP (24 h)', 'number', '' ),
			'seo_sitemap'        => array( 'Sitemap: accesible y válido (nº de entradas)', 'number', '', '1.0.11' ),
			'seo_robots'         => array( 'robots.txt: SEO y GEO (0-100)', 'number', '', '1.0.11' ),
			'xmlrpc_enabled'     => array( 'XML-RPC activo', 'number', '' ),
			'users_can_register' => array( 'Registro de usuarios abierto', 'number', '' ),
			'file_edit_disabled' => array( 'Editor de ficheros deshabilitado', 'number', '' ),
			'wp_debug'           => array( 'WP_DEBUG activo', 'number', '' ),
		);
	}

	/**
	 * Claves de todas las métricas.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_keys( self::all() );
	}

	/**
	 * Versión en la que se añadió una métrica ("0" = desde el principio).
	 *
	 * @param string $name Clave.
	 * @return string
	 */
	public static function since( $name ) {
		$all = self::all();
		return isset( $all[ $name ][3] ) ? (string) $all[ $name ][3] : '0';
	}

	/**
	 * Métricas añadidas después de una versión (para activarlas al actualizar).
	 *
	 * @param string $version Versión instalada hasta ahora.
	 * @return string[]
	 */
	public static function added_after( $version ) {
		$out = array();
		foreach ( self::names() as $name ) {
			if ( version_compare( self::since( $name ), $version, '>' ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Métricas agrupadas por tema (para la pantalla de administración).
	 *
	 * @return array grupo => [claves]
	 */
	public static function groups() {
		return array(
			'Núcleo y parches' => array( 'wp_version', 'wp_update_core', 'php_version', 'db_version' ),
			'Plugins y temas'  => array( 'plugins_total', 'plugins_active', 'plugins_updates', 'themes_updates', 'plugins_recent' ),
			'Rendimiento'      => array( 'ttfb_ms', 'php_memory_pct', 'php_memory_peak', 'db_size', 'options_autoload', 'object_cache', 'cron_overdue', 'php_errors_24h' ),
			'SEO y GEO'        => array( 'seo_sitemap', 'seo_robots' ),
			'Contenido'        => array( 'posts_published', 'posts_draft', 'posts_scheduled', 'comments_pending', 'comments_spam', 'users_total' ),
			'Seguridad'        => array( 'xmlrpc_enabled', 'users_can_register', 'file_edit_disabled', 'wp_debug' ),
		);
	}

	/**
	 * Etiqueta de una métrica.
	 *
	 * @param string $name Clave.
	 * @return string
	 */
	public static function label( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ][0] : $name;
	}

	/**
	 * Tipo de una métrica (number|string).
	 *
	 * @param string $name Clave.
	 * @return string
	 */
	public static function type( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ][1] : 'number';
	}

	/**
	 * Formatea un valor para mostrarlo (bytes -> KB/MB/GB, %, etc.).
	 *
	 * @param mixed  $value Valor.
	 * @param string $name  Métrica.
	 * @return string
	 */
	public static function format_value( $value, $name ) {
		$all  = self::all();
		$unit = isset( $all[ $name ][2] ) ? $all[ $name ][2] : '';
		if ( is_numeric( $value ) ) {
			if ( 'bytes' === $unit ) {
				return self::human_bytes( (float) $value );
			}
			if ( '%' === $unit ) {
				return rtrim( rtrim( number_format( (float) $value, 1, ',', '.' ), '0' ), ',' ) . ' %';
			}
		}
		return (string) $value;
	}

	/**
	 * Bytes legibles.
	 *
	 * @param float $b Bytes.
	 * @return string
	 */
	public static function human_bytes( $b ) {
		$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0;
		while ( $b >= 1024 && $i < 4 ) {
			$b /= 1024;
			++$i;
		}
		return ( 0 === $i ? (string) (int) $b : number_format( $b, 1, ',', '.' ) ) . ' ' . $u[ $i ];
	}
}
