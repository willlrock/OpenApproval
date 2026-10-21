#!/usr/bin/env bash
# Consistent native-server snapshot: PostgreSQL, private files, and recovery key.
set -euo pipefail
source "$(dirname "$0")/operations-common.sh"
mode=${1:---daily}
case "$mode" in --daily|--online-deploy|--during-deploy|--check-space) ;; *) echo 'Unknown backup mode.' >&2; exit 2 ;; esac
check_backup_space
[ "$mode" != --check-space ] || exit 0
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
BACKUP_ROOT=$(realpath "$BACKUP_ROOT")
snapshot=$(mktemp -d "$BACKUP_ROOT/.incomplete-XXXXXXXX")
# Reuse only immutable backup files, never hardlink live application files.
# Changed files get new inodes; --inplace must never be used here.
previous=''
while IFS= read -r directory; do
    [ ! -L "$directory" ] && [[ "${directory##*/}" =~ ^[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]+$ ]] || continue
    [ -f "$directory/backup-format.txt" ] && [ "$(cat "$directory/backup-format.txt")" = files-v1 ] || continue
    [ -d "$directory/private" ] && [ ! -L "$directory/private" ] || continue
    (cd "$directory"; sha256sum --check --status SHA256SUMS) || continue
    previous="$directory/private"
    break
done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -print | sort -r)
if [ "$mode" = --during-deploy ]; then
    [ "${OPS_LOCK_INHERITED:-0}" = 1 ] && [ -f "$APP_DIR/storage/framework/down" ]
    ! systemctl is-active --quiet "$PHP_FPM_SERVICE"
    status=$(supervisorctl status "${WORKER_GROUPS[@]}") || [ "$?" = 3 ]
    for group in "${WORKER_GROUPS[@]}"; do
        prefix=${group%:*}
        awk -v prefix="$prefix:" '$1 ~ ("^" prefix) {found=1; if ($2 != "STOPPED") bad=1} END {exit (!found || bad)}' <<< "$status"
    done
fi
link_args=()
[ -z "$previous" ] || link_args+=("--link-dest=$previous")
if [ "$mode" = --during-deploy ]; then
    pg_dump --format=custom --no-owner --no-privileges --file="$snapshot/database.dump"
    rsync --archive --checksum "${link_args[@]}" "$APP_DIR/storage/app/private/" "$snapshot/private/"
else
    attempts=${BACKUP_ONLINE_ATTEMPTS:-3}
    [[ "$attempts" =~ ^[1-9][0-9]*$ ]] && [ "$attempts" -le 10 ] || { echo 'Invalid online backup attempt count.' >&2; exit 2; }
    for ((attempt=1; attempt<=attempts; attempt++)); do
        if python3 "$(dirname "$0")/online-backup.py" "$APP_DIR/storage/app/private" "$snapshot" "$previous"; then break; else result=$?; fi
        if [ "$result" -ne 75 ] || [ "$attempt" -eq "$attempts" ]; then
            echo 'No consistent snapshot was published. The site was not paused.' >&2
            exit 1
        fi
        # Retry in a fresh directory: files from a rejected attempt cannot leak
        # into a successful snapshot. Never modify reused backup hardlinks.
        rm -rf -- "$snapshot"
        snapshot=$(mktemp -d "$BACKUP_ROOT/.incomplete-XXXXXXXX")
    done
fi
cp "$APP_DIR/.env" "$snapshot/environment.env"
if [ -f "$APP_DIR/current/.release-sha" ]; then
    cat "$APP_DIR/current/.release-sha" > "$snapshot/revision.txt"
else
    git -c safe.directory="$APP_DIR" -C "$APP_DIR" rev-parse HEAD > "$snapshot/revision.txt"
fi
if [ "$mode" = --daily ]; then echo daily > "$snapshot/backup-kind.txt"; else echo deploy > "$snapshot/backup-kind.txt"; fi
echo files-v1 > "$snapshot/backup-format.txt"
(cd "$snapshot"; sha256sum database.dump environment.env revision.txt backup-kind.txt backup-format.txt > SHA256SUMS; find private -type f -print0 | sort -z | xargs -0 -r sha256sum >> SHA256SUMS; sha256sum --check --status SHA256SUMS)
target="$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)-${snapshot##*-}"
mv "$snapshot" "$target"

# Delete only older verified snapshots owned by this backup layout. Keep daily
# history independent of the more frequent pre-deployment recovery snapshots.
for kind in daily deploy; do
    if [ "$kind" = daily ]; then keep=${BACKUP_KEEP_DAILY:-7}; else keep=${BACKUP_KEEP_DEPLOY:-3}; fi
    [[ "$keep" =~ ^[1-9][0-9]*$ ]] || { echo 'Invalid backup retention count.' >&2; exit 1; }
    snapshots=()
    if [ "$(cat "$target/backup-kind.txt")" = "$kind" ]; then snapshots+=("$target"); fi
    while IFS= read -r directory; do
        [ "$directory" != "$target" ] || continue
        [ ! -L "$directory" ] && [[ "${directory##*/}" =~ ^[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]+$ ]] || continue
        [ -f "$directory/backup-kind.txt" ] && [ "$(cat "$directory/backup-kind.txt")" = "$kind" ] || continue
        (cd "$directory"; sha256sum --check --status SHA256SUMS) || continue
        snapshots+=("$directory")
    done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -print | sort -r)
    for ((index=keep; index<${#snapshots[@]}; index++)); do
        rm -rf -- "${snapshots[index]}"
    done
done
echo "Backup completed: $target"
