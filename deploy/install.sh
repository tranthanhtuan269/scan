#!/usr/bin/env bash
# Bootstrap scan on Ubuntu 22.04/24.04 — run as root:
#   curl -fsSL https://raw.githubusercontent.com/tranthanhtuan269/scan/main/deploy/install.sh | bash
# Or after clone:
#   sudo bash deploy/install.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/scan}"
REPO_URL="${REPO_URL:-https://github.com/tranthanhtuan269/scan.git}"
DB_NAME="${DB_NAME:-couponspeak_crawl}"
DB_USER="${DB_USER:-scan_user}"
DB_PASS="${DB_PASS:-$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 24)}"
IMPORT_DUMP="${IMPORT_DUMP:-1}"
PHP_FPM_SOCK="${PHP_FPM_SOCK:-}"

echo "==> Installing packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    nginx \
    mysql-server \
    git \
    curl \
    unzip \
    python3 \
    python3-pip \
    python3-venv \
    php-fpm \
    php-mysql \
    php-mbstring \
    php-xml \
    php-curl

# Detect PHP-FPM socket
if [[ -z "$PHP_FPM_SOCK" ]]; then
    for sock in /run/php/php8.5-fpm.sock /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /run/php/php8.1-fpm.sock; do
        if [[ -S "$sock" ]]; then
            PHP_FPM_SOCK="$sock"
            break
        fi
    done
fi

if [[ -z "$PHP_FPM_SOCK" ]]; then
    echo "ERROR: PHP-FPM socket not found."
    exit 1
fi

echo "==> Cloning application to ${APP_DIR}..."
mkdir -p "$(dirname "$APP_DIR")"
if [[ -d "$APP_DIR/.git" ]]; then
    cd "$APP_DIR"
    git pull --ff-only origin main
else
    git clone "$REPO_URL" "$APP_DIR"
    cd "$APP_DIR"
fi

echo "==> Python dependencies..."
pip3 install --break-system-packages -q -r requirements.txt 2>/dev/null \
    || pip3 install -q -r requirements.txt

echo "==> MySQL database..."
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
TABLE_COUNT=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo 0)
if [[ "$TABLE_COUNT" -eq 0 && "$IMPORT_DUMP" == "1" && -f "$APP_DIR/db/couponspeak_crawl_dump.sql" ]]; then
    echo "==> Importing database dump (may take a few minutes)..."
    if head -c 2 "$APP_DIR/db/couponspeak_crawl_dump.sql" | grep -q $'^\xff\xfe'; then
        iconv -f UTF-16LE -t UTF-8 "$APP_DIR/db/couponspeak_crawl_dump.sql" | mysql --default-character-set=utf8mb4
    else
        mysql --default-character-set=utf8mb4 < "$APP_DIR/db/couponspeak_crawl_dump.sql"
    fi
    mysql "$DB_NAME" < "$APP_DIR/db/migrations/001_add_sitename.sql" 2>/dev/null || true
elif [[ "$TABLE_COUNT" -eq 0 ]]; then
    echo "==> Initializing schema..."
    cp "$APP_DIR/.env.example" "$APP_DIR/.env.bootstrap"
    sed -i "s/^DB_USER=.*/DB_USER=root/" "$APP_DIR/.env.bootstrap"
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=/" "$APP_DIR/.env.bootstrap"
    DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD= DB_NAME="$DB_NAME" python3 "$APP_DIR/jobs/init_db.py"
    mysql "$DB_NAME" < "$APP_DIR/db/migrations/001_add_sitename.sql" 2>/dev/null || true
    rm -f "$APP_DIR/.env.bootstrap"
fi

mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null \
    || mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> Writing .env..."
cat > "$APP_DIR/.env" <<EOF
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASS}
DB_NAME=${DB_NAME}
WEB_BASE_PATH=
BASE_URL=https://couponspeak.com
REQUEST_DELAY=0.8
MAX_CONCURRENT=3
REQUEST_TIMEOUT=30
SAVE_RAW_HTML=0
CRAWL_INTERVAL_DAYS=1
INCREMENTAL_STALE_DAYS=1
FULL_CRAWL_INTERVAL_DAYS=7
INCREMENTAL_TIER3_BATCH=200
SEARCH_MAX_PAGES=50
EOF
chmod 640 "$APP_DIR/.env"
chown www-data:www-data "$APP_DIR/.env"

echo "==> Nginx..."
sed "s|unix:/run/php/php8.5-fpm.sock|unix:${PHP_FPM_SOCK}|" "$APP_DIR/deploy/nginx.conf" \
    > /etc/nginx/sites-available/scan
ln -sf /etc/nginx/sites-available/scan /etc/nginx/sites-enabled/scan
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx php*-fpm mysql
systemctl reload nginx

echo "==> Cron daily crawl (06:00)..."
CRON_LINE="0 6 * * * cd ${APP_DIR} && /usr/bin/python3 jobs/daily_crawl.py >> ${APP_DIR}/logs/cron.log 2>&1"
( crontab -l 2>/dev/null | grep -v 'jobs/daily_crawl.py' || true; echo "$CRON_LINE" ) | crontab -

chown -R www-data:www-data "$APP_DIR/web" "$APP_DIR/logs" "$APP_DIR/storage" 2>/dev/null || true
chmod -R 775 "$APP_DIR/logs" "$APP_DIR/storage" 2>/dev/null || true

echo ""
echo "=============================================="
echo " SCAN deployed successfully"
echo "=============================================="
echo " Web/API : http://$(hostname -I | awk '{print $1}')/"
echo " API test: http://$(hostname -I | awk '{print $1}')/api/coupons?site=thuoc360&store=alsoasked"
echo " App dir : ${APP_DIR}"
echo " DB name : ${DB_NAME}"
echo " DB user : ${DB_USER}"
echo " DB pass : ${DB_PASS}"
echo "=============================================="
echo "Save the DB password above. Credentials are in ${APP_DIR}/.env"
