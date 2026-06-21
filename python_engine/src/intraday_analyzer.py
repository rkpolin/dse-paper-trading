from __future__ import annotations

from datetime import date
from typing import Any

import pandas as pd

from src.intraday_collector import TARGET_BUCKETS, normalize_bucket

EXTREME_COLUMNS = [
    "symbol",
    "trade_date",
    "day_high",
    "day_high_time",
    "day_low",
    "day_low_time",
    "intraday_range_pct",
    "open_snapshot_price",
    "close_snapshot_price",
    "snapshot_count",
    "is_complete",
]

STATS_COLUMNS = [
    "symbol",
    "lookback_days",
    "bucket_time",
    "sample_days",
    "low_count",
    "high_count",
    "low_probability",
    "high_probability",
    "avg_return_to_close_pct",
    "avg_return_next_bucket_pct",
    "buy_window_score",
    "sell_window_score",
    "confidence_level",
    "computed_through_date",
]


def calculate_daily_extremes(snapshots: pd.DataFrame) -> pd.DataFrame:
    if snapshots.empty:
        return pd.DataFrame(columns=EXTREME_COLUMNS)

    data = _prepare_snapshots(snapshots)
    records: list[dict[str, Any]] = []
    for (symbol, trade_date), group in data.groupby(["symbol", "trade_date"], sort=False):
        group = group.sort_values(["bucket_time", "snapshot_time"]).reset_index(drop=True)
        high_row = group.loc[group["day_high"].idxmax()]
        low_row = group.loc[group["day_low"].idxmin()]
        open_price = float(group.iloc[0]["last_price"])
        close_price = float(group.iloc[-1]["last_price"])
        day_high = float(high_row["day_high"])
        day_low = float(low_row["day_low"])
        range_pct = 0.0 if open_price <= 0 else (day_high - day_low) / open_price
        bucket_set = set(group["bucket_time"].astype(str).str.slice(0, 5))
        records.append(
            {
                "symbol": symbol,
                "trade_date": trade_date,
                "day_high": round(day_high, 4),
                "day_high_time": _time_with_seconds(high_row["bucket_time"]),
                "day_low": round(day_low, 4),
                "day_low_time": _time_with_seconds(low_row["bucket_time"]),
                "intraday_range_pct": round(range_pct, 6),
                "open_snapshot_price": round(open_price, 4),
                "close_snapshot_price": round(close_price, 4),
                "snapshot_count": int(len(group)),
                "is_complete": "14:05" in bucket_set or len(bucket_set) >= len(TARGET_BUCKETS),
            }
        )
    return pd.DataFrame.from_records(records, columns=EXTREME_COLUMNS)


def calculate_time_window_stats(
    snapshots: pd.DataFrame,
    extremes: pd.DataFrame,
    lookbacks: tuple[int, ...] = (20, 30, 60),
    as_of_date: date | None = None,
) -> pd.DataFrame:
    if snapshots.empty or extremes.empty:
        return pd.DataFrame(columns=STATS_COLUMNS)

    clean_snapshots = _prepare_snapshots(snapshots)
    clean_extremes = extremes.copy()
    clean_extremes["trade_date"] = pd.to_datetime(clean_extremes["trade_date"]).dt.date
    clean_extremes = clean_extremes[clean_extremes["is_complete"].astype(bool)]
    if as_of_date is not None:
        clean_extremes = clean_extremes[clean_extremes["trade_date"] < as_of_date]
        clean_snapshots = clean_snapshots[clean_snapshots["trade_date"] < as_of_date]
    if clean_extremes.empty:
        return pd.DataFrame(columns=STATS_COLUMNS)

    computed_through_date = max(clean_extremes["trade_date"])
    snapshot_returns = _snapshot_returns(clean_snapshots)
    records: list[dict[str, Any]] = []

    for symbol, symbol_extremes in clean_extremes.groupby("symbol"):
        symbol_dates = sorted(symbol_extremes["trade_date"].unique())
        symbol_snapshots = snapshot_returns[snapshot_returns["symbol"] == symbol]
        for lookback in lookbacks:
            keep_dates = set(symbol_dates[-lookback:])
            window_extremes = symbol_extremes[symbol_extremes["trade_date"].isin(keep_dates)]
            window_snapshots = symbol_snapshots[symbol_snapshots["trade_date"].isin(keep_dates)]
            sample_days = int(window_extremes["trade_date"].nunique())

            for bucket in TARGET_BUCKETS:
                bucket_key = normalize_bucket(bucket)
                low_count = int((window_extremes["day_low_time"].astype(str).str.slice(0, 5) == bucket_key).sum())
                high_count = int((window_extremes["day_high_time"].astype(str).str.slice(0, 5) == bucket_key).sum())
                bucket_rows = window_snapshots[
                    window_snapshots["bucket_time"].astype(str).str.slice(0, 5) == bucket_key
                ]
                avg_to_close = _mean_or_none(bucket_rows, "return_to_close_pct")
                avg_next = _mean_or_none(bucket_rows, "return_next_bucket_pct")
                low_probability = low_count / sample_days if sample_days else 0.0
                high_probability = high_count / sample_days if sample_days else 0.0
                records.append(
                    {
                        "symbol": symbol,
                        "lookback_days": lookback,
                        "bucket_time": bucket_key + ":00",
                        "sample_days": sample_days,
                        "low_count": low_count,
                        "high_count": high_count,
                        "low_probability": round(low_probability, 6),
                        "high_probability": round(high_probability, 6),
                        "avg_return_to_close_pct": avg_to_close,
                        "avg_return_next_bucket_pct": avg_next,
                        "buy_window_score": _buy_score(low_probability, avg_to_close, avg_next, sample_days),
                        "sell_window_score": _sell_score(high_probability, avg_to_close, avg_next, sample_days),
                        "confidence_level": confidence_level(sample_days),
                        "computed_through_date": computed_through_date,
                    }
                )

    return pd.DataFrame.from_records(records, columns=STATS_COLUMNS)


def confidence_level(sample_days: int) -> str:
    if sample_days < 20:
        return "NOT_ENOUGH_DATA"
    if sample_days >= 50:
        return "HIGH"
    if sample_days >= 30:
        return "MEDIUM"
    return "LOW"


def _prepare_snapshots(snapshots: pd.DataFrame) -> pd.DataFrame:
    data = snapshots.copy()
    data["trade_date"] = pd.to_datetime(data["trade_date"]).dt.date
    data["bucket_time"] = data["bucket_time"].astype(str).apply(lambda value: normalize_bucket(value) + ":00")
    data["snapshot_time"] = data["snapshot_time"].astype(str)
    for column in ["last_price", "day_high", "day_low"]:
        data[column] = data[column].astype(float)
    return data.sort_values(["symbol", "trade_date", "bucket_time"]).reset_index(drop=True)


def _snapshot_returns(snapshots: pd.DataFrame) -> pd.DataFrame:
    if snapshots.empty:
        return snapshots.copy()
    records = []
    for (_, trade_date), group in snapshots.groupby(["symbol", "trade_date"], sort=False):
        group = group.sort_values("bucket_time").reset_index(drop=True)
        close_price = float(group.iloc[-1]["last_price"])
        for idx, row in group.iterrows():
            price = float(row["last_price"])
            next_price = float(group.iloc[idx + 1]["last_price"]) if idx + 1 < len(group) else None
            record = row.to_dict()
            record["return_to_close_pct"] = None if price <= 0 else (close_price - price) / price
            record["return_next_bucket_pct"] = None if next_price is None or price <= 0 else (next_price - price) / price
            records.append(record)
    return pd.DataFrame.from_records(records)


def _mean_or_none(df: pd.DataFrame, column: str) -> float | None:
    if df.empty or column not in df.columns:
        return None
    values = pd.to_numeric(df[column], errors="coerce").dropna()
    if values.empty:
        return None
    return round(float(values.mean()), 6)


def _buy_score(low_probability: float, avg_to_close: float | None, avg_next: float | None, sample_days: int) -> float:
    if sample_days < 20:
        return 0.0
    score = low_probability * 0.7
    score += max(avg_to_close or 0.0, 0.0) * 3
    score += max(avg_next or 0.0, 0.0) * 2
    return round(min(score, 1.0), 6)


def _sell_score(high_probability: float, avg_to_close: float | None, avg_next: float | None, sample_days: int) -> float:
    if sample_days < 20:
        return 0.0
    score = high_probability * 0.7
    score += max(-(avg_to_close or 0.0), 0.0) * 3
    score += max(-(avg_next or 0.0), 0.0) * 2
    return round(min(score, 1.0), 6)


def _time_with_seconds(value: Any) -> str:
    return normalize_bucket(str(value)) + ":00"
