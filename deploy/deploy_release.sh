#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="${APP_ROOT:-${1:-/var/www/mak-galerie-admin}}"
RELEASE_NAME="${RELEASE_SHA:-${2:-$(date +%Y%m%d%H%M%S)}}"
ARCHIVE_PATH="${RELEASE_ARCHIVE:-${3:-release.tar.gz}}"
[[ "$APP_ROOT" = /* && "$APP_ROOT" != "/" && "$RELEASE_NAME" =~ ^[a-zA-Z0-9._-]+$ && "$RELEASE_NAME" != "." && "$RELEASE_NAME" != ".." ]]
test -s "$ARCHIVE_PATH"
if [[ -e "$APP_ROOT/current" && ! -L "$APP_ROOT/current" ]]; then
  echo "Refusing to replace non-symlink current directory" >&2; exit 1
fi
mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared/storage/data" "$APP_ROOT/shared/storage/uploads" "$APP_ROOT/shared/storage/backups" "$APP_ROOT/shared/storage/logs"
release="$APP_ROOT/releases/$RELEASE_NAME"
if [[ -d "$release" ]]; then
  # A retry must not modify an existing release, especially the active one.
  test "$(readlink -f "$APP_ROOT/current" || true)" = "$release" || { echo "Release already exists but is inactive" >&2; exit 1; }
  echo "Release already active: $RELEASE_NAME"; exit 0
fi
stage="$(mktemp -d "$APP_ROOT/releases/.stage.XXXXXX")"
link="$APP_ROOT/.current-$RELEASE_NAME-$$"
trap 'rm -rf -- "$stage"; rm -f -- "$link"' EXIT
chmod 755 "$stage"
tar -xzf "$ARCHIVE_PATH" -C "$stage"
test -f "$stage/public/index.php"
if [[ -f "$APP_ROOT/current/config.local.php" ]]; then
  cp -p "$APP_ROOT/current/config.local.php" "$stage/config.local.php"
fi
rm -rf -- "$stage/storage"
ln -s "$APP_ROOT/shared/storage" "$stage/storage"
find "$stage/app" "$stage/public" -name '*.php' -print0 | xargs -0 -n1 php -l
mv -- "$stage" "$release"
ln -s "$release" "$link"
mv -Tf -- "$link" "$APP_ROOT/current"
echo "Release active : $RELEASE_NAME"
