from __future__ import annotations

import subprocess
from pathlib import Path

from config.settings import BASE_URL, RAW_HTML_DIR, SAVE_RAW_HTML
from crawler.discovery import Discovery
from crawler.fetcher import Fetcher
from crawler.logo_assets import localize_store_logo
from crawler.parsers.blog import parse_blog_page
from crawler.parsers.store import parse_store_page
from crawler.utils import classify_url
from db.repository import Repository, now

SCAN_ROOT = Path(__file__).resolve().parents[1]


class CrawlPipeline:
    def __init__(self, repo: Repository, fetcher: Fetcher):
        self.repo = repo
        self.fetcher = fetcher
        self.discovery = Discovery(fetcher)
        self.stats = {
            "urls_fetched": 0,
            "urls_changed": 0,
            "urls_new": 0,
            "urls_skipped": 0,
            "urls_failed": 0,
        }

    def _save_raw(self, slug: str, html: str):
        if not SAVE_RAW_HTML:
            return
        RAW_HTML_DIR.mkdir(parents=True, exist_ok=True)
        (RAW_HTML_DIR / f"{slug}.html").write_text(html, encoding="utf-8")

    def process_store(
        self,
        url: str,
        slug: str,
        priority: int = 3,
        force: bool = False,
        skip_if_exists: bool = True,
        site_base_url: str | None = None,
    ) -> bool:
        existing = self.repo.get_store_by_slug(slug)
        if skip_if_exists and existing:
            self.stats["urls_skipped"] += 1
            return False

        is_new = existing is None

        result = self.fetcher.fetch(
            url,
            etag=existing.get("etag") if existing else None,
            last_modified=existing.get("last_modified") if existing else None,
        )
        self.stats["urls_fetched"] += 1

        if result.from_cache:
            self.stats["urls_skipped"] += 1
            self.repo.execute(
                "UPDATE stores SET last_crawled_at = %s WHERE slug = %s",
                (now(), slug),
            )
            self.repo.commit()
            return False

        if result.status_code == 404:
            self.stats["urls_failed"] += 1
            self.repo.mark_store_failed(slug, 404)
            return False

        if result.status_code != 200 or not result.text:
            self.stats["urls_failed"] += 1
            self.repo.mark_store_failed(slug, result.status_code)
            return False

        parsed = parse_store_page(result.text, url, slug)
        parsed["etag"] = result.etag
        parsed["last_modified"] = result.last_modified
        parsed["http_status"] = result.status_code
        parsed["priority"] = priority
        localize_store_logo(parsed, slug, site_base_url or BASE_URL)

        if (
            not force
            and existing
            and existing.get("coupon_section_hash") == parsed.get("coupon_section_hash")
        ):
            self.stats["urls_skipped"] += 1
            self.repo.execute(
                """
                UPDATE stores SET
                    last_crawled_at = %s, etag = %s, last_modified = %s, http_status = %s
                WHERE id = %s
                """,
                (now(), result.etag, result.last_modified, result.status_code, existing["id"]),
            )
            self.repo.commit()
            return False

        self._save_raw(slug, result.text)
        store_id = self.repo.upsert_store(parsed)
        self.repo.sync_coupons(store_id, parsed.get("coupons", []))
        self.repo.dedupe_coupons_by_label(store_id)

        if is_new:
            self.stats["urls_new"] += 1
        else:
            self.stats["urls_changed"] += 1

        self.repo.upsert_crawl_url(url, "store", priority=priority)
        self.repo.commit()
        self._refresh_store_api_lookup(store_id)
        return True

    def _refresh_store_api_lookup(self, store_id: int) -> None:
        script = SCAN_ROOT / "jobs" / "refresh_store_api_lookup.php"
        if not script.exists():
            return
        subprocess.run(
            ["php", str(script), str(store_id)],
            capture_output=True,
            timeout=30,
            check=False,
        )

    def process_blog(self, url: str, slug: str | None) -> bool:
        result = self.fetcher.fetch(url)
        self.stats["urls_fetched"] += 1
        if result.status_code != 200 or not result.text:
            self.stats["urls_failed"] += 1
            return False

        parsed = parse_blog_page(result.text, url, slug)
        parsed["http_status"] = result.status_code
        changed = self.repo.upsert_page(parsed)
        if changed:
            self.stats["urls_changed"] += 1
        else:
            self.stats["urls_skipped"] += 1
        self.repo.upsert_crawl_url(url, "blog", priority=2)
        self.repo.commit()
        return changed

    def collect_hot_store_slugs(self, seed_urls: list[str]) -> set[str]:
        hot_slugs: set[str] = set()
        for seed in seed_urls:
            result = self.fetcher.fetch(seed)
            self.stats["urls_fetched"] += 1
            if result.status_code != 200 or not result.text:
                continue

            links = self.discovery.discover_from_html(result.text, seed)
            for store_url in links["store"]:
                _url_type, slug = classify_url(store_url)
                if slug:
                    hot_slugs.add(slug)

            for blog_url in links["blog"]:
                _url_type, slug = classify_url(blog_url)
                self.process_blog(blog_url, slug)

        return hot_slugs
