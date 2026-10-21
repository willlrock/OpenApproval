#!/usr/bin/env bash
set -euo pipefail
umask 077

OPS_CONFIG=${OPS_CONFIG:-/etc/teg-approval-backup.env}
if [ -r "$OPS_CONFIG" ]; then
    set -a
    # This is a root-owned shell environment file, never a downloaded artifact.
    source "$OPS_CONFIG"
    set +a
fi
APP_DIR=${APP_DIR:-/var/www/teg-approval-bot}
BACKUP_ROOT=${BACKUP_ROOT:-/var/backups/teg-approval-bot}
OPS_LOCK=${OPS_LOCK:-/var/lock/teg-approval-bot-deploy.lock}
# Ubuntu aliases /var/lock to /run/lock; /proc reports the canonical FD path.
OPS_LOCK=$(realpath -m "$OPS_LOCK")
APP_USER=${APP_USER:-tegbot}
APP_GROUP=${APP_GROUP:-www-data}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-php8.3-fpm}
: "${PGDATABASE:?Configure PGDATABASE in the backup environment}"
: "${PGUSER:?Configure PGUSER and PGPASSFILE (or .pgpass)}"
export PGDATABASE PGUSER
APP_DIR=$(realpath "$APP_DIR")
[ "$APP_DIR" != / ] && [ -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/artisan" ]
[[ "$BACKUP_ROOT" = /* ]] && [ ! -L "$BACKUP_ROOT" ] || { echo 'Backup directory must be an absolute non-symlink path.' >&2; exit 1; }
BACKUP_ROOT=$(realpath -m "$BACKUP_ROOT")
case "$BACKUP_ROOT" in /|/var|/var/backups|/etc|/home|/tmp|"$APP_DIR"|"$APP_DIR/storage"|"$APP_DIR/storage/app")
    echo 'Refusing to use a system or application directory as backup root.' >&2; exit 1 ;;
esac
for keep in "${BACKUP_KEEP_DAILY:-7}" "${BACKUP_KEEP_DEPLOY:-3}"; do
    [[ "$keep" =~ ^[1-9][0-9]*$ ]] || { echo 'Invalid backup retention count.' >&2; exit 1; }
done
for tool in php supervisorctl systemctl psql pg_dump pg_restore tar rsync sha256sum flock; do command -v "$tool" >/dev/null; done
WORKER_GROUPS=('teg-queue-worker:*' 'teg-deadlines-worker:*')
if [ "${OPS_LOCK_INHERITED:-0}" = 1 ]; then
    [ "$(readlink /proc/self/fd/9)" = "$OPS_LOCK" ] || { echo 'Missing inherited deployment lock.' >&2; exit 1; }
else
    exec 9>"$OPS_LOCK"
fi
flock -n 9 || { echo 'Another deployment/backup/restore is running.' >&2; exit 1; }

check_backup_space() {
    local available files database reserve
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"
    available=$(df -PB1 "$BACKUP_ROOT" | awk 'NR==2 {print $4}')
    files=$(du -sb "$APP_DIR/storage/app/private" | awk '{print $1}')
    database=$(psql -At --set=ON_ERROR_STOP=1 -c 'SELECT pg_database_size(current_database())')
    [ -z "$(find "$APP_DIR/storage/app/private" ! -type d ! -type f -print -quit)" ] || { echo 'Private storage contains unsupported file types.' >&2; exit 1; }
    reserve=${BACKUP_MIN_FREE_MB:-256}
    [[ "$available" =~ ^[0-9]+$ && "$files" =~ ^[0-9]+$ && "$database" =~ ^[0-9]+$ && "$reserve" =~ ^[0-9]+$ ]]
    if (( available < files + database + reserve * 1024 * 1024 )); then
        echo 'Not enough disk space for a verified backup and operating reserve. Application was not paused.' >&2
        exit 1
    fi
}

app_php() {
    if [ "$(id -un)" = "$APP_USER" ]; then php "$@"; else runuser -u "$APP_USER" -- php "$@"; fi
}

pause_app() {
    [ ! -f "$APP_DIR/storage/framework/down" ] || { echo 'Application is already in maintenance; refusing to take ownership.' >&2; exit 1; }
    systemctl is-active --quiet "$PHP_FPM_SERVICE"
    case "$(systemctl show --property=KillSignal --value "$PHP_FPM_SERVICE")" in
        3|SIGQUIT) ;;
        *) echo 'PHP-FPM must stop gracefully with KillSignal=SIGQUIT to drain in-flight requests.' >&2; exit 1 ;;
    esac
    local status
    status=$(supervisorctl status "${WORKER_GROUPS[@]}")
    for group in "${WORKER_GROUPS[@]}"; do
        local prefix=${group%:*}
        awk -v prefix="$prefix:" '$1 ~ ("^" prefix) {found=1; if ($2 != "RUNNING") bad=1} END {exit (!found || bad)}' <<< "$status"
    done
    cd "$APP_DIR"
    app_php artisan down --retry=30
    trap 'result=$?; if [ "$result" -ne 0 ]; then
        if [ "${OPS_READ_ONLY_BACKUP:-0}" = 1 ]; then
            trap - EXIT
            if resume_app; then echo "Read-only backup failed; unchanged application resumed." >&2;
            else echo "Backup failed and services could not resume; application remains in maintenance." >&2; fi
        else echo "Operation failed; application remains in maintenance. Repair and verify DB/files before resuming workers and artisan up." >&2; fi
    fi' EXIT
    supervisorctl stop "${WORKER_GROUPS[@]}"
    # Maintenance blocks new writes; graceful FPM stop also drains requests that
    # started before maintenance. No HTTP request may write during dump + tar.
    systemctl stop "$PHP_FPM_SERVICE"
}

resume_app() {
    systemctl start "$PHP_FPM_SERVICE" || return 1
    systemctl is-active --quiet "$PHP_FPM_SERVICE" || return 1
    supervisorctl start "${WORKER_GROUPS[@]}" || return 1
    local status
    status=$(supervisorctl status "${WORKER_GROUPS[@]}") || return 1
    for group in "${WORKER_GROUPS[@]}"; do
        local prefix=${group%:*}
        awk -v prefix="$prefix:" '$1 ~ ("^" prefix) {found=1; if ($2 != "RUNNING") bad=1} END {exit (!found || bad)}' <<< "$status" || return 1
    done
    app_php artisan up || return 1
    trap - EXIT
}
