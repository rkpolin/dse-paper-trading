from __future__ import annotations

from datetime import date

import requests

from src.dse_fetcher import fetch_latest_dse_prices, parse_dse_archive_html, parse_latest_dse_html


def test_parse_latest_dse_html_converts_share_table_to_ohlcv() -> None:
    html = """
    <table class='table table-bordered background-white shares-table fixedHeader'>
      <thead><tr><th>#</th><th>TRADING CODE</th><th>LTP*</th><th>HIGH</th><th>LOW</th><th>CLOSEP*</th><th>YCP*</th><th>CHANGE</th><th>TRADE</th><th>VALUE (mn)</th><th>VOLUME</th></tr></thead>
      <tbody>
        <tr>
          <td>1</td><td><a>GP</a></td><td>263.10</td><td>263.10</td><td>261.50</td><td>263.10</td><td>261.60</td><td>1.50</td><td>21</td><td>0.63</td><td>2,390</td>
        </tr>
      </tbody>
    </table>
    """
    result = parse_latest_dse_html(html, date(2026, 6, 18))
    row = result.iloc[0]
    assert row["symbol"] == "GP"
    assert row["date"] == date(2026, 6, 18)
    assert row["open"] == 261.60
    assert row["high"] == 263.10
    assert row["low"] == 261.50
    assert row["close"] == 263.10
    assert row["volume"] == 2390


def test_parse_latest_dse_html_uses_ycp_when_latest_values_are_zero() -> None:
    html = """
    <table class='table table-bordered background-white shares-table fixedHeader'>
      <thead><tr><th>#</th><th>TRADING CODE</th><th>LTP*</th><th>HIGH</th><th>LOW</th><th>CLOSEP*</th><th>YCP*</th><th>CHANGE</th><th>TRADE</th><th>VALUE (mn)</th><th>VOLUME</th></tr></thead>
      <tbody>
        <tr>
          <td>1</td><td><a>ABBANK</a></td><td>0</td><td>0</td><td>0</td><td>0</td><td>5.0</td><td>--</td><td>0</td><td>0</td><td>0</td>
        </tr>
      </tbody>
    </table>
    """
    result = parse_latest_dse_html(html, date(2026, 7, 2))
    row = result.iloc[0]
    assert row["symbol"] == "ABBANK"
    assert row["date"] == date(2026, 7, 2)
    assert row["open"] == 5.0
    assert row["high"] == 5.0
    assert row["low"] == 5.0
    assert row["close"] == 5.0
    assert row["volume"] == 0


def test_fetch_latest_dse_prices_returns_empty_frame_when_parse_fails(monkeypatch) -> None:
    class DummyResponse:
        headers = {}

    class DummySession:
        def __init__(self) -> None:
            self.headers = {}

        def get(self, *args, **kwargs):
            return DummyResponse()

    monkeypatch.setattr(requests, "Session", DummySession)
    monkeypatch.setattr("src.dse_fetcher._fetch_market_date", lambda *args, **kwargs: date(2026, 7, 2))
    monkeypatch.setattr("src.dse_fetcher._get_dse_text", lambda *args, **kwargs: "<html><body>no table</body></html>")

    result = fetch_latest_dse_prices("https://example.com/latest", "https://example.com/mst")

    assert result.empty
    assert list(result.columns) == ["symbol", "date", "open", "high", "low", "close", "volume"]


def test_parse_dse_archive_html_converts_day_end_table_to_ohlcv() -> None:
    html = """
    <table class='table table-bordered background-white shares-table fixedHeader'>
      <thead><tr><th>#</th><th>DATE</th><th>TRADING CODE</th><th>LTP*</th><th>HIGH</th><th>LOW</th><th>OPENP*</th><th>CLOSEP*</th><th>YCP</th><th>TRADE</th><th>VALUE (mn)</th><th>VOLUME</th></tr></thead>
      <tbody>
        <tr>
          <td>1</td><td>2026-06-18</td><td><a>GP</a></td><td>252.3</td><td>254.4</td><td>251.5</td><td>251.5</td><td>252.3</td><td>251.5</td><td>1,790</td><td>74.467</td><td>294,401</td>
        </tr>
      </tbody>
    </table>
    """
    result = parse_dse_archive_html(html)
    row = result.iloc[0]
    assert row["symbol"] == "GP"
    assert row["date"] == date(2026, 6, 18)
    assert row["open"] == 251.5
    assert row["high"] == 254.4
    assert row["low"] == 251.5
    assert row["close"] == 252.3
    assert row["volume"] == 294401
