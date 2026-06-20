from __future__ import annotations

from pathlib import Path

import pandas as pd


REQUIRED_COLUMNS = {"symbol", "date", "open", "high", "low", "close", "volume"}

COLUMN_ALIASES = {
    "symbol": {"symbol", "ticker", "trading code", "trading_code", "instrument", "instr code"},
    "date": {"date", "trade_date", "trade date"},
    "open": {"open", "openp", "openp*", "opening price"},
    "high": {"high"},
    "low": {"low"},
    "close": {"close", "closep", "closep*", "closing price", "ltp", "ltp*"},
    "volume": {"volume"},
}


def load_price_csv(path: Path) -> pd.DataFrame:
    if not path.exists():
        raise FileNotFoundError(f"CSV file not found: {path}")

    df = pd.read_csv(path)
    df = _normalize_columns(df)
    missing = REQUIRED_COLUMNS - set(df.columns)
    if missing:
        raise ValueError(f"CSV is missing required columns: {sorted(missing)}")

    df = df.copy()
    df["symbol"] = df["symbol"].astype(str).str.upper().str.strip()
    df["date"] = pd.to_datetime(df["date"], errors="raise").dt.date

    numeric_columns = ["open", "high", "low", "close", "volume"]
    for column in numeric_columns:
        df[column] = pd.to_numeric(
            df[column].astype(str).str.replace(",", "", regex=False).str.strip(),
            errors="raise",
        )

    if (df["symbol"] == "").any():
        raise ValueError("CSV contains an empty symbol")
    if (df[["open", "high", "low", "close"]] <= 0).any().any():
        raise ValueError("OHLC prices must be positive")
    if (df["volume"] < 0).any():
        raise ValueError("Volume must be zero or positive")
    if (df["high"] < df[["open", "close", "low"]].max(axis=1)).any():
        raise ValueError("High must be greater than or equal to open, close, and low")
    if (df["low"] > df[["open", "close", "high"]].min(axis=1)).any():
        raise ValueError("Low must be less than or equal to open, close, and high")

    return df.sort_values(["symbol", "date"]).reset_index(drop=True)


def _normalize_columns(df: pd.DataFrame) -> pd.DataFrame:
    normalized = {}
    used_targets: set[str] = set()

    for original in df.columns:
        cleaned = str(original).strip()
        key = cleaned.lower().replace("\ufeff", "")
        target = None
        for canonical, aliases in COLUMN_ALIASES.items():
            if key in aliases:
                target = canonical
                break
        if target is not None and target not in used_targets:
            normalized[original] = target
            used_targets.add(target)
        else:
            normalized[original] = cleaned

    return df.rename(columns=normalized)


def merge_price_data(base: pd.DataFrame, latest: pd.DataFrame) -> pd.DataFrame:
    if base.empty:
        return latest.sort_values(["symbol", "date"]).reset_index(drop=True)
    if latest.empty:
        return base.sort_values(["symbol", "date"]).reset_index(drop=True)

    merged = pd.concat([base, latest], ignore_index=True)
    merged = merged.drop_duplicates(subset=["symbol", "date"], keep="last")
    return merged.sort_values(["symbol", "date"]).reset_index(drop=True)
