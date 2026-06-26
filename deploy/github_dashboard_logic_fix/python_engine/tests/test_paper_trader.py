from __future__ import annotations

from datetime import date, timedelta

import pandas as pd

from src.paper_trader import TradingRules, simulate_paper_trades


def test_paper_trader_buys_once_and_sells_take_profit() -> None:
    start = date(2026, 1, 1)
    prices = pd.DataFrame(
        [
            {"symbol": "AAA", "date": start, "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1000},
            {"symbol": "AAA", "date": start + timedelta(days=1), "open": 101, "high": 102, "low": 100, "close": 101, "volume": 1000},
            {"symbol": "AAA", "date": start + timedelta(days=2), "open": 109, "high": 111, "low": 108, "close": 109, "volume": 1000},
        ]
    )
    signals = pd.DataFrame(
        [
            {"symbol": "AAA", "date": start, "signal_type": "BUY", "confidence": 0.9},
            {"symbol": "AAA", "date": start + timedelta(days=1), "signal_type": "BUY", "confidence": 0.9},
            {"symbol": "AAA", "date": start + timedelta(days=2), "signal_type": "HOLD", "confidence": 0.5},
        ]
    )
    result = simulate_paper_trades(
        prices,
        signals,
        "run-test",
        TradingRules(initial_balance=100000, max_position_pct=0.10, transaction_cost_pct=0.005),
    )
    buys = [trade for trade in result["trades"] if trade["side"] == "BUY"]
    sells = [trade for trade in result["trades"] if trade["side"] == "SELL"]
    assert len(buys) == 1
    assert len(sells) == 1
    assert sells[0]["reason"] == "TAKE_PROFIT"
    assert buys[0]["source"] == "SYSTEM"
    assert sells[0]["source"] == "SYSTEM"
    assert sells[0]["entry_trade_id"] == buys[0]["trade_id"]
    assert result["summary"]["open_positions"] == 0


def test_paper_trader_respects_max_open_positions() -> None:
    day = date(2026, 1, 1)
    prices = pd.DataFrame(
        [
            {"symbol": "AAA", "date": day, "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1000},
            {"symbol": "BBB", "date": day, "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1000},
        ]
    )
    signals = pd.DataFrame(
        [
            {"symbol": "AAA", "date": day, "signal_type": "BUY", "confidence": 0.9},
            {"symbol": "BBB", "date": day, "signal_type": "BUY", "confidence": 0.8},
        ]
    )
    result = simulate_paper_trades(
        prices,
        signals,
        "run-test",
        TradingRules(initial_balance=100000, max_open_positions=1),
    )
    assert result["summary"]["open_positions"] == 1
    assert len([trade for trade in result["trades"] if trade["side"] == "BUY"]) == 1


def test_paper_trader_does_not_rebuy_same_symbol_after_same_day_exit() -> None:
    start = date(2026, 1, 1)
    prices = pd.DataFrame(
        [
            {"symbol": "AAA", "date": start, "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1000},
            {"symbol": "AAA", "date": start + timedelta(days=1), "open": 109, "high": 111, "low": 108, "close": 109, "volume": 1000},
        ]
    )
    signals = pd.DataFrame(
        [
            {"symbol": "AAA", "date": start, "signal_type": "BUY", "confidence": 0.9},
            {"symbol": "AAA", "date": start + timedelta(days=1), "signal_type": "BUY", "confidence": 0.9},
        ]
    )
    result = simulate_paper_trades(
        prices,
        signals,
        "run-test",
        TradingRules(initial_balance=100000, transaction_cost_pct=0.005),
    )
    assert [trade["side"] for trade in result["trades"]] == ["BUY", "SELL"]
    assert result["trades"][1]["reason"] == "TAKE_PROFIT"
    assert result["trades"][1]["entry_trade_id"] == result["trades"][0]["trade_id"]
    assert result["summary"]["open_positions"] == 0
