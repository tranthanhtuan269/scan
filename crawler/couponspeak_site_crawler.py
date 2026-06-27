"""Shared crawl loop for couponspeak-style coupon sites."""

from __future__ import annotations

import subprocess
from collections.abc import Callable
from pathlib import Path
from typing import Any

from crawler.couponspeak_discovery import (
    classify_url,
    discover_blog_urls,
    discover_store_urls,
    discover_stores_via_search,
)
from crawler.fetcher import Fetcher
from crawler.logo_assets import localize_store_logo
from crawler.parsers.blog import parse_blog_page
from db.repository import Repository

SCAN_ROOT = Path(__file__).resolve().parents[1]
ParseStoreFn = Callable[[str, str, str], dict[str, Any]]
DbSlugFn = Callable[[str], str]


def refresh_store_api_lookup(store_id: int) -> None:
    script = SCAN_ROOT / "jobs" / "refresh_store_api_lookup.php"
    if not script.exists():
        return
    subprocess.run(
        ["php", str(script), str(store_id)],
        capture_output=True,
        timeout=30,
        check=False,
    )


def crawl_couponspeak_platform(
    *,
    site_label: str,
    base_url: str,
    repo: Repository,
    fetcher: Fetcher,
    stats: dict[str, int],
    db_slug_fn: DbSlugFn,
    parse_store_fn: ParseStoreFn,
    seeds_only: bool = False,
    skip_blog: bool = False,
    skip_existing: bool = True,
) -> tuple[int, int]:
    """Discover and crawl stores. Returns (store_count, blog_count)."""
    print(f"Phase 1: discover from seeds + deals ({site_label})...")
    store_urls = discover_store_urls(fetcher, base_url)
    print(f"  Seeds/deals: {len(store_urls)} stores")

    if not seeds_only:
        print("Phase 2: discover via /search...")
        store_urls.update(discover_stores_via_search(fetcher, base_url))
        print(f"  Total stores: {len(store_urls)}")

    blog_urls: set[str] = set()
    if not skip_blog:
        blog_urls = discover_blog_urls(fetcher, base_url)
        print(f"Discovered {len(blog_urls)} blog URLs")

    site_slugs = sorted(
        {
            slug
            for url in store_urls
            for url_type, slug in [classify_url(url, base_url)]
            if url_type == "store" and slug
        }
    )
    print(f"Crawling {len(site_slugs)} stores (skip_existing={skip_existing})...")

    for idx, site_slug in enumerate(site_slugs, start=1):
        page_url = f"{base_url.rstrip('/')}/store/{site_slug}"
        slug = db_slug_fn(site_slug)
        existing = repo.get_store_by_slug(slug)

        if skip_existing and existing:
            stats["urls_skipped"] += 1
            print(f"[{idx}/{len(site_slugs)}] {site_slug}: SKIP (exists)")
            continue

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

        parsed = parse_store_fn(result.text, page_url, site_slug)
        parsed["http_status"] = result.status_code
        parsed["priority"] = 2
        localize_store_logo(parsed, slug, base_url)

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
            _url_type, site_slug = classify_url(blog_url, base_url)
            if not site_slug:
                continue

            result = fetcher.fetch(blog_url)
            stats["urls_fetched"] += 1
            if result.status_code != 200 or not result.text:
                stats["urls_failed"] += 1
                continue

            db_blog_slug = db_slug_fn(site_slug)
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

    return len(site_slugs), len(blog_urls)
