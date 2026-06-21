from __future__ import annotations

import copy
import re
from datetime import datetime, timezone
from typing import Any

import pandas as pd

SYMBOL_CLEAN_RE = re.compile(r"[^A-Za-z0-9]+")
SYMBOL_PAYLOAD_KEYS = (
    "stocks",
    "daily_prices",
    "indicators",
    "signals",
    "paper_trades",
    "positions",
    "accuracy_evaluations",
)


def build_payload(
    run_id: str,
    prices: pd.DataFrame,
    indicators: pd.DataFrame,
    signals: pd.DataFrame,
    trading: dict[str, Any],
    evaluations: pd.DataFrame,
    started_at: datetime,
    status: str = "SUCCESS",
) -> dict[str, Any]:
    completed_at = datetime.now(timezone.utc)
    latest_date = max(prices["date"]) if not prices.empty else None
    accuracy_summary = _accuracy_summary(evaluations)
    return {
        "schema_version": 1,
        "run": {
            "run_id": run_id,
            "started_at": started_at.isoformat(),
            "completed_at": completed_at.isoformat(),
            "status": status,
            "source": "github_actions_python_engine",
            "latest_data_date": latest_date,
        },
        "stocks": _stocks(prices),
        "daily_prices": _records(prices, ["symbol", "date", "open", "high", "low", "close", "volume"]),
        "indicators": _records(
            indicators,
            [
                "symbol",
                "date",
                "rsi",
                "sma20",
                "sma50",
                "volume_ratio",
                "momentum",
                "breakout",
                "pump_risk",
            ],
        ),
        "signals": _records(signals),
        "paper_trades": trading["trades"],
        "positions": trading["positions"],
        "portfolio_snapshots": trading["snapshots"],
        "accuracy_evaluations": _records(evaluations),
        "strategy_performance": {
            "run_id": run_id,
            "strategy_name": "dse_mvp_rules_v1",
            "initial_balance": trading["summary"]["initial_balance"],
            "ending_value": trading["summary"]["ending_value"],
            "total_pl": trading["summary"]["total_pl"],
            "total_return_pct": trading["summary"]["total_return_pct"],
            "trade_count": trading["summary"]["trade_count"],
            "win_rate": _trade_win_rate(trading["trades"]),
            "signal_accuracy": accuracy_summary["signal_accuracy"],
        },
    }


def _records(df: pd.DataFrame, columns: list[str] | None = None) -> list[dict[str, Any]]:
    if df.empty:
        return []
    selected = df[columns] if columns else df
    output = []
    for record in selected.to_dict(orient="records"):
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


def clean_symbol(symbol: Any) -> str:
    return SYMBOL_CLEAN_RE.sub("", str(symbol).strip()).upper()


def sanitize_payload_symbols(payload: dict[str, Any]) -> tuple[dict[str, Any], dict[str, int]]:
    clean_payload = copy.deepcopy(payload)
    stats = {"changed": 0, "dropped": 0}

    for key in SYMBOL_PAYLOAD_KEYS:
        rows = clean_payload.get(key)
        if not isinstance(rows, list):
            continue

        clean_rows = []
        for row in rows:
            if not isinstance(row, dict) or "symbol" not in row:
                clean_rows.append(row)
                continue

            original = str(row["symbol"])
            cleaned = clean_symbol(original)
            if not cleaned:
                stats["dropped"] += 1
                continue
            if cleaned != original:
                stats["changed"] += 1
                row["symbol"] = cleaned
                if key == "stocks" and str(row.get("name", "")) == original:
                    row["name"] = cleaned
            clean_rows.append(row)

        clean_payload[key] = clean_rows

    return clean_payload, stats


def _stocks(prices: pd.DataFrame) -> list[dict[str, str]]:
    return [{"symbol": symbol, "name": symbol} for symbol in sorted(prices["symbol"].unique())]


def _trade_win_rate(trades: list[dict[str, Any]]) -> float:
    sells = [trade for trade in trades if trade["side"] == "SELL"]
    if not sells:
        return 0.0
    winners = [trade for trade in sells if float(trade.get("realized_pl", 0)) > 0]
    return round(len(winners) / len(sells), 6)


def _accuracy_summary(evaluations: pd.DataFrame) -> dict[str, float]:
    if evaluations.empty:
        return {"signal_accuracy": 0.0}
    scored = evaluations[evaluations["status"].isin(["CORRECT", "WRONG"])]
    if scored.empty:
        return {"signal_accuracy": 0.0}
    return {"signal_accuracy": round(float((scored["status"] == "CORRECT").mean()), 6)}
