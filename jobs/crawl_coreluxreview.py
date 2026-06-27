#!/usr/bin/env python3
"""Crawl coreluxreview.com stores, coupons, and blog posts into the scan DB."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from crawler.couponspeak_site_crawler import crawl_couponspeak_platform  # noqa: E402
from crawler.fetcher import Fetcher  # noqa: E402
from crawler.parsers.coreluxreview_store import (  # noqa: E402
    BASE_URL,
    db_slug,
    parse_store_page,
)
from db.repository import Repository  # noqa: E402


def main() -> int:
    sys.stdout.reconfigure(line_buffering=True)

    parser = argparse.ArgumentParser(description="Crawl coreluxreview.com into scan DB")
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
    parser.add_argument(
        "--update-existing",
        action="store_true",
        help="Re-crawl stores that already exist in DB (default: skip)",
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

    print("=== CORELUXREVIEW CRAWL START ===")

    try:
        store_count, blog_count = crawl_couponspeak_platform(
            site_label="coreluxreview",
            base_url=BASE_URL,
            repo=repo,
            fetcher=fetcher,
            stats=stats,
            db_slug_fn=db_slug,
            parse_store_fn=parse_store_page,
            seeds_only=args.seeds_only,
            skip_blog=args.skip_blog,
            skip_existing=not args.update_existing,
        )

        db_stats = repo.count_stats()
        stats.update(db_stats)
        stats["notes"] = f"coreluxreview stores={store_count} blogs={blog_count}"
        repo.finish_run(run_id, stats)

        print("=== CORELUXREVIEW CRAWL DONE ===")
        print(f"Stores discovered: {store_count}")
        print(f"Blog posts: {blog_count}")
        print(f"New: {stats['urls_new']} | Skipped: {stats['urls_skipped']} | Failed: {stats['urls_failed']}")
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
