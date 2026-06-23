from __future__ import annotations

import time
from dataclasses import dataclass

import httpx

from config.settings import (
    DEFAULT_HEADERS,
    MAX_CONCURRENT,
    REQUEST_DELAY,
    REQUEST_TIMEOUT,
)


@dataclass
class FetchResult:
    url: str
    status_code: int
    text: str
    etag: str | None = None
    last_modified: str | None = None
    from_cache: bool = False
    error: str | None = None


class Fetcher:
    def __init__(self, delay: float | None = None):
        self.delay = delay if delay is not None else REQUEST_DELAY
        self._last_request_at = 0.0
        self.client = httpx.Client(
            headers=DEFAULT_HEADERS,
            follow_redirects=True,
            timeout=REQUEST_TIMEOUT,
        )

    def close(self):
        self.client.close()

    def _throttle(self):
        elapsed = time.monotonic() - self._last_request_at
        if elapsed < self.delay:
            time.sleep(self.delay - elapsed)

    def fetch(
        self,
        url: str,
        etag: str | None = None,
        last_modified: str | None = None,
    ) -> FetchResult:
        headers = {}
        if etag:
            headers["If-None-Match"] = etag
        if last_modified:
            headers["If-Modified-Since"] = last_modified

        self._throttle()
        try:
            response = self.client.get(url, headers=headers)
            self._last_request_at = time.monotonic()
            return FetchResult(
                url=url,
                status_code=response.status_code,
                text=response.text if response.status_code == 200 else "",
                etag=response.headers.get("etag"),
                last_modified=response.headers.get("last-modified"),
                from_cache=response.status_code == 304,
            )
        except httpx.HTTPError as exc:
            self._last_request_at = time.monotonic()
            return FetchResult(url=url, status_code=0, text="", error=str(exc))

    def fetch_final_url(self, url: str) -> str | None:
        """Follow redirects and return the final URL (for affiliate /go links)."""
        self._throttle()
        try:
            response = self.client.get(url, follow_redirects=True)
            self._last_request_at = time.monotonic()
            if response.status_code < 400:
                return str(response.url)
        except httpx.HTTPError:
            self._last_request_at = time.monotonic()
        return None
