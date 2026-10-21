#!/usr/bin/env bash
# Run as root during deployment, before any Artisan command writes files.
set -euo pipefail

APP_DIR=${1:?Application directory is required}
PHP_FPM_SVC=${2:?PHP-FPM service is required}
CODE_DIR=${3:-$APP_DIR}
if [ "$(realpath -- "$APP_DIR")" != /var/www/teg-approval-bot ]; then
    echo 'Unexpected application directory.' >&2
    exit 1
fi
if [ "$CODE_DIR" = "$APP_DIR" ] && [ -L "$APP_DIR/current" ]; then CODE_DIR=$(realpath "$APP_DIR/current"); fi
if [ "$CODE_DIR" != "$APP_DIR" ]; then
    case "$CODE_DIR/" in "$APP_DIR/releases/"?*/) ;; *) echo 'Unexpected release directory.' >&2; exit 1 ;; esac
    test "$(realpath -- "$CODE_DIR")" = "$CODE_DIR"
    test -f "$CODE_DIR/.release-sha"
fi
if [ "$(id -u)" != 0 ]; then
    echo 'Run this storage setup with sudo.' >&2
    exit 1
fi
id tegbot >/dev/null
id -nG tegbot | tr ' ' '\n' | grep -qx www-data
[[ "$PHP_FPM_SVC" =~ ^php([0-9]+\.[0-9]+)-fpm\.service$ ]]
PHP_VERSION=${BASH_REMATCH[1]}
PHP_FPM_BIN="/usr/sbin/php-fpm${PHP_VERSION}"
PHP_UPLOAD_CONFIG="/etc/php/${PHP_VERSION}/fpm/conf.d/99-teg-uploads.ini"
test -x "$PHP_FPM_BIN"
test -d "$(dirname "$PHP_UPLOAD_CONFIG")"

# Repair only the application's private storage; never follow symlinks.
PRIVATE_DIR="$APP_DIR/storage/app/private"
test "$(realpath -- "$APP_DIR/storage/app")" = "$APP_DIR/storage/app"
test ! -L "$PRIVATE_DIR"
install -d -o tegbot -g www-data -m 2770 "$PRIVATE_DIR"
test "$(realpath -- "$PRIVATE_DIR")" = "$PRIVATE_DIR"
find -P "$PRIVATE_DIR" -xdev -type d -exec chown tegbot:www-data {} + -exec chmod 2770 {} +
find -P "$PRIVATE_DIR" -xdev -type f -exec chown tegbot:www-data {} + -exec chmod 0660 {} +
for directory in attachments attachments/drafts protocols payment_proofs exports; do
    test ! -L "$PRIVATE_DIR/$directory"
    install -d -o tegbot -g www-data -m 2770 "$PRIVATE_DIR/$directory"
done

# Root-run Composer/Vite hooks can also leave unreadable application caches.
for relative in bootstrap/cache storage/framework storage/logs; do
    runtime_dir="$APP_DIR/$relative"
    if [ "$relative" = bootstrap/cache ]; then runtime_dir="$CODE_DIR/$relative"; fi
    test "$(realpath -- "$runtime_dir")" = "$runtime_dir"
    find -P "$runtime_dir" -xdev -type d -exec chown tegbot:www-data {} + -exec chmod 2770 {} +
    find -P "$runtime_dir" -xdev -type f -exec chown tegbot:www-data {} + -exec chmod 0660 {} +
done

# Vite/Wayfinder must be able to replace output from earlier root-run builds.
for relative in public/build resources/js/actions resources/js/routes resources/js/wayfinder; do
    output_dir="$CODE_DIR/$relative"
    test ! -L "$output_dir"
    install -d -o tegbot -g www-data -m 2770 "$output_dir"
    test "$(realpath -- "$output_dir")" = "$output_dir"
    find -P "$output_dir" -xdev -type d -exec chown tegbot:www-data {} + -exec chmod 2770 {} +
    find -P "$output_dir" -xdev -type f -exec chown tegbot:www-data {} + -exec chmod 0660 {} +
done

# Older root-run npm installs leave nested directories that tegbot cannot replace.
# Preserve executable file modes and never follow dependency symlinks.
DEPENDENCY_DIR="$CODE_DIR/node_modules"
if [ -e "$DEPENDENCY_DIR" ] || [ -L "$DEPENDENCY_DIR" ]; then
    test ! -L "$DEPENDENCY_DIR"
    test -d "$DEPENDENCY_DIR"
    test "$(realpath -- "$DEPENDENCY_DIR")" = "$DEPENDENCY_DIR"
    find -P "$DEPENDENCY_DIR" -xdev -type d -exec chown tegbot:www-data {} + -exec chmod 2770 {} +
    find -P "$DEPENDENCY_DIR" -xdev -type f -exec chown tegbot:www-data {} +
    find -P "$DEPENDENCY_DIR" -xdev -type l -exec chown -h tegbot:www-data {} +
fi

# Each upload request contains one file; allow room for multipart headers.
printf 'upload_max_filesize = 15M\npost_max_size = 20M\n' > "$PHP_UPLOAD_CONFIG"
chmod 0644 "$PHP_UPLOAD_CONFIG"
"$PHP_FPM_BIN" -t

for runtime_user in www-data tegbot; do
    runuser -u "$runtime_user" -- sh -ec '
        umask 0007
        probe=$(mktemp "$1/.upload-check.XXXXXX")
        trap '\''rm -f -- "$probe"'\'' EXIT
        printf "upload-check" > "$probe"
        test "$(cat "$probe")" = upload-check
    ' sh "$PRIVATE_DIR/attachments/drafts"
done
