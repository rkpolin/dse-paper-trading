from __future__ import annotations

from pathlib import Path

from src.data_loader import load_price_csv


def test_load_price_csv_accepts_downloaded_header_format(tmp_path: Path) -> None:
    csv_path = tmp_path / "downloaded.csv"
    csv_path.write_text(
        "\n".join(
            [
                "Date,Ticker,Open,High,Low,Close,Volume",
                "2026-06-18,GP,251.5,254.4,251.5,252.3,\"294,401\"",
            ]
        ),
        encoding="utf-8",
    )

    result = load_price_csv(csv_path)
    row = result.iloc[0]
    assert row["symbol"] == "GP"
    assert str(row["date"]) == "2026-06-18"
    assert row["open"] == 251.5
    assert row["close"] == 252.3
    assert row["volume"] == 294401


def test_load_price_csv_accepts_bangladesh_day_month_year_dates(tmp_path: Path) -> None:
    csv_path = tmp_path / "downloaded_dates.csv"
    csv_path.write_text(
        "\n".join(
            [
                "Date,Ticker,Open,High,Low,Close,Volume",
                "01-10-12,GP,251.5,254.4,251.5,252.3,294401",
                "02-10-2012,GP,252.3,255.0,252.0,254.0,300000",
            ]
        ),
        encoding="utf-8",
    )

    result = load_price_csv(csv_path)
    assert str(result.iloc[0]["date"]) == "2012-10-01"
    assert str(result.iloc[1]["date"]) == "2012-10-02"
