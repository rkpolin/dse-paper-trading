from __future__ import annotations

import hashlib
from typing import Any

import pandas as pd


VALID_SIGNALS = {"BUY", "SELL", "HOLD", "WATCH", "AVOID"}


def _signal_id(run_id: str, symbol: str, date_value: Any, signal_type: str) -> str:
    raw = f"{run_id}|{symbol}|{date_value}|{signal_type}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def _round_or_none(value: Any, digits: int = 4) -> float | None:
    if pd.isna(value):
        return None
    return round(float(value), digits)


def classify_signal(row: pd.Series) -> tuple[str, float, str]:
    close = float(row["close"])
    sma20 = float(row["sma20"])
    sma50 = float(row["sma50"])
    rsi = float(row["rsi"])
    volume_ratio = float(row["volume_ratio"])
    momentum = float(row["momentum"])
    breakout = bool(row["breakout"])
    pump_risk = bool(row["pump_risk"])

    if pump_risk:
        return "AVOID", 0.82, "Pump risk: abnormal volume and fast price movement"

    if close > sma20 > sma50 and 45 <= rsi <= 80 and volume_ratio >= 1.10 and momentum > 0:
        confidence = 0.72 + min(volume_ratio, 2.0) * 0.05 + min(momentum, 0.08)
        reason = "Trend aligned above SMA20/SMA50 with healthy momentum"
        if breakout:
            confidence += 0.05
            reason = "Breakout with trend confirmation and healthy volume"
        return "BUY", min(confidence, 0.95), reason

    if rsi < 40 or close < sma20 or momentum <= -0.03:
        return "SELL", 0.68, "Weak momentum or price below short-term average"

    if close >= sma20 and momentum > 0 and 40 <= rsi <= 82:
        return "WATCH", 0.58, "Improving setup but not enough confirmation"

    return "HOLD", 0.52, "No strong directional edge"


def generate_signals(indicator_df: pd.DataFrame, run_id: str) -> pd.DataFrame:
    records: list[dict[str, Any]] = []
    for _, row in indicator_df.sort_values(["date", "symbol"]).iterrows():
        signal_type, confidence, reason = classify_signal(row)
        symbol = str(row["symbol"])
        signal_date = row["date"]
        records.append(
            {
                "signal_id": _signal_id(run_id, symbol, signal_date, signal_type),
                "run_id": run_id,
                "symbol": symbol,
                "date": signal_date,
                "signal_type": signal_type,
                "close": float(row["close"]),
                "confidence": round(float(confidence), 4),
                "reason": reason,
                "rsi": _round_or_none(row["rsi"]),
                "sma20": _round_or_none(row["sma20"]),
                "sma50": _round_or_none(row["sma50"]),
                "volume_ratio": _round_or_none(row["volume_ratio"]),
                "momentum": _round_or_none(row["momentum"]),
                "breakout": bool(row["breakout"]),
                "pump_risk": bool(row["pump_risk"]),
            }
        )
    return pd.DataFrame.from_records(records)
