from __future__ import annotations

from typing import Any

from bs4 import BeautifulSoup

from crawler.utils import sha256_text


def parse_blog_page(html: str, page_url: str, slug: str | None) -> dict[str, Any]:
    soup = BeautifulSoup(html, "lxml")

    title_el = soup.select_one("h1") or soup.select_one("title")
    title = title_el.get_text(strip=True) if title_el else slug

    excerpt_el = soup.select_one("meta[name='description']")
    excerpt = excerpt_el.get("content") if excerpt_el else None

    content_el = (
        soup.select_one(".blog-content")
        or soup.select_one(".post-content")
        or soup.select_one("article")
        or soup.select_one("main")
    )
    content_html = str(content_el) if content_el else None

    return {
        "url": page_url,
        "page_type": "blog",
        "slug": slug,
        "title": title,
        "excerpt": excerpt,
        "content_html": content_html,
        "content_hash": sha256_text(content_html or html[:8000]),
    }
