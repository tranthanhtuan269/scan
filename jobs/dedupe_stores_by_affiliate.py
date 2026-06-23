#!/usr/bin/env python3
"""Remove duplicate stores that share the same affiliate destination website.

For each destination (merchant site or affiliate-network merchant id), keep the
store with the most active coupons that have affiliate_url; delete the rest.
Coupons are removed via ON DELETE CASCADE.
"""

from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import pymysql

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER  # noqa: E402

SHORTENER_HOSTS = {"t.co", "bit.ly", "tinyurl.com", "goo.gl", "ow.ly"}
CJ_HOSTS = {"anrdoezrs.net", "jdoqocy.com", "tkqlhce.com", "kqzyfj.com", "dpbolvw.net"}


def destination_key(url: str | None) -> str | None:
    if not url or not url.strip():
        return None

    raw = url.strip()
    if not raw.startswith(("http://", "https://")):
        raw = "https://" + raw

    parsed = urlparse(raw)
    host = parsed.netloc.lower()
    if host.startswith("www."):
        host = host[4:]

    query = parse_qs(parsed.query)

    if host == "awin1.com":
        merchant_id = query.get("awinmid", [""])[0]
        return f"awin:{merchant_id}" if merchant_id else None

    if host == "track.flexlinkspro.com":
        foid = query.get("foid", [""])[0]
        if foid:
            return f"flexlinks:{foid.split('.')[0]}"
        return None

    if host == "collabs.shop":
        segment = parsed.path.strip("/").split("/")[0] if parsed.path else ""
        return f"collabs.shop:{segment}" if segment else "collabs.shop"

    cj_match = re.search(r"/click-\d+-(\d+)", parsed.path)
    if cj_match and host in CJ_HOSTS:
        return f"cj:{cj_match.group(1)}"

    if host in SHORTENER_HOSTS:
        path = parsed.path.strip("/")
        return f"{host}:{path}" if path else None

    return host


def load_store_coupon_counts(cur) -> dict[int, dict]:
    cur.execute(
        """
        SELECT s.id, s.slug, s.name, s.page_url,
               COUNT(c.id) AS coupon_count
        FROM stores s
        JOIN coupons c ON c.store_id = s.id
        WHERE c.affiliate_url IS NOT NULL
          AND c.affiliate_url != ''
          AND c.status = 'active'
        GROUP BY s.id, s.slug, s.name, s.page_url
        """
    )
    return {row["id"]: row for row in cur.fetchall()}


def load_store_primary_destinations(cur) -> dict[int, str]:
    cur.execute(
        """
        SELECT store_id, affiliate_url
        FROM coupons
        WHERE affiliate_url IS NOT NULL
          AND affiliate_url != ''
          AND status = 'active'
        """
    )
    by_store: dict[int, list[str]] = defaultdict(list)
    for row in cur.fetchall():
        key = destination_key(row["affiliate_url"])
        if key:
            by_store[row["store_id"]].append(key)

    primary: dict[int, str] = {}
    for store_id, keys in by_store.items():
        counts: dict[str, int] = defaultdict(int)
        for key in keys:
            counts[key] += 1
        primary[store_id] = max(counts, key=counts.get)
    return primary


def find_duplicates(stores: dict[int, dict], primary: dict[int, str]) -> list[dict]:
    groups: dict[str, list[dict]] = defaultdict(list)
    for store_id, store in stores.items():
        key = primary.get(store_id)
        if key:
            groups[key].append(store)

    to_delete: list[dict] = []
    for key, group in groups.items():
        if len(group) < 2:
            continue
        group.sort(key=lambda s: (-s["coupon_count"], s["id"]))
        keeper = group[0]
        for duplicate in group[1:]:
            to_delete.append(
                {
                    "destination": key,
                    "keep_id": keeper["id"],
                    "keep_slug": keeper["slug"],
                    "keep_coupons": keeper["coupon_count"],
                    "delete_id": duplicate["id"],
                    "delete_slug": duplicate["slug"],
                    "delete_coupons": duplicate["coupon_count"],
                    "page_url": duplicate["page_url"],
                }
            )
    return to_delete


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--execute",
        action="store_true",
        help="Apply deletions (default is dry-run)",
    )
    args = parser.parse_args()

    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )

    try:
        with conn.cursor() as cur:
            stores = load_store_coupon_counts(cur)
            primary = load_store_primary_destinations(cur)
            duplicates = find_duplicates(stores, primary)

            print(f"Stores with affiliate coupons: {len(stores)}")
            print(f"Duplicate stores to remove: {len(duplicates)}")

            if not duplicates:
                return 0

            log_path = ROOT / "logs" / "dedupe_stores.log"
            log_path.parent.mkdir(parents=True, exist_ok=True)
            with log_path.open("a", encoding="utf-8") as log:
                for item in duplicates:
                    line = (
                        f"destination={item['destination']} "
                        f"keep={item['keep_slug']}({item['keep_coupons']}) "
                        f"delete={item['delete_slug']}({item['delete_coupons']})"
                    )
                    print(line)
                    log.write(line + "\n")

                if not args.execute:
                    print("\nDry-run only. Re-run with --execute to delete duplicates.")
                    return 0

                delete_ids = [item["delete_id"] for item in duplicates]
                page_urls = [item["page_url"] for item in duplicates if item["page_url"]]

                placeholders = ",".join(["%s"] * len(delete_ids))
                cur.execute(f"DELETE FROM stores WHERE id IN ({placeholders})", delete_ids)

                if page_urls:
                    url_placeholders = ",".join(["%s"] * len(page_urls))
                    cur.execute(
                        f"DELETE FROM crawl_urls WHERE url IN ({url_placeholders})",
                        page_urls,
                    )

            conn.commit()
            print(f"\nDeleted {len(duplicates)} duplicate stores.")
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
