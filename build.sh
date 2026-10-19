#!/usr/bin/env bash
# Empaqueta el plugin como ZIP listo para instalar en WordPress.
# El repositorio ES el plugin (raíz), así que el ZIP lleva la carpeta
# pandora-cloud-monitoring/ con los ficheros del plugin dentro.
set -euo pipefail
cd "$(dirname "$0")"

SLUG="pandora-cloud-monitoring"
rm -f "${SLUG}.zip"
rm -rf build
mkdir -p "build/${SLUG}"

cp -r "${SLUG}.php" includes assets uninstall.php readme.txt "build/${SLUG}/"
( cd build && zip -rq "../${SLUG}.zip" "${SLUG}" -x '*.DS_Store' )
rm -rf build

echo "Generado: $(pwd)/${SLUG}.zip"
