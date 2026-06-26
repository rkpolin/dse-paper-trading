<?php
declare(strict_types=1);

const DASHBOARD_INITIAL_BALANCE = 100000.0;
const DASHBOARD_TRANSACTION_COST_PCT = 0.005;

function fetch_trade_rows_for_dashboard(PDO $pdo, ?string $dailyRunId): array
{
    $params = [];
    $where = ["t.source = 'MANUAL'"];
    if ($dailyRunId !== null && $dailyRunId !== '') {
        $where[] = '(t.run_id = :daily_run_id AND t.source <> \'MANUAL\')';
        $params['daily_run_id'] = $dailyRunId;
    }

    return fetch_all(
        $pdo,
        'SELECT t.trade_id, t.run_id, t.stock_id, st.symbol, t.trade_date, t.side, t.quantity, t.price,
                t.gross_value, t.transaction_cost, t.net_value, t.realized_pl, t.reason, t.source,
                t.entry_trade_id, t.created_at
         FROM paper_trades t
         JOIN stocks st ON st.id = t.stock_id
         WHERE ' . implode(' OR ', $where) . '
         ORDER BY t.trade_date ASC, t.created_at ASC, t.trade_id ASC',
        $params
    );
}

function build_trade_ledger(array $tradeRows): array
{
    $openLots = [];
    $closedTrades = [];
    $cash = DASHBOARD_INITIAL_BALANCE;

    foreach ($tradeRows as $row) {
        $row['quantity'] = (int)$row['quantity'];
        $row['price'] = (float)$row['price'];
        $row['gross_value'] = (float)$row['gross_value'];
        $row['transaction_cost'] = (float)$row['transaction_cost'];
        $row['net_value'] = (float)$row['net_value'];
        $row['realized_pl'] = (float)$row['realized_pl'];
        $row['source'] = (string)($row['source'] ?? 'SYSTEM');
        $row['entry_trade_id'] = $row['entry_trade_id'] ?? null;
        $row['symbol'] = (string)$row['symbol'];
        $row['trade_date'] = (string)$row['trade_date'];
        $row['reason'] = (string)($row['reason'] ?? '');

        if ($row['side'] === 'BUY') {
            $cash -= $row['net_value'];
            $openLots[] = [
                'trade_id' => (string)$row['trade_id'],
                'stock_id' => (int)$row['stock_id'],
                'symbol' => $row['symbol'],
                'buy_date' => $row['trade_date'],
                'buy_price' => $row['price'],
                'quantity' => $row['quantity'],
                'remaining_quantity' => $row['quantity'],
                'remaining_cost_basis' => $row['net_value'],
                'source' => $row['source'],
                'buy_reason' => $row['reason'],
            ];
            continue;
        }

        $cash += $row['net_value'];
        $quantityToMatch = $row['quantity'];
        $candidateIndexes = trade_candidate_indexes($openLots, $row);

        foreach ($candidateIndexes as $index) {
            if ($quantityToMatch <= 0) {
                break;
            }
            if (!isset($openLots[$index]) || $openLots[$index]['remaining_quantity'] <= 0) {
                continue;
            }

            $buyLot = $openLots[$index];
            $matchedQuantity = min($quantityToMatch, (int)$buyLot['remaining_quantity']);
            $costShare = ($buyLot['remaining_cost_basis'] / max(1, (int)$buyLot['remaining_quantity'])) * $matchedQuantity;
            $sellNetShare = ($row['net_value'] / max(1, $row['quantity'])) * $matchedQuantity;
            $realizedPl = $sellNetShare - $costShare;

            $closedTrades[] = [
                'buy_trade_id' => (string)$buyLot['trade_id'],
                'sell_trade_id' => (string)$row['trade_id'],
                'stock_id' => (int)$row['stock_id'],
                'symbol' => $row['symbol'],
                'buy_date' => (string)$buyLot['buy_date'],
                'buy_price' => (float)$buyLot['buy_price'],
                'sell_date' => $row['trade_date'],
                'sell_price' => $row['price'],
                'quantity' => $matchedQuantity,
                'realized_pl' => round($realizedPl, 4),
                'holding_days' => dashboard_date_diff_days((string)$buyLot['buy_date'], $row['trade_date']),
                'sell_reason' => $row['reason'],
                'buy_source' => (string)$buyLot['source'],
                'sell_source' => $row['source'],
                'source' => $row['source'] === 'MANUAL' || $buyLot['source'] === 'MANUAL' ? 'MANUAL' : 'SYSTEM',
            ];

            $openLots[$index]['remaining_quantity'] -= $matchedQuantity;
            $openLots[$index]['remaining_cost_basis'] = max(0.0, $openLots[$index]['remaining_cost_basis'] - $costShare);
            $quantityToMatch -= $matchedQuantity;
        }

        $openLots = array_values(array_filter(
            $openLots,
            static fn(array $lot): bool => (int)$lot['remaining_quantity'] > 0
        ));
    }

    return [
        'cash_balance' => round($cash, 4),
        'closed_trades' => $closedTrades,
        'open_lots' => $openLots,
    ];
}

function trade_candidate_indexes(array $openLots, array $sellTrade): array
{
    $preferred = [];
    $fallback = [];
    $entryTradeId = $sellTrade['entry_trade_id'];
    $symbol = (string)$sellTrade['symbol'];

    foreach ($openLots as $index => $lot) {
        if ((string)$lot['symbol'] !== $symbol || (int)$lot['remaining_quantity'] <= 0) {
            continue;
        }
        if ($entryTradeId !== null && $entryTradeId !== '' && (string)$lot['trade_id'] === (string)$entryTradeId) {
            $preferred[] = $index;
        } else {
            $fallback[] = $index;
        }
    }

    return array_merge($preferred, $fallback);
}

function summarize_closed_trades(array $closedTrades): array
{
    $winningTrades = 0;
    $losingTrades = 0;
    foreach ($closedTrades as $trade) {
        if ((float)$trade['realized_pl'] > 0) {
            $winningTrades++;
        } elseif ((float)$trade['realized_pl'] < 0) {
            $losingTrades++;
        }
    }
    $closedCount = $winningTrades + $losingTrades;
    return [
        'closed_count' => $closedCount,
        'winning_count' => $winningTrades,
        'losing_count' => $losingTrades,
        'win_rate' => $closedCount > 0 ? round($winningTrades / $closedCount, 6) : null,
    ];
}

function fetch_latest_close_prices(PDO $pdo): array
{
    $rows = fetch_all(
        $pdo,
        'SELECT dp.stock_id, st.symbol, dp.trade_date, dp.close_price
         FROM daily_prices dp
         JOIN stocks st ON st.id = dp.stock_id
         JOIN (
            SELECT stock_id, MAX(trade_date) AS latest_trade_date
            FROM daily_prices
            GROUP BY stock_id
         ) latest
           ON latest.stock_id = dp.stock_id
          AND latest.latest_trade_date = dp.trade_date'
    );

    $prices = [];
    foreach ($rows as $row) {
        $prices[(int)$row['stock_id']] = [
            'symbol' => (string)$row['symbol'],
            'trade_date' => (string)$row['trade_date'],
            'close_price' => (float)$row['close_price'],
        ];
    }
    return $prices;
}

function aggregate_open_positions(array $openLots, array $latestPrices): array
{
    $grouped = [];
    foreach ($openLots as $lot) {
        $stockId = (int)$lot['stock_id'];
        if (!isset($grouped[$stockId])) {
            $grouped[$stockId] = [
                'stock_id' => $stockId,
                'symbol' => (string)$lot['symbol'],
                'quantity' => 0,
                'cost_basis' => 0.0,
                'entry_date' => (string)$lot['buy_date'],
                'source' => (string)$lot['source'],
            ];
        }
        $grouped[$stockId]['quantity'] += (int)$lot['remaining_quantity'];
        $grouped[$stockId]['cost_basis'] += (float)$lot['remaining_cost_basis'];
        if ((string)$lot['buy_date'] < $grouped[$stockId]['entry_date']) {
            $grouped[$stockId]['entry_date'] = (string)$lot['buy_date'];
        }
        if ((string)$lot['source'] === 'MANUAL') {
            $grouped[$stockId]['source'] = 'MANUAL';
        }
    }

    $positions = [];
    foreach ($grouped as $stockId => $position) {
        $currentPrice = isset($latestPrices[$stockId]) ? (float)$latestPrices[$stockId]['close_price'] : 0.0;
        $marketValue = $currentPrice * $position['quantity'];
        $avgPrice = $position['quantity'] > 0 ? $position['cost_basis'] / $position['quantity'] : 0.0;
        $positions[] = [
            'stock_id' => $stockId,
            'symbol' => $position['symbol'],
            'quantity' => $position['quantity'],
            'avg_price' => round($avgPrice, 4),
            'current_price' => round($currentPrice, 4),
            'market_value' => round($marketValue, 4),
            'cost_basis' => round($position['cost_basis'], 4),
            'unrealized_pl' => round($marketValue - $position['cost_basis'], 4),
            'entry_date' => $position['entry_date'],
            'source' => $position['source'],
            'price_date' => $latestPrices[$stockId]['trade_date'] ?? null,
        ];
    }

    usort(
        $positions,
        static fn(array $left, array $right): int => $right['market_value'] <=> $left['market_value']
    );
    return $positions;
}

function portfolio_totals_from_ledger(array $ledger, array $positions): array
{
    $positionsValue = 0.0;
    $unrealizedPl = 0.0;
    $realizedPl = 0.0;

    foreach ($positions as $position) {
        $positionsValue += (float)$position['market_value'];
        $unrealizedPl += (float)$position['unrealized_pl'];
    }
    foreach ($ledger['closed_trades'] as $trade) {
        $realizedPl += (float)$trade['realized_pl'];
    }

    return [
        'cash_balance' => round((float)$ledger['cash_balance'], 4),
        'positions_value' => round($positionsValue, 4),
        'portfolio_value' => round((float)$ledger['cash_balance'] + $positionsValue, 4),
        'realized_pl' => round($realizedPl, 4),
        'unrealized_pl' => round($unrealizedPl, 4),
        'open_positions' => count($positions),
    ];
}
