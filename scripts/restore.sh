#!/usr/bin/env bash
# Replaces the configured database. Keep the target APP_KEY from this snapshot.
set -euo pipefail
[ "$#" -eq 2 ] && [ "$2" = --confirm-replace ] || { echo 'Usage: restore.sh SNAPSHOT_DIR --confirm-replace' >&2; exit 2; }
snapshot=$(realpath "$1")
source "$(dirname "$0")/operations-common.sh"
backup_root=$(realpath "$BACKUP_ROOT")
case "$snapshot/" in "$backup_root/"?*/) ;; *) echo 'Snapshot must be inside BACKUP_ROOT.' >&2; exit 2 ;; esac
(cd "$snapshot"; sha256sum --check SHA256SUMS)
format=tar-v1
if [ -f "$snapshot/backup-format.txt" ]; then format=$(cat "$snapshot/backup-format.txt"); fi
case "$format" in
    files-v1)
        [ -d "$snapshot/private" ] && [ ! -L "$snapshot/private" ]
        [ -z "$(find "$snapshot/private" ! -type d ! -type f -print -quit)" ] || { echo 'Snapshot contains unsupported file types.' >&2; exit 2; }
        ;;
    tar-v1) tar -tzf "$snapshot/private.tar.gz" | awk '(/^\// || /(^|\/)\.\.(\/|$)/ || $0 !~ /^private(\/|$)/) {bad=1} END {exit bad}' ;;
    *) echo 'Unsupported backup format.' >&2; exit 2 ;;
esac
backup_key=$(sed -n 's/^APP_KEY=//p' "$snapshot/environment.env")
target_key=$(sed -n 's/^APP_KEY=//p' "$APP_DIR/.env")
[ -n "$backup_key" ] && [ "$backup_key" = "$target_key" ] || { echo 'Target APP_KEY must match environment.env from the snapshot; it is never overwritten automatically.' >&2; exit 2; }
pause_app
staging=$(mktemp -d "$APP_DIR/storage/app/.restore-XXXXXXXX")
if [ "$format" = files-v1 ]; then
    # Copy bytes, not links to backup inodes: uploads cannot change history.
    cp -a --reflink=auto "$snapshot/private" "$staging/private"
else
    tar -xzf "$snapshot/private.tar.gz" --no-same-owner -C "$staging"
fi
pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error --single-transaction --dbname="$PGDATABASE" "$snapshot/database.dump"
previous="$APP_DIR/storage/app/private.pre-restore-$(date -u +%Y%m%dT%H%M%SZ)-$$"
mv "$APP_DIR/storage/app/private" "$previous"
mv "$staging/private" "$APP_DIR/storage/app/private"
rmdir "$staging"
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR/storage/app/private"
app_php artisan optimize:clear
app_php artisan config:cache
resume_app
echo "Restore completed. Previous private files retained at $previous"
