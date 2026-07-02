from __future__ import annotations

from datetime import date
from pathlib import Path
from types import SimpleNamespace

import pandas as pd

import run_engine


def test_load_prices_uses_archive_only_when_latest_page_fails(monkeypatch, tmp_path: Path) -> None:
    csv_path = tmp_path / "demo.csv"
    csv_path.write_text(
        "\n".join(
            [
                "Date,Ticker,Open,High,Low,Close,Volume",
                "2026-06-29,AAA,10,10,10,10,1000",
            ]
        ),
        encoding="utf-8",
    )

    archive_df = pd.DataFrame(
        [
            {"symbol": "AAA", "date": date(2026, 6, 29), "open": 10.0, "high": 11.0, "low": 9.5, "close": 10.5, "volume": 1200},
            {"symbol": "AAA", "date": date(2026, 6, 30), "open": 10.5, "high": 11.5, "low": 10.0, "close": 11.0, "volume": 1400},
        ]
    )

    config = SimpleNamespace(
        csv_path=csv_path,
        data_source="dse",
        dse_latest_url="https://example.com/latest",
        dse_market_status_url="https://example.com/mst",
        dse_archive_url="https://example.com/archive",
        dse_archive_lookback_days=120,
        dse_symbols=(),
        merge_dse_with_csv=True,
    )

    def fail_latest(*args, **kwargs):
        raise ValueError("No DSE price rows could be parsed from latest share price page")

    monkeypatch.setattr(run_engine, "fetch_latest_dse_prices", fail_latest)
    monkeypatch.setattr(run_engine, "fetch_dse_archive_prices", lambda *args, **kwargs: archive_df)

    result = run_engine.load_prices(config)

    assert not result.empty
    assert str(result["date"].max()) == "2026-06-30"
    assert "AAA" in set(result["symbol"])
