from __future__ import annotations

from datetime import datetime

from src.intraday_collector import BD_TZ, load_intraday_csv, nearest_target_bucket, should_collect_now


def test_nearest_target_bucket_uses_configured_15_minute_windows() -> None:
    local_dt = datetime(2026, 6, 21, 10, 6, tzinfo=BD_TZ)

    assert nearest_target_bucket(local_dt, tolerance_minutes=8) == "10:05"


def test_delayed_run_never_uses_future_bucket() -> None:
    still_valid_for_1005 = datetime(2026, 6, 21, 10, 13, tzinfo=BD_TZ)
    too_early_for_1020 = datetime(2026, 6, 21, 10, 18, tzinfo=BD_TZ)

    assert nearest_target_bucket(still_valid_for_1005, tolerance_minutes=8) == "10:05"
    assert nearest_target_bucket(too_early_for_1020, tolerance_minutes=8) is None


def test_market_closed_skip_on_friday_and_holiday() -> None:
    friday = datetime(2026, 6, 19, 10, 5, tzinfo=BD_TZ)
    holiday = datetime(2026, 6, 21, 10, 5, tzinfo=BD_TZ)

    assert should_collect_now(friday)[0] is False
    assert should_collect_now(holiday, holidays=("2026-06-21",))[0] is False


def test_intraday_csv_loader_drops_duplicate_symbol_date_bucket(tmp_path) -> None:
    csv_path = tmp_path / "intraday.csv"
    csv_path.write_text(
        "\n".join(
            [
                "Date,Time,Ticker,LTP,High,Low,Volume",
                "2026-06-21,10:05,ABC-1,100,101,99,1000",
                "2026-06-21,10:05,ABC-1,101,102,99,1200",
            ]
        ),
        encoding="utf-8",
    )

    rows = load_intraday_csv(csv_path)

    assert len(rows) == 1
    assert rows.iloc[0]["symbol"] == "ABC1"
    assert rows.iloc[0]["last_price"] == 101
    assert rows.iloc[0]["volume"] == 1200
