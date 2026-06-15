#!/usr/bin/env bash
set -euo pipefail

MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:?Set MYSQL_ROOT_PASS before running}"
PMA_DIR=/usr/share/phpmyadmin
NGINX_SITE=/etc/nginx/sites-available/scan
PMA_SNIPPET=/etc/nginx/snippets/scan-phpmyadmin.conf

echo "==> Install phpMyAdmin..."
export DEBIAN_FRONTEND=noninteractive
debconf-set-selections <<< 'phpmyadmin phpmyadmin/dbconfig-install boolean false'
debconf-set-selections <<< 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect'
apt-get update -qq
apt-get install -y -qq phpmyadmin php-mbstring php-zip php-gd php-bz2

echo "==> Set MySQL root password..."
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PASS}';" 2>/dev/null \
  || mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASS}';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> phpMyAdmin config..."
BLOWFISH=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)
cat > /etc/phpmyadmin/conf.d/99-scan-custom.php <<EOF
<?php
\$cfg['blowfish_secret'] = '${BLOWFISH}';
\$cfg['Servers'][1]['auth_type'] = 'cookie';
\$cfg['Servers'][1]['host'] = 'localhost';
\$cfg['Servers'][1]['compress'] = false;
\$cfg['Servers'][1]['AllowNoPassword'] = false;
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
EOF

echo "==> Nginx phpMyAdmin location..."
cat > "$PMA_SNIPPET" <<'EOF'
location ^~ /phpmyadmin {
    root /usr/share/;
    index index.php;

    location ~ ^/phpmyadmin/(.+\.php)$ {
        root /usr/share/;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* ^/phpmyadmin/(.+\.(jpg|jpeg|gif|css|png|js|ico|html|xml|txt))$ {
        root /usr/share/;
        expires 30d;
    }
}
EOF

if ! grep -q 'scan-phpmyadmin.conf' "$NGINX_SITE"; then
  sed -i '/add_header X-Content-Type-Options/i\    include snippets/scan-phpmyadmin.conf;' "$NGINX_SITE"
fi

nginx -t
systemctl reload nginx

echo "=============================================="
echo "phpMyAdmin ready"
echo "URL: https://scan.thuoc360.com/phpmyadmin"
echo "MySQL user: root"
echo "MySQL pass: (as configured)"
echo "Database: couponspeak_crawl"
echo "=============================================="
