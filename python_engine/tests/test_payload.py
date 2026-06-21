from __future__ import annotations

from src.payload import clean_symbol, sanitize_payload_symbols


def test_clean_symbol_keeps_only_alphanumeric_uppercase() -> None:
    assert clean_symbol(" abc-1 ") == "ABC1"
    assert clean_symbol("BAD SYMBOL!") == "BADSYMBOL"
    assert clean_symbol("A_B.C") == "ABC"


def test_sanitize_payload_symbols_strips_or_drops_invalid_symbols() -> None:
    payload = {
        "stocks": [
            {"symbol": "ABC-1", "name": "ABC-1"},
            {"symbol": "BAD SYMBOL", "name": "Bad Symbol Co"},
            {"symbol": "!!!", "name": "Invalid"},
        ],
        "daily_prices": [
            {"symbol": "ABC-1", "close": 10},
            {"symbol": "BAD SYMBOL", "close": 20},
            {"symbol": "!!!", "close": 30},
        ],
        "signals": [{"symbol": "squr pharma", "signal_type": "BUY"}],
        "portfolio_snapshots": [{"total_value": 100000}],
    }

    clean_payload, stats = sanitize_payload_symbols(payload)

    assert stats == {"changed": 5, "dropped": 2}
    assert clean_payload["stocks"] == [
        {"symbol": "ABC1", "name": "ABC1"},
        {"symbol": "BADSYMBOL", "name": "Bad Symbol Co"},
    ]
    assert clean_payload["daily_prices"] == [
        {"symbol": "ABC1", "close": 10},
        {"symbol": "BADSYMBOL", "close": 20},
    ]
    assert clean_payload["signals"] == [{"symbol": "SQURPHARMA", "signal_type": "BUY"}]
    assert clean_payload["portfolio_snapshots"] == [{"total_value": 100000}]
    assert payload["stocks"][0]["symbol"] == "ABC-1"
