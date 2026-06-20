from __future__ import annotations

import json
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from src.api_client import HostingerApiClient, send_telegram_summary
from src.config import ENGINE_ROOT, EngineConfig
from src.data_loader import load_price_csv
from src.evaluator import evaluate_signals
from src.indicators import add_indicators
from src.paper_trader import TradingRules, simulate_paper_trades
from src.payload import build_payload
from src.signals import generate_signals


def main() -> int:
    started_at = datetime.now(timezone.utc)
    run_id = f"run-{started_at.strftime('%Y%m%dT%H%M%SZ')}-{uuid.uuid4().hex[:8]}"
    config = EngineConfig.from_env()

    prices = load_price_csv(config.csv_path)
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
    payload = build_payload(run_id, prices, indicators, signals, trading, evaluations, started_at)

    if config.api_enabled:
        result = HostingerApiClient(
            config.api_base_url,
            config.api_token,
            config.hmac_secret,
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


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Engine failed: {exc}", file=sys.stderr)
        raise
