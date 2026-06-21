from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

import pandas as pd

from src.payload import sanitize_payload_symbols


def build_intraday_run(run_id: str, started_at: datetime, latest_data_date: Any, status: str = "SUCCESS") -> dict[str, Any]:
    completed_at = datetime.now(timezone.utc)
    return {
        "run_id": run_id,
        "started_at": started_at.isoformat(),
        "completed_at": completed_at.isoformat(),
        "status": status,
        "source": "github_actions_intraday_engine",
        "latest_data_date": latest_data_date,
    }


def build_snapshots_payload(run_id: str, snapshots: pd.DataFrame, started_at: datetime) -> dict[str, Any]:
    latest_date = _latest_date(snapshots, "trade_date")
    payload = {
        "schema_version": 1,
        "run": build_intraday_run(run_id, started_at, latest_date),
        "intraday_snapshots": _records(snapshots),
    }
    return sanitize_payload_symbols(payload)[0]


def build_extremes_payload(run_id: str, extremes: pd.DataFrame, started_at: datetime) -> dict[str, Any]:
    latest_date = _latest_date(extremes, "trade_date")
    payload = {
        "schema_version": 1,
        "run": build_intraday_run(run_id, started_at, latest_date),
        "daily_intraday_extremes": _records(extremes),
    }
    return sanitize_payload_symbols(payload)[0]


def build_stats_payload(run_id: str, stats: pd.DataFrame, started_at: datetime) -> dict[str, Any]:
    latest_date = _latest_date(stats, "computed_through_date")
    payload = {
        "schema_version": 1,
        "run": build_intraday_run(run_id, started_at, latest_date),
        "intraday_time_window_stats": _records(stats),
    }
    return sanitize_payload_symbols(payload)[0]


def _latest_date(df: pd.DataFrame, column: str) -> Any:
    if df.empty or column not in df.columns:
        return None
    return max(df[column])


def _records(df: pd.DataFrame) -> list[dict[str, Any]]:
    if df.empty:
        return []
    output = []
    for record in df.to_dict(orient="records"):
        clean = {}
        for key, value in record.items():
            if pd.isna(value):
                clean[key] = None
            elif hasattr(value, "isoformat"):
                clean[key] = value.isoformat()
            else:
                clean[key] = value
        output.append(clean)
    return output
