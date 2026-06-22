# CouponSpeak Crawler



Crawler tự động cho [couponspeak.com](https://couponspeak.com/) — lưu **toàn bộ dữ liệu** (store, coupon, blog, FAQ, affiliate link) vào **MySQL**.



## Yêu cầu



- Python 3.10+

- MySQL (XAMPP) — bật MySQL trước khi chạy

- Kết nối internet



## Cài đặt



```bash

cd C:\xampp\htdocs\scan

pip install -r requirements.txt

copy .env.example .env

```



Chỉnh `.env` nếu MySQL có mật khẩu khác.



Khởi tạo database:



```bash

python jobs/init_db.py

```



Hoặc import **full dump** (schema + dữ liệu đã crawl):



```bash

mysql -u root < db/couponspeak_crawl_dump.sql

```



> File `db/couponspeak_crawl_dump.sql` chứa toàn bộ stores, coupons, blog pages đã crawl.



## Chạy crawl



| Lệnh | Mô tả |

|------|-------|

| `python jobs/full_crawl.py` | Lần đầu — discover + crawl tất cả store + blog |

| `python jobs/incremental_crawl.py` | Crawl nhanh — ưu tiên store hot, skip trang không đổi |

| `python jobs/daily_crawl.py` | **Khuyến nghị** — tự chọn full/incremental, kiểm tra interval |

| `python jobs/daily_crawl.py --force` | Bỏ qua kiểm tra interval, chạy ngay |



Double-click:



- `run_full_crawl.bat` — init DB + full crawl

- `run_daily.bat` — daily crawl (theo `CRAWL_INTERVAL_DAYS`)



## Lên lịch Windows Task Scheduler



1. Mở **Task Scheduler** → Create Basic Task

2. Trigger: Daily, 06:00

3. Action: Start a program → `C:\xampp\htdocs\scan\run_daily.bat`



Nếu máy tắt vào giờ chạy, crawl sẽ chạy lần tiếp theo khi máy bật (vì `daily_crawl` kiểm tra số ngày, không phụ thuộc giờ chính xác).



## Cấu hình tần suất (`.env`)



| Biến | Mặc định | Mô tả |

|------|----------|-------|

| `CRAWL_INTERVAL_DAYS` | 1 | Số ngày giữa 2 lần chạy `daily_crawl` |

| `INCREMENTAL_STALE_DAYS` | 1 | Store chưa crawl trong N ngày → re-check |

| `FULL_CRAWL_INTERVAL_DAYS` | 7 | Mỗi N ngày search discovery tìm store mới |

| `REQUEST_DELAY` | 0.8 | Giây giữa các HTTP request |

| `SAVE_RAW_HTML` | 0 | Lưu HTML gốc vào `storage/raw/` |



Ví dụ crawl **3 ngày 1 lần**:



```

CRAWL_INTERVAL_DAYS=3

INCREMENTAL_STALE_DAYS=3

```



## Chiến lược incremental



| Tier | Trang | Hành vi |

|------|-------|---------|

| T1 | `/`, `/deals`, `/blog` | Luôn fetch — tìm store/blog mới |

| T2 | Store xuất hiện trên seed | Fetch + parse |

| T3 | Store còn lại trong DB | Fetch, skip parse nếu hash không đổi |



Coupon không còn thấy trên trang store → `status = expired`.



## Dữ liệu lưu trong MySQL



| Bảng | Nội dung |

|------|----------|

| `stores` | Tên, rating, vote, about, FAQ, affiliate, logo, meta |

| `coupons` | Mã giảm giá / deal, verified, discount, code, affiliate URL |

| `pages` | Blog posts (title, excerpt, content HTML) |

| `crawl_urls` | Registry URL đã discover |

| `crawl_runs` | Log mỗi lần chạy (fetched, new, updated, skipped) |
| `sitename` | Whitelist site được phép gọi API (`?site=...`) |



## Web local (hiển thị dữ liệu đã crawl)

Site PHP đọc trực tiếp từ MySQL, giao diện giống couponspeak.com.

Yêu cầu: Apache + MySQL (XAMPP) đang chạy.

### Cách 1 — VirtualHost (khuyến nghị)

File mẫu: `web/apache-vhost.conf`

1. Thêm vhost vào `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
2. Thêm vào `C:\Windows\System32\drivers\etc\hosts`:
   ```
   127.0.0.1   scan.test
   ```
3. Restart Apache
4. Truy cập: **http://scan.test/**

### Cách 2 — Subfolder

Truy cập: **http://localhost/scan/web/**

| Trang | URL (scan.test) | URL (localhost) |
|-------|-----------------|-----------------|
| Trang chủ | `/` | `/scan/web/` |
| Stores | `/stores` | `/scan/web/stores` |
| Store | `/store/{slug}` | `/scan/web/store/{slug}` |
| Deals | `/deals` | `/scan/web/deals` |
| Blog | `/blog` | `/scan/web/blog` |

> **Lưu ý:** Nếu dùng vhost `scan.test`, không truy cập qua `/scan/web/` nữa — `RewriteBase` và link sẽ sai.

### API JSON

**Tìm coupon theo tên store:**

```
GET /api/coupons?site=thuoc360&store=alsoasked
GET /api/search?site=thuoc360&store=alsoasked
```

> **Bắt buộc** có param `site` — tên site phải tồn tại trong bảng `sitename` (whitelist). Site không đăng ký → HTTP 403.

Response mẫu:

```json
{
  "success": true,
  "site": "thuoc360",
  "store": "alsoasked",
  "count": 9,
  "coupons": [
    {
      "discount_label": "20% Off",
      "title": "Save 20% with annual billing on AlsoAsked Basic",
      "coupon_code": null,
      "coupon_type": "deal"
    }
  ]
}
```

| Endpoint | Mô tả |
|----------|-------|
| `GET /api/` | Danh sách endpoint + site đã đăng ký |
| `GET /api/coupons?site={site}&store={name}` | Tìm coupon theo store (chỉ có affiliate_url) |
| `GET /api/store/{slug}?site={site}` | Chi tiết store đầy đủ |

Params: `page`, `limit` (max 200)

**AI fallback** (khi store chưa có trong DB): tự gọi AI → import → trả coupon.

**Gemini** (ưu tiên khi `GEMINI_ENABLED=true`):

| Biến | Mô tả |
|------|--------|
| `GEMINI_ENABLED` | `true` bật Gemini |
| `GEMINI_API_KEY` | Google AI API key |
| `GEMINI_MODEL` | vd `gemini-2.5-flash` |
| `GEMINI_TIMEOUT` | Timeout giây (mặc định 90) |

**OpenAI-compatible** (khi `GEMINI_ENABLED=false`):

| Biến | Mô tả |
|------|--------|
| `API_AI` | URL chat completions |
| `API_AI_KEY` | API key |

Query: `?api_ai=URL` override endpoint | `?gemini_model=model` override model | `?ai=0` tắt fallback

Log: `logs/api-ai.log`

### Quản lý site được phép gọi API

Bảng `sitename` — chỉ site có trong bảng mới nhận được dữ liệu:

```sql
INSERT INTO sitename (name, label, notes) VALUES ('thuoc360', 'Thuoc360', 'Laravel site');
```

Chạy migration nếu DB đã tồn tại từ trước:

```bash
mysql -u root couponspeak_crawl < db/migrations/001_add_sitename.sql
```

## Cấu trúc project

```
scan/
├── config/settings.py
├── crawler/          # fetcher, discovery, parsers, pipeline
├── db/               # schema.sql, repository
├── jobs/             # init_db, full_crawl, incremental_crawl, daily_crawl
├── web/              # PHP site local
├── run_daily.bat
└── run_full_crawl.bat
```

