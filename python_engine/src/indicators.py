from __future__ import annotations

import numpy as np
import pandas as pd


def calculate_rsi(close: pd.Series, period: int = 14) -> pd.Series:
    delta = close.diff()
    gain = delta.clip(lower=0)
    loss = -delta.clip(upper=0)
    avg_gain = gain.rolling(window=period, min_periods=period).mean()
    avg_loss = loss.rolling(window=period, min_periods=period).mean()
    rs = avg_gain / avg_loss.replace(0, np.nan)
    rsi = 100 - (100 / (1 + rs))
    rsi = rsi.mask((avg_loss == 0) & (avg_gain > 0), 100)
    rsi = rsi.mask((avg_loss == 0) & (avg_gain == 0), 50)
    return rsi.fillna(50)


def add_indicators(prices: pd.DataFrame) -> pd.DataFrame:
    frames: list[pd.DataFrame] = []
    for _, group in prices.sort_values(["symbol", "date"]).groupby("symbol", sort=False):
        g = group.copy()
        g["sma20"] = g["close"].rolling(window=20, min_periods=1).mean()
        g["sma50"] = g["close"].rolling(window=50, min_periods=1).mean()
        avg_volume20 = g["volume"].rolling(window=20, min_periods=1).mean()
        g["volume_ratio"] = (g["volume"] / avg_volume20.replace(0, np.nan)).fillna(0)
        g["momentum"] = g["close"].pct_change(periods=5).fillna(0)
        prior_20_high = g["high"].rolling(window=20, min_periods=1).max().shift(1)
        g["breakout"] = (g["close"] > prior_20_high).fillna(False)
        g["rsi"] = calculate_rsi(g["close"])
        g["pump_risk"] = (
            ((g["volume_ratio"] >= 3.0) & (g["momentum"] >= 0.12))
            | ((g["rsi"] >= 85) & (g["volume_ratio"] >= 2.0))
        )
        frames.append(g)

    result = pd.concat(frames, ignore_index=True)
    return result.sort_values(["symbol", "date"]).reset_index(drop=True)
