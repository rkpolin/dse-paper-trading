from __future__ import annotations

import hmac
from hashlib import sha256

from src.api_client import build_signature, encode_payload


def test_hmac_signature_uses_timestamp_dot_raw_body() -> None:
    payload = {"run": {"run_id": "run-1"}, "signals": []}
    raw_body = encode_payload(payload)
    timestamp = "1790000000"
    secret = "test-secret"
    expected = hmac.new(secret.encode(), timestamp.encode() + b"." + raw_body, sha256).hexdigest()
    assert build_signature(timestamp, raw_body, secret) == expected
