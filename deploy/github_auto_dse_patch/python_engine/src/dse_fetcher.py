from __future__ import annotations

import re
from datetime import datetime
from html.parser import HTMLParser
from zoneinfo import ZoneInfo

import pandas as pd
import requests


class DseLatestPriceParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_price_table = False
        self.table_depth = 0
        self.in_row = False
        self.in_cell = False
        self.current_cell: list[str] = []
        self.current_row: list[str] = []
        self.rows: list[list[str]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attrs_dict = {key: value or "" for key, value in attrs}
        if tag == "table" and "shares-table" in attrs_dict.get("class", ""):
            self.in_price_table = True
            self.table_depth = 1
            return
        if self.in_price_table and tag == "table":
            self.table_depth += 1
        if self.in_price_table and tag == "tr":
            self.in_row = True
            self.current_row = []
        if self.in_row and tag in {"td", "th"}:
            self.in_cell = True
            self.current_cell = []

    def handle_data(self, data: str) -> None:
        if self.in_cell:
            self.current_cell.append(data)

    def handle_endtag(self, tag: str) -> None:
        if self.in_cell and tag in {"td", "th"}:
            text = " ".join("".join(self.current_cell).split())
            self.current_row.append(text)
            self.in_cell = False
        if self.in_price_table and tag == "tr":
            if self.current_row:
                self.rows.append(self.current_row)
            self.in_row = False
        if self.in_price_table and tag == "table":
            self.table_depth -= 1
            if self.table_depth <= 0:
                self.in_price_table = False


def fetch_latest_dse_prices(
    latest_url: str,
    market_status_url: str,
    symbols: tuple[str, ...] = (),
    timeout: int = 30,
) -> pd.DataFrame:
    session = requests.Session()
    session.headers.update(
        {
            "User-Agent": "dse-paper-trading-engine/1.0 (+https://dse.rkpolin.com)",
            "Accept": "text/html, text/plain;q=0.9, */*;q=0.8",
        }
    )
    market_date = _fetch_market_date(session, market_status_url, timeout)
    html = session.get(latest_url, timeout=timeout).text
    return parse_latest_dse_html(html, market_date, symbols)


def parse_latest_dse_html(
    html: str,
    market_date,
    symbols: tuple[str, ...] = (),
) -> pd.DataFrame:
    parser = DseLatestPriceParser()
    parser.feed(html)
    wanted = set(symbols)
    records = []

    for cells in parser.rows:
        if len(cells) < 11 or not cells[0].isdigit():
            continue
        symbol = cells[1].upper().strip()
        if wanted and symbol not in wanted:
            continue

        ltp = _to_float(cells[2])
        high = _to_float(cells[3])
        low = _to_float(cells[4])
        close = _to_float(cells[5]) or ltp
        ycp = _to_float(cells[6])
        volume = int(_to_float(cells[10]))
        open_price = ycp or close

        if close <= 0:
            continue

        high = max(high, open_price, close, low)
        low = min(value for value in [low, open_price, close, high] if value > 0)
        records.append(
            {
                "symbol": symbol,
                "date": market_date,
                "open": open_price,
                "high": high,
                "low": low,
                "close": close,
                "volume": volume,
            }
        )

    if not records:
        raise ValueError("No DSE price rows could be parsed from latest share price page")
    return pd.DataFrame.from_records(records).sort_values(["symbol", "date"]).reset_index(drop=True)


def _fetch_market_date(session: requests.Session, url: str, timeout: int):
    try:
        text = session.get(url, timeout=timeout).text
        match = re.search(r"TODAY'S SHARE MARKET\s*:\s*(\d{4}-\d{2}-\d{2})", text)
        if match:
            return datetime.strptime(match.group(1), "%Y-%m-%d").date()
    except requests.RequestException:
        pass
    return datetime.now(ZoneInfo("Asia/Dhaka")).date()


def _to_float(value: str) -> float:
    cleaned = value.replace(",", "").strip()
    if cleaned in {"", "-", "--"}:
        return 0.0
    return float(cleaned)
