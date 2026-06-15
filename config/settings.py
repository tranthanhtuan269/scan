import os
from pathlib import Path

from dotenv import load_dotenv

ROOT_DIR = Path(__file__).resolve().parent.parent
load_dotenv(ROOT_DIR / ".env")

BASE_URL = os.getenv("BASE_URL", "https://couponspeak.com").rstrip("/")
DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USER", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")
DB_NAME = os.getenv("DB_NAME", "couponspeak_crawl")

REQUEST_DELAY = float(os.getenv("REQUEST_DELAY", "0.8"))
MAX_CONCURRENT = int(os.getenv("MAX_CONCURRENT", "3"))
REQUEST_TIMEOUT = float(os.getenv("REQUEST_TIMEOUT", "30"))
SAVE_RAW_HTML = os.getenv("SAVE_RAW_HTML", "0") == "1"

# Số ngày giữa 2 lần chạy crawl (daily_crawl.py sẽ skip nếu chưa đủ)
CRAWL_INTERVAL_DAYS = int(os.getenv("CRAWL_INTERVAL_DAYS", "1"))

# Store chưa crawl trong N ngày sẽ được re-check (tier 3)
INCREMENTAL_STALE_DAYS = int(os.getenv("INCREMENTAL_STALE_DAYS", "1"))

# Mỗi N ngày chạy full discovery trong incremental
FULL_CRAWL_INTERVAL_DAYS = int(os.getenv("FULL_CRAWL_INTERVAL_DAYS", "7"))

INCREMENTAL_TIER3_BATCH = int(os.getenv("INCREMENTAL_TIER3_BATCH", "200"))
SEARCH_MAX_PAGES = int(os.getenv("SEARCH_MAX_PAGES", "50"))

STORAGE_DIR = ROOT_DIR / "storage"
RAW_HTML_DIR = STORAGE_DIR / "raw"
LOG_DIR = ROOT_DIR / "logs"

SEED_URLS = [
    f"{BASE_URL}/",
    f"{BASE_URL}/deals",
    f"{BASE_URL}/blog",
]

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/121.0.0.0 Safari/537.36"
)

DEFAULT_HEADERS = {
    "User-Agent": USER_AGENT,
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
    "Connection": "keep-alive",
}
