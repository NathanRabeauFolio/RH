#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/mak-galerie-admin"
export DEBIAN_FRONTEND=noninteractive

apt-get update -y
apt-get install -y poppler-utils ghostscript

mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared/storage/data" "$APP_ROOT/shared/storage/uploads" "$APP_ROOT/shared/storage/backups" "$APP_ROOT/shared/storage/logs"
chown -R "$USER":"$USER" "$APP_ROOT"

echo "Lightsail bootstrap terminé : $APP_ROOT"
echo "Dépendances installées : poppler-utils, ghostscript"
