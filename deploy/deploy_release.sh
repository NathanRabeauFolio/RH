#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/mak-galerie-admin}"
RELEASE_NAME="${2:-$(date +%Y%m%d%H%M%S)}"
ARCHIVE_PATH="${3:-release.tar.gz}"

mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared/storage/data" "$APP_ROOT/shared/storage/uploads"
mkdir -p "$APP_ROOT/releases/$RELEASE_NAME"
tar -xzf "$ARCHIVE_PATH" -C "$APP_ROOT/releases/$RELEASE_NAME"
rm -rf "$APP_ROOT/releases/$RELEASE_NAME/storage"
ln -sfn "$APP_ROOT/shared/storage" "$APP_ROOT/releases/$RELEASE_NAME/storage"
ln -sfn "$APP_ROOT/releases/$RELEASE_NAME" "$APP_ROOT/current"

echo "Release active : $RELEASE_NAME"
