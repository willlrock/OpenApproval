#!/usr/bin/env bash
# Run as root with the exact CI-tested DEPLOY_SHA. Normal deployments stay online.
set -euo pipefail

APP_DIR="/var/www/teg-approval-bot"
BRANCH=${DEPLOY_BRANCH:-master}
TARGET_SHA=${DEPLOY_SHA:?DEPLOY_SHA must identify a commit with successful CI}
APP_USER=${APP_USER:-tegbot}
MAINTENANCE=${DEPLOY_MAINTENANCE:-0}
[[ "$MAINTENANCE" = 0 || "$MAINTENANCE" = 1 ]]
[[ "$TARGET_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'DEPLOY_SHA must be a full commit SHA.' >&2; exit 1; }
app_run() { if [ "$(id -un)" = "$APP_USER" ]; then "$@"; else runuser -u "$APP_USER" -- "$@"; fi; }

cd "$APP_DIR"
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR"
exec 9>/var/lock/teg-approval-bot-deploy.lock
flock -n 9 || { echo 'Another deployment/backup/restore is running.' >&2; exit 1; }
git fetch origin "$BRANCH"
if [ "$(git rev-parse "origin/$BRANCH")" != "$TARGET_SHA" ]; then
    echo 'A newer commit is pending; skip this older deployment.'
    exit 0
fi
for tool in python3 curl php composer npm supervisorctl systemctl rsync nginx; do command -v "$tool" >/dev/null; done
WORKER_GROUPS=('teg-queue-worker:*' 'teg-deadlines-worker:*')
worker_status() {
    local status result=0 group prefix
    local allow_stopped=${1:-$MAINTENANCE}
    status=$(supervisorctl status "${WORKER_GROUPS[@]}") || result=$?
    [ "$result" = 0 ] || { [ "$allow_stopped" = 1 ] && [ "$result" = 3 ]; } || return 1
    for group in "${WORKER_GROUPS[@]}"; do
        prefix=${group%:*}
        awk -v prefix="$prefix:" -v maintenance="$allow_stopped" '$1 ~ ("^" prefix) {found=1; if ($2 != "RUNNING" && !(maintenance == 1 && $2 == "STOPPED")) bad=1} END {exit (!found || bad)}' <<< "$status" || return 1
    done
    printf '%s\n' "$status"
}
WORKER_STATUS=$(worker_status)
PHP_FPM_SVC=${DEPLOY_PHP_FPM_SERVICE:-}
if [ -z "$PHP_FPM_SVC" ]; then
    mapfile -t FPM_SERVICES < <(systemctl list-units --type=service --all --no-legend | awk '/php[^ ]*-fpm.service/ {print $1}')
    [ "${#FPM_SERVICES[@]}" -eq 1 ] || { echo 'Set DEPLOY_PHP_FPM_SERVICE to the dedicated application service.' >&2; exit 1; }
    PHP_FPM_SVC=${FPM_SERVICES[0]}
fi
[ -n "$PHP_FPM_SVC" ] || { echo 'PHP-FPM service was not found.' >&2; exit 1; }
FPM_STATE=$(systemctl is-active "$PHP_FPM_SVC" || true)
[ "$FPM_STATE" = active ] || { [ "$MAINTENANCE" = 1 ] && [ "$FPM_STATE" = inactive ]; }
if [ "$MAINTENANCE" = 0 ] && [ -f storage/framework/down ]; then
    echo 'Existing maintenance requires an explicit DEPLOY_MAINTENANCE=1 recovery.' >&2
    exit 1
fi

PREP_ROOT=$(mktemp -d)
CANDIDATE=''
PREVIOUS=''
SWITCHED=0
OFFLINE_STARTED=0
APPLICATION_READY=0
STAGE='prepare production configuration'
on_exit() {
    result=$?
    if [ "$result" -ne 0 ]; then
        echo "Deployment failed during: $STAGE." >&2
        if [ "$OFFLINE_STARTED" = 1 ]; then
            echo 'Maintenance was NOT disabled. Repair and rerun with DEPLOY_MAINTENANCE=1.' >&2
        elif [ "$MAINTENANCE" = 1 ] && [ "$SWITCHED" = 1 ] && [ "$APPLICATION_READY" = 0 ]; then
            (cd "$CANDIDATE"; app_run php artisan down --retry=30) || true
            supervisorctl stop "${WORKER_GROUPS[@]}" || true
            systemctl stop "$PHP_FPM_SVC" || true
            echo 'Maintenance restored after failed health check. No incompatible code rollback was attempted.' >&2
        elif [ "$SWITCHED" = 1 ] && [ "$APPLICATION_READY" = 0 ]; then
            if python3 "$CANDIDATE/scripts/atomic-link.py" "$PREVIOUS" "$APP_DIR/current"; then
                systemctl reload "$PHP_FPM_SVC" || true
                (cd "$PREVIOUS"; app_run php artisan queue:restart) || true
                supervisorctl restart 'teg-deadlines-worker:*' || true
                echo 'Previous release restored. No database rollback was attempted.' >&2
            else
                echo 'CRITICAL: failed to restore previous release; inspect current immediately.' >&2
            fi
        elif [ "$APPLICATION_READY" = 1 ]; then
            echo 'New release is online; repair the failed backup service setup.' >&2
        else
            echo 'The live release was not interrupted.' >&2
        fi
    fi
    rm -rf -- "$PREP_ROOT"
    # Published releases may still serve requests or finishing jobs.
    if [ -n "$CANDIDATE" ] && [ "$SWITCHED" = 0 ]; then rm -rf -- "$CANDIDATE"; fi
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
mkdir -p "$PREP_ROOT/scripts" "$PREP_ROOT/ops"
for file in scripts/prepare-production.php scripts/operations-common.sh scripts/backup.sh scripts/online-backup.py; do
    git show "$TARGET_SHA:$file" > "$PREP_ROOT/$file"
done
export OPS_CONFIG=${OPS_CONFIG:-/etc/teg-approval-backup.env} OPS_LOCK_INHERITED=1
if systemctl is-active --quiet cloudflared.service; then export DEPLOY_LOCAL_PROXY=1; fi
BOT_ENABLED=$(php "$PREP_ROOT/scripts/prepare-production.php" "$APP_DIR" "$PHP_FPM_SVC" "${PRODUCTION_CONFIG_ROOT:-/etc}" "$APP_USER" www-data)
bash "$PREP_ROOT/scripts/backup.sh" --check-space

STAGE='build candidate'
BUILD_RESERVE_MB=${DEPLOY_BUILD_MIN_FREE_MB:-2048}
[[ "$BUILD_RESERVE_MB" =~ ^[0-9]+$ ]] && [ "$BUILD_RESERVE_MB" -ge 512 ] || { echo 'Invalid build disk reserve.' >&2; exit 1; }
BUILD_AVAILABLE=$(df -PB1 "$APP_DIR" | awk 'NR==2 {print $4}')
[[ "$BUILD_AVAILABLE" =~ ^[0-9]+$ ]] && (( BUILD_AVAILABLE >= BUILD_RESERVE_MB * 1024 * 1024 )) || {
    echo 'Not enough free space to build alongside the running site. Old release remains online.' >&2
    exit 1
}
install -d -o "$APP_USER" -g www-data -m 2750 "$APP_DIR/releases"
CANDIDATE=$(mktemp -d "$APP_DIR/releases/${TARGET_SHA:0:12}-XXXXXXXX")
git archive "$TARGET_SHA" | tar -x -C "$CANDIDATE"
mkdir -p "$CANDIDATE/storage/framework/"{cache/data,sessions,views} "$CANDIDATE/storage/logs" "$CANDIDATE/bootstrap/cache"
cp "$APP_DIR/.env" "$CANDIDATE/.env"
chmod 640 "$CANDIDATE/.env"
printf '%s\n' "$TARGET_SHA" > "$CANDIDATE/.release-sha"
chown -R "$APP_USER:www-data" "$CANDIDATE"
find "$CANDIDATE" -type d -exec chmod 2750 {} +
(
    cd "$CANDIDATE"
    app_run composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
    app_run npm ci --no-audit --no-fund
    app_run npm run build
)
test -s "$CANDIDATE/vendor/autoload.php"
test -s "$CANDIDATE/public/build/manifest.json"
chgrp -R www-data "$CANDIDATE/vendor" "$CANDIDATE/public/build"
chmod -R u=rwX,g=rX,o= "$CANDIDATE/vendor" "$CANDIDATE/public/build"
if [ -d "$APP_DIR/public/build/assets" ]; then
    rsync --archive --ignore-existing "$APP_DIR/public/build/assets/" "$CANDIDATE/public/build/assets/"
fi
rm -rf -- "$CANDIDATE/node_modules" "$CANDIDATE/storage"
rm "$CANDIDATE/.env"
ln -s "$APP_DIR/.env" "$CANDIDATE/.env"
ln -s "$APP_DIR/storage" "$CANDIDATE/storage"
ln -s "$APP_DIR/storage/app/public" "$CANDIDATE/public/storage"
cd "$CANDIDATE"
STAGE='validate candidate and migrations'
nginx -T > "$PREP_ROOT/nginx.conf" 2>/dev/null
python3 scripts/check-release-nginx.py "$APP_DIR" < "$PREP_ROOT/nginx.conf"
app_run php scripts/release-health.php
HEALTH_URL=$(app_run php scripts/release-health.php --url)
if [ "$MAINTENANCE" = 0 ]; then app_run php artisan deployment:migrate --check; fi
bash "$PREP_ROOT/scripts/backup.sh" --check-space

STAGE='create verified pre-deployment backup'
if [ "$MAINTENANCE" = 1 ]; then
    install -m 755 scripts/production-backup.sh "$APP_DIR/scripts/production-backup.sh"
    bash scripts/install-production-services.sh "$CANDIDATE" "$PHP_FPM_SVC"
    OFFLINE_STARTED=1
    (cd "$APP_DIR"; app_run php artisan down --retry=30)
    while read -r process; do
        [ -z "$process" ] || supervisorctl stop "$process"
    done < <(awk '$2 == "RUNNING" {print $1}' <<< "$WORKER_STATUS")
    systemctl stop "$PHP_FPM_SVC"
    bash "$PREP_ROOT/scripts/backup.sh" --during-deploy
    bash scripts/configure-private-storage.sh "$APP_DIR" "$PHP_FPM_SVC" "$CANDIDATE"
else
    bash "$PREP_ROOT/scripts/backup.sh" --online-deploy
fi
STAGE='migrate database'
if [ "$MAINTENANCE" = 1 ]; then
    app_run php artisan migrate --force
    app_run php artisan requests:privatize-files
    if ! app_run php artisan signatures:clean-legacy --apply; then
        echo 'WARNING: legacy signature cleanup retained originals; inspect its report.' >&2
    fi
else
    app_run php artisan deployment:migrate
fi
STAGE='cache candidate'
# Never clear the shared cache (live locks and queue restart signals).
app_run php artisan config:cache
app_run php artisan route:cache
app_run php artisan view:cache
app_run php artisan event:cache
if [ "$BOT_ENABLED" = 1 ]; then app_run php artisan telegram:webhook set; fi

STAGE='prepare atomic activation'
if [ -L "$APP_DIR/current" ]; then
    PREVIOUS=$(realpath "$APP_DIR/current")
    case "$PREVIOUS/" in "$APP_DIR/releases/"?*/) ;; *) echo 'Unexpected current release target.' >&2; exit 1 ;; esac
else
    [ ! -e "$APP_DIR/current" ] && [ ! -L "$APP_DIR/public" ] && [ ! -L "$APP_DIR/artisan" ]
    # Preserve the existing installation for the first rollback. Never reset
    # this source checkout: its original vendor also supports this legacy copy.
    PREVIOUS=$(mktemp -d "$APP_DIR/releases/legacy-XXXXXXXX")
    git -C "$APP_DIR" archive HEAD | tar -x -C "$PREVIOUS"
    # Preserve application hotfixes that are not part of the source commit.
    if [ -d "$APP_DIR/app" ]; then rsync --archive "$APP_DIR/app/" "$PREVIOUS/app/"; fi
    mkdir -p "$PREVIOUS/public" "$PREVIOUS/bootstrap/cache"
    rsync --archive "$APP_DIR/public/" "$PREVIOUS/public/"
    rm -rf -- "$PREVIOUS/storage"
    ln -s "$APP_DIR/vendor" "$PREVIOUS/vendor"
    ln -s "$APP_DIR/storage" "$PREVIOUS/storage"
    ln -s "$APP_DIR/.env" "$PREVIOUS/.env"
    git -C "$APP_DIR" rev-parse HEAD > "$PREVIOUS/.release-sha"
    chown -hR "$APP_USER:www-data" "$PREVIOUS"
    find "$PREVIOUS" -type d -exec chmod 2750 {} +
    # A subtree/empty archive must fail before public points at the legacy copy.
    test -s "$PREVIOUS/artisan"
    test -s "$PREVIOUS/bootstrap/app.php"
    test -s "$PREVIOUS/public/index.php"
    python3 scripts/atomic-link.py "$PREVIOUS" "$APP_DIR/current"
fi
# Keep the indirection through current. Nginx uses $realpath_root so every
# request boots one release, including its own vendor and bootstrap caches.
python3 scripts/atomic-link.py "$APP_DIR/current/public" "$APP_DIR/public"
python3 scripts/atomic-link.py "$APP_DIR/current/artisan" "$APP_DIR/artisan"
python3 scripts/atomic-link.py "$CANDIDATE" "$APP_DIR/current"
SWITCHED=1
STAGE='reload services'
if [ "$MAINTENANCE" = 1 ]; then
    systemctl start "$PHP_FPM_SVC"
    supervisorctl start "${WORKER_GROUPS[@]}"
    app_run php artisan up
    OFFLINE_STARTED=0
else
    systemctl reload "$PHP_FPM_SVC"
    app_run php artisan queue:restart
    supervisorctl restart 'teg-deadlines-worker:*'
fi
STAGE='verify published release'
systemctl is-active --quiet "$PHP_FPM_SVC"
for ((attempt=1; attempt<=10; attempt++)); do
    if WORKER_STATUS=$(worker_status 0); then break; fi
    [ "$attempt" -lt 10 ] || exit 1
    sleep 1
done
verify_http() {
    local response
    response=$(curl --fail --silent --show-error --connect-timeout 5 --max-time 15 \
        -H 'Cache-Control: no-cache' "$HEALTH_URL?release=$TARGET_SHA") || return 1
    printf '%s' "$response" | app_run php scripts/release-health.php --response "$TARGET_SHA"
}
for ((attempt=1; attempt<=5; attempt++)); do
    if verify_http; then break; fi
    [ "$attempt" -lt 5 ] || exit 1
    sleep 1
done
APPLICATION_READY=1
STAGE='enable daily online backups'
install -m 755 scripts/production-backup.sh "$APP_DIR/scripts/production-backup.sh"
bash scripts/install-production-services.sh "$CANDIDATE" "$PHP_FPM_SVC"
flock -u 9
systemctl enable --now teg-approval-backup.timer
systemctl is-active --quiet teg-approval-backup.timer
echo "Deploy complete: $TARGET_SHA (maintenance=$MAINTENANCE)."
