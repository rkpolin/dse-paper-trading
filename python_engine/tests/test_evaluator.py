from __future__ import annotations

from datetime import date, timedelta

import pandas as pd

from src.evaluator import evaluate_signals


def test_buy_correct_when_gain_hits_before_loss() -> None:
    start = date(2026, 1, 1)
    prices = _future_prices(start, [100, 101, 102, 104, 103, 102])
    signals = pd.DataFrame(
        [
            {
                "signal_id": "sig-1",
                "run_id": "run-1",
                "symbol": "AAA",
                "date": start,
                "signal_type": "BUY",
                "close": 100,
            }
        ]
    )
    result = evaluate_signals(prices, signals, evaluation_days=5)
    assert result.iloc[0]["status"] == "CORRECT"


def test_hold_wrong_when_price_leaves_band() -> None:
    start = date(2026, 1, 1)
    prices = _future_prices(start, [100, 100, 101, 103, 101, 100])
    signals = pd.DataFrame(
        [
            {
                "signal_id": "sig-2",
                "run_id": "run-1",
                "symbol": "AAA",
                "date": start,
                "signal_type": "HOLD",
                "close": 100,
            }
        ]
    )
    result = evaluate_signals(prices, signals, evaluation_days=5)
    assert result.iloc[0]["status"] == "WRONG"


def test_pending_when_evaluation_window_incomplete() -> None:
    start = date(2026, 1, 1)
    prices = _future_prices(start, [100, 101])
    signals = pd.DataFrame(
        [
            {
                "signal_id": "sig-3",
                "run_id": "run-1",
                "symbol": "AAA",
                "date": start,
                "signal_type": "SELL",
                "close": 100,
            }
        ]
    )
    result = evaluate_signals(prices, signals, evaluation_days=5)
    assert result.iloc[0]["status"] == "PENDING"


def _future_prices(start: date, closes: list[float]) -> pd.DataFrame:
    rows = []
    for i, close in enumerate(closes):
        rows.append(
            {
                "symbol": "AAA",
                "date": start + timedelta(days=i),
                "open": close,
                "high": close,
                "low": close,
                "close": close,
                "volume": 1000,
            }
        )
    return pd.DataFrame(rows)
