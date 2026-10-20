<?php
/**
 * Cliente HTTP: descubre el nodo por el Cloud Master y habla con la API del agente.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente de la API.
 */
class PANDCM_Client {

	/**
	 * URL del Cloud Master (descubre el nodo del tenant). Filtrable.
	 *
	 * @return string
	 */
	private static function master_url() {
		$u = defined( 'PANDCM_MASTER_URL' ) ? PANDCM_MASTER_URL : 'https://cloudmaster.pandorafms.com';
		$u = apply_filters( 'pandcm_master_url', $u );
		return untrailingslashit( $u );
	}

	/**
	 * URL base de la API del nodo (p. ej. https://host/pandoracloud-beta/api/v1).
	 *
	 * @return string
	 */
	private static function api_base() {
		$s = PANDCM_Store::settings();
		return ! empty( $s['api_base'] ) ? untrailingslashit( $s['api_base'] ) : '';
	}

	/**
	 * Conecta: el Master localiza el nodo con email+contraseña y el nodo crea el
	 * agente y devuelve un token de agente. No se pide la URL.
	 *
	 * @param string $email     Email de la cuenta.
	 * @param string $password  Contraseña.
	 * @param string $site_name Nombre del sitio (agente).
	 * @param string $site_url  URL del sitio.
	 * @return array [ok(bool), message(string)]
	 */
	public static function connect( $email, $password, $site_name, $site_url ) {
		if ( '' === $email || '' === $password ) {
			return array( false, __( 'Email y contraseña son obligatorios.', 'pandora-cloud-monitoring' ) );
		}
		// 1) El Cloud Master localiza el nodo del tenant.
		$res = self::request(
			'POST',
			self::master_url() . '/v1/access',
			array(
				'email'    => $email,
				'password' => $password,
			),
			''
		);
		if ( ! $res['ok'] ) {
			return array( false, self::error_message( $res, __( 'No se pudo localizar tu espacio en Pandora Cloud.', 'pandora-cloud-monitoring' ) ) );
		}
		$node = isset( $res['data']['node_url'] ) ? untrailingslashit( $res['data']['node_url'] ) : '';
		$api  = isset( $res['data']['api_url'] ) ? untrailingslashit( $res['data']['api_url'] ) : '';
		if ( '' === $api ) {
			return array( false, __( 'El Master no devolvió la URL del nodo.', 'pandora-cloud-monitoring' ) );
		}
		// 2) Alta del agente en el nodo -> token de agente.
		$cur = PANDCM_Store::settings();
		$reg = self::request(
			'POST',
			$api . '/auth/agent',
			array(
				'email'     => $email,
				'password'  => $password,
				'site_name' => $site_name,
				'site_url'  => $site_url,
				'alias'     => $cur['alias'],
				'agent_ref' => $cur['agent_ref'],
				'agent_id'  => (int) $cur['agent_id'],
			),
			''
		);
		if ( ! $reg['ok'] ) {
			return array( false, self::error_message( $reg, __( 'No se pudo crear el agente.', 'pandora-cloud-monitoring' ) ) );
		}
		$b = $reg['data'];
		PANDCM_Store::patch(
			array(
				'url'        => $node,
				'api_base'   => $api,
				'token'      => isset( $b['token'] ) ? $b['token'] : '',
				'agent_id'   => isset( $b['agent_id'] ) ? (int) $b['agent_id'] : 0,
				'agent_ref'  => isset( $b['agent_ref'] ) ? $b['agent_ref'] : '',
				'agent'      => isset( $b['agent_name'] ) ? $b['agent_name'] : '',
				'alias'      => isset( $b['alias'] ) ? $b['alias'] : '',
				'email'      => $email,
				'last_error' => '',
			)
		);
		return array(
			true,
			sprintf(
				/* translators: %s: nombre del agente en Pandora Cloud. */
				__( 'Conectado. Agente «%s».', 'pandora-cloud-monitoring' ),
				isset( $b['agent_name'] ) ? $b['agent_name'] : ''
			),
		);
	}

	/**
	 * Cambia el alias visible del agente en Pandora Cloud.
	 *
	 * @param string $alias Nombre visible.
	 * @return array [ok(bool), message(string)]
	 */
	public static function set_alias( $alias ) {
		$alias = trim( $alias );
		$s     = PANDCM_Store::settings();
		if ( '' === $alias ) {
			return array( false, __( 'El nombre no puede estar vacío.', 'pandora-cloud-monitoring' ) );
		}
		if ( empty( $s['token'] ) ) {
			return array( false, __( 'Conecta primero con Pandora Cloud.', 'pandora-cloud-monitoring' ) );
		}
		$res = self::request( 'PATCH', self::api_base() . '/agent/profile', array( 'alias' => $alias ), $s['token'] );
		if ( $res['ok'] ) {
			PANDCM_Store::patch(
				array(
					'alias' => $alias,
					'agent' => $alias,
				)
			);
			return array( true, __( 'Nombre actualizado.', 'pandora-cloud-monitoring' ) );
		}
		return array( false, self::error_message( $res, __( 'No se pudo cambiar el nombre.', 'pandora-cloud-monitoring' ) ) );
	}

	/**
	 * Envía un lote de muestras.
	 *
	 * @param array $samples Muestras.
	 * @return bool
	 */
	public static function push( $samples ) {
		if ( empty( $samples ) ) {
			return true;
		}
		$s = PANDCM_Store::settings();
		if ( empty( $s['token'] ) ) {
			return false;
		}
		$res = self::request( 'PUT', self::api_base() . '/agent/data', array( 'samples' => $samples ), $s['token'] );
		if ( $res['ok'] ) {
			PANDCM_Store::patch(
				array(
					'last_push'  => time(),
					'last_error' => '',
				)
			);
			return true;
		}
		PANDCM_Store::patch( array( 'last_error' => self::error_message( $res, __( 'Error al enviar datos.', 'pandora-cloud-monitoring' ) ) ) );
		return false;
	}

	/**
	 * Latido del agente.
	 */
	public static function heartbeat() {
		$s = PANDCM_Store::settings();
		if ( empty( $s['token'] ) ) {
			return;
		}
		self::request( 'POST', self::api_base() . '/agent/heartbeat', null, $s['token'] );
	}

	/**
	 * Petición HTTP.
	 *
	 * @param string     $method Método.
	 * @param string     $url    URL completa.
	 * @param array|null $body   Cuerpo (JSON).
	 * @param string     $token  Bearer (opcional).
	 * @return array
	 */
	private static function request( $method, $url, $body, $token ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
				'data' => array(),
				'raw'  => $resp->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = (string) wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		return array(
			'ok'   => ( $code >= 200 && $code < 300 ),
			'code' => $code,
			'data' => is_array( $data ) ? $data : array(),
			'raw'  => $raw,
		);
	}

	/**
	 * Mensaje de error legible.
	 *
	 * @param array  $res      Resultado.
	 * @param string $fallback Por defecto.
	 * @return string
	 */
	private static function error_message( $res, $fallback ) {
		switch ( $res['code'] ) {
			case 401:
				return __( 'Email o contraseña incorrectos.', 'pandora-cloud-monitoring' );
			case 403:
				return __( 'La cuenta está desactivada o el email sin validar.', 'pandora-cloud-monitoring' );
			case 409:
				return __( 'Esta cuenta usa verificación en dos pasos: conéctala desde la web de Pandora Cloud.', 'pandora-cloud-monitoring' );
			case 429:
				return __( 'Demasiados intentos; prueba en un rato.', 'pandora-cloud-monitoring' );
		}
		if ( isset( $res['data']['error']['message'] ) ) {
			return $res['data']['error']['message'];
		}
		if ( isset( $res['data']['error'] ) && is_string( $res['data']['error'] ) ) {
			return $res['data']['error'];
		}
		if ( ! empty( $res['raw'] ) && strlen( $res['raw'] ) < 200 ) {
			return $fallback . ' ' . $res['raw'];
		}
		return $fallback;
	}
}
