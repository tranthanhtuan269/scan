#!/usr/bin/env python3
"""
Khi store chưa có trong DB: gọi AI tìm ưu đãi giá → POST /api/coupons/import.

Yêu cầu .env:
  OPENAI_API_KEY=sk-...
  OPENAI_MODEL=gpt-4o-mini          (tùy chọn)
  OPENAI_API_BASE=https://api.openai.com/v1
  WEB_BASE_URL=http://scan.test     (base URL PHP site)
  API_SITE=thuoc360                 (site whitelist)
  AFFILIATE_PARAM=sca_ref=10362718  (tùy chọn)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path

import httpx
import pymysql

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER  # noqa: E402

PROMPT_FILE = ROOT / "storage" / "prompts" / "find-store-offers.md"


def normalize_lookup_key(store: str) -> str:
    key = store.strip().lower()
    key = re.sub(r"^https?://", "", key)
    key = re.sub(r"^www\.", "", key)
    match = re.match(r"^([^/?#]+)", key)
    return match.group(1) if match else key


def store_exists_in_db(store_query: str) -> bool:
    key = normalize_lookup_key(store_query)
    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT store_id FROM store_api_lookup WHERE lookup_key = %s LIMIT 1",
                (key,),
            )
            if cur.fetchone():
                return True

            like = f"%{store_query}%"
            slug_like = f"%{store_query.replace(' ', '-').lower()}%"
            cur.execute(
                """
                SELECT s.id FROM stores s
                WHERE s.is_active = 1
                  AND (
                    s.name LIKE %s OR s.slug LIKE %s OR s.slug LIKE %s
                    OR s.affiliate_url LIKE %s
                  )
                LIMIT 1
                """,
                (like, like, slug_like, like),
            )
            return cur.fetchone() is not None
    finally:
        conn.close()


def load_prompt_sections() -> tuple[str, str]:
    text = PROMPT_FILE.read_text(encoding="utf-8")
    system_match = re.search(
        r"## System prompt\s+```\s*(.*?)\s*```",
        text,
        re.DOTALL,
    )
    user_match = re.search(
        r"## User prompt\s+```\s*(.*?)\s*```",
        text,
        re.DOTALL,
    )
    if not system_match or not user_match:
        raise RuntimeError(f"Cannot parse prompts from {PROMPT_FILE}")
    return system_match.group(1).strip(), user_match.group(1).strip()


def render_prompt(template: str, **kwargs: str) -> str:
    out = template
    for key, value in kwargs.items():
        out = out.replace("{" + key + "}", value)
    return out


def uses_gemini() -> bool:
    return os.getenv("GEMINI_ENABLED", "false").strip().lower() in {"1", "true", "yes", "on"}


def call_ai_gemini(store_query: str, affiliate_base: str, affiliate_param: str, max_offers: int) -> dict:
    api_key = os.getenv("GEMINI_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("Missing GEMINI_API_KEY in .env")

    model = os.getenv("GEMINI_MODEL", "gemini-2.5-flash").strip()
    api_base = os.getenv("GEMINI_API_BASE", "https://generativelanguage.googleapis.com/v1beta").rstrip("/")
    timeout = float(os.getenv("GEMINI_TIMEOUT", "90"))
    url = f"{api_base}/models/{model}:generateContent"

    system_tpl, user_tpl = load_prompt_sections()
    system_prompt = render_prompt(
        system_tpl,
        max_offers=str(max_offers),
        affiliate_param=affiliate_param or "(không có)",
    )
    user_prompt = render_prompt(
        user_tpl,
        store_query=store_query,
        affiliate_base_url=affiliate_base or "(chưa biết)",
        affiliate_param=affiliate_param or "(không có)",
    )

    payload = {
        "systemInstruction": {"parts": [{"text": system_prompt}]},
        "contents": [{"role": "user", "parts": [{"text": user_prompt}]}],
        "generationConfig": {
            "temperature": 0.2,
            "responseMimeType": "application/json",
        },
    }

    with httpx.Client(timeout=timeout) as client:
        resp = client.post(
            url,
            headers={
                "Content-Type": "application/json",
                "x-goog-api-key": api_key,
            },
            json=payload,
        )
        resp.raise_for_status()
        data = resp.json()

    content = data["candidates"][0]["content"]["parts"][0]["text"]
    parsed = json.loads(content)
    if not isinstance(parsed, dict):
        raise RuntimeError("AI response is not a JSON object")
    return parsed


def call_ai_openai(store_query: str, affiliate_base: str, affiliate_param: str, max_offers: int) -> dict:
    api_key = os.getenv("API_AI_KEY", os.getenv("OPENAI_API_KEY", "")).strip()
    if not api_key:
        raise RuntimeError("Missing API_AI_KEY (or OPENAI_API_KEY) in .env")

    model = os.getenv("API_AI_MODEL", os.getenv("OPENAI_MODEL", "gpt-4o-mini")).strip()
    api_ai = os.getenv("API_AI", os.getenv("OPENAI_API_BASE", "https://api.openai.com/v1/chat/completions")).strip()
    api_ai = api_ai.rstrip("/")
    if not api_ai.endswith("/chat/completions"):
        api_ai += "/chat/completions"

    system_tpl, user_tpl = load_prompt_sections()
    system_prompt = render_prompt(
        system_tpl,
        max_offers=str(max_offers),
        affiliate_param=affiliate_param or "(không có)",
    )
    user_prompt = render_prompt(
        user_tpl,
        store_query=store_query,
        affiliate_base_url=affiliate_base or "(chưa biết)",
        affiliate_param=affiliate_param or "(không có)",
    )

    payload = {
        "model": model,
        "temperature": 0.2,
        "response_format": {"type": "json_object"},
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ],
    }

    with httpx.Client(timeout=120.0) as client:
        resp = client.post(
            api_ai,
            headers={
                "Authorization": f"Bearer {api_key}",
                "Content-Type": "application/json",
            },
            json=payload,
        )
        resp.raise_for_status()
        data = resp.json()

    content = data["choices"][0]["message"]["content"]
    parsed = json.loads(content)
    if not isinstance(parsed, dict):
        raise RuntimeError("AI response is not a JSON object")
    return parsed


def call_ai(store_query: str, affiliate_base: str, affiliate_param: str, max_offers: int) -> dict:
    if uses_gemini():
        return call_ai_gemini(store_query, affiliate_base, affiliate_param, max_offers)
    return call_ai_openai(store_query, affiliate_base, affiliate_param, max_offers)


def import_payload(base_url: str, site: str, payload: dict) -> dict:
    url = f"{base_url.rstrip('/')}/api/coupons/import?site={site}"
    with httpx.Client(timeout=60.0, follow_redirects=True) as client:
        resp = client.post(url, json=payload)
        resp.raise_for_status()
        return resp.json()


def main() -> int:
    parser = argparse.ArgumentParser(description="AI fetch store offers when missing in DB")
    parser.add_argument("--store", required=True, help="Store name or domain, e.g. alsoasked")
    parser.add_argument("--site", default=os.getenv("API_SITE", "thuoc360"))
    parser.add_argument("--affiliate-url", default=os.getenv("AFFILIATE_BASE_URL", ""))
    parser.add_argument(
        "--affiliate-param",
        default=os.getenv("AFFILIATE_PARAM", ""),
        help="e.g. sca_ref=10362718",
    )
    parser.add_argument("--max-offers", type=int, default=10)
    parser.add_argument("--dry-run", action="store_true", help="Only print AI JSON, do not import")
    parser.add_argument("--force", action="store_true", help="Run even if store already in DB")
    args = parser.parse_args()

    if not args.force and store_exists_in_db(args.store):
        print(f"Store already in database: {args.store!r} (use --force to fetch anyway)")
        return 0

    print(f"Calling AI for offers: {args.store!r} ...")
    payload = call_ai(
        args.store,
        args.affiliate_url,
        args.affiliate_param,
        args.max_offers,
    )

    coupons = payload.get("coupons") or []
    print(f"AI returned {len(coupons)} coupon(s)")

    if args.dry_run:
        print(json.dumps(payload, ensure_ascii=False, indent=2))
        return 0

    if not coupons:
        print("No coupons to import.")
        return 1

    base_url = os.getenv("WEB_BASE_URL", "http://scan.test").strip()
    result = import_payload(base_url, args.site, payload)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
