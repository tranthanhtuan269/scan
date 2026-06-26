#!/usr/bin/env python3
"""Crawl globalcouponshub.com stores, coupons, and blog posts into the scan DB."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse, urlunparse

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from bs4 import BeautifulSoup  # noqa: E402

from crawler.fetcher import Fetcher  # noqa: E402
from crawler.parsers.blog import parse_blog_page  # noqa: E402
from crawler.parsers.globalcouponshub_store import (  # noqa: E402
    BASE_URL,
    db_slug,
    parse_store_page,
)
from db.repository import Repository  # noqa: E402

STORE_PATH_RE = re.compile(r"^/store/([a-zA-Z0-9._-]+)/?$")
BLOG_PATH_RE = re.compile(r"^/blog/([a-zA-Z0-9._-]+)/?$")
OFFER_URL_RE = re.compile(r"/store/([a-zA-Z0-9._-]+)(?:\?offer=\d+)?")

SEED_URLS = [
    f"{BASE_URL}/",
    f"{BASE_URL}/deals",
    f"{BASE_URL}/blog",
]


def normalize_url(url: str) -> str | None:
    if not url or url.startswith(("javascript:", "mailto:", "tel:", "#")):
        return None

    absolute = urljoin(BASE_URL + "/", url.strip())
    parsed = urlparse(absolute)
    site_host = urlparse(BASE_URL).netloc.replace("www.", "")
    if parsed.netloc and parsed.netloc.replace("www.", "") != site_host:
        return None

    path = parsed.path or "/"
    if path != "/" and path.endswith("/"):
        path = path.rstrip("/")

    clean = urlunparse((parsed.scheme or "https", parsed.netloc, path, "", parsed.query, ""))
    return clean.rstrip("/") if clean.endswith("/") and path != "/" else clean


def classify_url(url: str) -> tuple[str, str | None]:
    parsed = urlparse(url)
    path = parsed.path or "/"

    store_match = STORE_PATH_RE.match(path)
    if store_match:
        return "store", store_match.group(1)

    blog_match = BLOG_PATH_RE.match(path)
    if blog_match:
        return "blog", blog_match.group(1)

    if path in ("/", "", "/deals", "/blog"):
        return "seed", None

    return "other", path.lstrip("/")


def extract_links(html: str) -> dict[str, set[str]]:
    soup = BeautifulSoup(html, "lxml")
    found: dict[str, set[str]] = {
        "store": set(),
        "blog": set(),
        "seed": set(),
        "other": set(),
    }

    candidates: set[str] = set()
    for tag in soup.find_all(True):
        for attr in ("href", "data-href", "data-url"):
            value = tag.get(attr)
            if value:
                candidates.add(value)

    for raw in candidates:
        normalized = normalize_url(raw)
        if not normalized:
            continue
        url_type, _slug = classify_url(normalized)
        if url_type == "other" and normalized.startswith(BASE_URL) and "/store/" in normalized:
            match = OFFER_URL_RE.search(
                urlparse(normalized).path
                + ("?" + urlparse(normalized).query if urlparse(normalized).query else "")
            )
            if match:
                normalized = f"{BASE_URL}/store/{match.group(1)}"
                url_type = "store"
        if url_type in found:
            found[url_type].add(normalized)

    return found


def discover_store_urls(fetcher: Fetcher, max_deals_pages: int = 100) -> set[str]:
    store_urls: set[str] = set()
    visited_pages: set[str] = set()
    queue = list(SEED_URLS)
    empty_deals_streak = 0

    while queue:
        page_url = queue.pop(0)
        if page_url in visited_pages:
            continue
        visited_pages.add(page_url)

        result = fetcher.fetch(page_url)
        if result.status_code != 200 or not result.text:
            continue

        links = extract_links(result.text)
        before = len(store_urls)
        store_urls.update(links["store"])

        for blog_url in links["blog"]:
            if blog_url not in visited_pages:
                queue.append(blog_url)

        parsed = urlparse(page_url)
        if parsed.path == "/deals":
            if len(store_urls) > before:
                empty_deals_streak = 0
            else:
                empty_deals_streak += 1

            qs = parse_qs(parsed.query)
            page_num = int(qs.get("page", ["1"])[0])
            if empty_deals_streak < 2 and page_num < max_deals_pages:
                next_url = f"{BASE_URL}/deals?page={page_num + 1}"
                if next_url not in visited_pages:
                    queue.append(next_url)

    return store_urls


def discover_stores_via_search(fetcher: Fetcher, max_pages_per_query: int = 50) -> set[str]:
    store_urls: set[str] = set()
    queries = list("abcdefghijklmnopqrstuvwxyz0123456789")

    for qi, query in enumerate(queries, start=1):
        empty_streak = 0
        for page in range(1, max_pages_per_query + 1):
            url = (
                f"{BASE_URL}/search?key={query}"
                if page == 1
                else f"{BASE_URL}/search?key={query}&page={page}"
            )
            result = fetcher.fetch(url)
            if result.status_code != 200 or not result.text:
                break

            links = extract_links(result.text)
            before = len(store_urls)
            store_urls.update(links["store"])
            if len(store_urls) == before:
                empty_streak += 1
                if empty_streak >= 2:
                    break
            else:
                empty_streak = 0

        if qi % 5 == 0 or qi == len(queries):
            print(f"  search [{qi}/{len(queries)}] -> {len(store_urls)} stores")

    return store_urls


def discover_blog_urls(fetcher: Fetcher) -> set[str]:
    blog_urls: set[str] = set()
    for seed in SEED_URLS:
        result = fetcher.fetch(seed)
        if result.status_code == 200 and result.text:
            blog_urls.update(extract_links(result.text)["blog"])
    return blog_urls


def refresh_store_api_lookup(store_id: int) -> None:
    script = ROOT / "jobs" / "refresh_store_api_lookup.php"
    if not script.exists():
        return
    subprocess.run(
        ["php", str(script), str(store_id)],
        capture_output=True,
        timeout=30,
        check=False,
    )


def main() -> int:
    sys.stdout.reconfigure(line_buffering=True)

    parser = argparse.ArgumentParser(description="Crawl globalcouponshub.com into scan DB")
    parser.add_argument(
        "--seeds-only",
        action="store_true",
        help="Skip /search discovery (faster, fewer stores)",
    )
    parser.add_argument(
        "--skip-blog",
        action="store_true",
        help="Do not crawl blog posts",
    )
    args = parser.parse_args()

    repo = Repository()
    fetcher = Fetcher()
    run_id = repo.start_run("full")

    stats = {
        "urls_fetched": 0,
        "urls_changed": 0,
        "urls_new": 0,
        "urls_skipped": 0,
        "urls_failed": 0,
    }

    print("=== GLOBALCOUPONSHUB CRAWL START ===")

    try:
        print("Phase 1: discover from seeds + deals...")
        store_urls = discover_store_urls(fetcher)
        print(f"  Seeds/deals: {len(store_urls)} stores")

        if not args.seeds_only:
            print("Phase 2: discover via /search...")
            store_urls.update(discover_stores_via_search(fetcher))
            print(f"  Total stores: {len(store_urls)}")

        blog_urls: set[str] = set()
        if not args.skip_blog:
            blog_urls = discover_blog_urls(fetcher)
            print(f"Discovered {len(blog_urls)} blog URLs")

        site_slugs = sorted(
            {
                slug
                for url in store_urls
                for url_type, slug in [classify_url(url)]
                if url_type == "store" and slug
            }
        )
        print(f"Crawling {len(site_slugs)} stores...")

        for idx, site_slug in enumerate(site_slugs, start=1):
            page_url = f"{BASE_URL}/store/{site_slug}"
            slug = db_slug(site_slug)
            existing = repo.get_store_by_slug(slug)
            is_new = existing is None

            result = fetcher.fetch(page_url)
            stats["urls_fetched"] += 1

            if result.status_code == 404:
                stats["urls_failed"] += 1
                repo.mark_store_failed(slug, 404)
                print(f"[{idx}/{len(site_slugs)}] {site_slug}: NOT FOUND")
                continue

            if result.status_code != 200 or not result.text:
                stats["urls_failed"] += 1
                print(f"[{idx}/{len(site_slugs)}] {site_slug}: HTTP {result.status_code}")
                continue

            parsed = parse_store_page(result.text, page_url, site_slug)
            parsed["http_status"] = result.status_code
            parsed["priority"] = 2

            store_id = repo.upsert_store(parsed)
            inserted, updated = repo.sync_coupons(store_id, parsed.get("coupons", []))
            repo.dedupe_coupons_by_label(store_id)
            repo.upsert_crawl_url(page_url, "store", priority=2)
            repo.commit()
            refresh_store_api_lookup(store_id)

            if is_new:
                stats["urls_new"] += 1
            else:
                stats["urls_changed"] += 1

            coupon_count = len(parsed.get("coupons", []))
            print(
                f"[{idx}/{len(site_slugs)}] {site_slug}: "
                f"{coupon_count} coupons (+{inserted}/~{updated})"
            )

        if blog_urls:
            print(f"Crawling {len(blog_urls)} blog posts...")
            for idx, blog_url in enumerate(sorted(blog_urls), start=1):
                _url_type, site_slug = classify_url(blog_url)
                if not site_slug:
                    continue

                result = fetcher.fetch(blog_url)
                stats["urls_fetched"] += 1
                if result.status_code != 200 or not result.text:
                    stats["urls_failed"] += 1
                    continue

                db_blog_slug = db_slug(site_slug)
                parsed = parse_blog_page(result.text, blog_url, db_blog_slug)
                changed = repo.upsert_page(parsed)
                repo.upsert_crawl_url(blog_url, "blog", priority=3)
                repo.commit()

                if changed:
                    stats["urls_changed"] += 1
                else:
                    stats["urls_skipped"] += 1

                if idx % 25 == 0 or idx == len(blog_urls):
                    print(f"  blog [{idx}/{len(blog_urls)}]")

        db_stats = repo.count_stats()
        stats.update(db_stats)
        stats["notes"] = f"globalcouponshub stores={len(site_slugs)} blogs={len(blog_urls)}"
        repo.finish_run(run_id, stats)

        print("=== GLOBALCOUPONSHUB CRAWL DONE ===")
        print(f"Stores crawled: {len(site_slugs)}")
        print(f"Blog posts: {len(blog_urls)}")
        print(f"Total stores in DB: {stats['stores_total']}")
        print(f"Active coupons: {stats['coupons_active']}")
    except Exception as exc:
        repo.rollback()
        repo.finish_run(run_id, {**stats, "notes": f"error: {exc}"})
        raise
    finally:
        fetcher.close()
        repo.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
