from __future__ import annotations

import json
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

import pandas as pd

from src.api_client import HostingerApiClient, send_telegram_summary
from src.config import ENGINE_ROOT, EngineConfig
from src.data_loader import load_price_csv, merge_price_data
from src.dse_fetcher import archive_start_date, fetch_dse_archive_prices, fetch_latest_dse_prices
from src.evaluator import evaluate_signals
from src.indicators import add_indicators
from src.paper_trader import TradingRules, simulate_paper_trades
from src.payload import build_payload, sanitize_payload_symbols
from src.signals import generate_signals


def main() -> int:
    started_at = datetime.now(timezone.utc)
    run_id = f"run-{started_at.strftime('%Y%m%dT%H%M%SZ')}-{uuid.uuid4().hex[:8]}"
    config = EngineConfig.from_env()

    prices = load_prices(config)
    print(
        "Loaded price data: "
        f"{len(prices)} rows, "
        f"{prices['symbol'].nunique()} symbols, "
        f"{min(prices['date'])} to {max(prices['date'])}"
    )
    indicators = add_indicators(prices)
    signals = generate_signals(indicators, run_id)
    rules = TradingRules(
        initial_balance=config.initial_balance,
        max_position_pct=config.max_position_pct,
        max_open_positions=config.max_open_positions,
        transaction_cost_pct=config.transaction_cost_pct,
        stop_loss_pct=config.stop_loss_pct,
        take_profit_pct=config.take_profit_pct,
    )
    trading = simulate_paper_trades(prices, signals, run_id, rules)
    evaluations = evaluate_signals(prices, signals, config.evaluation_days)
    api_prices, api_indicators, api_signals, api_trading, api_evaluations = limit_api_payload_data(
        prices,
        indicators,
        signals,
        trading,
        evaluations,
        config.api_payload_trading_days,
    )
    payload = build_payload(
        run_id,
        api_prices,
        api_indicators,
        api_signals,
        api_trading,
        api_evaluations,
        started_at,
    )
    payload, symbol_stats = sanitize_payload_symbols(payload)
    if symbol_stats["changed"] or symbol_stats["dropped"]:
        print(
            "Cleaned payload symbols: "
            f"{symbol_stats['changed']} changed, {symbol_stats['dropped']} dropped"
        )
    print_api_payload_counts(payload)

    if config.api_enabled:
        print(f"Hostinger API timeout seconds: {config.api_timeout_seconds}")
        result = HostingerApiClient(
            config.api_base_url,
            config.api_token,
            config.hmac_secret,
            timeout=config.api_timeout_seconds,
        ).post_run(payload)
        print(json.dumps({"run_id": run_id, "api_result": result}, indent=2))
    else:
        output_dir = ENGINE_ROOT / "output"
        output_dir.mkdir(parents=True, exist_ok=True)
        output_file = output_dir / "latest_payload.json"
        output_file.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
        print(f"API credentials not configured. Wrote local payload to {output_file}")

    if config.telegram_enabled:
        summary = payload["strategy_performance"]
        send_telegram_summary(
            config.telegram_bot_token,
            config.telegram_chat_id,
            (
                f"DSE paper run {run_id}\n"
                f"Portfolio: {summary['ending_value']:.2f} BDT\n"
                f"P/L: {summary['total_pl']:.2f} BDT\n"
                f"Signal accuracy: {summary['signal_accuracy']:.2%}"
            ),
        )

    return 0


def limit_api_payload_data(
    prices: pd.DataFrame,
    indicators: pd.DataFrame,
    signals: pd.DataFrame,
    trading: dict[str, Any],
    evaluations: pd.DataFrame,
    max_trading_days: int,
) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame, dict[str, Any], pd.DataFrame]:
    if max_trading_days <= 0 or prices.empty or "date" not in prices.columns:
        return prices, indicators, signals, trading, evaluations

    trading_dates = sorted(prices["date"].dropna().unique())
    keep_dates = trading_dates[-max_trading_days:]
    keep_date_strings = {str(date_value) for date_value in keep_dates}
    if not keep_dates:
        return prices, indicators, signals, trading, evaluations

    print(
        "API payload limited to latest "
        f"{len(keep_dates)} trading days: {keep_dates[0]} to {keep_dates[-1]}"
    )
    return (
        _filter_frame_by_dates(prices, "date", keep_dates),
        _filter_frame_by_dates(indicators, "date", keep_dates),
        _filter_frame_by_dates(signals, "date", keep_dates),
        _filter_trading_payload(trading, keep_date_strings),
        _filter_frame_by_dates(evaluations, "signal_date", keep_dates),
    )


def _filter_frame_by_dates(df: pd.DataFrame, column: str, keep_dates: list[Any]) -> pd.DataFrame:
    if df.empty or column not in df.columns:
        return df.copy()
    return df[df[column].isin(keep_dates)].copy()


def _filter_trading_payload(trading: dict[str, Any], keep_date_strings: set[str]) -> dict[str, Any]:
    filtered = dict(trading)
    # Keep the full trade and snapshot history for the current run.
    # Trimming these causes two practical problems:
    # 1. SELL trades can reference older BUY trades through entry_trade_id,
    #    which breaks the paper_trades foreign key on the API side.
    # 2. The dashboard needs full run history for lifecycle, win-rate, and
    #    portfolio history views.
    filtered["trades"] = list(trading.get("trades", []))
    filtered["positions"] = list(trading.get("positions", []))
    filtered["snapshots"] = list(trading.get("snapshots", []))
    filtered["summary"] = dict(trading.get("summary", {}))
    return filtered


def print_api_payload_counts(payload: dict[str, Any]) -> None:
    count_keys = [
        "stocks",
        "daily_prices",
        "indicators",
        "signals",
        "paper_trades",
        "positions",
        "portfolio_snapshots",
        "accuracy_evaluations",
    ]
    counts = ", ".join(f"{key}={len(payload.get(key, []))}" for key in count_keys)
    print(f"API payload rows: {counts}")


def load_prices(config: EngineConfig):
    csv_prices = load_price_csv(config.csv_path)
    if config.data_source == "csv":
        return csv_prices
    if config.data_source not in {"auto", "dse"}:
        raise ValueError("DATA_SOURCE must be one of: auto, dse, csv")

    if config.dse_skip_latest_page:
        archive_end_date = datetime.now(ZoneInfo("Asia/Dhaka")).date()
        archive_prices = fetch_dse_archive_prices(
            config.dse_archive_url,
            archive_start_date(archive_end_date, config.dse_archive_lookback_days),
            archive_end_date,
            config.dse_symbols,
        )
        print(
            "Skipped DSE latest page and used archive-only data "
            f"through {archive_end_date}: {len(archive_prices)} rows"
        )
        if config.merge_dse_with_csv:
            return merge_price_data(csv_prices, archive_prices)
        return archive_prices

    latest_prices = None
    latest_error = None
    try:
        latest_prices = fetch_latest_dse_prices(
            config.dse_latest_url,
            config.dse_market_status_url,
            config.dse_symbols,
        )
    except Exception as exc:
        latest_error = exc
        if config.data_source != "dse":
            print(f"DSE latest page fetch failed, trying archive-only fallback: {exc}")

    archive_end_date = (
        max(latest_prices["date"])
        if latest_prices is not None and not latest_prices.empty
        else datetime.now(ZoneInfo("Asia/Dhaka")).date()
    )

    try:
        archive_prices = fetch_dse_archive_prices(
            config.dse_archive_url,
            archive_start_date(archive_end_date, config.dse_archive_lookback_days),
            archive_end_date,
            config.dse_symbols,
        )
        if latest_prices is not None and not latest_prices.empty:
            dse_prices = merge_price_data(archive_prices, latest_prices)
            print(f"Fetched {len(archive_prices)} DSE archive rows and {len(latest_prices)} latest rows")
        else:
            dse_prices = archive_prices
            print(
                "Latest DSE page unavailable, using archive-only data "
                f"through {archive_end_date}: {latest_error}"
            )
        if config.merge_dse_with_csv:
            return merge_price_data(csv_prices, dse_prices)
        return dse_prices
    except Exception as exc:
        if config.data_source == "dse" and latest_prices is None:
            raise RuntimeError(
                "DSE latest page and archive page both failed. "
                f"Latest error: {latest_error}. Archive error: {exc}"
            ) from exc
        if config.data_source == "dse" and latest_prices is not None and not latest_prices.empty:
            print(f"DSE archive fetch failed, using latest page only: {exc}")
            if config.merge_dse_with_csv:
                return merge_price_data(csv_prices, latest_prices)
            return latest_prices
        print(f"DSE archive fetch failed, trying latest page only: {exc}")
        if latest_prices is not None and not latest_prices.empty:
            if config.merge_dse_with_csv:
                print(f"Fetched {len(latest_prices)} DSE rows and merged with CSV history")
                return merge_price_data(csv_prices, latest_prices)
            print(f"Fetched {len(latest_prices)} DSE rows")
            return latest_prices

    if config.data_source == "dse":
        raise RuntimeError(f"DSE fetch failed and CSV fallback is disabled. Latest error: {latest_error}")
    print(f"DSE fetch failed, using CSV fallback: {latest_error}")
    return csv_prices


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Engine failed: {exc}", file=sys.stderr)
        raise
