#!/usr/bin/env bash
# Fresh Ubuntu 24.04 installation only; existing servers use deploy.sh.
# sudo APP_URL=https://approval.example.com LETSENCRYPT_EMAIL=ops@example.com bash setup-server.sh
set -euo pipefail
umask 027
[ "$(id -u)" -eq 0 ] || { echo 'Run as root.' >&2; exit 2; }
: "${APP_URL:?Set APP_URL to the public HTTPS origin}"
: "${LETSENCRYPT_EMAIL:?Set the certificate contact email}"
[[ "$APP_URL" =~ ^https://([a-zA-Z0-9.-]+)$ ]] || { echo 'APP_URL must be an HTTPS origin without a path or port.' >&2; exit 2; }
DOMAIN=${BASH_REMATCH[1]}
APP_USER=tegbot
APP_DIR=/var/www/teg-approval-bot
DB_NAME=teg_bot
DB_USER=teg_user
SOURCE_DIR=$(pwd -P)
[ -f "$SOURCE_DIR/artisan" ] && [ -d "$SOURCE_DIR/.git" ] || { echo 'Run from a standalone Git clone (not a linked worktree).' >&2; exit 2; }
[ ! -e "$APP_DIR/.env" ] || { echo 'Existing installation found. Use deploy.sh; setup never replaces its environment/database.' >&2; exit 2; }

apt-get update
apt-get install -y curl git unzip rsync nginx supervisor ufw openssl ca-certificates \
    php8.3-cli php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache \
    postgresql postgresql-contrib certbot python3-certbot-nginx \
    libreoffice-writer poppler-utils fonts-dejavu-core fonts-liberation python3-opencv python3-numpy
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs
installer=$(mktemp)
curl -fsSL https://getcomposer.org/installer -o "$installer"
expected=$(curl -fsSL https://composer.github.io/installer.sig)
[ "$(sha384sum "$installer" | awk '{print $1}')" = "$expected" ] || { rm -f "$installer"; echo 'Composer installer checksum mismatch.' >&2; exit 1; }
php "$installer" --install-dir=/usr/local/bin --filename=composer
rm -f "$installer"

id "$APP_USER" >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/tegbot --shell /usr/sbin/nologin "$APP_USER"
mkdir -p "$APP_DIR"
if [ "$SOURCE_DIR" != "$APP_DIR" ]; then
    rsync -a --exclude=node_modules --exclude=vendor --exclude=.env --exclude=storage --exclude=.local "$SOURCE_DIR/" "$APP_DIR/"
fi
cd "$APP_DIR"
mkdir -p storage/app/private storage/app/public storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
DB_PASS=$(openssl rand -hex 32)
WEBHOOK_SECRET=$(openssl rand -hex 32)
systemctl enable --now postgresql
# Fail on existing roles/databases instead of hiding partial installation errors.
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS';"
runuser -u postgres -- createdb --owner="$DB_USER" "$DB_NAME"
cp .env.example .env
sed -i -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
    -e "s|^APP_URL=.*|APP_URL=$APP_URL|" -e 's/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/' -e 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' \
    -e "s/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" -e "s/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/" \
    -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" \
    -e "s|^TELEGRAM_WEBHOOK_URL=.*|TELEGRAM_WEBHOOK_URL=$APP_URL/telegram/webhook|" \
    -e "s/^TELEGRAM_WEBHOOK_SECRET=.*/TELEGRAM_WEBHOOK_SECRET=$WEBHOOK_SECRET/" .env
printf '\nSESSION_SECURE_COOKIE=true\n' >> .env
chown -R "$APP_USER:www-data" "$APP_DIR"
chmod -R u=rwX,g=rX,o= "$APP_DIR"
chmod -R u=rwX,g=rwX,o= storage bootstrap/cache
chmod 600 .env
runuser -u "$APP_USER" -- composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
runuser -u "$APP_USER" -- npm ci --no-audit --no-fund
runuser -u "$APP_USER" -- npm run build
runuser -u "$APP_USER" -- php artisan key:generate --force
runuser -u "$APP_USER" -- php artisan migrate --force
runuser -u "$APP_USER" -- php artisan db:seed --force
runuser -u "$APP_USER" -- php artisan storage:link
runuser -u "$APP_USER" -- php artisan optimize

# The application user executes both HTTP and background jobs; nginx can read public assets/socket.
cat > /etc/php/8.3/fpm/pool.d/tegbot.conf <<'FPM'
[tegbot]
user = tegbot
group = www-data
listen = /run/php/php8.3-tegbot.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPM
php-fpm8.3 -t
systemctl enable --now php8.3-fpm nginx supervisor
systemctl restart php8.3-fpm
sed "s/TEG_SERVER_NAME/$DOMAIN/g" ops/nginx.conf > /etc/nginx/sites-available/teg-approval-bot
ln -sf /etc/nginx/sites-available/teg-approval-bot /etc/nginx/sites-enabled/teg-approval-bot
nginx -t
systemctl reload nginx
# DNS for DOMAIN must already point here. Keep existing firewall access rules.
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
certbot --nginx --non-interactive --agree-tos --email "$LETSENCRYPT_EMAIL" -d "$DOMAIN" --redirect
install -m 644 ops/supervisor.conf /etc/supervisor/conf.d/teg-approval-bot.conf
supervisorctl reread
supervisorctl update

# Local backups include DB, private files and APP_KEY; protect this directory and copy it off-host.
install -d -m 700 /var/backups/teg-approval-bot
printf '127.0.0.1:5432:%s:%s:%s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > /etc/teg-approval-backup.pgpass
chmod 600 /etc/teg-approval-backup.pgpass
cat > /etc/teg-approval-backup.env <<ENV
APP_DIR=$APP_DIR
BACKUP_ROOT=/var/backups/teg-approval-bot
APP_USER=$APP_USER
APP_GROUP=www-data
PHP_FPM_SERVICE=php8.3-fpm
PGHOST=127.0.0.1
PGPORT=5432
PGDATABASE=$DB_NAME
PGUSER=$DB_USER
PGPASSFILE=/etc/teg-approval-backup.pgpass
ENV
chmod 600 /etc/teg-approval-backup.env
install -m 644 ops/teg-approval-backup.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now teg-approval-backup.timer
printf '\nInstallation ready: %s\n' "$APP_URL"
echo 'Next: run admin:bootstrap as tegbot; change its temporary password on first sign-in.'
echo 'Set TELEGRAM_TOKEN in .env, rebuild config cache and run telegram:webhook set as tegbot.'
echo 'Wait up to five minutes and verify /health/ready. Configure encrypted off-host backups and retention.'
