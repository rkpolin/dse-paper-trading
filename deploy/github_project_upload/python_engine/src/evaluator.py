from __future__ import annotations

import hashlib
from typing import Any

import pandas as pd


def _evaluation_id(signal_id: str) -> str:
    return hashlib.sha256(f"evaluation|{signal_id}".encode("utf-8")).hexdigest()


def evaluate_signals(
    price_df: pd.DataFrame,
    signals_df: pd.DataFrame,
    evaluation_days: int = 5,
) -> pd.DataFrame:
    evaluations: list[dict[str, Any]] = []
    prices = price_df.sort_values(["symbol", "date"]).copy()

    for _, signal in signals_df.iterrows():
        signal_type = signal["signal_type"]
        entry_price = float(signal["close"])
        symbol_prices = prices[
            (prices["symbol"] == signal["symbol"]) & (prices["date"] > signal["date"])
        ].head(evaluation_days)

        status = "PENDING"
        result_note = "Evaluation window is not complete"
        days_checked = int(len(symbol_prices))
        max_gain_pct = None
        max_drawdown_pct = None

        if len(symbol_prices) >= evaluation_days:
            highs = symbol_prices["high"].astype(float)
            lows = symbol_prices["low"].astype(float)
            max_gain_pct = float((highs.max() - entry_price) / entry_price)
            max_drawdown_pct = float((lows.min() - entry_price) / entry_price)

            if signal_type == "BUY":
                status = _evaluate_buy(symbol_prices, entry_price)
                result_note = "BUY needs +3% before -3% within 5 trading days"
            elif signal_type == "SELL":
                status = "CORRECT" if max_drawdown_pct <= -0.03 else "WRONG"
                result_note = "SELL needs price to fall at least 3%"
            elif signal_type == "HOLD":
                inside_band = max_gain_pct <= 0.02 and max_drawdown_pct >= -0.02
                status = "CORRECT" if inside_band else "WRONG"
                result_note = "HOLD needs price to stay between -2% and +2%"
            else:
                status = "NOT_APPLICABLE"
                result_note = "WATCH and AVOID are not scored by the configured rule set"

        evaluations.append(
            {
                "evaluation_id": _evaluation_id(signal["signal_id"]),
                "signal_id": signal["signal_id"],
                "run_id": signal["run_id"],
                "symbol": signal["symbol"],
                "signal_date": signal["date"],
                "signal_type": signal_type,
                "entry_price": entry_price,
                "evaluation_days": evaluation_days,
                "days_checked": days_checked,
                "status": status,
                "max_gain_pct": None if max_gain_pct is None else round(max_gain_pct, 6),
                "max_drawdown_pct": None if max_drawdown_pct is None else round(max_drawdown_pct, 6),
                "result_note": result_note,
            }
        )

    return pd.DataFrame.from_records(evaluations)


def _evaluate_buy(future_prices: pd.DataFrame, entry_price: float) -> str:
    gain_target = entry_price * 1.03
    loss_trigger = entry_price * 0.97
    for _, row in future_prices.iterrows():
        low = float(row["low"])
        high = float(row["high"])
        if low <= loss_trigger:
            return "WRONG"
        if high >= gain_target:
            return "CORRECT"
    return "WRONG"
