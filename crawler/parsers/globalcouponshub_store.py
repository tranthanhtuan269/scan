"""Parser for globalcouponshub.com (same HTML platform as couponspeak)."""

from __future__ import annotations

from typing import Any

from crawler.parsers import store as couponspeak_store

BASE_URL = "https://globalcouponshub.com"
SLUG_PREFIX = "gch-"


def db_slug(site_slug: str) -> str:
    return f"{SLUG_PREFIX}{site_slug}"


def site_slug_from_db(db_slug_value: str) -> str:
    if db_slug_value.startswith(SLUG_PREFIX):
        return db_slug_value[len(SLUG_PREFIX) :]
    return db_slug_value


def parse_store_page(html: str, page_url: str, site_slug: str) -> dict[str, Any]:
    parsed = couponspeak_store.parse_store_page(html, page_url, db_slug(site_slug))
    parsed["slug"] = db_slug(site_slug)
    parsed["page_url"] = page_url
    return parsed
