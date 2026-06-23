#!/usr/bin/env python3
"""Remove duplicate coupons within the same store by discount_label (case-insensitive).

Keeps one coupon per (store_id, discount_label) group; deletes the rest.
"""

from __future__ import annotations

import argparse
import sys
from collections import defaultdict
from pathlib import Path

import pymysql

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER  # noqa: E402

STATUS_RANK = {"active": 0, "expired": 1, "removed": 2}


def label_key(value: str | None) -> str | None:
    if value is None:
        return None
    trimmed = value.strip()
    return trimmed.lower() if trimmed else None


def coupon_rank(coupon: dict) -> tuple:
    has_code = 1 if coupon.get("coupon_code") not in (None, "") else 0
    has_affiliate = 1 if coupon.get("affiliate_url") not in (None, "") else 0
    status = STATUS_RANK.get(coupon.get("status") or "removed", 9)
    last_seen = coupon.get("last_seen_at")
    return (-has_code, -has_affiliate, status, last_seen, coupon["id"])


def find_duplicates(coupons: list[dict]) -> list[dict]:
    groups: dict[tuple[int, str], list[dict]] = defaultdict(list)
    for coupon in coupons:
        key = label_key(coupon.get("discount_label"))
        if not key:
            continue
        groups[(coupon["store_id"], key)].append(coupon)

    to_delete: list[dict] = []
    for (store_id, label), group in groups.items():
        if len(group) < 2:
            continue
        group.sort(key=coupon_rank)
        keeper = group[0]
        for duplicate in group[1:]:
            to_delete.append(
                {
                    "store_id": store_id,
                    "label": label,
                    "keep_id": keeper["id"],
                    "keep_label": keeper["discount_label"],
                    "delete_id": duplicate["id"],
                    "delete_label": duplicate["discount_label"],
                    "delete_title": duplicate["title"],
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
            cur.execute(
                """
                SELECT id, store_id, discount_label, title, coupon_code,
                       affiliate_url, status, last_seen_at
                FROM coupons
                WHERE discount_label IS NOT NULL
                  AND TRIM(discount_label) != ''
                """
            )
            coupons = cur.fetchall()
            duplicates = find_duplicates(coupons)

            print(f"Coupons with discount_label: {len(coupons)}")
            print(f"Duplicate coupons to remove: {len(duplicates)}")

            if not duplicates:
                return 0

            log_path = ROOT / "logs" / "dedupe_coupons_by_label.log"
            log_path.parent.mkdir(parents=True, exist_ok=True)
            with log_path.open("a", encoding="utf-8") as log:
                for item in duplicates:
                    line = (
                        f"store_id={item['store_id']} label={item['label']!r} "
                        f"keep_id={item['keep_id']}({item['keep_label']!r}) "
                        f"delete_id={item['delete_id']}({item['delete_label']!r}) "
                        f"title={item['delete_title'][:80]!r}"
                    )
                    print(line)
                    log.write(line + "\n")

                if not args.execute:
                    print("\nDry-run only. Re-run with --execute to delete duplicates.")
                    return 0

                delete_ids = [item["delete_id"] for item in duplicates]
                batch_size = 500
                for offset in range(0, len(delete_ids), batch_size):
                    batch = delete_ids[offset : offset + batch_size]
                    placeholders = ",".join(["%s"] * len(batch))
                    cur.execute(
                        f"DELETE FROM coupons WHERE id IN ({placeholders})",
                        batch,
                    )

            conn.commit()
            print(f"\nDeleted {len(duplicates)} duplicate coupons.")
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
