from __future__ import annotations

import hmac
import json
import time
from hashlib import sha256
from typing import Any
from urllib.parse import urlparse

import requests

BROWSER_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/126.0.0.0 Safari/537.36"
)


def encode_payload(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, separators=(",", ":"), ensure_ascii=False, default=str).encode("utf-8")


def build_signature(timestamp: str, raw_body: bytes, secret: str) -> str:
    message = timestamp.encode("utf-8") + b"." + raw_body
    return hmac.new(secret.encode("utf-8"), message, sha256).hexdigest()


def origin_from_url(url: str) -> str:
    parsed = urlparse(url)
    if not parsed.scheme or not parsed.netloc:
        return url.rstrip("/")
    return f"{parsed.scheme}://{parsed.netloc}"


def browser_headers(base_url: str) -> dict[str, str]:
    origin = origin_from_url(base_url)
    return {
        "User-Agent": BROWSER_USER_AGENT,
        "Accept": "application/json, text/plain, */*",
        "Accept-Language": "en-US,en;q=0.9,bn;q=0.8",
        "Accept-Encoding": "gzip, deflate",
        "Origin": origin,
        "Referer": f"{origin}/dashboard/",
        "Connection": "keep-alive",
        "DNT": "1",
        "Sec-Fetch-Dest": "empty",
        "Sec-Fetch-Mode": "cors",
        "Sec-Fetch-Site": "same-origin",
    }


class HostingerApiClient:
    def __init__(self, base_url: str, api_token: str, hmac_secret: str, timeout: int = 180) -> None:
        self.base_url = base_url.rstrip("/")
        self.api_token = api_token
        self.hmac_secret = hmac_secret
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update(browser_headers(self.base_url))

    def post_run(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post_endpoint("index.php", payload)

    def post_endpoint(self, endpoint: str, payload: dict[str, Any]) -> dict[str, Any]:
        timestamp = str(int(time.time()))
        raw_body = encode_payload(payload)
        signature = build_signature(timestamp, raw_body, self.hmac_secret)
        headers = {
            "Content-Type": "application/json",
            "X-API-Key": self.api_token,
            "X-API-Token": self.api_token,
            "X-Timestamp": timestamp,
            "X-Signature": signature,
        }
        errors: list[str] = []
        for url in endpoint_candidates(self.base_url, endpoint):
            try:
                response = self.session.post(
                    url,
                    data=raw_body,
                    headers=headers,
                    timeout=self.timeout,
                )
            except requests.RequestException as exc:
                errors.append(f"{url}: {exc}")
                continue

            if response.status_code >= 400:
                body_preview = response.text[:800].replace(self.api_token, "***")
                errors.append(f"HTTP {response.status_code} for {url}: {body_preview}")
                continue

            return response.json()

        raise RuntimeError("Hostinger API request failed. " + " | ".join(errors[-4:]))


def endpoint_candidates(base_url: str, endpoint: str) -> list[str]:
    normalized_base = base_url.rstrip("/")
    normalized_endpoint = endpoint.lstrip("/")
    parsed = urlparse(normalized_base)
    origin = origin_from_url(normalized_base).rstrip("/")
    base_path = parsed.path.rstrip("/")

    candidates: list[str] = []

    def add_candidate(url: str) -> None:
        if url not in candidates:
            candidates.append(url)

    if base_path.endswith("/" + normalized_endpoint) or base_path == "/" + normalized_endpoint:
        add_candidate(normalized_base)
        return candidates

    add_candidate(f"{normalized_base}/{normalized_endpoint}")

    if base_path.endswith("/api") or base_path.endswith("/hostinger_api"):
        return candidates

    if base_path:
        add_candidate(f"{origin}{base_path}/api/{normalized_endpoint}")
        add_candidate(f"{origin}{base_path}/hostinger_api/{normalized_endpoint}")
    else:
        add_candidate(f"{origin}/api/{normalized_endpoint}")
        add_candidate(f"{origin}/hostinger_api/{normalized_endpoint}")

    return candidates


def send_telegram_summary(bot_token: str, chat_id: str, text: str) -> None:
    url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
    requests.post(url, json={"chat_id": chat_id, "text": text}, timeout=15).raise_for_status()
