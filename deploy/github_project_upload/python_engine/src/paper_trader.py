from __future__ import annotations

import hashlib
from dataclasses import dataclass
from typing import Any

import pandas as pd


@dataclass
class TradingRules:
    initial_balance: float = 100000.0
    max_position_pct: float = 0.10
    max_open_positions: int = 5
    transaction_cost_pct: float = 0.005
    stop_loss_pct: float = -0.05
    take_profit_pct: float = 0.08


@dataclass
class Position:
    symbol: str
    quantity: int
    avg_price: float
    entry_date: Any
    cost_basis: float


def _trade_id(run_id: str, symbol: str, side: str, date_value: Any, reason: str) -> str:
    raw = f"trade|{run_id}|{symbol}|{side}|{date_value}|{reason}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def simulate_paper_trades(
    price_df: pd.DataFrame,
    signals_df: pd.DataFrame,
    run_id: str,
    rules: TradingRules,
) -> dict[str, Any]:
    cash = float(rules.initial_balance)
    realized_pl = 0.0
    positions: dict[str, Position] = {}
    trades: list[dict[str, Any]] = []
    snapshots: list[dict[str, Any]] = []

    prices_by_date = {
        date_value: group.set_index("symbol")
        for date_value, group in price_df.sort_values(["date", "symbol"]).groupby("date")
    }
    signals_by_date = {
        date_value: group.sort_values("confidence", ascending=False)
        for date_value, group in signals_df.groupby("date")
    }

    for date_value in sorted(prices_by_date):
        day_prices = prices_by_date[date_value]
        day_signals = signals_by_date.get(date_value, pd.DataFrame())

        for symbol in list(positions):
            if symbol not in day_prices.index:
                continue
            close_price = float(day_prices.loc[symbol]["close"])
            signal_type = _signal_for_symbol(day_signals, symbol)
            position = positions[symbol]
            pnl_pct = (close_price - position.avg_price) / position.avg_price
            exit_reason = None
            if pnl_pct <= rules.stop_loss_pct:
                exit_reason = "STOP_LOSS"
            elif pnl_pct >= rules.take_profit_pct:
                exit_reason = "TAKE_PROFIT"
            elif signal_type == "SELL":
                exit_reason = "SELL_SIGNAL"
            if exit_reason:
                cash, realized = _sell_position(
                    trades, run_id, date_value, position, close_price, cash, rules, exit_reason
                )
                realized_pl += realized
                del positions[symbol]

        if not day_signals.empty:
            buy_signals = day_signals[day_signals["signal_type"] == "BUY"]
            for _, signal in buy_signals.iterrows():
                symbol = str(signal["symbol"])
                if symbol in positions:
                    continue
                if len(positions) >= rules.max_open_positions:
                    continue
                if symbol not in day_prices.index:
                    continue
                price = float(day_prices.loc[symbol]["close"])
                equity = _portfolio_value(cash, positions, day_prices)
                max_notional = equity * rules.max_position_pct
                available = min(cash, max_notional)
                quantity = int(available / (price * (1 + rules.transaction_cost_pct)))
                if quantity <= 0:
                    continue
                gross = quantity * price
                fee = gross * rules.transaction_cost_pct
                total_cost = gross + fee
                cash -= total_cost
                positions[symbol] = Position(symbol, quantity, price, date_value, total_cost)
                trades.append(
                    {
                        "trade_id": _trade_id(run_id, symbol, "BUY", date_value, "BUY_SIGNAL"),
                        "run_id": run_id,
                        "symbol": symbol,
                        "trade_date": date_value,
                        "side": "BUY",
                        "quantity": quantity,
                        "price": round(price, 4),
                        "gross_value": round(gross, 4),
                        "transaction_cost": round(fee, 4),
                        "net_value": round(total_cost, 4),
                        "realized_pl": 0.0,
                        "reason": "BUY_SIGNAL",
                    }
                )

        portfolio_value = _portfolio_value(cash, positions, day_prices)
        unrealized_pl = _unrealized_pl(positions, day_prices)
        snapshots.append(
            {
                "snapshot_id": hashlib.sha256(f"snapshot|{run_id}|{date_value}".encode("utf-8")).hexdigest(),
                "run_id": run_id,
                "snapshot_date": date_value,
                "cash_balance": round(cash, 4),
                "positions_value": round(portfolio_value - cash, 4),
                "portfolio_value": round(portfolio_value, 4),
                "realized_pl": round(realized_pl, 4),
                "unrealized_pl": round(unrealized_pl, 4),
                "open_positions": len(positions),
            }
        )

    final_prices = prices_by_date[max(prices_by_date)] if prices_by_date else pd.DataFrame()
    open_positions = []
    for position in positions.values():
        current_price = float(final_prices.loc[position.symbol]["close"]) if position.symbol in final_prices.index else position.avg_price
        market_value = position.quantity * current_price
        open_positions.append(
            {
                "run_id": run_id,
                "symbol": position.symbol,
                "quantity": position.quantity,
                "avg_price": round(position.avg_price, 4),
                "current_price": round(current_price, 4),
                "market_value": round(market_value, 4),
                "cost_basis": round(position.cost_basis, 4),
                "unrealized_pl": round(market_value - position.cost_basis, 4),
                "entry_date": position.entry_date,
                "status": "OPEN",
            }
        )

    final_value = snapshots[-1]["portfolio_value"] if snapshots else rules.initial_balance
    return {
        "trades": trades,
        "positions": open_positions,
        "snapshots": snapshots,
        "summary": {
            "run_id": run_id,
            "initial_balance": round(rules.initial_balance, 4),
            "ending_value": round(final_value, 4),
            "cash_balance": round(cash, 4),
            "realized_pl": round(realized_pl, 4),
            "unrealized_pl": round(_unrealized_pl(positions, final_prices), 4) if not final_prices.empty else 0.0,
            "total_pl": round(final_value - rules.initial_balance, 4),
            "total_return_pct": round((final_value - rules.initial_balance) / rules.initial_balance, 6),
            "open_positions": len(open_positions),
            "trade_count": len(trades),
        },
    }


def _signal_for_symbol(day_signals: pd.DataFrame, symbol: str) -> str | None:
    if day_signals.empty:
        return None
    rows = day_signals[day_signals["symbol"] == symbol]
    if rows.empty:
        return None
    return str(rows.iloc[0]["signal_type"])


def _sell_position(
    trades: list[dict[str, Any]],
    run_id: str,
    date_value: Any,
    position: Position,
    price: float,
    cash: float,
    rules: TradingRules,
    reason: str,
) -> tuple[float, float]:
    gross = position.quantity * price
    fee = gross * rules.transaction_cost_pct
    net = gross - fee
    realized = net - position.cost_basis
    cash += net
    trades.append(
        {
            "trade_id": _trade_id(run_id, position.symbol, "SELL", date_value, reason),
            "run_id": run_id,
            "symbol": position.symbol,
            "trade_date": date_value,
            "side": "SELL",
            "quantity": position.quantity,
            "price": round(price, 4),
            "gross_value": round(gross, 4),
            "transaction_cost": round(fee, 4),
            "net_value": round(net, 4),
            "realized_pl": round(realized, 4),
            "reason": reason,
        }
    )
    return cash, realized


def _portfolio_value(cash: float, positions: dict[str, Position], day_prices: pd.DataFrame) -> float:
    value = cash
    for position in positions.values():
        if position.symbol in day_prices.index:
            value += position.quantity * float(day_prices.loc[position.symbol]["close"])
        else:
            value += position.quantity * position.avg_price
    return value


def _unrealized_pl(positions: dict[str, Position], day_prices: pd.DataFrame) -> float:
    value = 0.0
    if day_prices.empty:
        return value
    for position in positions.values():
        if position.symbol not in day_prices.index:
            continue
        current = position.quantity * float(day_prices.loc[position.symbol]["close"])
        value += current - position.cost_basis
    return value
