# Pandora Cloud Monitoring (WordPress plugin)

Monitoriza tu WordPress con **Pandora Cloud**: versiones, actualizaciones, rendimiento, contenido y
seguridad. Funciona **en local** (histórico y gráficas dentro del propio WordPress) y, opcionalmente,
se conecta a Pandora Cloud para **monitorización independiente, avisos y ver varios WordPress en una
sola pantalla**.

- Descarga: https://wordpress.org/plugins/pandora-cloud-monitoring/
- Web: https://pandorafms.com/
- Privacidad: https://pandorafms.com/en/privacy-policy/
- Licencia: GPL-2.0-or-later

## Estructura

```
pandora-cloud-monitoring.php   # cabecera y arranque
includes/                      # métricas, almacén local, cliente API, recolector y administración
assets/admin.css               # estilos de la pantalla de administración
uninstall.php                  # limpieza al desinstalar
readme.txt                     # ficha para el directorio de WordPress.org
wporg-assets/                  # banner/iconos/screenshots del listado (SVN assets/, no dentro del plugin)
.github/workflows/deploy.yml   # publica en WordPress.org al crear un tag
```

## Desarrollo

```sh
# Empaquetar para instalar a mano:
./build.sh                       # genera pandora-cloud-monitoring.zip

# Publicar en el directorio de WordPress.org (SVN):
WPORG_USER=articast ./deploy-svn.sh 1.0.4
```

## Publicar (CI)

Con los secretos **`SVN_USERNAME`** y **`SVN_PASSWORD`** en el repositorio, al empujar un tag
(`1.0.4`) la acción publica automáticamente en WordPress.org.
