#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/var/www/scan
DB_NAME=couponspeak_crawl
DB_USER=scan_user
DB_PASS=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 24)
PHP_FPM_SOCK=/run/php/php8.5-fpm.sock

echo "==> Converting dump UTF-16 -> UTF-8..."
iconv -f UTF-16LE -t UTF-8 "$APP_DIR/db/couponspeak_crawl_dump.sql" > /tmp/couponspeak_utf8.sql

echo "==> Importing database (may take a few minutes)..."
mysql --default-character-set=utf8mb4 < /tmp/couponspeak_utf8.sql
rm -f /tmp/couponspeak_utf8.sql

mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null \
  || mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

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
chmod 600 "$APP_DIR/.env"

sed "s|unix:/run/php/php8.5-fpm.sock|unix:${PHP_FPM_SOCK}|" "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/scan
ln -sf /etc/nginx/sites-available/scan /etc/nginx/sites-enabled/scan
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx php8.5-fpm mysql
systemctl reload nginx

CRON_LINE="0 6 * * * cd ${APP_DIR} && /usr/bin/python3 jobs/daily_crawl.py >> ${APP_DIR}/logs/cron.log 2>&1"
( crontab -l 2>/dev/null | grep -v 'jobs/daily_crawl.py' || true; echo "$CRON_LINE" ) | crontab -

chown -R www-data:www-data "$APP_DIR/web" "$APP_DIR/logs" "$APP_DIR/storage" 2>/dev/null || true
chmod -R 775 "$APP_DIR/logs" "$APP_DIR/storage" 2>/dev/null || true

echo "==> DB counts:"
mysql -N -e "SELECT 'stores', COUNT(*) FROM couponspeak_crawl.stores UNION ALL SELECT 'coupons', COUNT(*) FROM couponspeak_crawl.coupons UNION ALL SELECT 'sitename', COUNT(*) FROM couponspeak_crawl.sitename;"

echo "=============================================="
echo "SCAN deployed"
echo "Web/API: http://5.78.124.80/"
echo "API test: http://5.78.124.80/api/coupons?site=thuoc360&store=alsoasked"
echo "DB_USER=${DB_USER}"
echo "DB_PASS=${DB_PASS}"
echo "=============================================="
