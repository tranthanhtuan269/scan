#!/usr/bin/env python3
"""Daily incremental crawl — prioritize hot stores, skip unchanged pages."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import (  # noqa: E402
    BASE_URL,
    FULL_CRAWL_INTERVAL_DAYS,
    INCREMENTAL_STALE_DAYS,
    INCREMENTAL_TIER3_BATCH,
    SEED_URLS,
)
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

    run_id = repo.start_run("incremental")
    print("=== INCREMENTAL CRAWL START ===")

    try:
        days_since_full = repo.days_since_last_full_crawl()
        if days_since_full is None or days_since_full >= FULL_CRAWL_INTERVAL_DAYS:
            print("Running search discovery for new stores (weekly)...")
            discovered = discovery.discover_stores_via_search()
            known = set(repo.get_all_store_slugs())
            for store_url in sorted(discovered):
                _t, slug = classify_url(store_url)
                if slug and slug not in known:
                    pipeline.process_store(f"{BASE_URL}/store/{slug}", slug, priority=1, force=True)
                    print(f"DISCOVERED NEW: {slug}")

        hot_slugs = pipeline.collect_hot_store_slugs(SEED_URLS)
        print(f"Hot stores from seed pages: {len(hot_slugs)}")

        stores = repo.get_stores_for_incremental(
            hot_slugs,
            stale_days=INCREMENTAL_STALE_DAYS,
            tier3_batch_limit=INCREMENTAL_TIER3_BATCH,
        )
        print(f"Stores queued this run: {len(stores)} (hot + stale batch)")
        known_slugs = {s["slug"] for s in stores}

        # New stores discovered on seed pages but not yet in DB
        for slug in sorted(hot_slugs - known_slugs):
            url = f"{BASE_URL}/store/{slug}"
            pipeline.process_store(url, slug, priority=1, force=True)
            print(f"NEW store: {slug}")

        for store in stores:
            slug = store["slug"]
            priority = 2 if slug in hot_slugs else 3
            url = store["page_url"] or f"{BASE_URL}/store/{slug}"
            changed = pipeline.process_store(url, slug, priority=priority, force=False)
            if changed:
                print(f"UPDATED: {slug}")
            elif priority <= 2:
                print(f"CHECKED: {slug}")

        stats = pipeline.stats
        db_stats = repo.count_stats()
        stats.update(db_stats)
        stats["notes"] = f"incremental hot={len(hot_slugs)}"
        repo.finish_run(run_id, stats)

        print("=== INCREMENTAL CRAWL DONE ===")
        print(f"Fetched: {stats['urls_fetched']}")
        print(f"New: {stats['urls_new']}")
        print(f"Updated: {stats['urls_changed']}")
        print(f"Skipped (unchanged): {stats['urls_skipped']}")
        print(f"Failed: {stats['urls_failed']}")
        print(f"Total stores: {stats['stores_total']}")
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
