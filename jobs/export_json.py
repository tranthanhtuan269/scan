#!/usr/bin/env python3
"""Export crawled data to JSON files."""

from __future__ import annotations

import json
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import STORAGE_DIR  # noqa: E402
from db.repository import Repository  # noqa: E402


def main():
    repo = Repository()
    export_dir = STORAGE_DIR / "exports"
    export_dir.mkdir(parents=True, exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")

    stores = repo.fetchall("SELECT * FROM stores WHERE is_active = 1 ORDER BY name")
    coupons = repo.fetchall(
        """
        SELECT c.*, s.slug AS store_slug, s.name AS store_name
        FROM coupons c
        JOIN stores s ON s.id = c.store_id
        WHERE c.status = 'active'
        ORDER BY s.name, c.discount_label
        """
    )
    pages = repo.fetchall("SELECT * FROM pages WHERE is_active = 1 ORDER BY last_changed_at DESC")

    payload = {
        "exported_at": ts,
        "stores": stores,
        "coupons": coupons,
        "pages": pages,
        "stats": repo.count_stats(),
    }

    out_file = export_dir / f"couponspeak_{ts}.json"
    out_file.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")

    latest = export_dir / "latest.json"
    latest.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")

    print(f"Exported to {out_file}")
    repo.close()


if __name__ == "__main__":
    main()
