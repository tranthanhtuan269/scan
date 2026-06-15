from __future__ import annotations

import re
from urllib.parse import parse_qs, urlparse

from bs4 import BeautifulSoup

from config.settings import BASE_URL, SEARCH_MAX_PAGES
from crawler.fetcher import Fetcher
from crawler.utils import classify_url, normalize_url

OFFER_URL_RE = re.compile(r"/store/([a-zA-Z0-9._-]+)(?:\?offer=\d+)?")


class Discovery:
    def __init__(self, fetcher: Fetcher):
        self.fetcher = fetcher

    def extract_links(self, html: str, source_url: str) -> dict[str, set[str]]:
        soup = BeautifulSoup(html, "lxml")
        found: dict[str, set[str]] = {
            "store": set(),
            "blog": set(),
            "category": set(),
            "seed": set(),
            "other": set(),
        }

        candidates: set[str] = set()
        for tag in soup.find_all(True):
            href = tag.get("href")
            if href:
                candidates.add(href)
            data_href = tag.get("data-href")
            if data_href:
                candidates.add(data_href)
            data_url = tag.get("data-url")
            if data_url:
                candidates.add(data_url)

        for raw in candidates:
            normalized = normalize_url(raw)
            if not normalized:
                continue
            url_type, _slug = classify_url(normalized)
            if url_type == "other" and normalized.startswith(BASE_URL):
                if "/store/" in normalized:
                    match = OFFER_URL_RE.search(urlparse(normalized).path + ("?" + urlparse(normalized).query if urlparse(normalized).query else ""))
                    if match:
                        normalized = f"{BASE_URL}/store/{match.group(1)}"
                        url_type = "store"
            if url_type in found:
                found[url_type].add(normalized)

        return found

    def discover_store_urls(self, seed_urls: list[str], max_deals_pages: int = 100) -> set[str]:
        store_urls: set[str] = set()
        visited_pages: set[str] = set()
        queue = list(seed_urls)
        empty_deals_streak = 0

        while queue:
            page_url = queue.pop(0)
            if page_url in visited_pages:
                continue
            visited_pages.add(page_url)

            result = self.fetcher.fetch(page_url)
            if result.status_code != 200 or not result.text:
                continue

            links = self.extract_links(result.text, page_url)
            before = len(store_urls)
            for store_url in links["store"]:
                store_urls.add(store_url)
            found_new = len(store_urls) > before

            for blog_url in links["blog"]:
                if blog_url not in visited_pages:
                    queue.append(blog_url)
            for category_url in links["category"]:
                if category_url not in visited_pages:
                    queue.append(category_url)

            parsed = urlparse(page_url)
            if parsed.path == "/deals":
                if found_new:
                    empty_deals_streak = 0
                else:
                    empty_deals_streak += 1

                qs = parse_qs(parsed.query)
                page_num = int(qs.get("page", ["1"])[0])
                if empty_deals_streak < 2 and page_num < max_deals_pages:
                    next_url = f"{BASE_URL}/deals?page={page_num + 1}"
                    if next_url not in visited_pages:
                        queue.append(next_url)

        return store_urls

    def discover_from_html(self, html: str, source_url: str) -> dict[str, set[str]]:
        return self.extract_links(html, source_url)

    def discover_stores_via_search(
        self,
        queries: list[str] | None = None,
        max_pages_per_query: int | None = None,
    ) -> set[str]:
        """Discover store URLs through /search?key= queries with pagination."""
        if queries is None:
            queries = list("abcdefghijklmnopqrstuvwxyz0123456789")
        if max_pages_per_query is None:
            max_pages_per_query = SEARCH_MAX_PAGES

        store_urls: set[str] = set()
        for qi, query in enumerate(queries, start=1):
            empty_streak = 0
            for page in range(1, max_pages_per_query + 1):
                if page == 1:
                    url = f"{BASE_URL}/search?key={query}"
                else:
                    url = f"{BASE_URL}/search?key={query}&page={page}"

                result = self.fetcher.fetch(url)
                if result.status_code != 200 or not result.text:
                    break

                links = self.extract_links(result.text, url)
                before = len(store_urls)
                store_urls.update(links["store"])
                if len(store_urls) == before:
                    empty_streak += 1
                    if empty_streak >= 2:
                        break
                else:
                    empty_streak = 0

            if qi % 5 == 0 or qi == len(queries):
                print(f"  search discovery [{qi}/{len(queries)}] queries done, {len(store_urls)} stores found")

        return store_urls

    def discover_all_store_urls(self, seed_urls: list[str]) -> set[str]:
        store_urls = self.discover_store_urls(seed_urls)
        store_urls.update(self.discover_stores_via_search())
        return store_urls
