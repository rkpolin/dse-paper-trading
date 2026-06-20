from __future__ import annotations

from datetime import date, timedelta

import pandas as pd

from src.indicators import add_indicators, calculate_rsi


def test_calculate_rsi_returns_neutral_for_flat_prices() -> None:
    close = pd.Series([100.0] * 20)
    rsi = calculate_rsi(close)
    assert rsi.iloc[-1] == 50


def test_add_indicators_calculates_expected_columns() -> None:
    rows = []
    start = date(2026, 1, 1)
    for i in range(55):
        close = 100 + i
        rows.append(
            {
                "symbol": "DEMO",
                "date": start + timedelta(days=i),
                "open": close - 1,
                "high": close + 1,
                "low": close - 2,
                "close": close,
                "volume": 1000 + i * 10,
            }
        )
    result = add_indicators(pd.DataFrame(rows))
    latest = result.iloc[-1]
    assert latest["sma20"] > latest["sma50"]
    assert latest["momentum"] > 0
    assert set(["rsi", "sma20", "sma50", "volume_ratio", "breakout", "pump_risk"]).issubset(result.columns)
