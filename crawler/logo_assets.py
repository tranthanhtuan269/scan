"""Download store logos to web/uploads/store-logos/ and store relative paths."""

from __future__ import annotations

import re
from pathlib import Path
from urllib.parse import urljoin, urlparse

import httpx

from config.settings import DEFAULT_HEADERS, REQUEST_TIMEOUT, ROOT_DIR

LOGO_DIR = ROOT_DIR / "web" / "uploads" / "store-logos"
SAFE_SLUG_RE = re.compile(r"[^a-z0-9._-]+", re.I)
ALLOWED_EXT = frozenset({"jpg", "jpeg", "png", "gif", "webp", "svg"})


def resolve_remote_url(url: str, site_base_url: str) -> str | None:
    url = (url or "").strip()
    if not url or url.startswith("data:"):
        return None
    if url.startswith("//"):
        return "https:" + url
    if url.startswith("/"):
        return urljoin(site_base_url.rstrip("/") + "/", url.lstrip("/"))
    if url.startswith("http://") or url.startswith("https://"):
        return url
    return urljoin(site_base_url.rstrip("/") + "/", url)


def _safe_filename(slug: str) -> str:
    slug = SAFE_SLUG_RE.sub("-", slug.strip().lower()).strip("-")
    return slug or "store"


def download_store_logo(db_slug: str, remote_url: str, site_base_url: str) -> str | None:
    """Return relative path e.g. /uploads/store-logos/foo.png or None on failure."""
    absolute = resolve_remote_url(remote_url, site_base_url)
    if not absolute:
        return None

    path_part = urlparse(absolute).path or ""
    ext = (Path(path_part).suffix or ".jpg").lstrip(".").lower()
    if ext not in ALLOWED_EXT:
        ext = "jpg"

    relative = f"/uploads/store-logos/{_safe_filename(db_slug)}.{ext}"
    local_path = LOGO_DIR / f"{_safe_filename(db_slug)}.{ext}"

    LOGO_DIR.mkdir(parents=True, exist_ok=True)

    try:
        with httpx.Client(
            headers=DEFAULT_HEADERS,
            timeout=REQUEST_TIMEOUT,
            follow_redirects=True,
        ) as client:
            response = client.get(absolute)
            response.raise_for_status()
            content = response.content
    except httpx.HTTPError:
        return None

    if not content:
        return None

    local_path.write_bytes(content)
    return relative


def localize_store_logo(store_data: dict, db_slug: str, site_base_url: str) -> None:
    remote = store_data.get("logo_url")
    if not remote:
        return
    local = download_store_logo(db_slug, str(remote), site_base_url)
    if local:
        store_data["logo_url"] = local
