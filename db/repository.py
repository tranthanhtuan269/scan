from __future__ import annotations

from datetime import datetime
from typing import Any

from db.connection import get_connection


def now() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def current_month() -> str:
    return datetime.now().strftime("%Y-%m")


class Repository:
    def __init__(self):
        self.conn = get_connection()

    def close(self):
        self.conn.close()

    def commit(self):
        self.conn.commit()

    def rollback(self):
        self.conn.rollback()

    def execute(self, sql: str, params: tuple | list | None = None):
        with self.conn.cursor() as cur:
            cur.execute(sql, params or ())
            return cur

    def fetchone(self, sql: str, params: tuple | list | None = None):
        with self.conn.cursor() as cur:
            cur.execute(sql, params or ())
            return cur.fetchone()

    def fetchall(self, sql: str, params: tuple | list | None = None):
        with self.conn.cursor() as cur:
            cur.execute(sql, params or ())
            return cur.fetchall()

    def start_run(self, mode: str) -> int:
        ts = now()
        cur = self.execute(
            "INSERT INTO crawl_runs (mode, started_at) VALUES (%s, %s)",
            (mode, ts),
        )
        self.commit()
        return cur.lastrowid

    def finish_run(self, run_id: int, stats: dict[str, Any]):
        self.execute(
            """
            UPDATE crawl_runs SET
                finished_at = %s,
                urls_fetched = %s,
                urls_changed = %s,
                urls_new = %s,
                urls_skipped = %s,
                urls_failed = %s,
                stores_total = %s,
                coupons_active = %s,
                notes = %s
            WHERE id = %s
            """,
            (
                now(),
                stats.get("urls_fetched", 0),
                stats.get("urls_changed", 0),
                stats.get("urls_new", 0),
                stats.get("urls_skipped", 0),
                stats.get("urls_failed", 0),
                stats.get("stores_total", 0),
                stats.get("coupons_active", 0),
                stats.get("notes"),
                run_id,
            ),
        )
        self.commit()

    def upsert_crawl_url(
        self,
        url: str,
        url_type: str,
        priority: int = 3,
        discovered_from: str | None = None,
    ):
        ts = now()
        self.execute(
            """
            INSERT INTO crawl_urls (url, url_type, priority, discovered_from, first_seen_at)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                url_type = VALUES(url_type),
                priority = LEAST(priority, VALUES(priority)),
                discovered_from = COALESCE(VALUES(discovered_from), discovered_from)
            """,
            (url, url_type, priority, discovered_from, ts),
        )

    def get_store_by_slug(self, slug: str):
        return self.fetchone("SELECT * FROM stores WHERE slug = %s", (slug,))

    def get_all_store_slugs(self) -> list[str]:
        rows = self.fetchall(
            "SELECT slug FROM stores WHERE is_active IN (1, -1) ORDER BY priority, last_crawled_at"
        )
        return [r["slug"] for r in rows]

    def get_stores_for_incremental(
        self,
        hot_slugs: set[str],
        stale_days: int = 1,
        tier3_batch_limit: int = 200,
    ) -> list[dict]:
        rows = self.fetchall(
            """
            SELECT * FROM stores WHERE is_active = 1
            ORDER BY last_crawled_at IS NULL DESC, last_crawled_at ASC
            """
        )
        selected: list[dict] = []
        tier3_count = 0

        for row in rows:
            slug = row["slug"]
            if slug in hot_slugs:
                row["priority"] = 2
                selected.append(row)
                continue

            # Tier 3: only re-check stale stores, batched per run
            last = row.get("last_crawled_at")
            is_stale = last is None
            if not is_stale and stale_days > 0:
                stale_row = self.fetchone(
                    "SELECT TIMESTAMPDIFF(DAY, %s, NOW()) >= %s AS is_stale",
                    (last, stale_days),
                )
                is_stale = bool(stale_row and stale_row["is_stale"])

            if is_stale and tier3_count < tier3_batch_limit:
                row["priority"] = 3
                selected.append(row)
                tier3_count += 1

        selected.sort(
            key=lambda r: (
                r["priority"],
                r["last_crawled_at"] is not None,
                str(r["last_crawled_at"] or ""),
            )
        )
        return selected

    def upsert_store(self, data: dict) -> int:
        existing = self.get_store_by_slug(data["slug"])
        ts = now()
        if existing:
            self.execute(
                """
                UPDATE stores SET
                    name = %s, page_url = %s, rating = %s, vote_count = %s,
                    coupon_codes_count = %s, deals_count = %s, best_offer = %s,
                    about_html = %s, how_to_apply_html = %s, faq_html = %s,
                    affiliate_url = %s, logo_url = %s, meta_title = %s, meta_description = %s,
                    content_hash = %s, coupon_section_hash = %s,
                    etag = %s, last_modified = %s, http_status = %s,
                    priority = LEAST(priority, %s), is_active = 1, fail_count = 0,
                    last_crawled_at = %s,
                    last_changed_at = CASE WHEN coupon_section_hash <> %s OR coupon_section_hash IS NULL THEN %s ELSE last_changed_at END
                WHERE id = %s
                """,
                (
                    data.get("name"),
                    data["page_url"],
                    data.get("rating"),
                    data.get("vote_count"),
                    data.get("coupon_codes_count", 0),
                    data.get("deals_count", 0),
                    data.get("best_offer"),
                    data.get("about_html"),
                    data.get("how_to_apply_html"),
                    data.get("faq_html"),
                    data.get("affiliate_url"),
                    data.get("logo_url"),
                    data.get("meta_title"),
                    data.get("meta_description"),
                    data.get("content_hash"),
                    data.get("coupon_section_hash"),
                    data.get("etag"),
                    data.get("last_modified"),
                    data.get("http_status"),
                    data.get("priority", 3),
                    ts,
                    data.get("coupon_section_hash"),
                    ts,
                    existing["id"],
                ),
            )
            self.commit()
            return existing["id"]

        cur = self.execute(
            """
            INSERT INTO stores (
                slug, name, page_url, rating, vote_count,
                coupon_codes_count, deals_count, best_offer,
                about_html, how_to_apply_html, faq_html,
                affiliate_url, logo_url, meta_title, meta_description,
                content_hash, coupon_section_hash, etag, last_modified, http_status,
                priority, first_seen_at, last_crawled_at, last_changed_at
            ) VALUES (
                %s, %s, %s, %s, %s,
                %s, %s, %s,
                %s, %s, %s,
                %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s
            )
            """,
            (
                data["slug"],
                data.get("name"),
                data["page_url"],
                data.get("rating"),
                data.get("vote_count"),
                data.get("coupon_codes_count", 0),
                data.get("deals_count", 0),
                data.get("best_offer"),
                data.get("about_html"),
                data.get("how_to_apply_html"),
                data.get("faq_html"),
                data.get("affiliate_url"),
                data.get("logo_url"),
                data.get("meta_title"),
                data.get("meta_description"),
                data.get("content_hash"),
                data.get("coupon_section_hash"),
                data.get("etag"),
                data.get("last_modified"),
                data.get("http_status"),
                data.get("priority", 3),
                ts,
                ts,
                ts,
            ),
        )
        self.commit()
        return cur.lastrowid

    def mark_store_failed(self, slug: str, http_status: int | None):
        self.execute(
            """
            UPDATE stores SET
                fail_count = fail_count + 1,
                http_status = %s,
                last_crawled_at = %s,
                is_active = CASE
                    WHEN is_active = -1 THEN -1
                    WHEN fail_count >= 5 AND http_status = 404 THEN 0
                    ELSE is_active
                END
            WHERE slug = %s
            """,
            (http_status, now(), slug),
        )
        self.commit()

    def sync_coupons(self, store_id: int, coupons: list[dict]) -> tuple[int, int]:
        ts = now()
        month = current_month()
        seen_fingerprints: set[str] = set()
        inserted = 0
        updated = 0

        for coupon in coupons:
            fp = coupon["fingerprint"]
            seen_fingerprints.add(fp)
            existing = self.fetchone(
                "SELECT id, fingerprint FROM coupons WHERE store_id = %s AND fingerprint = %s",
                (store_id, fp),
            )
            if existing:
                self.execute(
                    """
                    UPDATE coupons SET
                        offer_id = %s, coupon_type = %s, is_verified = %s,
                        discount_label = %s, title = %s, description = %s,
                        coupon_code = %s, offer_url = %s, affiliate_url = %s,
                        button_text = %s, status = 'active', coupon_month = %s,
                        last_seen_at = %s,
                        last_changed_at = %s
                    WHERE id = %s
                    """,
                    (
                        coupon.get("offer_id"),
                        coupon.get("coupon_type", "deal"),
                        coupon.get("is_verified", 0),
                        coupon.get("discount_label"),
                        coupon.get("title"),
                        coupon.get("description"),
                        coupon.get("coupon_code"),
                        coupon.get("offer_url"),
                        coupon.get("affiliate_url"),
                        coupon.get("button_text"),
                        month,
                        ts,
                        ts,
                        existing["id"],
                    ),
                )
                updated += 1
            else:
                self.execute(
                    """
                    INSERT INTO coupons (
                        store_id, offer_id, fingerprint, coupon_type, is_verified,
                        discount_label, title, description, coupon_code,
                        offer_url, affiliate_url, button_text, status, coupon_month,
                        first_seen_at, last_seen_at, last_changed_at
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'active', %s, %s, %s, %s)
                    """,
                    (
                        store_id,
                        coupon.get("offer_id"),
                        fp,
                        coupon.get("coupon_type", "deal"),
                        coupon.get("is_verified", 0),
                        coupon.get("discount_label"),
                        coupon.get("title"),
                        coupon.get("description"),
                        coupon.get("coupon_code"),
                        coupon.get("offer_url"),
                        coupon.get("affiliate_url"),
                        coupon.get("button_text"),
                        month,
                        ts,
                        ts,
                        ts,
                    ),
                )
                inserted += 1

        if seen_fingerprints:
            placeholders = ",".join(["%s"] * len(seen_fingerprints))
            self.execute(
                f"""
                UPDATE coupons SET status = 'expired', last_changed_at = %s
                WHERE store_id = %s AND status = 'active' AND coupon_month = %s
                  AND fingerprint NOT IN ({placeholders})
                """,
                (ts, store_id, month, *seen_fingerprints),
            )
        else:
            self.execute(
                """
                UPDATE coupons SET status = 'expired', last_changed_at = %s
                WHERE store_id = %s AND status = 'active' AND coupon_month = %s
                """,
                (ts, store_id, month),
            )

        self.commit()
        return inserted, updated

    def dedupe_coupons_by_label(self, store_id: int) -> int:
        """Mark duplicate active coupons (same discount_label, case-insensitive) as legacy (-1)."""
        row = self.fetchone(
            """
            SELECT COUNT(*) AS c FROM (
                SELECT id
                FROM (
                    SELECT id,
                        ROW_NUMBER() OVER (
                            PARTITION BY LOWER(TRIM(discount_label))
                            ORDER BY is_verified DESC,
                                (coupon_code IS NOT NULL AND coupon_code != '') DESC,
                                last_seen_at DESC,
                                id ASC
                        ) AS rn
                    FROM coupons
                    WHERE store_id = %s
                      AND status = 'active'
                      AND discount_label IS NOT NULL
                      AND TRIM(discount_label) != ''
                ) ranked
                WHERE ranked.rn > 1
            ) losers
            """,
            (store_id,),
        )
        count = int(row["c"] or 0) if row else 0
        if count == 0:
            return 0

        ts = now()
        self.execute(
            """
            UPDATE coupons c
            INNER JOIN (
                SELECT id FROM (
                    SELECT id,
                        ROW_NUMBER() OVER (
                            PARTITION BY LOWER(TRIM(discount_label))
                            ORDER BY is_verified DESC,
                                (coupon_code IS NOT NULL AND coupon_code != '') DESC,
                                last_seen_at DESC,
                                id ASC
                        ) AS rn
                    FROM coupons
                    WHERE store_id = %s
                      AND status = 'active'
                      AND discount_label IS NOT NULL
                      AND TRIM(discount_label) != ''
                ) ranked
                WHERE ranked.rn > 1
            ) losers ON losers.id = c.id
            SET c.status = '-1', c.last_changed_at = %s
            """,
            (store_id, ts),
        )
        self.commit()
        return count

    def upsert_page(self, data: dict) -> bool:
        existing = self.fetchone("SELECT id, content_hash FROM pages WHERE url = %s", (data["url"],))
        ts = now()
        changed = not existing or existing.get("content_hash") != data.get("content_hash")

        if existing:
            self.execute(
                """
                UPDATE pages SET
                    page_type = %s, slug = %s, title = %s, excerpt = %s,
                    content_html = %s, content_hash = %s, http_status = %s,
                    is_active = 1, last_crawled_at = %s,
                    last_changed_at = CASE WHEN content_hash <> %s OR content_hash IS NULL THEN %s ELSE last_changed_at END
                WHERE id = %s
                """,
                (
                    data.get("page_type", "other"),
                    data.get("slug"),
                    data.get("title"),
                    data.get("excerpt"),
                    data.get("content_html"),
                    data.get("content_hash"),
                    data.get("http_status"),
                    ts,
                    data.get("content_hash"),
                    ts,
                    existing["id"],
                ),
            )
        else:
            self.execute(
                """
                INSERT INTO pages (
                    url, page_type, slug, title, excerpt, content_html,
                    content_hash, http_status, first_seen_at, last_crawled_at, last_changed_at
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    data["url"],
                    data.get("page_type", "other"),
                    data.get("slug"),
                    data.get("title"),
                    data.get("excerpt"),
                    data.get("content_html"),
                    data.get("content_hash"),
                    data.get("http_status"),
                    ts,
                    ts,
                    ts,
                ),
            )
        self.commit()
        return changed

    def days_since_last_run(self, mode: str) -> int | None:
        row = self.fetchone(
            """
            SELECT TIMESTAMPDIFF(DAY, finished_at, NOW()) AS days_ago
            FROM crawl_runs
            WHERE mode = %s AND finished_at IS NOT NULL
            ORDER BY finished_at DESC
            LIMIT 1
            """,
            (mode,),
        )
        if not row or row["days_ago"] is None:
            return None
        return int(row["days_ago"])

    def days_since_last_full_crawl(self) -> int | None:
        return self.days_since_last_run("full")

    def days_since_last_incremental_crawl(self) -> int | None:
        return self.days_since_last_run("incremental")

    def days_since_last_any_crawl(self) -> int | None:
        row = self.fetchone(
            """
            SELECT TIMESTAMPDIFF(DAY, finished_at, NOW()) AS days_ago
            FROM crawl_runs
            WHERE finished_at IS NOT NULL
            ORDER BY finished_at DESC
            LIMIT 1
            """
        )
        if not row or row["days_ago"] is None:
            return None
        return int(row["days_ago"])

    def count_stats(self) -> dict[str, int]:
        stores = self.fetchone("SELECT COUNT(*) AS c FROM stores WHERE is_active = 1")
        coupons = self.fetchone("SELECT COUNT(*) AS c FROM coupons WHERE status = 'active'")
        pages = self.fetchone("SELECT COUNT(*) AS c FROM pages WHERE is_active = 1")
        return {
            "stores_total": stores["c"] if stores else 0,
            "coupons_active": coupons["c"] if coupons else 0,
            "pages_total": pages["c"] if pages else 0,
        }
