#!/usr/bin/env bash
# Publica el plugin en el directorio de WordPress.org (SVN).
#
# Requisitos: svn instalado y ser colaborador del plugin en WordPress.org.
# Uso:  WPORG_USER=tu_usuario ./deploy-svn.sh 1.0.4
#
# Sube el código a trunk/ y corta tags/<versión>/, además de los assets del
# listado (banner/iconos/screenshots) a assets/ (fuera de trunk).
set -euo pipefail
cd "$(dirname "$0")"

VERSION="${1:?uso: WPORG_USER=tu_usuario ./deploy-svn.sh <version>}"
SLUG="pandora-cloud-monitoring"
USER="${WPORG_USER:?export WPORG_USER=tu_usuario_de_wordpress.org}"
SRC="$(pwd)/${SLUG}"
ASSETS="$(pwd)/wporg-assets"

command -v svn >/dev/null || { echo "Falta svn (svn --version)"; exit 1; }
[ -d "$SRC" ] || { echo "No encuentro $SRC"; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "→ Checkout de https://plugins.svn.wordpress.org/${SLUG}/"
svn checkout "https://plugins.svn.wordpress.org/${SLUG}/" "$WORK"

echo "→ Copiando código a trunk/"
rsync -a --delete --exclude '.git' --exclude 'wporg-assets' "$SRC/" "$WORK/trunk/"

if [ -d "$ASSETS" ]; then
  mkdir -p "$WORK/assets"
  cp "$ASSETS"/banner-*.png "$ASSETS"/icon-*.png "$ASSETS"/screenshot-*.png "$WORK/assets/" 2>/dev/null || true
fi

cd "$WORK"
svn add trunk/* --force >/dev/null 2>&1 || true
[ -d assets ] && svn add assets/* --force >/dev/null 2>&1 || true
svn status | grep -E '^[?AD]' || true

echo "→ Commit de trunk/ assets/"
svn ci -m "Pandora Cloud Monitoring ${VERSION}" --username "$USER"

echo "→ Tag ${VERSION}"
svn cp trunk "tags/${VERSION}"
svn ci -m "Tag ${VERSION}" --username "$USER"

echo "✔ Publicado ${VERSION} (trunk + tag + assets)"
