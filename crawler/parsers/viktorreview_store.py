from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlparse

from bs4 import BeautifulSoup

from crawler.utils import sha256_text

BASE_URL = "https://viktorreview.com"
SLUG_PREFIX = "vr-"


def db_slug(site_slug: str) -> str:
    return f"{SLUG_PREFIX}{site_slug}"


def site_slug_from_db(db_slug_value: str) -> str:
    if db_slug_value.startswith(SLUG_PREFIX):
        return db_slug_value[len(SLUG_PREFIX) :]
    return db_slug_value


def _coupon_type(badge: str, coupon_code: str | None) -> str:
    if coupon_code:
        return "code"
    if "code" in badge.lower():
        return "code"
    if "deal" in badge.lower():
        return "deal"
    return "other"


def parse_store_page(html: str, page_url: str, site_slug: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "lxml")
    slug = db_slug(site_slug)

    name_el = soup.select_one("h1")
    name = name_el.get_text(strip=True) if name_el else site_slug.replace("-", " ").title()

    logo = soup.select_one(".store-page-header .store-logo-img, .store-logo-img")
    about_el = soup.select_one(".store-description-content")
    website_el = soup.select_one(".store-page-website a, a.store-page-website")

    coupons: list[dict[str, Any]] = []
    codes = 0
    deals = 0

    for article in soup.select("article.coupon-card"):
        title_el = article.select_one(".coupon-title a")
        if not title_el:
            continue

        title = title_el.get_text(strip=True)
        badge_el = article.select_one(".coupon-badge")
        badge = badge_el.get_text(strip=True) if badge_el else ""

        btn = article.select_one("button[data-code]")
        coupon_code = (btn.get("data-code") or "").strip() if btn else None
        if coupon_code == "":
            coupon_code = None

        go_el = article.select_one('a[href*="/go"]')
        offer_href = title_el.get("href") or ""
        go_href = go_el.get("href") if go_el else ""

        coupon_path = urlparse(offer_href).path.rstrip("/")
        coupon_slug = coupon_path.split("/")[-1] if coupon_path else sha256_text(title)[:16]
        offer_id = coupon_slug[:50]

        coupon_type = _coupon_type(badge, coupon_code)
        if coupon_type == "code":
            codes += 1
        elif coupon_type == "deal":
            deals += 1

        discount_label = title
        percent_match = re.match(r"^(\d+%\s*off[^|]*|save\s+.+?)(?:\s+[A-Z]|$)", title, re.I)
        if percent_match:
            discount_label = percent_match.group(1).strip()
        elif badge:
            discount_label = badge

        fingerprint = sha256_text(f"viktorreview|{coupon_slug}|{coupon_type}|{title}")

        coupons.append(
            {
                "offer_id": offer_id,
                "fingerprint": fingerprint,
                "coupon_type": coupon_type,
                "is_verified": 0,
                "discount_label": discount_label,
                "title": title,
                "description": None,
                "coupon_code": coupon_code,
                "offer_url": offer_href or None,
                "affiliate_url": go_href or None,
                "button_text": "Shop Now" if go_el else None,
                "_go_url": go_href or None,
            }
        )

    coupon_grid = soup.select_one(".coupon-grid")
    coupon_section_hash = sha256_text(str(coupon_grid) if coupon_grid else html[:5000])

    return {
        "slug": slug,
        "name": name,
        "page_url": page_url,
        "rating": None,
        "vote_count": None,
        "coupon_codes_count": codes,
        "deals_count": deals,
        "best_offer": coupons[0]["discount_label"] if coupons else None,
        "about_html": str(about_el) if about_el else None,
        "how_to_apply_html": None,
        "faq_html": None,
        "affiliate_url": website_el.get("href") if website_el else None,
        "logo_url": logo.get("src") if logo else None,
        "meta_title": soup.title.get_text(strip=True) if soup.title else None,
        "meta_description": (soup.find("meta", attrs={"name": "description"}) or {}).get("content"),
        "content_hash": sha256_text(html),
        "coupon_section_hash": coupon_section_hash,
        "coupons": coupons,
    }
