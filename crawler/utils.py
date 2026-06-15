from __future__ import annotations

import hashlib
import re
from urllib.parse import urljoin, urlparse, urlunparse

from config.settings import BASE_URL

STORE_PATH_RE = re.compile(r"^/store/([a-zA-Z0-9._-]+)/?$")
BLOG_PATH_RE = re.compile(r"^/blog/([a-zA-Z0-9._-]+)/?$")


def normalize_url(url: str) -> str | None:
    if not url or url.startswith(("javascript:", "mailto:", "tel:", "#")):
        return None

    absolute = urljoin(BASE_URL + "/", url.strip())
    parsed = urlparse(absolute)
    if parsed.netloc and parsed.netloc.replace("www.", "") != urlparse(BASE_URL).netloc.replace("www.", ""):
        return None

    path = parsed.path or "/"
    if path != "/" and path.endswith("/"):
        path = path.rstrip("/")

    clean = urlunparse((parsed.scheme or "https", parsed.netloc, path, "", parsed.query, ""))
    return clean.rstrip("/") if clean.endswith("/") and path != "/" else clean


def classify_url(url: str) -> tuple[str, str | None]:
    parsed = urlparse(url)
    path = parsed.path or "/"

    store_match = STORE_PATH_RE.match(path)
    if store_match:
        return "store", store_match.group(1)

    blog_match = BLOG_PATH_RE.match(path)
    if blog_match:
        return "blog", blog_match.group(1)

    if path in ("/", ""):
        return "seed", None
    if path == "/deals":
        return "seed", None
    if path == "/blog":
        return "seed", None
    if path.startswith("/blog/"):
        return "blog", path.split("/blog/", 1)[1]
    if path.startswith("/article") or path.startswith("/articles-"):
        return "category", path.lstrip("/")

    return "other", path.lstrip("/")


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8", errors="ignore")).hexdigest()


def parse_int(text: str | None) -> int | None:
    if not text:
        return None
    digits = re.sub(r"[^\d]", "", text)
    return int(digits) if digits else None


def parse_rating(text: str | None) -> float | None:
    if not text:
        return None
    try:
        return float(text.strip())
    except ValueError:
        return None
