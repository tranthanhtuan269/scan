#!/usr/bin/env python3
"""Crawl viktorreview.com stores and coupons into the existing DB schema."""

from __future__ import annotations

import re
import sys
from pathlib import Path
from urllib.parse import urljoin

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from crawler.fetcher import Fetcher  # noqa: E402
from crawler.parsers.viktorreview_store import (  # noqa: E402
    BASE_URL,
    db_slug,
    parse_store_page,
)
from db.repository import Repository  # noqa: E402

STORE_LINK_RE = re.compile(rf"{re.escape(BASE_URL)}/stores/([a-z0-9-]+)")


def discover_store_slugs(fetcher: Fetcher) -> list[str]:
    slugs: list[str] = []
    seen: set[str] = set()

    for page in range(1, 50):
        url = f"{BASE_URL}/stores" if page == 1 else f"{BASE_URL}/stores?page={page}"
        result = fetcher.fetch(url)
        if result.status_code != 200 or not result.text:
            break

        found = STORE_LINK_RE.findall(result.text)
        new = [s for s in found if s not in seen]
        if not new:
            break

        for slug in new:
            seen.add(slug)
            slugs.append(slug)

    return slugs


def resolve_coupon_affiliates(fetcher: Fetcher, coupons: list[dict]) -> None:
    cache: dict[str, str | None] = {}
    for coupon in coupons:
        go_url = coupon.pop("_go_url", None)
        if not go_url:
            continue
        go_url = urljoin(BASE_URL + "/", go_url)
        if go_url not in cache:
            cache[go_url] = fetcher.fetch_final_url(go_url)
        if cache[go_url]:
            coupon["affiliate_url"] = cache[go_url]


def main() -> int:
    sys.stdout.reconfigure(line_buffering=True)

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

    print("=== VIKTORREVIEW CRAWL START ===")

    try:
        slugs = discover_store_slugs(fetcher)
        stats["urls_fetched"] += 1  # listing pages counted in loop below
        print(f"Discovered {len(slugs)} stores")

        for idx, site_slug in enumerate(slugs, start=1):
            page_url = f"{BASE_URL}/stores/{site_slug}"
            slug = db_slug(site_slug)
            existing = repo.get_store_by_slug(slug)
            is_new = existing is None

            result = fetcher.fetch(page_url)
            stats["urls_fetched"] += 1

            if result.status_code == 404:
                stats["urls_failed"] += 1
                repo.mark_store_failed(slug, 404)
                print(f"[{idx}/{len(slugs)}] {site_slug}: NOT FOUND")
                continue

            if result.status_code != 200 or not result.text:
                stats["urls_failed"] += 1
                print(f"[{idx}/{len(slugs)}] {site_slug}: HTTP {result.status_code}")
                continue

            parsed = parse_store_page(result.text, page_url, site_slug)
            parsed["http_status"] = result.status_code
            parsed["priority"] = 2

            resolve_coupon_affiliates(fetcher, parsed.get("coupons", []))
            stats["urls_fetched"] += len(parsed.get("coupons", []))

            store_id = repo.upsert_store(parsed)
            inserted, updated = repo.sync_coupons(store_id, parsed.get("coupons", []))

            repo.upsert_crawl_url(page_url, "store", priority=2)
            repo.commit()

            if is_new:
                stats["urls_new"] += 1
            else:
                stats["urls_changed"] += 1

            print(
                f"[{idx}/{len(slugs)}] {site_slug}: "
                f"{len(parsed.get('coupons', []))} coupons (+{inserted}/~{updated})"
            )

        db_stats = repo.count_stats()
        stats.update(db_stats)
        stats["notes"] = f"viktorreview stores={len(slugs)}"
        repo.finish_run(run_id, stats)

        print("=== VIKTORREVIEW CRAWL DONE ===")
        print(f"Stores crawled: {len(slugs)}")
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
