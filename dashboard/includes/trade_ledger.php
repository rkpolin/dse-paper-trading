<?php
declare(strict_types=1);

const DASHBOARD_INITIAL_BALANCE = 100000.0;
const DASHBOARD_TRANSACTION_COST_PCT = 0.005;
const DASHBOARD_MANUAL_RUN_ID = 'dashboard-manual-trades';

if (!function_exists('dashboard_manual_upsert_run')) {
    function dashboard_manual_upsert_run(PDO $pdo, string $latestDataDate): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO system_runs (run_id, started_at, completed_at, status, source, latest_data_date)
             VALUES (:run_id, :started_at, :completed_at, 'SUCCESS', 'dashboard_manual', :latest_data_date)
             ON DUPLICATE KEY UPDATE
                completed_at = VALUES(completed_at),
                latest_data_date = VALUES(latest_data_date),
                updated_at = CURRENT_TIMESTAMP"
        );
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
        $stmt->execute([
            'run_id' => DASHBOARD_MANUAL_RUN_ID,
            'started_at' => $now,
            'completed_at' => $now,
            'latest_data_date' => $latestDataDate,
        ]);
    }
}

if (!function_exists('dashboard_insert_manual_trade')) {
    function dashboard_insert_manual_trade(PDO $pdo, array $trade): void
    {
        $optionalColumns = [];
        foreach (['source', 'entry_trade_id', 'reason'] as $column) {
            if (dashboard_table_column_exists($pdo, 'paper_trades', $column)) {
                $optionalColumns[] = $column;
            }
        }

        $columns = [
            'trade_id',
            'run_id',
            'stock_id',
            'trade_date',
            'side',
            'quantity',
            'price',
            'gross_value',
            'transaction_cost',
            'net_value',
            'realized_pl',
            ...$optionalColumns,
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO paper_trades (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')'
        );
        $stmt->execute(dashboard_sql_values($trade, $columns));
    }
}

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

function fetch_stocks_with_latest_prices(PDO $pdo): array
{
    return fetch_all(
        $pdo,
        'SELECT st.id, st.symbol, st.name, dp.trade_date AS latest_trade_date, dp.close_price
         FROM stocks st
         LEFT JOIN (
            SELECT dp1.stock_id, dp1.trade_date, dp1.close_price
            FROM daily_prices dp1
            JOIN (
                SELECT stock_id, MAX(trade_date) AS latest_trade_date
                FROM daily_prices
                GROUP BY stock_id
            ) latest
              ON latest.stock_id = dp1.stock_id
             AND latest.latest_trade_date = dp1.trade_date
         ) dp ON dp.stock_id = st.id
         ORDER BY st.symbol ASC'
    );
}

function manual_order_table_exists(PDO $pdo): bool
{
    return dashboard_table_exists($pdo, 'manual_trade_orders');
}

function process_pending_manual_orders(PDO $pdo, ?string $dailyRunId): array
{
    if (!manual_order_table_exists($pdo)) {
        return ['executed' => 0, 'cancelled' => 0];
    }

    $orders = fetch_all(
        $pdo,
        "SELECT order_id, run_id, stock_id, side, requested_date, quantity, target_price, current_reference_price,
                entry_trade_id, reason, status, executed_trade_id, executed_date, last_checked_date, note, created_at
         FROM manual_trade_orders
         WHERE status = 'PENDING'
         ORDER BY requested_date ASC, created_at ASC"
    );

    if (!$orders) {
        return ['executed' => 0, 'cancelled' => 0];
    }

    $tradeRows = fetch_trade_rows_for_dashboard($pdo, $dailyRunId);
    $executed = 0;
    $cancelled = 0;

    foreach ($orders as $order) {
        $ledger = build_trade_ledger($tradeRows);
        $priceRows = fetch_all(
            $pdo,
            'SELECT trade_date, open_price, high_price, low_price, close_price
             FROM daily_prices
             WHERE stock_id = :stock_id AND trade_date >= :requested_date
             ORDER BY trade_date ASC',
            [
                'stock_id' => $order['stock_id'],
                'requested_date' => $order['requested_date'],
            ]
        );

        if (!$priceRows) {
            continue;
        }

        $matchRow = null;
        foreach ($priceRows as $priceRow) {
            $targetPrice = (float)$order['target_price'];
            if ($order['side'] === 'BUY' && (float)$priceRow['low_price'] <= $targetPrice) {
                $matchRow = $priceRow;
                break;
            }
            if ($order['side'] === 'SELL' && (float)$priceRow['high_price'] >= $targetPrice) {
                $matchRow = $priceRow;
                break;
            }
        }

        $lastCheckedDate = (string)$priceRows[count($priceRows) - 1]['trade_date'];
        if ($matchRow === null) {
            update_manual_order_progress($pdo, (string)$order['order_id'], $lastCheckedDate, null);
            continue;
        }

        if ($order['side'] === 'SELL') {
            $selectedLot = null;
            foreach ($ledger['open_lots'] as $lot) {
                if ((string)$lot['trade_id'] === (string)$order['entry_trade_id']) {
                    $selectedLot = $lot;
                    break;
                }
            }
            if ($selectedLot === null || (int)$selectedLot['remaining_quantity'] < (int)$order['quantity']) {
                cancel_manual_order($pdo, (string)$order['order_id'], 'Open lot is no longer available for this sell order.');
                $cancelled++;
                continue;
            }
        }

        try {
            $pdo->beginTransaction();
            dashboard_manual_upsert_run($pdo, (string)$matchRow['trade_date']);
            $tradeId = execute_manual_order_trade($pdo, $order, $matchRow, $ledger);
            mark_manual_order_executed($pdo, (string)$order['order_id'], $tradeId, (string)$matchRow['trade_date']);
            $pdo->commit();
            $executed++;

            $tradeRows = fetch_trade_rows_for_dashboard($pdo, $dailyRunId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            update_manual_order_progress(
                $pdo,
                (string)$order['order_id'],
                (string)$lastCheckedDate,
                manual_order_failure_note($exception)
            );
        }
    }

    return ['executed' => $executed, 'cancelled' => $cancelled];
}

function execute_manual_order_trade(PDO $pdo, array $order, array $priceRow, array $ledger): string
{
    $tradeId = hash('sha256', 'manual-order|' . $order['order_id'] . '|' . $priceRow['trade_date'] . '|' . bin2hex(random_bytes(8)));
    $quantity = (int)$order['quantity'];
    $price = round((float)$order['target_price'], 4);
    $grossValue = round($quantity * $price, 4);
    $fee = round($grossValue * DASHBOARD_TRANSACTION_COST_PCT, 4);

    if ($order['side'] === 'BUY') {
        dashboard_insert_manual_trade($pdo, [
            'trade_id' => $tradeId,
            'run_id' => DASHBOARD_MANUAL_RUN_ID,
            'stock_id' => (int)$order['stock_id'],
            'trade_date' => (string)$priceRow['trade_date'],
            'side' => 'BUY',
            'quantity' => $quantity,
            'price' => $price,
            'gross_value' => $grossValue,
            'transaction_cost' => $fee,
            'net_value' => round($grossValue + $fee, 4),
            'realized_pl' => 0.0,
            'source' => 'MANUAL',
            'entry_trade_id' => null,
            'reason' => (string)$order['reason'],
        ]);
        return $tradeId;
    }

    $selectedLot = null;
    foreach ($ledger['open_lots'] as $lot) {
        if ((string)$lot['trade_id'] === (string)$order['entry_trade_id']) {
            $selectedLot = $lot;
            break;
        }
    }
    if ($selectedLot === null) {
        throw new RuntimeException('Open lot not found for sell order.');
    }

    $costBasisShare = ((float)$selectedLot['remaining_cost_basis'] / max(1, (int)$selectedLot['remaining_quantity'])) * $quantity;
    $netValue = round($grossValue - $fee, 4);
    dashboard_insert_manual_trade($pdo, [
        'trade_id' => $tradeId,
        'run_id' => DASHBOARD_MANUAL_RUN_ID,
        'stock_id' => (int)$order['stock_id'],
        'trade_date' => (string)$priceRow['trade_date'],
        'side' => 'SELL',
        'quantity' => $quantity,
        'price' => $price,
        'gross_value' => $grossValue,
        'transaction_cost' => $fee,
        'net_value' => $netValue,
        'realized_pl' => round($netValue - $costBasisShare, 4),
        'source' => 'MANUAL',
        'entry_trade_id' => (string)$order['entry_trade_id'],
        'reason' => (string)$order['reason'],
    ]);
    return $tradeId;
}

function update_manual_order_progress(PDO $pdo, string $orderId, string $lastCheckedDate, ?string $note): void
{
    $stmt = $pdo->prepare(
        'UPDATE manual_trade_orders
         SET last_checked_date = :last_checked_date,
             note = COALESCE(:note, note),
             updated_at = CURRENT_TIMESTAMP
         WHERE order_id = :order_id AND status = \'PENDING\''
    );
    $stmt->execute([
        'order_id' => $orderId,
        'last_checked_date' => $lastCheckedDate,
        'note' => $note,
    ]);
}

function mark_manual_order_executed(PDO $pdo, string $orderId, string $tradeId, string $executedDate): void
{
    $stmt = $pdo->prepare(
        "UPDATE manual_trade_orders
         SET status = 'EXECUTED',
             executed_trade_id = :executed_trade_id,
             executed_date = :executed_date,
             last_checked_date = :last_checked_date,
             note = 'Target price hit and paper trade was created.',
             updated_at = CURRENT_TIMESTAMP
         WHERE order_id = :order_id"
    );
    $stmt->execute([
        'order_id' => $orderId,
        'executed_trade_id' => $tradeId,
        'executed_date' => $executedDate,
        'last_checked_date' => $executedDate,
    ]);
}

function cancel_manual_order(PDO $pdo, string $orderId, string $note): void
{
    $stmt = $pdo->prepare(
        "UPDATE manual_trade_orders
         SET status = 'CANCELLED',
             note = :note,
             updated_at = CURRENT_TIMESTAMP
         WHERE order_id = :order_id"
    );
    $stmt->execute([
        'order_id' => $orderId,
        'note' => $note,
    ]);
}

function fetch_manual_orders(PDO $pdo): array
{
    if (!manual_order_table_exists($pdo)) {
        return [];
    }

    return fetch_all(
        $pdo,
        'SELECT o.order_id, st.symbol, st.name, o.side, o.requested_date, o.quantity, o.target_price,
                o.current_reference_price, latest.close_price AS latest_close_price,
                latest.trade_date AS latest_trade_date, o.reason, o.status, o.executed_date, o.last_checked_date,
                o.note, o.entry_trade_id, o.executed_trade_id, o.created_at
         FROM manual_trade_orders o
         JOIN stocks st ON st.id = o.stock_id
         LEFT JOIN (
            SELECT dp1.stock_id, dp1.trade_date, dp1.close_price
            FROM daily_prices dp1
            JOIN (
                SELECT stock_id, MAX(trade_date) AS latest_trade_date
                FROM daily_prices
                GROUP BY stock_id
            ) latest_dates
              ON latest_dates.stock_id = dp1.stock_id
             AND latest_dates.latest_trade_date = dp1.trade_date
         ) latest ON latest.stock_id = o.stock_id
         ORDER BY o.created_at DESC
         LIMIT 200'
    );
}

function dashboard_table_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $row = fetch_one(
            $pdo,
            'SELECT COUNT(*) AS column_count
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );
        $cache[$cacheKey] = (int)($row['column_count'] ?? 0) > 0;
        return $cache[$cacheKey];
    } catch (PDOException) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function dashboard_sql_values(array $values, array $columns): array
{
    $filtered = [];
    foreach ($columns as $column) {
        $filtered[$column] = $values[$column] ?? null;
    }
    return $filtered;
}

function manual_order_failure_note(Throwable $exception): string
{
    $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()));
    if ($message === '') {
        $message = $exception::class;
    }
    return substr('Order matched market data but could not be executed. ' . $message, 0, 255);
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
