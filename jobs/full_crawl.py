#!/usr/bin/env python3
"""Full crawl of couponspeak.com — discover all stores and save to MySQL."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import BASE_URL, SEED_URLS  # noqa: E402
from crawler.discovery import Discovery  # noqa: E402
from crawler.fetcher import Fetcher  # noqa: E402
from crawler.pipeline import CrawlPipeline  # noqa: E402
from crawler.utils import classify_url  # noqa: E402
from db.repository import Repository  # noqa: E402


def main():
    import sys
    sys.stdout.reconfigure(line_buffering=True)

    repo = Repository()
    fetcher = Fetcher()
    pipeline = CrawlPipeline(repo, fetcher)
    discovery = Discovery(fetcher)

    run_id = repo.start_run("full")
    print("=== FULL CRAWL START ===")
    print("Discovering store URLs from seed pages...")

    try:
        print("Phase 1: BFS from seed pages...")
        store_urls = discovery.discover_store_urls(SEED_URLS)
        print(f"  Seeds found {len(store_urls)} stores")

        print("Phase 2: Search discovery (a-z, 0-9)...")
        search_urls = discovery.discover_stores_via_search()
        store_urls.update(search_urls)
        print(f"Discovered {len(store_urls)} store URLs total (seeds + search)")

        blog_urls: set[str] = set()
        for seed in SEED_URLS:
            result = fetcher.fetch(seed)
            if result.status_code == 200 and result.text:
                links = discovery.discover_from_html(result.text, seed)
                blog_urls.update(links["blog"])

        print(f"Discovered {len(blog_urls)} blog URLs from seeds")

        for idx, store_url in enumerate(sorted(store_urls), start=1):
            _url_type, slug = classify_url(store_url)
            if not slug:
                continue
            url = f"{BASE_URL}/store/{slug}"
            changed = pipeline.process_store(url, slug, priority=2, force=True)
            status = "UPDATED" if changed else "SKIP"
            print(f"[{idx}/{len(store_urls)}] {slug}: {status}")

        for idx, blog_url in enumerate(sorted(blog_urls), start=1):
            _url_type, slug = classify_url(blog_url)
            changed = pipeline.process_blog(blog_url, slug)
            status = "UPDATED" if changed else "SKIP"
            print(f"[blog {idx}/{len(blog_urls)}] {slug or blog_url}: {status}")

        stats = pipeline.stats
        db_stats = repo.count_stats()
        stats.update(db_stats)
        stats["notes"] = f"full crawl discovered={len(store_urls)}"
        repo.finish_run(run_id, stats)

        print("=== FULL CRAWL DONE ===")
        print(f"Fetched: {stats['urls_fetched']}")
        print(f"New stores: {stats['urls_new']}")
        print(f"Updated: {stats['urls_changed']}")
        print(f"Skipped: {stats['urls_skipped']}")
        print(f"Failed: {stats['urls_failed']}")
        print(f"Total stores in DB: {stats['stores_total']}")
        print(f"Active coupons: {stats['coupons_active']}")
    except Exception as exc:
        repo.rollback()
        repo.finish_run(run_id, {**pipeline.stats, "notes": f"error: {exc}"})
        raise
    finally:
        fetcher.close()
        repo.close()


if __name__ == "__main__":
    main()
