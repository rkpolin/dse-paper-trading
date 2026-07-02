from __future__ import annotations

from datetime import date

import pandas as pd

from run_engine import limit_api_payload_data
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


def test_limit_api_payload_keeps_full_trade_and_snapshot_history() -> None:
    prices = pd.DataFrame(
        [
            {"symbol": "AAA", "date": date(2026, 6, 29), "open": 10, "high": 11, "low": 9, "close": 10, "volume": 1000},
            {"symbol": "AAA", "date": date(2026, 6, 30), "open": 11, "high": 12, "low": 10, "close": 11, "volume": 1200},
        ]
    )
    indicators = prices.rename(columns={"open": "rsi"}).copy()
    indicators["sma20"] = 10
    indicators["sma50"] = 10
    indicators["volume_ratio"] = 1.0
    indicators["momentum"] = 0.01
    indicators["breakout"] = False
    indicators["pump_risk"] = False
    indicators = indicators[["symbol", "date", "rsi", "sma20", "sma50", "volume_ratio", "momentum", "breakout", "pump_risk"]]
    signals = pd.DataFrame(
        [
            {"symbol": "AAA", "date": date(2026, 6, 29), "signal_type": "BUY", "close": 10, "confidence": 0.8},
            {"symbol": "AAA", "date": date(2026, 6, 30), "signal_type": "SELL", "close": 11, "confidence": 0.6},
        ]
    )
    evaluations = pd.DataFrame(
        [
            {"symbol": "AAA", "signal_date": date(2026, 6, 29), "status": "PENDING"},
            {"symbol": "AAA", "signal_date": date(2026, 6, 30), "status": "PENDING"},
        ]
    )
    trading = {
        "trades": [
            {"trade_id": "buy-1", "trade_date": "2026-06-29", "side": "BUY", "entry_trade_id": None},
            {"trade_id": "sell-1", "trade_date": "2026-06-30", "side": "SELL", "entry_trade_id": "buy-1"},
        ],
        "positions": [],
        "snapshots": [
            {"snapshot_id": "snap-1", "snapshot_date": "2026-06-29"},
            {"snapshot_id": "snap-2", "snapshot_date": "2026-06-30"},
        ],
        "summary": {"trade_count": 2},
    }

    _, _, filtered_signals, filtered_trading, filtered_evaluations = limit_api_payload_data(
        prices,
        indicators,
        signals,
        trading,
        evaluations,
        1,
    )

    assert len(filtered_signals) == 1
    assert str(filtered_signals.iloc[0]["date"]) == "2026-06-30"
    assert [trade["trade_id"] for trade in filtered_trading["trades"]] == ["buy-1", "sell-1"]
    assert [snapshot["snapshot_date"] for snapshot in filtered_trading["snapshots"]] == ["2026-06-29", "2026-06-30"]
    assert len(filtered_evaluations) == 1
