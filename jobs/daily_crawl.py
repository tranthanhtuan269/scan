#!/usr/bin/env python3
"""
Scheduled crawl entry point.

- Chạy incremental nếu đã đủ CRAWL_INTERVAL_DAYS kể từ lần crawl trước.
- Tự động chạy full crawl lần đầu (chưa có dữ liệu).
- Có thể force bằng: python jobs/daily_crawl.py --force
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import CRAWL_INTERVAL_DAYS  # noqa: E402
from db.repository import Repository  # noqa: E402


def should_run(force: bool = False) -> tuple[bool, str]:
    if force:
        return True, "forced"

    repo = Repository()
    try:
        stats = repo.count_stats()
        if stats["stores_total"] == 0:
            return True, "no data yet - first full crawl needed"

        days = repo.days_since_last_any_crawl()
        if days is None:
            return True, "no previous crawl run"

        if days >= CRAWL_INTERVAL_DAYS:
            return True, f"last crawl was {days} day(s) ago (interval={CRAWL_INTERVAL_DAYS})"

        return False, f"skipped - only {days} day(s) since last crawl (interval={CRAWL_INTERVAL_DAYS})"
    finally:
        repo.close()


def main():
    parser = argparse.ArgumentParser(description="Daily couponspeak.com crawl scheduler")
    parser.add_argument("--force", action="store_true", help="Run even if interval not reached")
    parser.add_argument(
        "--mode",
        choices=["auto", "full", "incremental"],
        default="auto",
        help="Crawl mode (auto = full if empty DB, else incremental)",
    )
    args = parser.parse_args()

    sys.stdout.reconfigure(line_buffering=True)

    run, reason = should_run(force=args.force)
    print(f"[daily_crawl] {reason}")

    if not run:
        return 0

    if args.mode == "full":
        from jobs.full_crawl import main as full_main

        full_main()
        return 0

    if args.mode == "incremental":
        from jobs.incremental_crawl import main as inc_main

        inc_main()
        return 0

    # auto
    repo = Repository()
    try:
        has_data = repo.count_stats()["stores_total"] > 0
    finally:
        repo.close()

    if has_data:
        from jobs.incremental_crawl import main as inc_main

        inc_main()
    else:
        from jobs.full_crawl import main as full_main

        full_main()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
