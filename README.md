# Pandora Cloud Monitoring (WordPress plugin)

Monitoriza tu WordPress con **Pandora Cloud**: versiones, actualizaciones, rendimiento, contenido,
seguridad y SEO/GEO. Funciona **en local** (histórico y gráficas dentro del propio WordPress) y,
opcionalmente, se conecta a Pandora Cloud para **monitorización independiente, avisos y ver varios
WordPress en una sola pantalla**.

- Descarga: https://wordpress.org/plugins/pandora-cloud-monitoring/
- Web: https://pandorafms.com/
- Privacidad: https://pandorafms.com/en/privacy-policy/
- Licencia: GPL-2.0-or-later

## Estructura

```
pandora-cloud-monitoring.php   # cabecera y arranque
includes/
  class-pcm-metrics.php        # catálogo de métricas
  class-pcm-store.php          # ajustes, histórico local, cola y eventos de plugins
  class-pcm-client.php         # cliente de la API (alta del agente + envío)
  class-pcm-collector.php      # recolección de métricas + cron
  class-pcm-admin.php          # pantalla de administración (qué monitorizar + gráficas)
assets/admin.css               # estilos de la pantalla de administración
uninstall.php                  # limpieza al desinstalar
readme.txt                     # ficha para el directorio de WordPress.org
wporg-assets/                  # banner/iconos/screenshots del listado (SVN assets/, no dentro del plugin)
.github/workflows/             # release.yml (ZIP por tag) + deploy.yml (SVN, opcional)
```

## Cómo funciona

1. **Conectar** (menú *Pandora Cloud*): email + contraseña de la cuenta. El plugin resuelve el nodo
   con el **Cloud Master**, obtiene un **token de agente** (ligado solo a este WordPress) y
   **descarta la contraseña**.
2. **Qué monitorizar**: casillas por métrica (se guardan en `pcm_settings`).
3. Cada **5 minutos** (WP-Cron): recolecta → guarda en la tabla local `{prefix}pcm_history` →
   `PUT /api/v1/agent/data` (con **cola de reintentos** si falla) → `POST /api/v1/agent/heartbeat`.
4. La pantalla muestra **minigráficas locales** del histórico (no consulta la API) y los
   **cambios de plugins recientes** (quién y cuándo).

## Desarrollo

```sh
# Empaquetar para instalar a mano:
./build.sh                       # genera pandora-cloud-monitoring.zip

# Publicar en el directorio de WordPress.org (SVN):
WPORG_USER=articast ./deploy-svn.sh 1.0.11
```

## Publicar (CI)

Con los secretos **`SVN_USERNAME`** y **`SVN_PASSWORD`** en el repositorio, al empujar un tag
(`1.0.11`) la acción publica automáticamente en WordPress.org. El workflow `release.yml` publica
además el ZIP como *release* de GitHub.

## Requisitos

- WordPress 6.2+ y PHP 7.4+.
- Opcional: una cuenta en Pandora Cloud (el plugin funciona sin ella; el histórico y las gráficas
  son locales).
