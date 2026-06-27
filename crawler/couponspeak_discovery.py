"""URL discovery for couponspeak-style sites (/store/, /deals, /search)."""

from __future__ import annotations

import re
from urllib.parse import parse_qs, urljoin, urlparse, urlunparse

from bs4 import BeautifulSoup

from crawler.fetcher import Fetcher

STORE_PATH_RE = re.compile(r"^/store/([a-zA-Z0-9._-]+)/?$")
BLOG_PATH_RE = re.compile(r"^/blog/([a-zA-Z0-9._-]+)/?$")
OFFER_URL_RE = re.compile(r"/store/([a-zA-Z0-9._-]+)(?:\?offer=\d+)?")


def seed_urls(base_url: str) -> list[str]:
    base = base_url.rstrip("/")
    return [f"{base}/", f"{base}/deals", f"{base}/blog"]


def normalize_url(url: str, base_url: str) -> str | None:
    if not url or url.startswith(("javascript:", "mailto:", "tel:", "#")):
        return None

    absolute = urljoin(base_url.rstrip("/") + "/", url.strip())
    parsed = urlparse(absolute)
    site_host = urlparse(base_url).netloc.replace("www.", "")
    if parsed.netloc and parsed.netloc.replace("www.", "") != site_host:
        return None

    path = parsed.path or "/"
    if path != "/" and path.endswith("/"):
        path = path.rstrip("/")

    clean = urlunparse((parsed.scheme or "https", parsed.netloc, path, "", parsed.query, ""))
    return clean.rstrip("/") if clean.endswith("/") and path != "/" else clean


def classify_url(url: str, base_url: str) -> tuple[str, str | None]:
    parsed = urlparse(url)
    path = parsed.path or "/"

    store_match = STORE_PATH_RE.match(path)
    if store_match:
        return "store", store_match.group(1)

    blog_match = BLOG_PATH_RE.match(path)
    if blog_match:
        return "blog", blog_match.group(1)

    if path in ("/", "", "/deals", "/blog"):
        return "seed", None

    return "other", path.lstrip("/")


def extract_links(html: str, base_url: str) -> dict[str, set[str]]:
    soup = BeautifulSoup(html, "lxml")
    found: dict[str, set[str]] = {
        "store": set(),
        "blog": set(),
        "seed": set(),
        "other": set(),
    }

    candidates: set[str] = set()
    for tag in soup.find_all(True):
        for attr in ("href", "data-href", "data-url"):
            value = tag.get(attr)
            if value:
                candidates.add(value)

    for raw in candidates:
        normalized = normalize_url(raw, base_url)
        if not normalized:
            continue
        url_type, _slug = classify_url(normalized, base_url)
        if url_type == "other" and normalized.startswith(base_url.rstrip("/")) and "/store/" in normalized:
            match = OFFER_URL_RE.search(
                urlparse(normalized).path
                + ("?" + urlparse(normalized).query if urlparse(normalized).query else "")
            )
            if match:
                normalized = f"{base_url.rstrip('/')}/store/{match.group(1)}"
                url_type = "store"
        if url_type in found:
            found[url_type].add(normalized)

    return found


def discover_store_urls(fetcher: Fetcher, base_url: str, max_deals_pages: int = 100) -> set[str]:
    store_urls: set[str] = set()
    visited_pages: set[str] = set()
    queue = list(seed_urls(base_url))
    empty_deals_streak = 0

    while queue:
        page_url = queue.pop(0)
        if page_url in visited_pages:
            continue
        visited_pages.add(page_url)

        result = fetcher.fetch(page_url)
        if result.status_code != 200 or not result.text:
            continue

        links = extract_links(result.text, base_url)
        before = len(store_urls)
        store_urls.update(links["store"])

        for blog_url in links["blog"]:
            if blog_url not in visited_pages:
                queue.append(blog_url)

        parsed = urlparse(page_url)
        if parsed.path == "/deals":
            if len(store_urls) > before:
                empty_deals_streak = 0
            else:
                empty_deals_streak += 1

            qs = parse_qs(parsed.query)
            page_num = int(qs.get("page", ["1"])[0])
            if empty_deals_streak < 2 and page_num < max_deals_pages:
                next_url = f"{base_url.rstrip('/')}/deals?page={page_num + 1}"
                if next_url not in visited_pages:
                    queue.append(next_url)

    return store_urls


def discover_stores_via_search(
    fetcher: Fetcher,
    base_url: str,
    max_pages_per_query: int = 50,
) -> set[str]:
    store_urls: set[str] = set()
    queries = list("abcdefghijklmnopqrstuvwxyz0123456789")
    base = base_url.rstrip("/")

    for qi, query in enumerate(queries, start=1):
        empty_streak = 0
        for page in range(1, max_pages_per_query + 1):
            url = f"{base}/search?key={query}" if page == 1 else f"{base}/search?key={query}&page={page}"
            result = fetcher.fetch(url)
            if result.status_code != 200 or not result.text:
                break

            links = extract_links(result.text, base_url)
            before = len(store_urls)
            store_urls.update(links["store"])
            if len(store_urls) == before:
                empty_streak += 1
                if empty_streak >= 2:
                    break
            else:
                empty_streak = 0

        if qi % 5 == 0 or qi == len(queries):
            print(f"  search [{qi}/{len(queries)}] -> {len(store_urls)} stores")

    return store_urls


def discover_blog_urls(fetcher: Fetcher, base_url: str) -> set[str]:
    blog_urls: set[str] = set()
    for seed in seed_urls(base_url):
        result = fetcher.fetch(seed)
        if result.status_code == 200 and result.text:
            blog_urls.update(extract_links(result.text, base_url)["blog"])
    return blog_urls
