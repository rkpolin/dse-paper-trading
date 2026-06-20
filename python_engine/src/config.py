from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


ENGINE_ROOT = Path(__file__).resolve().parents[1]
PROJECT_ROOT = ENGINE_ROOT.parent


def load_env_file(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


def _env_float(name: str, default: float) -> float:
    value = os.getenv(name)
    return default if value in (None, "") else float(value)


def _env_int(name: str, default: int) -> int:
    value = os.getenv(name)
    return default if value in (None, "") else int(value)


def _env_str(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _resolve_path(value: str) -> Path:
    path = Path(value)
    if path.is_absolute():
        return path
    return PROJECT_ROOT / path


@dataclass(frozen=True)
class EngineConfig:
    csv_path: Path
    initial_balance: float
    max_position_pct: float
    max_open_positions: int
    transaction_cost_pct: float
    stop_loss_pct: float
    take_profit_pct: float
    evaluation_days: int
    api_base_url: str
    api_token: str
    hmac_secret: str
    telegram_bot_token: str
    telegram_chat_id: str

    @classmethod
    def from_env(cls) -> "EngineConfig":
        env_file = os.getenv("ENV_FILE")
        if env_file:
            load_env_file(Path(env_file))
        else:
            load_env_file(ENGINE_ROOT / ".env")

        csv_default = "python_engine/sample_data/dse_demo_prices.csv"
        return cls(
            csv_path=_resolve_path(_env_str("CSV_PATH", csv_default)),
            initial_balance=_env_float("INITIAL_BALANCE_BDT", 100000.0),
            max_position_pct=_env_float("MAX_POSITION_PCT", 0.10),
            max_open_positions=_env_int("MAX_OPEN_POSITIONS", 5),
            transaction_cost_pct=_env_float("TRANSACTION_COST_PCT", 0.005),
            stop_loss_pct=_env_float("STOP_LOSS_PCT", -0.05),
            take_profit_pct=_env_float("TAKE_PROFIT_PCT", 0.08),
            evaluation_days=_env_int("EVALUATION_DAYS", 5),
            api_base_url=_env_str("HOSTINGER_API_BASE_URL"),
            api_token=_env_str("HOSTINGER_API_TOKEN"),
            hmac_secret=_env_str("HOSTINGER_HMAC_SECRET"),
            telegram_bot_token=_env_str("TELEGRAM_BOT_TOKEN"),
            telegram_chat_id=_env_str("TELEGRAM_CHAT_ID"),
        )

    @property
    def api_enabled(self) -> bool:
        return bool(self.api_base_url and self.api_token and self.hmac_secret)

    @property
    def telegram_enabled(self) -> bool:
        return bool(self.telegram_bot_token and self.telegram_chat_id)
