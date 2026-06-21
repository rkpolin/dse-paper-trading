from __future__ import annotations

import hmac
from hashlib import sha256

from src.api_client import browser_headers, build_signature, encode_payload, origin_from_url


def test_hmac_signature_uses_timestamp_dot_raw_body() -> None:
    payload = {"run": {"run_id": "run-1"}, "signals": []}
    raw_body = encode_payload(payload)
    timestamp = "1790000000"
    secret = "test-secret"
    expected = hmac.new(secret.encode(), timestamp.encode() + b"." + raw_body, sha256).hexdigest()
    assert build_signature(timestamp, raw_body, secret) == expected


def test_browser_headers_use_origin_without_api_path() -> None:
    headers = browser_headers("https://dse.rkpolin.com/api")

    assert origin_from_url("https://dse.rkpolin.com/api") == "https://dse.rkpolin.com"
    assert headers["Origin"] == "https://dse.rkpolin.com"
    assert headers["Referer"] == "https://dse.rkpolin.com/dashboard/"
    assert headers["Accept"] == "application/json, text/plain, */*"
    assert headers["Accept-Language"].startswith("en-US")
    assert "Mozilla/5.0" in headers["User-Agent"]
