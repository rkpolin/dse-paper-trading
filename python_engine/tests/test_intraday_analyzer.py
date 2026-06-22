from __future__ import annotations

from datetime import date, timedelta

import pandas as pd

from src.intraday_analyzer import calculate_daily_extremes, calculate_time_window_stats


def test_daily_extremes_detect_high_and_low_times() -> None:
    snapshots = _snapshots(date(2026, 6, 1), days=1)

    extremes = calculate_daily_extremes(snapshots)
    row = extremes.iloc[0]

    assert row["day_low"] == 98
    assert row["day_low_time"] == "10:20:00"
    assert row["day_high"] == 103
    assert row["day_high_time"] == "14:05:00"
    assert row["snapshot_count"] == 4
    assert bool(row["is_complete"]) is True


def test_time_window_stats_scores_buy_and_sell_windows_after_20_days() -> None:
    snapshots = _snapshots(date(2026, 5, 1), days=20)
    extremes = calculate_daily_extremes(snapshots)

    stats = calculate_time_window_stats(snapshots, extremes, lookbacks=(20,), as_of_date=date(2026, 6, 1))
    buy_row = stats[(stats["bucket_time"] == "10:20:00") & (stats["lookback_days"] == 20)].iloc[0]
    sell_row = stats[(stats["bucket_time"] == "14:05:00") & (stats["lookback_days"] == 20)].iloc[0]

    assert buy_row["sample_days"] == 20
    assert buy_row["confidence_level"] == "LOW"
    assert buy_row["low_probability"] == 1.0
    assert buy_row["buy_window_score"] > 0
    assert sell_row["high_probability"] == 1.0
    assert sell_row["sell_window_score"] > 0


def test_stats_do_not_use_today_for_today_recommendation() -> None:
    history = _snapshots(date(2026, 5, 1), days=20)
    today = _snapshots(date(2026, 6, 1), days=1, low_bucket="10:05")
    snapshots = pd.concat([history, today], ignore_index=True)
    extremes = calculate_daily_extremes(snapshots)

    stats = calculate_time_window_stats(snapshots, extremes, lookbacks=(20,), as_of_date=date(2026, 6, 1))
    today_low_bucket = stats[(stats["bucket_time"] == "10:05:00") & (stats["lookback_days"] == 20)].iloc[0]
    historical_low_bucket = stats[(stats["bucket_time"] == "10:20:00") & (stats["lookback_days"] == 20)].iloc[0]

    assert today_low_bucket["low_count"] == 0
    assert historical_low_bucket["low_count"] == 20


def test_not_enough_data_has_zero_scores() -> None:
    snapshots = _snapshots(date(2026, 5, 1), days=5)
    extremes = calculate_daily_extremes(snapshots)

    stats = calculate_time_window_stats(snapshots, extremes, lookbacks=(20,), as_of_date=date(2026, 6, 1))

    assert set(stats["confidence_level"]) == {"NOT_ENOUGH_DATA"}
    assert float(stats["buy_window_score"].max()) == 0.0
    assert float(stats["sell_window_score"].max()) == 0.0


def test_empty_data_returns_empty_frames() -> None:
    empty = pd.DataFrame()

    extremes = calculate_daily_extremes(empty)
    stats = calculate_time_window_stats(empty, extremes)

    assert extremes.empty
    assert stats.empty


def _snapshots(start: date, days: int, low_bucket: str = "10:20") -> pd.DataFrame:
    records = []
    for offset in range(days):
        trade_date = start + timedelta(days=offset)
        low_by_bucket = {"10:05": 99, "10:20": 98, "10:35": 98, "14:05": 98}
        if low_bucket == "10:05":
            low_by_bucket = {"10:05": 97, "10:20": 97, "10:35": 97, "14:05": 97}
        for bucket, price, high, low in [
            ("10:05", 100, 100, low_by_bucket["10:05"]),
            ("10:20", 99, 100, low_by_bucket["10:20"]),
            ("10:35", 101, 102, low_by_bucket["10:35"]),
            ("14:05", 103, 103, low_by_bucket["14:05"]),
        ]:
            records.append(
                {
                    "symbol": "AAA",
                    "trade_date": trade_date,
                    "snapshot_time": bucket + ":00",
                    "bucket_time": bucket + ":00",
                    "snapshot_at": f"{trade_date} {bucket}:00",
                    "last_price": price,
                    "day_high": high,
                    "day_low": low,
                    "volume": 1000,
                    "source": "test",
                }
            )
    return pd.DataFrame.from_records(records)
