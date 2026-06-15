from __future__ import annotations

import re
from typing import Any

from bs4 import BeautifulSoup

from crawler.utils import parse_int, parse_rating, sha256_text

OFFER_ID_RE = re.compile(r"cpid-(\d+)|offer=(\d+)")


def _extract_offer_id(element) -> str | None:
    deal_desc = element.select_one(".deal-desc")
    if deal_desc and deal_desc.get("id"):
        match = OFFER_ID_RE.search(deal_desc["id"])
        if match:
            return match.group(1)

    for tag in element.select("[data-url]"):
        match = OFFER_ID_RE.search(tag.get("data-url", ""))
        if match:
            return match.group(2) or match.group(1)
    return None


def _coupon_type_from_classes(class_list: list[str]) -> str:
    classes = set(class_list)
    if "js-filter-coupon-type-codes" in classes:
        return "code"
    if "js-filter-coupon-type-deals" in classes:
        return "deal"
    return "other"


def _extract_coupon_code(article) -> str | None:
    btn_el = article.select_one(".get-deal a, .get-code a, .get-coupon a")
    if btn_el:
        code = btn_el.get("data-code") or btn_el.get("data-coupon")
        if code and str(code).strip():
            return str(code).strip()

    code_wrap = article.select_one(".get-code, .get-coupon")
    if code_wrap:
        for span in code_wrap.select("span"):
            text = span.get_text(strip=True)
            if text and text.lower() not in {"get code", "copy", "tap to copy"}:
                return text

    return None


def _extract_button(article):
    return article.select_one(".get-deal a, .get-code a, .get-coupon a")


def _coupon_fingerprint(
    offer_id: str | None,
    title: str,
    discount: str,
    coupon_type: str,
    coupon_code: str | None = None,
) -> str:
    # offer_id is stable; coupon_code is filled in later and must not change the fingerprint
    base = f"{offer_id or ''}|{coupon_type}|{discount}|{title}".strip().lower()
    return sha256_text(base)


def parse_store_page(html: str, page_url: str, slug: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "lxml")

    name_tag = soup.select_one("a.go-store")
    name = name_tag.get_text(strip=True) if name_tag else slug.replace("-", " ").title()

    rating_tag = soup.select_one('[itemprop="ratingValue"]')
    votes_tag = soup.select_one('[itemprop="ratingCount"]')

    stats = {}
    for row in soup.select("table.merchant-stats tr"):
        label = row.select_one(".merchant-title")
        value = row.select_one(".merchant-data")
        if label and value:
            stats[label.get_text(strip=True).lower()] = value.get_text(strip=True)

    logo = soup.select_one(".logo img")
    affiliate_tag = soup.select_one("a.go-store[data-affiliate_url]") or soup.select_one("[data-affiliate_url]")

    coupon_articles = soup.select("article.cp")
    coupons: list[dict[str, Any]] = []
    coupon_section = soup.select_one(".js-normal") or soup.select_one(".container-codes")

    for article in coupon_articles:
        classes = article.get("class", [])
        discount_el = article.select_one(".discount")
        discount_label = discount_el.get_text(strip=True) if discount_el else None

        title_el = article.select_one("h2.title a") or article.select_one("h2.title")
        title = title_el.get_text(strip=True) if title_el else ""

        desc_el = article.select_one(".last-click-time-wrap .title")
        description = desc_el.get_text(strip=True) if desc_el else None

        type_el = article.select_one(".type-deal span, .type-code span")
        type_label = type_el.get_text(strip=True) if type_el else ""

        btn_el = _extract_button(article)
        button_text = btn_el.get_text(strip=True) if btn_el else None

        offer_url = None
        affiliate_url = None
        if btn_el:
            offer_url = btn_el.get("data-url")
            affiliate_url = btn_el.get("data-affiliate_url")

        coupon_code = _extract_coupon_code(article)

        offer_id = _extract_offer_id(article)
        coupon_type = _coupon_type_from_classes(classes)
        if coupon_code:
            coupon_type = "code"
        elif "code" in type_label.lower():
            coupon_type = "code"
        elif "deal" in type_label.lower():
            coupon_type = "deal"

        is_verified = "verify" in classes or "verified" in type_label.lower()

        fingerprint = _coupon_fingerprint(
            offer_id,
            title,
            discount_label or "",
            coupon_type,
            coupon_code,
        )
        coupons.append(
            {
                "offer_id": offer_id,
                "fingerprint": fingerprint,
                "coupon_type": coupon_type,
                "is_verified": 1 if is_verified else 0,
                "discount_label": discount_label,
                "title": title,
                "description": description,
                "coupon_code": coupon_code,
                "offer_url": offer_url,
                "affiliate_url": affiliate_url,
                "button_text": button_text,
            }
        )

    about_html = None
    how_to_html = None
    faq_html = None
    for block in soup.select(".people-also-ask-container"):
        title_el = block.select_one(".people-also-ask-title")
        if not title_el:
            continue
        title_text = title_el.get_text(strip=True).lower()
        table = block.select_one("table")
        content = str(table) if table else block.get_text("\n", strip=True)
        if "about store" in title_text:
            about_html = content
        elif "how to apply" in title_text:
            how_to_html = content
        elif "questions" in title_text:
            faq_html = content

    coupon_section_hash = sha256_text(str(coupon_section) if coupon_section else html[:5000])
    content_hash = sha256_text(html)

    return {
        "slug": slug,
        "name": name,
        "page_url": page_url,
        "rating": parse_rating(rating_tag.get_text(strip=True) if rating_tag else None),
        "vote_count": parse_int(votes_tag.get_text(strip=True) if votes_tag else None),
        "coupon_codes_count": parse_int(stats.get("coupon codes", "0")) or 0,
        "deals_count": parse_int(stats.get("deals", "0")) or 0,
        "best_offer": stats.get("best offer"),
        "about_html": about_html,
        "how_to_apply_html": how_to_html,
        "faq_html": faq_html,
        "affiliate_url": affiliate_tag.get("data-affiliate_url") if affiliate_tag else None,
        "logo_url": logo.get("src") if logo else None,
        "meta_title": soup.title.get_text(strip=True) if soup.title else None,
        "meta_description": (soup.find("meta", attrs={"name": "description"}) or {}).get("content"),
        "content_hash": content_hash,
        "coupon_section_hash": coupon_section_hash,
        "coupons": coupons,
    }
