from __future__ import annotations

from datetime import datetime, time
from pathlib import Path
from zoneinfo import ZoneInfo

import pandas as pd
import requests

from src.dse_fetcher import DSE_HEADERS, DseLatestPriceParser, _fetch_market_date, _get_dse_text, _to_float
from src.payload import clean_symbol

BD_TZ = ZoneInfo("Asia/Dhaka")
TARGET_BUCKETS = (
    "10:05",
    "10:20",
    "10:35",
    "10:50",
    "11:05",
    "11:20",
    "11:35",
    "11:50",
    "12:05",
    "12:20",
    "12:35",
    "12:50",
    "13:05",
    "13:20",
    "13:35",
    "13:50",
    "14:05",
)

INTRADAY_COLUMNS = [
    "symbol",
    "trade_date",
    "snapshot_time",
    "bucket_time",
    "snapshot_at",
    "last_price",
    "day_high",
    "day_low",
    "volume",
    "source",
]


def now_bd() -> datetime:
    return datetime.now(BD_TZ)


def normalize_bucket(bucket: str) -> str:
    parts = bucket.strip().split(":")
    if len(parts) < 2:
        raise ValueError(f"Invalid bucket time: {bucket}")
    return f"{int(parts[0]):02d}:{int(parts[1]):02d}"


def bucket_as_time(bucket: str) -> time:
    normalized = normalize_bucket(bucket)
    hour, minute = normalized.split(":")
    return time(int(hour), int(minute))


def time_to_bucket(value: time | str) -> str:
    if isinstance(value, str):
        return normalize_bucket(value)
    return f"{value.hour:02d}:{value.minute:02d}"


def is_market_day(local_dt: datetime, holidays: tuple[str, ...] = ()) -> bool:
    if local_dt.weekday() in {4, 5}:
        return False
    return local_dt.date().isoformat() not in set(holidays)


def nearest_target_bucket(local_dt: datetime, tolerance_minutes: int = 8) -> str | None:
    selected_bucket = None
    for bucket in TARGET_BUCKETS:
        bucket_dt = datetime.combine(local_dt.date(), bucket_as_time(bucket), tzinfo=BD_TZ)
        minutes_after_bucket = (local_dt - bucket_dt).total_seconds() / 60
        if 0 <= minutes_after_bucket <= tolerance_minutes:
            selected_bucket = bucket
    return selected_bucket


def should_collect_now(
    local_dt: datetime,
    holidays: tuple[str, ...] = (),
    tolerance_minutes: int = 8,
    force: bool = False,
) -> tuple[bool, str | None, str]:
    if not force and not is_market_day(local_dt, holidays):
        return False, None, "Market closed: weekend or configured holiday"

    bucket = nearest_target_bucket(local_dt, tolerance_minutes)
    if bucket is None and force:
        bucket = time_to_bucket(local_dt.time())
    if bucket is None:
        return False, None, "Outside configured intraday collection buckets"
    return True, bucket, "OK"


def empty_intraday_frame() -> pd.DataFrame:
    return pd.DataFrame(columns=INTRADAY_COLUMNS)


def collect_dse_intraday_snapshots(
    latest_url: str,
    market_status_url: str,
    bucket_time: str,
    local_dt: datetime,
    symbols: tuple[str, ...] = (),
    timeout: int = 30,
) -> pd.DataFrame:
    session = requests.Session()
    session.headers.update(DSE_HEADERS)
    market_date = _fetch_market_date(session, market_status_url, timeout)
    html = _get_dse_text(session, latest_url, timeout)
    parser = DseLatestPriceParser()
    parser.feed(html)

    wanted = {clean_symbol(symbol) for symbol in symbols if clean_symbol(symbol)}
    snapshot_time = f"{local_dt.hour:02d}:{local_dt.minute:02d}:00"
    snapshot_at = local_dt.replace(tzinfo=None, second=0, microsecond=0).strftime("%Y-%m-%d %H:%M:%S")
    records = []

    for cells in parser.rows:
        if len(cells) < 11 or not cells[0].isdigit():
            continue

        symbol = clean_symbol(cells[1])
        if not symbol or (wanted and symbol not in wanted):
            continue

        last_price = _to_float(cells[2])
        day_high = _to_float(cells[3])
        day_low = _to_float(cells[4])
        volume = int(_to_float(cells[10]))
        if last_price <= 0 or day_high <= 0 or day_low <= 0:
            continue

        records.append(
            {
                "symbol": symbol,
                "trade_date": market_date,
                "snapshot_time": snapshot_time,
                "bucket_time": normalize_bucket(bucket_time),
                "snapshot_at": snapshot_at,
                "last_price": last_price,
                "day_high": max(day_high, last_price),
                "day_low": min(day_low, last_price),
                "volume": volume,
                "source": "dse_latest_share_price",
            }
        )

    if not records:
        return empty_intraday_frame()
    return pd.DataFrame.from_records(records, columns=INTRADAY_COLUMNS)


def load_intraday_csv(path: Path) -> pd.DataFrame:
    if not path.exists():
        return empty_intraday_frame()

    df = pd.read_csv(path)
    df = _normalize_intraday_columns(df)
    if "datetime" in df.columns and ("trade_date" not in df.columns or "snapshot_time" not in df.columns):
        parsed = pd.to_datetime(df["datetime"], errors="raise")
        df["trade_date"] = parsed.dt.date
        df["snapshot_time"] = parsed.dt.strftime("%H:%M:%S")

    required = {"symbol", "trade_date", "snapshot_time", "last_price", "day_high", "day_low", "volume"}
    missing = required - set(df.columns)
    if missing:
        raise ValueError(f"Intraday CSV is missing required columns: {sorted(missing)}")

    output = pd.DataFrame()
    output["symbol"] = df["symbol"].apply(clean_symbol)
    output["trade_date"] = pd.to_datetime(df["trade_date"], errors="raise").dt.date
    output["snapshot_time"] = df["snapshot_time"].astype(str).apply(_normalize_time_with_seconds)
    if "bucket_time" in df.columns:
        output["bucket_time"] = df["bucket_time"].astype(str).apply(lambda value: normalize_bucket(value) + ":00")
    else:
        output["bucket_time"] = output["snapshot_time"].str.slice(0, 5) + ":00"
    if "snapshot_at" in df.columns:
        output["snapshot_at"] = pd.to_datetime(df["snapshot_at"], errors="raise").dt.strftime("%Y-%m-%d %H:%M:%S")
    else:
        output["snapshot_at"] = output["trade_date"].astype(str) + " " + output["snapshot_time"]
    output["last_price"] = pd.to_numeric(df["last_price"].astype(str).str.replace(",", "", regex=False), errors="raise")
    output["day_high"] = pd.to_numeric(df["day_high"].astype(str).str.replace(",", "", regex=False), errors="raise")
    output["day_low"] = pd.to_numeric(df["day_low"].astype(str).str.replace(",", "", regex=False), errors="raise")
    output["volume"] = pd.to_numeric(df["volume"].astype(str).str.replace(",", "", regex=False), errors="raise").astype(int)
    output["source"] = df["source"].astype(str) if "source" in df.columns else "csv_intraday_history"

    output = output[output["symbol"] != ""]
    output = output.drop_duplicates(subset=["symbol", "trade_date", "bucket_time"], keep="last")
    return output.sort_values(["trade_date", "symbol", "bucket_time"]).reset_index(drop=True)


def _normalize_intraday_columns(df: pd.DataFrame) -> pd.DataFrame:
    aliases = {
        "symbol": {"symbol", "ticker", "trading code", "trading_code"},
        "trade_date": {"date", "trade_date", "trade date"},
        "snapshot_time": {"time", "snapshot_time", "snapshot time"},
        "datetime": {"datetime", "date_time", "snapshot_at", "snapshot at"},
        "bucket_time": {"bucket_time", "bucket time"},
        "last_price": {"ltp", "last_price", "last price", "price", "close"},
        "day_high": {"high", "day_high", "day high"},
        "day_low": {"low", "day_low", "day low"},
        "volume": {"volume", "vol"},
        "source": {"source"},
    }
    renamed = {}
    used = set()
    for original in df.columns:
        key = str(original).strip().lower().replace("\ufeff", "")
        target = None
        for canonical, names in aliases.items():
            if key in names:
                target = canonical
                break
        if target and target not in used:
            renamed[original] = target
            used.add(target)
        else:
            renamed[original] = str(original).strip()
    return df.rename(columns=renamed)


def _normalize_time_with_seconds(value: str) -> str:
    parts = value.strip().split(":")
    if len(parts) < 2:
        raise ValueError(f"Invalid intraday time: {value}")
    hour = int(parts[0])
    minute = int(parts[1])
    second = int(parts[2]) if len(parts) > 2 and parts[2] else 0
    return f"{hour:02d}:{minute:02d}:{second:02d}"
