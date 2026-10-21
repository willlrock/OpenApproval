#!/usr/bin/env bash
# Real file layout, atomic links and online snapshot guard; isolated service doubles.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/../.." && pwd)
bash "$REPO/tests/Deployment/github-deploy-test.sh"
TEST_ROOT=$(mktemp -d)
export REPO TEST_ROOT APP_USER="$(id -un)" REAL_USER="$(id -un)"
export REAL_INSTALL="$(command -v install)"
export DEPLOY_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
trap 'result=$?; if [ "$result" -ne 0 ] && [ -f "${CASE_DIR:-}/result" ]; then cat "$CASE_DIR/result"; fi; rm -rf -- "$TEST_ROOT"' EXIT
mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/candidate/scripts" "$TEST_ROOT/candidate/ops" "$TEST_ROOT/legacy/public" "$TEST_ROOT/legacy/bootstrap"
printf candidate-artisan > "$TEST_ROOT/candidate/artisan"
printf legacy-artisan > "$TEST_ROOT/legacy/artisan"
printf legacy-bootstrap > "$TEST_ROOT/legacy/bootstrap/app.php"
printf legacy-index > "$TEST_ROOT/legacy/public/index.php"
for file in atomic-link.py release-health.php check-release-nginx.py backup.sh online-backup.py operations-common.sh install-production-services.sh production-backup.sh; do
    cp "$REPO/scripts/$file" "$TEST_ROOT/candidate/scripts/$file"
done
cp "$REPO/ops/teg-approval-backup."{service,timer} "$TEST_ROOT/candidate/ops/"
printf '#!/usr/bin/env bash\nexit 0\n' > "$TEST_ROOT/candidate/scripts/configure-private-storage.sh"
cat > "$TEST_ROOT/bin/stub" <<'STUB'
#!/usr/bin/env bash
set -eu
name=$(basename "$0")
if [ "$name" = git ] && [ "${1:-}" = -C ]; then cd "$2"; shift 2; fi
call="$name $*"
printf '%s\n' "$call" >> "$CASE_DIR/calls"
[ "$call" != "${FAIL_AT:-}" ] || exit 7
case "$call" in
    'id -u') echo 0 ;;
    'id -un') echo "$REAL_USER" ;;
    runuser\ -u\ *\ --\ *) shift 3; exec "$@" ;;
    'git rev-parse origin/master') echo "$DEPLOY_SHA" ;;
    'git rev-parse HEAD') echo bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ;;
    'git archive HEAD')
        test "$PWD" = "$APP_DIR"
        if [ "${EMPTY_LEGACY_ARCHIVE:-0}" = 1 ]; then tar -c --files-from /dev/null; else tar -c -C "$TEST_ROOT/legacy" .; fi ;;
    'git archive '*) tar -c -C "$TEST_ROOT/candidate" . ;;
    'git show '*) cat "$REPO/${2#*:}" ;;
    php\ *prepare-production.php*) [ "${FAIL_PREP:-0}" = 0 ]; echo 1 ;;
    'php scripts/release-health.php --url') echo https://fixture.test/health/ready ;;
    'php scripts/release-health.php --response '*)
        python3 -c 'import json,sys; body=json.load(sys.stdin); sys.exit(0 if body.get("status")=="ok" and body.get("release")==sys.argv[1] else 1)' "$3" ;;
    'php artisan down --retry=30') touch "$APP_DIR/storage/framework/down" ;;
    'php artisan up') rm "$APP_DIR/storage/framework/down" ;;
    'composer install '*|'npm ci '*|'npm run build')
        # Build only the exported candidate; never change the original vendor.
        test "$PWD" != "$APP_DIR"
        test "$(cat artisan)" = candidate-artisan
        test "$(cat "$APP_DIR/vendor/autoload.php")" = legacy-vendor
        if [ "$name" = composer ]; then mkdir vendor; printf prepared-vendor > vendor/autoload.php; fi
        if [ "$call" = 'npm run build' ]; then
            mkdir -p public/build/assets
            printf prepared-manifest > public/build/manifest.json
            printf new-js > public/build/assets/new.js
            touch "$CASE_DIR/built"
            if [ "${MISSING_ARTIFACT:-0}" = 1 ]; then rm public/build/manifest.json; fi
        fi ;;
    'psql -At --set=ON_ERROR_STOP=1 -c SELECT pg_database_size(current_database())') echo 10 ;;
    pg_dump*)
        [ "${FAIL_DUMP:-0}" = 0 ] || exit 7
        for value in "$@"; do case "$value" in --file=*) printf dump > "${value#--file=}";; esac; done ;;
    'df -PB1 '*)
        available=${SPACE_AVAILABLE:-10000000000}
        if [ -f "$CASE_DIR/built" ]; then available=${SPACE_AFTER_BUILD:-$available}; fi
        printf 'Filesystem Size Used Available Use%% Mounted\nfake 1000000000 0 %s 0%% /\n' "$available" ;;
    'systemctl list-units --type=service --all --no-legend')
        echo 'php8.3-fpm.service loaded active running PHP-FPM'
        if [ "${LARGE_SERVICE_LIST:-0}" = 1 ]; then for ((i=0;i<10000;i++)); do echo "fixture-$i.service loaded active running Fixture"; done; fi ;;
    'systemctl is-active --quiet cloudflared.service') exit 1 ;;
    'systemctl is-active php8.3-fpm.service') cat "$CASE_DIR/fpm" ;;
    'systemctl is-active --quiet php8.3-fpm.service') [ "$(cat "$CASE_DIR/fpm")" = active ] ;;
    'systemctl show --property=KillSignal --value php8.3-fpm.service') echo SIGQUIT ;;
    'systemctl stop php8.3-fpm.service') echo inactive > "$CASE_DIR/fpm" ;;
    'systemctl start php8.3-fpm.service') echo active > "$CASE_DIR/fpm" ;;
    supervisorctl\ stop*) echo STOPPED > "$CASE_DIR/workers" ;;
    supervisorctl\ start*) echo RUNNING > "$CASE_DIR/workers" ;;
    supervisorctl\ status*)
        state=$(cat "$CASE_DIR/workers")
        echo "teg-queue-worker:teg-queue-worker_00 $state"
        if [ "${MISSING_WORKER:-0}" = 0 ]; then echo "teg-deadlines-worker:teg-deadlines-worker_00 $state"; fi
        [ "$state" = RUNNING ] || exit 3 ;;
    curl\ *)
        revision=$(cat "$APP_DIR/current/.release-sha")
        if [ "${FAIL_HEALTH:-0}" = 1 ]; then revision=wrong; fi
        printf '{"status":"ok","release":"%s"}' "$revision" ;;
    'nginx -T')
        if [ "${BAD_NGINX:-0}" = 1 ]; then filename='$document_root$fastcgi_script_name'; else filename='$realpath_root$fastcgi_script_name'; fi
        printf 'server { root %s/public; location ~ \\.php$ { fastcgi_param SCRIPT_FILENAME %s; } }' "$APP_DIR" "$filename" ;;
    install\ *)
        args=()
        while [ "$#" -gt 0 ]; do case "$1" in -o|-g) shift 2;; *) args+=("$1"); shift;; esac; done
        exec "$REAL_INSTALL" "${args[@]}" ;;
esac
STUB
for command in git php composer npm supervisorctl systemctl flock runuser id psql pg_dump df chown chgrp install curl sleep nginx; do
    cp "$TEST_ROOT/bin/stub" "$TEST_ROOT/bin/$command"
    chmod +x "$TEST_ROOT/bin/$command"
done
export PATH="$TEST_ROOT/bin:$(dirname "$BASH"):$PATH"
reset_case() {
    CASE_DIR=$(mktemp -d "$TEST_ROOT/case-XXXXXXXX")
    export CASE_DIR APP_DIR="$CASE_DIR/app" SYSTEMD_ROOT="$CASE_DIR/systemd" OPS_CONFIG="$CASE_DIR/ops.env"
    mkdir -p "$APP_DIR/scripts" "$APP_DIR/vendor" "$APP_DIR/app/Telegram" "$APP_DIR/public/build/assets" "$APP_DIR/storage/app/private" "$APP_DIR/storage/app/public" "$APP_DIR/storage/framework" "$APP_DIR/storage/logs" "$SYSTEMD_ROOT" "$CASE_DIR/etc"
    export PRODUCTION_CONFIG_ROOT="$CASE_DIR/etc"
    printf legacy-artisan > "$APP_DIR/artisan"
    printf legacy-vendor > "$APP_DIR/vendor/autoload.php"
    printf server-hotfix > "$APP_DIR/app/Telegram/ServerHotfix.php"
    printf legacy-manifest > "$APP_DIR/public/build/manifest.json"
    printf old-js > "$APP_DIR/public/build/assets/old.js"
    printf original-file > "$APP_DIR/storage/app/private/file"
    printf 'APP_KEY=fixture\n' > "$APP_DIR/.env"
    echo RUNNING > "$CASE_DIR/workers"
    echo active > "$CASE_DIR/fpm"
    : > "$CASE_DIR/calls"
    cat > "$OPS_CONFIG" <<CONFIG
APP_DIR="$APP_DIR"
BACKUP_ROOT="$CASE_DIR/backups"
APP_USER="$APP_USER"
PHP_FPM_SERVICE=php8.3-fpm.service
PGDATABASE=fixture
PGUSER=fixture
OPS_LOCK="$CASE_DIR/deploy.lock"
CONFIG
    sed -e "s|APP_DIR=\"/var/www/teg-approval-bot\"|APP_DIR=\"$APP_DIR\"|" -e "s|/var/lock/teg-approval-bot-deploy.lock|$CASE_DIR/deploy.lock|" "$REPO/deploy.sh" > "$CASE_DIR/deploy.sh"
    unset FAIL_AT FAIL_PREP MISSING_ARTIFACT FAIL_DUMP SPACE_AVAILABLE SPACE_AFTER_BUILD MISSING_WORKER LARGE_SERVICE_LIST FAIL_HEALTH DEPLOY_MAINTENANCE BAD_NGINX EMPTY_LEGACY_ARCHIVE
}
run_deploy() { bash "$CASE_DIR/deploy.sh" > "$CASE_DIR/result" 2>&1; }
assert_online() {
    test ! -f "$APP_DIR/storage/framework/down"
    test "$(cat "$CASE_DIR/fpm")" = active
    test "$(cat "$CASE_DIR/workers")" = RUNNING
    ! grep -Eq '^php artisan down |^systemctl (stop|start) php|^supervisorctl stop |^git reset ' "$CASE_DIR/calls"
}
passed=0
reset_case
run_deploy
assert_online
test "$(cat "$APP_DIR/current/.release-sha")" = "$DEPLOY_SHA"
test "$(cat "$APP_DIR/public/build/manifest.json")" = prepared-manifest
test "$(cat "$APP_DIR/public/build/assets/old.js")" = old-js
test "$(cat "$APP_DIR/.public.legacy/build/manifest.json")" = legacy-manifest
test "$(cat "$APP_DIR/.artisan.legacy")" = legacy-artisan
legacy=$(find "$APP_DIR/releases" -maxdepth 1 -name 'legacy-*' -print -quit)
test "$(cat "$legacy/bootstrap/app.php")" = legacy-bootstrap
test "$(cat "$legacy/app/Telegram/ServerHotfix.php")" = server-hotfix
first=$(realpath "$APP_DIR/current")
test "$(realpath "$first/storage")" = "$APP_DIR/storage"
test -L "$first/.env"
test -f "$APP_DIR/scripts/production-backup.sh"
awk '/^npm run build$/ {built=1} /^pg_dump / {if (!built) exit 1; dump=1} /^php artisan deployment:migrate$/ {if (!dump) exit 1; migrated=1} END {if (!migrated) exit 1}' "$CASE_DIR/calls"
run_deploy
assert_online
test "$(realpath "$APP_DIR/current")" != "$first"
find "$CASE_DIR/backups" -name revision.txt -exec cat {} \; | grep -q "$DEPLOY_SHA"
bash "$APP_DIR/scripts/production-backup.sh" --daily > "$CASE_DIR/result" 2>&1
assert_online
passed=$((passed+1))
reset_case
export EMPTY_LEGACY_ARCHIVE=1
if run_deploy; then echo 'Empty legacy archive accepted' >&2; exit 1; fi
assert_online
test ! -e "$APP_DIR/current"
test ! -L "$APP_DIR/public"
test "$(cat "$APP_DIR/artisan")" = legacy-artisan
passed=$((passed+1))
for failure in 'git archive aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    'composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist' \
    'npm ci --no-audit --no-fund' 'npm run build' \
    'php scripts/release-health.php' 'php artisan deployment:migrate --check' \
    'php artisan deployment:migrate' 'php artisan route:cache' \
    'php artisan telegram:webhook set' 'flock -n 9'; do
    reset_case
    export FAIL_AT="$failure"
    if run_deploy; then echo "Expected failure: $failure" >&2; exit 1; fi
    assert_online
    test ! -e "$APP_DIR/current"
    test -z "$(find "$APP_DIR/releases" -maxdepth 1 -name 'aaaaaaaaaaaa-*' 2>/dev/null)"
    passed=$((passed+1))
done
for variable in FAIL_PREP MISSING_ARTIFACT FAIL_DUMP MISSING_WORKER BAD_NGINX; do
    reset_case
    export "$variable=1"
    if run_deploy; then echo "Expected failure: $variable" >&2; exit 1; fi
    assert_online
    test ! -e "$APP_DIR/current"
    passed=$((passed+1))
done
for variable in SPACE_AVAILABLE SPACE_AFTER_BUILD; do
    reset_case
    export "$variable=1"
    if run_deploy; then echo 'Low disk accepted' >&2; exit 1; fi
    assert_online
    test ! -e "$APP_DIR/current"
    passed=$((passed+1))
done
for failure in 'systemctl reload php8.3-fpm.service' 'php artisan queue:restart' 'supervisorctl restart teg-deadlines-worker:*'; do
    reset_case
    export FAIL_AT="$failure"
    if run_deploy; then echo 'Post-switch failure accepted' >&2; exit 1; fi
    assert_online
    test "$(cat "$APP_DIR/current/.release-sha")" = bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
    test "$(cat "$APP_DIR/public/build/manifest.json")" = legacy-manifest
    grep -q 'Previous release restored' "$CASE_DIR/result"
    passed=$((passed+1))
done
reset_case
export FAIL_HEALTH=1
if run_deploy; then echo 'Wrong HTTP revision accepted' >&2; exit 1; fi
assert_online
test "$(cat "$APP_DIR/current/.release-sha")" = bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
unset FAIL_HEALTH
run_deploy
assert_online
test "$(cat "$APP_DIR/current/.release-sha")" = "$DEPLOY_SHA"
passed=$((passed+1))
reset_case
export LARGE_SERVICE_LIST=1
run_deploy
assert_online
passed=$((passed+1))
reset_case
touch "$APP_DIR/storage/framework/down"
if run_deploy; then echo 'Existing maintenance bypassed' >&2; exit 1; fi
test -f "$APP_DIR/storage/framework/down"
passed=$((passed+1))
reset_case
export DEPLOY_MAINTENANCE=1 FAIL_AT='php artisan migrate --force'
if run_deploy; then echo 'Failed maintenance migration accepted' >&2; exit 1; fi
test -f "$APP_DIR/storage/framework/down"
test "$(cat "$CASE_DIR/fpm")" = inactive
test "$(cat "$CASE_DIR/workers")" = STOPPED
unset FAIL_AT
run_deploy
test ! -f "$APP_DIR/storage/framework/down"
test "$(cat "$CASE_DIR/fpm")" = active
test "$(cat "$CASE_DIR/workers")" = RUNNING
grep -q '^php artisan requests:privatize-files$' "$CASE_DIR/calls"
passed=$((passed+1))
reset_case
export DEPLOY_MAINTENANCE=1 FAIL_HEALTH=1
if run_deploy; then echo 'Failed maintenance health check accepted' >&2; exit 1; fi
test -f "$APP_DIR/storage/framework/down"
test "$(cat "$APP_DIR/current/.release-sha")" = "$DEPLOY_SHA"
test "$(cat "$CASE_DIR/fpm")" = inactive
! grep -q 'Previous release restored' "$CASE_DIR/result"
passed=$((passed+1))
reset_case
export FAIL_AT='systemctl enable --now teg-approval-backup.timer'
if run_deploy; then echo 'Timer failure accepted' >&2; exit 1; fi
assert_online
test "$(cat "$APP_DIR/current/.release-sha")" = "$DEPLOY_SHA"
grep -q 'New release is online' "$CASE_DIR/result"
passed=$((passed+1))
echo "$passed deployment scenarios passed"
