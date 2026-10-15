#!/usr/bin/env bash
# Empaqueta el plugin como ZIP listo para instalar en WordPress.
set -euo pipefail
cd "$(dirname "$0")"
rm -f pandora-cloud-monitoring.zip
zip -rq pandora-cloud-monitoring.zip pandora-cloud-monitoring \
  -x '*.DS_Store' -x '*/.git/*'
echo "Generado: $(pwd)/pandora-cloud-monitoring.zip"
