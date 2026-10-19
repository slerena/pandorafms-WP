# Publicar «Pandora Cloud Monitoring» (WordPress.org + GitHub)

## Datos de la cuenta
- **WordPress.org usuario**: `articast` (ya puesto en `Contributors:`).
- **Email**: `wp-team@artica.es` (cuenta de WordPress.org; **no se guarda en el repo**).
- **Legales**: [info legal y licencias](https://pandorafms.com/en/legal-information-licenses/) · [privacidad](https://pandorafms.com/en/privacy-policy/).
- **Código**: https://github.com/slerena/pandorafms-WP

## Estructura del repositorio

Este repositorio **es** el plugin (raíz) y es la única fuente de verdad:

```
pandorafms-WP/
├── pandora-cloud-monitoring.php · readme.txt · uninstall.php
├── includes/ · assets/          ← código del plugin (lo que va a trunk/ y al ZIP)
├── wporg-assets/                ← assets del LISTADO (banner/iconos/screenshot → SVN assets/)
├── .github/workflows/           ← release.yml (ZIP en cada tag) + deploy.yml (SVN, opcional)
├── build.sh                     ← genera pandora-cloud-monitoring.zip para enviar a WP.org
├── deploy-svn.sh                ← publica trunk + tag + assets por SVN
├── .distignore                  ← qué NO va al SVN
└── WORDPRESS-ORG.md             ← este documento
```

## 1) Trabajar con el repositorio

```sh
git clone git@github.com:slerena/pandorafms-WP.git
cd pandorafms-WP
./build.sh                      # genera pandora-cloud-monitoring.zip
```

> Rama principal: **`master`**. El workflow `release.yml` publica el ZIP como *release*
> en cada tag (`git tag 1.0.11 && git push origin 1.0.11`).

## 1.bis) Validación antes de enviar (Plugin Check)

El formulario de envío pide marcar que se ha pasado **Plugin Check**. Procedimiento reproducible
(probado en mara, sin tocar producción):

```sh
# WordPress + SQLite + Plugin Check en un contenedor (sin base de datos externa)
docker run -d --name wpcheck ...   # ver /tmp/wpcheck (mara) — WP 7.1.3 + plugin-check 2.1
wp plugin check pandora-cloud-monitoring --format=table
```

Resultado con la **1.0.11**: `Success: Checks complete. No errors found.`

También se pasa **PHPCS con el estándar WordPress** completo (0 errores / 0 avisos):

```sh
composer require --dev wp-coding-standards/wpcs:^3.1 squizlabs/php_codesniffer:^3.9
./vendor/bin/phpcs --standard=WordPress --extensions=php --ignore=vendor/* .
```


## 2) Enviar a WordPress.org

1. Genera el ZIP con `./build.sh` (contiene `pandora-cloud-monitoring/`, sin `wporg-assets/`).
2. Súbelo en **https://wordpress.org/plugins/developers/add/** (con la cuenta `articast`).
3. Revisión **manual** (semanas). Responden por email; se corrige en el hilo y se reenvía si lo piden.

## 3) Publicar (una vez aprobado)

Manual por SVN:
```sh
export WPORG_USER=articast
./deploy-svn.sh 1.0.11
```
O automático con GitHub Actions: crea los secretos **`SVN_USERNAME`** (=`articast`) y **`SVN_PASSWORD`**
en el repo, y al empujar un **tag** (`1.0.11`) `.github/workflows/deploy.yml` publica en WordPress.org.

## Pendiente / a confirmar
- [ ] `Tested up to: 7.1` (última WP conocida). Actualizar cuando salga una nueva.
- [ ] Screenshot-1 es del panel (mockup); se pueden añadir más capturas reales.
- [ ] Nombre/slug definitivos: **Pandora Cloud Monitoring** / `pandora-cloud-monitoring`.
