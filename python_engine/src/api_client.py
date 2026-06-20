from __future__ import annotations

import hmac
import json
import time
from hashlib import sha256
from typing import Any

import requests


def encode_payload(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, separators=(",", ":"), ensure_ascii=False, default=str).encode("utf-8")


def build_signature(timestamp: str, raw_body: bytes, secret: str) -> str:
    message = timestamp.encode("utf-8") + b"." + raw_body
    return hmac.new(secret.encode("utf-8"), message, sha256).hexdigest()


class HostingerApiClient:
    def __init__(self, base_url: str, api_token: str, hmac_secret: str, timeout: int = 30) -> None:
        self.base_url = base_url.rstrip("/")
        self.api_token = api_token
        self.hmac_secret = hmac_secret
        self.timeout = timeout

    def post_run(self, payload: dict[str, Any]) -> dict[str, Any]:
        timestamp = str(int(time.time()))
        raw_body = encode_payload(payload)
        signature = build_signature(timestamp, raw_body, self.hmac_secret)
        url = f"{self.base_url}/index.php"
        response = requests.post(
            url,
            data=raw_body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "User-Agent": "dse-paper-trading-engine/1.0 (+https://dse.rkpolin.com)",
                "X-API-Token": self.api_token,
                "X-Timestamp": timestamp,
                "X-Signature": signature,
            },
            timeout=self.timeout,
        )
        if response.status_code >= 400:
            body_preview = response.text[:800].replace(self.api_token, "***")
            raise RuntimeError(f"Hostinger API returned HTTP {response.status_code} for {url}: {body_preview}")
        return response.json()


def send_telegram_summary(bot_token: str, chat_id: str, text: str) -> None:
    url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
    requests.post(url, json={"chat_id": chat_id, "text": text}, timeout=15).raise_for_status()
