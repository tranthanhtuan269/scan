# Deploy scan lên Ubuntu VPS

Server mẫu: **5.78.124.80**

## Cách 1 — Một lệnh (khuyến nghị)

SSH vào VPS rồi chạy:

```bash
curl -fsSL https://raw.githubusercontent.com/tranthanhtuan269/scan/main/deploy/install.sh | sudo bash
```

Script sẽ cài Nginx, PHP, MySQL, Python, clone repo, import DB dump (~83MB), cấu hình cron crawl hàng ngày.

## Cách 2 — Thủ công

```bash
sudo apt update
sudo apt install -y nginx mysql-server git python3 python3-pip php-fpm php-mysql
sudo git clone https://github.com/tranthanhtuan269/scan.git /var/www/scan
cd /var/www/scan
sudo pip3 install -r requirements.txt
sudo mysql < db/couponspeak_crawl_dump.sql
cp .env.example .env   # chỉnh DB_USER, DB_PASSWORD
sudo cp deploy/nginx.conf /etc/nginx/sites-available/scan
sudo ln -sf /etc/nginx/sites-available/scan /etc/nginx/sites-enabled/scan
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## API sau khi deploy

```
GET http://5.78.124.80/api/coupons?site=thuoc360&store=alsoasked
GET http://5.78.124.80/api/
```

Cập nhật thuoc360 `.env`:

```env
COUPONSPEAK_API_URL=http://5.78.124.80/api/coupons
COUPONSPEAK_API_SITE=thuoc360
```

## Cron crawl

Mặc định `install.sh` thêm cron 06:00 mỗi ngày:

```bash
0 6 * * * cd /var/www/scan && python3 jobs/daily_crawl.py >> logs/cron.log 2>&1
```

### SSL (khi có domain)

DNS: thêm bản ghi **A** `scan.thuoc360.com` → `5.78.124.80` (tắt proxy Cloudflare lúc cấp SSL, hoặc dùng SSL mode Flexible).

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d scan.thuoc360.com
```

Truy cập: **https://scan.thuoc360.com/** — API: `https://scan.thuoc360.com/api/coupons?site=thuoc360&store=alsoasked`
