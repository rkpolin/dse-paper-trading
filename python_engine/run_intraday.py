from __future__ import annotations

import json
import sys
import uuid
from datetime import datetime, timezone

import pandas as pd

from src.api_client import HostingerApiClient
from src.config import ENGINE_ROOT, EngineConfig
from src.intraday_analyzer import calculate_daily_extremes, calculate_time_window_stats
from src.intraday_collector import (
    collect_dse_intraday_snapshots,
    empty_intraday_frame,
    load_intraday_csv,
    now_bd,
    should_collect_now,
)
from src.intraday_payload import build_extremes_payload, build_snapshots_payload, build_stats_payload


def main() -> int:
    started_at = datetime.now(timezone.utc)
    run_id = f"intraday-{started_at.strftime('%Y%m%dT%H%M%SZ')}-{uuid.uuid4().hex[:8]}"
    config = EngineConfig.from_env()
    local_dt = now_bd()

    should_collect, bucket_time, reason = should_collect_now(
        local_dt,
        config.dse_holidays,
        config.intraday_bucket_tolerance_minutes,
        config.intraday_force_run,
    )
    if not should_collect or bucket_time is None:
        print(f"Intraday skipped: {reason} ({local_dt.isoformat()})")
        return 0

    current_snapshots = collect_dse_intraday_snapshots(
        config.dse_latest_url,
        config.dse_market_status_url,
        bucket_time,
        local_dt,
        config.dse_symbols,
    )
    if current_snapshots.empty:
        print("Intraday skipped: DSE returned no snapshot rows")
        return 0

    history = load_intraday_csv(config.intraday_history_csv_path)
    all_snapshots = combine_snapshots(history, current_snapshots)
    extremes = calculate_daily_extremes(all_snapshots)
    stats = calculate_time_window_stats(
        all_snapshots,
        extremes,
        config.intraday_stat_lookbacks,
        as_of_date=max(current_snapshots["trade_date"]),
    )

    current_dates = set(current_snapshots["trade_date"])
    current_extremes = extremes[
        extremes["trade_date"].isin(current_dates) & (extremes["snapshot_count"].astype(int) > 1)
    ].copy()
    snapshots_payload = build_snapshots_payload(run_id, current_snapshots, started_at)
    extremes_payload = build_extremes_payload(run_id, current_extremes, started_at)
    stats_payload = build_stats_payload(run_id, stats, started_at)

    print(
        "Intraday rows: "
        f"snapshots={len(snapshots_payload['intraday_snapshots'])}, "
        f"daily_extremes={len(extremes_payload['daily_intraday_extremes'])}, "
        f"stats={len(stats_payload['intraday_time_window_stats'])}, "
        f"bucket={bucket_time}"
    )

    if config.api_enabled:
        client = HostingerApiClient(
            config.api_base_url,
            config.api_token,
            config.hmac_secret,
            timeout=config.api_timeout_seconds,
        )
        print(f"Hostinger API timeout seconds: {config.api_timeout_seconds}")
        print(json.dumps({"snapshots": client.post_endpoint("save_intraday_snapshots.php", snapshots_payload)}, indent=2))
        if extremes_payload["daily_intraday_extremes"]:
            print(json.dumps({"extremes": client.post_endpoint("save_intraday_extremes.php", extremes_payload)}, indent=2))
        if stats_payload["intraday_time_window_stats"] and _should_post_stats(bucket_time, config.intraday_force_run):
            print(json.dumps({"stats": client.post_endpoint("save_intraday_stats.php", stats_payload)}, indent=2))
        elif stats_payload["intraday_time_window_stats"]:
            print("Intraday stats calculated but only posted at 14:05 or when INTRADAY_FORCE_RUN=true")
    else:
        write_local_payloads(snapshots_payload, extremes_payload, stats_payload)

    return 0


def combine_snapshots(history: pd.DataFrame, current: pd.DataFrame) -> pd.DataFrame:
    if history.empty:
        combined = current.copy()
    elif current.empty:
        combined = history.copy()
    else:
        combined = pd.concat([history, current], ignore_index=True)
    if combined.empty:
        return empty_intraday_frame()
    combined = combined.drop_duplicates(subset=["symbol", "trade_date", "bucket_time"], keep="last")
    return combined.sort_values(["trade_date", "symbol", "bucket_time"]).reset_index(drop=True)


def write_local_payloads(*payloads: dict) -> None:
    output_dir = ENGINE_ROOT / "output" / "intraday"
    output_dir.mkdir(parents=True, exist_ok=True)
    names = ["latest_snapshots_payload.json", "latest_extremes_payload.json", "latest_stats_payload.json"]
    for name, payload in zip(names, payloads):
        output_file = output_dir / name
        output_file.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
        print(f"API credentials not configured. Wrote {output_file}")


def _should_post_stats(bucket_time: str, force: bool) -> bool:
    return force or bucket_time == "14:05"


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Intraday engine failed: {exc}", file=sys.stderr)
        raise
