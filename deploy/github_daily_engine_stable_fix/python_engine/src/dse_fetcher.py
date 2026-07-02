from __future__ import annotations

import re
from datetime import date, datetime, timedelta
from html.parser import HTMLParser
from zoneinfo import ZoneInfo

import pandas as pd
import requests
import urllib3


DSE_HEADERS = {
    "User-Agent": "dse-paper-trading-engine/1.0 (+https://dse.rkpolin.com)",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}


def empty_price_frame() -> pd.DataFrame:
    return pd.DataFrame(columns=["symbol", "date", "open", "high", "low", "close", "volume"])


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


class DseArchiveParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_archive_table = False
        self.table_depth = 0
        self.in_row = False
        self.in_cell = False
        self.current_cell: list[str] = []
        self.current_row: list[str] = []
        self.rows: list[list[str]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attrs_dict = {key: value or "" for key, value in attrs}
        if tag == "table" and "shares-table" in attrs_dict.get("class", ""):
            self.in_archive_table = True
            self.table_depth = 1
            return
        if self.in_archive_table and tag == "table":
            self.table_depth += 1
        if self.in_archive_table and tag == "tr":
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
        if self.in_archive_table and tag == "tr":
            if self.current_row:
                self.rows.append(self.current_row)
            self.in_row = False
        if self.in_archive_table and tag == "table":
            self.table_depth -= 1
            if self.table_depth <= 0:
                self.in_archive_table = False


def fetch_latest_dse_prices(
    latest_url: str,
    market_status_url: str,
    symbols: tuple[str, ...] = (),
    timeout: int = 30,
) -> pd.DataFrame:
    session = requests.Session()
    session.headers.update(DSE_HEADERS)
    market_date = _fetch_market_date(session, market_status_url, timeout)
    html = _get_dse_text(session, latest_url, timeout)
    try:
        return parse_latest_dse_html(html, market_date, symbols)
    except ValueError as exc:
        print(f"Warning: latest DSE page could not be parsed; returning empty latest frame: {exc}")
        return empty_price_frame()


def fetch_dse_archive_prices(
    archive_url: str,
    start_date: date,
    end_date: date,
    symbols: tuple[str, ...] = (),
    timeout: int = 60,
) -> pd.DataFrame:
    session = requests.Session()
    session.headers.update(DSE_HEADERS | {"Referer": "https://www.dsebd.org/data_archive.php"})
    instrument = symbols[0] if len(symbols) == 1 else "All Instrument"
    html = _get_dse_text(
        session,
        archive_url,
        timeout,
        params={
            "startDate": start_date.isoformat(),
            "endDate": end_date.isoformat(),
            "inst": instrument,
            "archive": "data",
        },
    )
    parsed = parse_dse_archive_html(html, symbols)
    if parsed.empty:
        raise ValueError("No DSE archive rows could be parsed")
    return parsed


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
        ycp = _to_float(cells[6])
        close = _to_float(cells[5]) or ltp or ycp
        volume = int(_to_float(cells[10]))
        open_price = ycp or close

        if close <= 0:
            continue

        high = max(value for value in [high, open_price, close, low] if value > 0)
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


def parse_dse_archive_html(html: str, symbols: tuple[str, ...] = ()) -> pd.DataFrame:
    parser = DseArchiveParser()
    parser.feed(html)
    wanted = set(symbols)
    records = []

    for cells in parser.rows:
        if len(cells) < 12 or not cells[0].isdigit():
            continue
        symbol = cells[2].upper().strip()
        if wanted and symbol not in wanted:
            continue

        trade_date = datetime.strptime(cells[1], "%Y-%m-%d").date()
        ltp = _to_float(cells[3])
        high = _to_float(cells[4])
        low = _to_float(cells[5])
        open_price = _to_float(cells[6])
        close = _to_float(cells[7]) or ltp
        volume = int(_to_float(cells[11]))

        if close <= 0:
            continue
        high = max(high, open_price, close, low)
        low = min(value for value in [low, open_price, close, high] if value > 0)
        records.append(
            {
                "symbol": symbol,
                "date": trade_date,
                "open": open_price or close,
                "high": high,
                "low": low,
                "close": close,
                "volume": volume,
            }
        )

    return pd.DataFrame.from_records(records).sort_values(["symbol", "date"]).reset_index(drop=True)


def archive_start_date(end_date: date, lookback_days: int) -> date:
    return end_date - timedelta(days=max(1, lookback_days))


def _fetch_market_date(session: requests.Session, url: str, timeout: int):
    try:
        text = _get_dse_text(session, url, timeout)
        match = re.search(r"TODAY'S SHARE MARKET\s*:\s*(\d{4}-\d{2}-\d{2})", text)
        if match:
            return datetime.strptime(match.group(1), "%Y-%m-%d").date()
    except requests.RequestException:
        pass
    return datetime.now(ZoneInfo("Asia/Dhaka")).date()


def _get_dse_text(
    session: requests.Session,
    url: str,
    timeout: int,
    params: dict[str, str] | None = None,
) -> str:
    errors: list[str] = []
    for verify in (True, False):
        for candidate in _dse_url_candidates(url):
            try:
                if not verify:
                    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
                response = session.get(candidate, params=params, timeout=timeout, verify=verify)
                response.raise_for_status()
                if not verify:
                    print(
                        "Warning: DSE HTTPS certificate verification failed; "
                        "retried without verification for public market-data page only."
                    )
                return response.text
            except requests.RequestException as exc:
                errors.append(f"{candidate} verify={verify}: {exc}")
    raise RuntimeError("Could not fetch DSE public data. " + " | ".join(errors[-4:]))


def _dse_url_candidates(url: str) -> list[str]:
    candidates = [url]
    replacements = {
        "https://www.dsebd.org": "https://www.dse.com.bd",
        "https://dsebd.org": "https://dse.com.bd",
        "https://www.dse.com.bd": "https://www.dsebd.org",
        "https://dse.com.bd": "https://www.dsebd.org",
    }
    for source, target in replacements.items():
        if url.startswith(source):
            candidates.append(url.replace(source, target, 1))
    return list(dict.fromkeys(candidates))


def _to_float(value: str) -> float:
    cleaned = value.replace(",", "").strip()
    if cleaned in {"", "-", "--"}:
        return 0.0
    return float(cleaned)
