<?php
/**
 * Vista de administración del plugin: conexión, qué monitorizar y gráficas locales.
 *
 * @package PandoraCloudMonitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administración.
 */
class PCM_Admin {

	/**
	 * Menú de administración.
	 *
	 * @return string Hook de la página (para encolar estilos solo ahí).
	 */
	public static function menu() {
		return add_menu_page(
			'Pandora Cloud',
			'Pandora Cloud',
			'manage_options',
			'pandora-cloud',
			array( __CLASS__, 'page' ),
			'dashicons-cloud',
			81
		);
	}

	/**
	 * Encola el CSS solo en la página del plugin.
	 *
	 * @param string $hook Hook actual.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_pandora-cloud' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pcm-admin', PCM_URL . 'assets/admin.css', array(), PCM_VERSION );
	}

	/**
	 * Guarda qué métricas se monitorizan.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'pandora-cloud-monitoring' ) );
		}
		check_admin_referer( 'pcm_save' );
		$enabled = isset( $_POST['pcm_enabled'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['pcm_enabled'] ) ) : array();
		$enabled = array_values( array_intersect( $enabled, PCM_Metrics::names() ) );
		PCM_Store::patch( array( 'enabled' => $enabled ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pandora-cloud',
					'pcm_msg' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Conecta con Pandora Cloud (email+contraseña → token de agente).
	 */
	public static function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'pandora-cloud-monitoring' ) );
		}
		check_admin_referer( 'pcm_connect' );
		$email = isset( $_POST['pcm_email'] ) ? sanitize_email( wp_unslash( $_POST['pcm_email'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- La contraseña se envía tal cual al nodo; sanearla alteraría credenciales válidas.
		$pass = isset( $_POST['pcm_password'] ) ? (string) wp_unslash( $_POST['pcm_password'] ) : '';
		$res  = PCM_Client::connect( $email, $pass, get_bloginfo( 'name' ), home_url( '/' ) );
		if ( $res[0] ) {
			// Al conectar: asegura el cron y envía YA las métricas (sin esperar al botón).
			if ( ! wp_next_scheduled( 'pcm_cron_push' ) ) {
				wp_schedule_event( time() + 60, 'pcm_every_5min', 'pcm_cron_push' );
			}
			PCM_Collector::run_cron();
			$msg = 'connected';
		} else {
			$msg = 'err:' . rawurlencode( $res[1] );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pandora-cloud',
					'pcm_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Cambia el nombre visible (alias) del agente en Pandora Cloud.
	 */
	public static function handle_alias() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'pandora-cloud-monitoring' ) );
		}
		check_admin_referer( 'pcm_alias' );
		$alias = isset( $_POST['pcm_alias'] ) ? sanitize_text_field( wp_unslash( $_POST['pcm_alias'] ) ) : '';
		$res   = PCM_Client::set_alias( $alias );
		$msg   = $res[0] ? 'alias' : ( 'err:' . rawurlencode( $res[1] ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pandora-cloud',
					'pcm_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Desconecta (borra el token).
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'pandora-cloud-monitoring' ) );
		}
		check_admin_referer( 'pcm_disconnect' );
		PCM_Store::patch(
			array(
				'token'    => '',
				'agent_id' => 0,
				'agent'    => '',
			)
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pandora-cloud',
					'pcm_msg' => 'disconnected',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Recolecta y envía ahora.
	 */
	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'pandora-cloud-monitoring' ) );
		}
		check_admin_referer( 'pcm_run' );
		PCM_Collector::run_cron();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pandora-cloud',
					'pcm_msg' => 'ran',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Página de administración.
	 */
	public static function page() {
		$s       = PCM_Store::settings();
		$conn    = PCM_Store::is_connected();
		$enabled = PCM_Store::enabled();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo muestra el aviso de resultado de una acción ya verificada.
		$msg  = isset( $_GET['pcm_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['pcm_msg'] ) ) : '';
		$next = wp_next_scheduled( 'pcm_cron_push' );
		$upd  = get_site_transient( 'update_plugins' );
		$nupd = ( is_object( $upd ) && ! empty( $upd->response ) ) ? count( $upd->response ) : 0;
		$last = $s['last_push']
			? sprintf(
				/* translators: %s: tiempo transcurrido desde la última sincronización (p. ej. "5 mins"). */
				__( 'hace %s', 'pandora-cloud-monitoring' ),
				human_time_diff( (int) $s['last_push'], time() )
			)
			: '—';
		$nupdtxt = $nupd > 0
			? sprintf(
				/* translators: %d: número de plugins con actualización disponible. */
				_n( '%d plugin con actualización', '%d plugins con actualización', $nupd, 'pandora-cloud-monitoring' ),
				$nupd
			)
			: '';
		$panel = ( ! empty( $s['url'] ) && ! empty( $s['agent_id'] ) ) ? untrailingslashit( $s['url'] ) . '/hosts/' . (int) $s['agent_id'] : '';
		?>
		<div class="wrap pcm-wrap">
			<div class="pcm-ptitle">
				<span class="dashicons dashicons-cloud"></span>
				<h1><?php esc_html_e( 'Pandora Cloud Monitoring', 'pandora-cloud-monitoring' ); ?></h1>
				<span class="pcm-ver">v<?php echo esc_html( PCM_VERSION ); ?></span>
			</div>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Monitorización guardada.', 'pandora-cloud-monitoring' ); ?></p></div>
			<?php elseif ( 'connected' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Conectado con Pandora Cloud.', 'pandora-cloud-monitoring' ); ?></p></div>
			<?php elseif ( 'disconnected' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Desconectado.', 'pandora-cloud-monitoring' ); ?></p></div>
			<?php elseif ( 'alias' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Nombre actualizado.', 'pandora-cloud-monitoring' ); ?></p></div>
			<?php elseif ( 'ran' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Recolectado y enviado.', 'pandora-cloud-monitoring' ); ?></p></div>
			<?php elseif ( 0 === strpos( $msg, 'err:' ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( rawurldecode( substr( $msg, 4 ) ) ); ?></p></div>
			<?php endif; ?>

			<!-- HERO -->
			<?php if ( $conn ) : ?>
			<div class="pcm-card pcm-hero pcm-hero-ok">
				<span class="pcm-badge"><span class="pcm-hero-dot"></span><?php esc_html_e( 'Conectado a Pandora Cloud', 'pandora-cloud-monitoring' ); ?></span>
				<h2><?php esc_html_e( 'Tu WordPress está monitorizado', 'pandora-cloud-monitoring' ); ?></h2>
				<p class="pcm-lead"><?php esc_html_e( 'Este sitio envía sus métricas a Pandora Cloud cada 5 minutos. Te avisamos si algo falla, incluso si el WordPress o el hosting se caen.', 'pandora-cloud-monitoring' ); ?></p>
				<div class="pcm-meta">
					<div class="pcm-m"><b><?php esc_html_e( 'Agente', 'pandora-cloud-monitoring' ); ?></b><span><?php echo esc_html( $s['alias'] ? $s['alias'] : $s['agent'] ); ?></span></div>
					<div class="pcm-m"><b><?php esc_html_e( 'Nodo', 'pandora-cloud-monitoring' ); ?></b><span><?php echo esc_html( wp_parse_url( $s['url'], PHP_URL_HOST ) ? wp_parse_url( $s['url'], PHP_URL_HOST ) : $s['url'] ); ?></span></div>
					<div class="pcm-m"><b><?php esc_html_e( 'Última sincronización', 'pandora-cloud-monitoring' ); ?></b><span><?php echo esc_html( $last ); ?></span></div>
				</div>
				<div class="pcm-hero-actions">
					<?php if ( $panel ) : ?>
						<a class="button button-hero-white" href="<?php echo esc_url( $panel ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver en Pandora Cloud', 'pandora-cloud-monitoring' ); ?></a>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php wp_nonce_field( 'pcm_run' ); ?>
						<input type="hidden" name="action" value="pcm_run">
						<button class="button button-hero-ghost" type="submit"><?php esc_html_e( 'Recolectar y enviar ahora', 'pandora-cloud-monitoring' ); ?></button>
					</form>
					<a class="button button-hero-ghost" href="#pcm-monitorizar"><?php esc_html_e( 'Ver qué se monitoriza', 'pandora-cloud-monitoring' ); ?></a>
				</div>
				<div class="pcm-foot"><?php esc_html_e( 'El histórico local sigue disponible aunque no haya conexión.', 'pandora-cloud-monitoring' ); ?></div>
			</div>
			<?php else : ?>
			<div class="pcm-card pcm-hero">
				<span class="pcm-badge">☁️ <?php esc_html_e( 'Funciona ya, en local · sin conectar nada', 'pandora-cloud-monitoring' ); ?></span>
				<h2><?php esc_html_e( 'Monitoriza tu WordPress de verdad', 'pandora-cloud-monitoring' ); ?></h2>
				<p class="pcm-lead"><?php esc_html_e( 'El plugin guarda el histórico y pinta las gráficas aquí mismo. Su verdadera potencia es conectarlo a Pandora Cloud:', 'pandora-cloud-monitoring' ); ?></p>
				<div class="pcm-benefits">
					<div class="pcm-benefit"><div class="pcm-ic">🛰️</div><b><?php esc_html_e( 'Monitorización independiente', 'pandora-cloud-monitoring' ); ?></b><span><?php esc_html_e( 'Aunque tu WordPress se caiga (o el hosting), Pandora Cloud sigue vigilando y te avisa.', 'pandora-cloud-monitoring' ); ?></span></div>
					<div class="pcm-benefit"><div class="pcm-ic">🔔</div><b><?php esc_html_e( 'Avisos donde estés', 'pandora-cloud-monitoring' ); ?></b><span><?php esc_html_e( 'Email, SMS, Telegram, Slack, Teams o webhook, con escalado a la guardia.', 'pandora-cloud-monitoring' ); ?></span></div>
					<div class="pcm-benefit"><div class="pcm-ic">🗂️</div><b><?php esc_html_e( 'Todos tus WP en una pantalla', 'pandora-cloud-monitoring' ); ?></b><span><?php esc_html_e( 'Varios sitios en el mismo panel: histórico, disponibilidad (SLA) e incidencias.', 'pandora-cloud-monitoring' ); ?></span></div>
				</div>
				<p style="margin:16px 0 0">
					<a class="button button-hero-white" href="#pcm-conexion"><?php esc_html_e( 'Conectar con Pandora Cloud', 'pandora-cloud-monitoring' ); ?></a>
					<a class="button button-hero-ghost" href="#pcm-monitorizar"><?php esc_html_e( 'Ver qué se monitoriza', 'pandora-cloud-monitoring' ); ?></a>
				</p>
				<div class="pcm-foot"><?php esc_html_e( 'Conectar no cambia lo que ya hace: solo añade la vigilancia externa y los avisos.', 'pandora-cloud-monitoring' ); ?></div>
			</div>
			<?php endif; ?>

			<!-- ESTADO -->
			<div class="pcm-status">
				<span class="pcm-chip ok"><span class="pcm-dot"></span><?php esc_html_e( 'Recolectando', 'pandora-cloud-monitoring' ); ?> <b><?php esc_html_e( 'en local', 'pandora-cloud-monitoring' ); ?></b></span>
				<?php if ( $conn ) : ?>
					<span class="pcm-chip ok"><span class="pcm-dot"></span><?php esc_html_e( 'Conectado a', 'pandora-cloud-monitoring' ); ?> <b><?php echo esc_html( $s['agent'] ? $s['agent'] : $s['url'] ); ?></b></span>
				<?php else : ?>
					<span class="pcm-chip"><span class="pcm-dot"></span><b><?php esc_html_e( 'Sin conectar', 'pandora-cloud-monitoring' ); ?></b> <?php esc_html_e( 'a Pandora Cloud', 'pandora-cloud-monitoring' ); ?></span>
				<?php endif; ?>
				<span class="pcm-chip info"><span class="pcm-dot"></span><?php esc_html_e( 'Próxima recolección', 'pandora-cloud-monitoring' ); ?> <?php echo esc_html( $next ? wp_date( 'H:i:s', $next ) : '—' ); ?></span>
				<?php if ( $nupd > 0 ) : ?>
					<span class="pcm-chip warn"><span class="pcm-dot"></span><?php echo esc_html( $nupdtxt ); ?></span>
				<?php endif; ?>
			</div>

			<!-- CONEXIÓN -->
			<div class="pcm-card" id="pcm-conexion">
				<h2><?php esc_html_e( 'Conexión con Pandora Cloud', 'pandora-cloud-monitoring' ); ?></h2>
				<?php if ( $conn ) : ?>
					<p>
						<?php esc_html_e( 'Conectado', 'pandora-cloud-monitoring' ); ?> · <?php esc_html_e( 'Nodo', 'pandora-cloud-monitoring' ); ?> <code><?php echo esc_html( $s['url'] ); ?></code>
						&nbsp;·&nbsp;<?php esc_html_e( 'Agente', 'pandora-cloud-monitoring' ); ?>: <strong><?php echo esc_html( $s['agent'] ); ?></strong>
					</p>
					<?php if ( ! empty( $s['last_error'] ) ) : ?>
						<p style="color:#b32d2e"><?php esc_html_e( 'Último error:', 'pandora-cloud-monitoring' ); ?> <?php echo esc_html( $s['last_error'] ); ?></p>
					<?php endif; ?>
					<p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'pcm_run' ); ?>
							<input type="hidden" name="action" value="pcm_run">
							<button class="button button-primary" type="submit"><?php esc_html_e( 'Recolectar y enviar ahora', 'pandora-cloud-monitoring' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'pcm_disconnect' ); ?>
							<input type="hidden" name="action" value="pcm_disconnect">
							<button class="button" type="submit"><?php esc_html_e( 'Desconectar', 'pandora-cloud-monitoring' ); ?></button>
						</form>
						<?php if ( $panel ) : ?>
							<a class="button" href="<?php echo esc_url( $panel ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver en Pandora Cloud', 'pandora-cloud-monitoring' ); ?></a>
						<?php endif; ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
						<?php wp_nonce_field( 'pcm_alias' ); ?>
						<input type="hidden" name="action" value="pcm_alias">
						<label class="pcm-field" style="max-width:460px"><span><?php esc_html_e( 'Nombre visible en Pandora Cloud', 'pandora-cloud-monitoring' ); ?></span>
							<input type="text" name="pcm_alias" value="<?php echo esc_attr( $s['alias'] ? $s['alias'] : $s['agent'] ); ?>" required>
						</label>
						<p><button class="button" type="submit"><?php esc_html_e( 'Guardar nombre', 'pandora-cloud-monitoring' ); ?></button>
						<span class="description"><?php esc_html_e( 'Por defecto: «WP - tu-dominio». Cámbialo cuando quieras: se actualiza en Pandora Cloud.', 'pandora-cloud-monitoring' ); ?></span></p>
					</form>
				<?php else : ?>
					<p class="description" style="margin:0 0 12px"><?php esc_html_e( 'Solo tu email y contraseña de Pandora Cloud: localizamos tu espacio automáticamente, creamos un agente para este WordPress y guardamos un token (tu contraseña no se guarda).', 'pandora-cloud-monitoring' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'pcm_connect' ); ?>
						<input type="hidden" name="action" value="pcm_connect">
						<div class="pcm-grid2">
							<label class="pcm-field"><span><?php esc_html_e( 'Email', 'pandora-cloud-monitoring' ); ?></span><input type="email" name="pcm_email" value="<?php echo esc_attr( $s['email'] ); ?>" required></label>
							<label class="pcm-field"><span><?php esc_html_e( 'Contraseña', 'pandora-cloud-monitoring' ); ?></span><input type="password" name="pcm_password" autocomplete="off" required></label>
						</div>
						<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Conectar', 'pandora-cloud-monitoring' ); ?></button></p>
					</form>
				<?php endif; ?>
			</div>

			<!-- QUÉ MONITORIZAR -->
			<div class="pcm-card" id="pcm-monitorizar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pcm_save' ); ?>
					<input type="hidden" name="action" value="pcm_save">
					<div style="display:flex;align-items:center;margin-bottom:4px">
						<h2 style="margin:0"><?php esc_html_e( 'Qué monitorizar', 'pandora-cloud-monitoring' ); ?></h2>
						<label class="description" style="margin-left:auto"><input type="checkbox" id="pcm-all"> <?php esc_html_e( 'Marcar todo', 'pandora-cloud-monitoring' ); ?></label>
					</div>
					<p class="description" style="margin:0 0 12px"><?php esc_html_e( 'Se recolecta cada 5 minutos. Marca solo lo que te interese.', 'pandora-cloud-monitoring' ); ?></p>
					<div class="pcm-groups">
						<?php foreach ( PCM_Metrics::groups() as $glabel => $names ) : ?>
							<div class="pcm-grp">
								<h3><?php echo esc_html( $glabel ); ?></h3>
								<?php foreach ( $names as $key ) : ?>
									<div class="pcm-row">
										<input type="checkbox" class="pcm-metric" name="pcm_enabled[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled, true ) ); ?>>
										<span><?php echo esc_html( PCM_Metrics::label( $key ) ); ?></span>
										<code><?php echo esc_html( $key ); ?></code>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="margin:14px 0 0"><button class="button button-primary" type="submit"><?php esc_html_e( 'Guardar', 'pandora-cloud-monitoring' ); ?></button></p>
				</form>
			</div>

			<!-- HISTÓRICO -->
			<div class="pcm-card">
				<h2><?php esc_html_e( 'Histórico local', 'pandora-cloud-monitoring' ); ?></h2>
				<p class="description" style="margin:0 0 10px"><?php esc_html_e( 'Gráficas guardadas en este WordPress (no se piden a la API).', 'pandora-cloud-monitoring' ); ?></p>
				<?php self::history(); ?>
			</div>
		</div>
		<script>
		( function () {
			var all = document.getElementById( 'pcm-all' );
			if ( ! all ) { return; }
			all.addEventListener( 'change', function () {
				document.querySelectorAll( '.pcm-metric' ).forEach( function ( c ) { c.checked = all.checked; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Pinta el histórico local con estado (OK/AVISO/MAL) y gráficas.
	 */
	private static function history() {
		$any = false;
		echo '<table class="pcm-hist"><tbody>';
		foreach ( PCM_Metrics::all() as $key => $meta ) {
			$last = PCM_Store::latest( $key );
			if ( empty( $last ) ) {
				continue;
			}
			$any     = true;
			$numeric = ( 'number' === $meta[1] );
			$rows    = $numeric ? PCM_Store::series( $key, 60 ) : array();
			$val     = ( isset( $last['value'] ) && null !== $last['value'] && '' !== $last['value'] ) ? $last['value'] : $last['value_str'];
			$shown   = PCM_Metrics::format_value( $val, $key );
			$st      = isset( $last['status'] ) && $last['status'] ? $last['status'] : 'ok';
			echo '<tr>';
			echo '<td class="pcm-name">' . esc_html( $meta[0] ) . '</td>';
			echo '<td>' . self::status_pill( $st ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="pcm-spark">' . ( $numeric ? self::sparkline( $rows ) : '<span class="description">—</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><span class="pcm-val">' . esc_html( $shown ) . '</span> <span class="pcm-when">' . esc_html( $last['ts'] ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( ! $any ) {
			echo '<p class="description">' . esc_html__( 'Todavía no hay datos (espera a la primera recolección).', 'pandora-cloud-monitoring' ) . '</p>';
		}
	}

	/**
	 * Píldora de estado.
	 *
	 * @param string $st ok|warn|down|nodata.
	 * @return string
	 */
	private static function status_pill( $st ) {
		$map = array(
			'ok'     => array( 'OK', 'ok' ),
			'warn'   => array( 'AVISO', 'warn' ),
			'down'   => array( 'MAL', 'down' ),
			'nodata' => array( 'SIN DATOS', 'off' ),
		);
		$m   = isset( $map[ $st ] ) ? $map[ $st ] : array( strtoupper( $st ), 'off' );
		return '<span class="pcm-pill ' . esc_attr( $m[1] ) . '">' . esc_html( $m[0] ) . '</span>';
	}

	/**
	 * Sparkline SVG desde el histórico local.
	 *
	 * @param array $rows Filas [ts, value].
	 * @return string SVG.
	 */
	private static function sparkline( $rows ) {
		$vals = array();
		foreach ( $rows as $r ) {
			if ( null !== $r['value'] && '' !== $r['value'] ) {
				$vals[] = (float) $r['value'];
			}
		}
		$n = count( $vals );
		if ( $n < 2 ) {
			return '<span class="description">—</span>';
		}
		$w   = 260;
		$h   = 40;
		$min = min( $vals );
		$max = max( $vals );
		if ( $max <= $min ) {
			$max = $min + 1;
		}
		$step = $w / ( $n - 1 );
		$pts  = '';
		foreach ( $vals as $i => $v ) {
			$x    = $i * $step;
			$y    = ( $h - 3 ) - ( ( $v - $min ) / ( $max - $min ) ) * ( $h - 6 );
			$pts .= sprintf( '%.1f,%.1f ', $x, $y );
		}
		return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"><polyline fill="none" stroke="#2f6fe4" stroke-width="1.6" points="' . esc_attr( trim( $pts ) ) . '"/></svg>';
	}
}
