from __future__ import annotations

import pandas as pd

from src.signals import classify_signal, generate_signals


def test_classify_buy_signal_for_healthy_trend() -> None:
    row = pd.Series(
        {
            "close": 120.0,
            "sma20": 112.0,
            "sma50": 105.0,
            "rsi": 62.0,
            "volume_ratio": 1.4,
            "momentum": 0.04,
            "breakout": True,
            "pump_risk": False,
        }
    )
    signal, confidence, reason = classify_signal(row)
    assert signal == "BUY"
    assert confidence > 0.75
    assert "Breakout" in reason


def test_generate_signals_creates_stable_signal_ids() -> None:
    df = pd.DataFrame(
        [
            {
                "symbol": "ABC",
                "date": "2026-01-01",
                "close": 100.0,
                "sma20": 90.0,
                "sma50": 80.0,
                "rsi": 55.0,
                "volume_ratio": 1.2,
                "momentum": 0.02,
                "breakout": False,
                "pump_risk": False,
            }
        ]
    )
    first = generate_signals(df, "run-a")
    second = generate_signals(df, "run-a")
    assert first.iloc[0]["signal_id"] == second.iloc[0]["signal_id"]
    assert first.iloc[0]["signal_type"] == "BUY"
