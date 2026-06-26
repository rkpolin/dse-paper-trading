<?php
declare(strict_types=1);

function ingest_payload(PDO $pdo, array $payload): array
{
    $run = $payload['run'];
    $runId = (string)$run['run_id'];
    $counts = [
        'stocks' => 0,
        'daily_prices' => 0,
        'indicators' => 0,
        'signals' => 0,
        'paper_trades' => 0,
        'positions' => 0,
        'portfolio_snapshots' => 0,
        'accuracy_evaluations' => 0,
        'strategy_performance' => 0,
    ];

    $pdo->beginTransaction();
    try {
        upsert_system_run($pdo, $run);

        foreach ($payload['stocks'] as $stock) {
            $symbol = require_symbol($stock);
            ensure_stock($pdo, $symbol, optional_string($stock, 'name', 120, $symbol));
            $counts['stocks']++;
        }

        foreach ($payload['daily_prices'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_daily_price($pdo, $symbolId, $row);
            $counts['daily_prices']++;
        }

        foreach ($payload['indicators'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_indicator($pdo, $symbolId, $runId, $row);
            $counts['indicators']++;
        }

        foreach ($payload['signals'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_signal($pdo, $symbolId, $runId, $row);
            $counts['signals']++;
        }

        foreach ($payload['paper_trades'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_paper_trade($pdo, $symbolId, $runId, $row);
            $counts['paper_trades']++;
        }

        foreach ($payload['positions'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_position($pdo, $symbolId, $runId, $row);
            $counts['positions']++;
        }

        foreach ($payload['portfolio_snapshots'] as $row) {
            upsert_portfolio_snapshot($pdo, $runId, $row);
            $counts['portfolio_snapshots']++;
        }

        foreach ($payload['accuracy_evaluations'] as $row) {
            $symbolId = ensure_stock($pdo, require_symbol($row), require_symbol($row));
            upsert_accuracy_evaluation($pdo, $symbolId, $runId, $row);
            $counts['accuracy_evaluations']++;
        }

        upsert_strategy_performance($pdo, $runId, $payload['strategy_performance']);
        $counts['strategy_performance'] = 1;
        insert_api_log($pdo, $runId, 'SUCCESS', 'Ingest completed');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return ['run_id' => $runId, 'counts' => $counts];
}

function ensure_stock(PDO $pdo, string $symbol, ?string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO stocks (symbol, name)
         VALUES (:symbol, :name)
         ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['symbol' => $symbol, 'name' => $name ?: $symbol]);

    $select = $pdo->prepare('SELECT id FROM stocks WHERE symbol = :symbol');
    $select->execute(['symbol' => $symbol]);
    $id = $select->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Stock lookup failed');
    }
    return (int)$id;
}

function upsert_system_run(PDO $pdo, array $run): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO system_runs (run_id, started_at, completed_at, status, source, latest_data_date)
         VALUES (:run_id, :started_at, :completed_at, :status, :source, :latest_data_date)
         ON DUPLICATE KEY UPDATE
            completed_at = VALUES(completed_at),
            status = VALUES(status),
            source = VALUES(source),
            latest_data_date = VALUES(latest_data_date)'
    );
    $stmt->execute([
        'run_id' => require_string($run, 'run_id', 80),
        'started_at' => optional_string($run, 'started_at', 40),
        'completed_at' => optional_string($run, 'completed_at', 40),
        'status' => isset($run['status']) ? require_enum_value($run, 'status', ['SUCCESS', 'FAILED', 'PARTIAL']) : 'SUCCESS',
        'source' => optional_string($run, 'source', 80, 'python_engine'),
        'latest_data_date' => optional_string($run, 'latest_data_date', 10),
    ]);
}

function upsert_daily_price(PDO $pdo, int $symbolId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO daily_prices (stock_id, trade_date, open_price, high_price, low_price, close_price, volume)
         VALUES (:stock_id, :trade_date, :open_price, :high_price, :low_price, :close_price, :volume)
         ON DUPLICATE KEY UPDATE
            open_price = VALUES(open_price),
            high_price = VALUES(high_price),
            low_price = VALUES(low_price),
            close_price = VALUES(close_price),
            volume = VALUES(volume)'
    );
    $stmt->execute([
        'stock_id' => $symbolId,
        'trade_date' => require_date_value($row, 'date'),
        'open_price' => require_float_value($row, 'open', 0),
        'high_price' => require_float_value($row, 'high', 0),
        'low_price' => require_float_value($row, 'low', 0),
        'close_price' => require_float_value($row, 'close', 0),
        'volume' => (int)require_float_value($row, 'volume', 0),
    ]);
}

function upsert_indicator(PDO $pdo, int $symbolId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO indicators (run_id, stock_id, trade_date, rsi, sma20, sma50, volume_ratio, momentum, breakout, pump_risk)
         VALUES (:run_id, :stock_id, :trade_date, :rsi, :sma20, :sma50, :volume_ratio, :momentum, :breakout, :pump_risk)
         ON DUPLICATE KEY UPDATE
            rsi = VALUES(rsi),
            sma20 = VALUES(sma20),
            sma50 = VALUES(sma50),
            volume_ratio = VALUES(volume_ratio),
            momentum = VALUES(momentum),
            breakout = VALUES(breakout),
            pump_risk = VALUES(pump_risk)'
    );
    $stmt->execute([
        'run_id' => $runId,
        'stock_id' => $symbolId,
        'trade_date' => require_date_value($row, 'date'),
        'rsi' => optional_float_value($row, 'rsi'),
        'sma20' => optional_float_value($row, 'sma20'),
        'sma50' => optional_float_value($row, 'sma50'),
        'volume_ratio' => optional_float_value($row, 'volume_ratio'),
        'momentum' => optional_float_value($row, 'momentum'),
        'breakout' => bool_to_int($row['breakout'] ?? false),
        'pump_risk' => bool_to_int($row['pump_risk'] ?? false),
    ]);
}

function upsert_signal(PDO $pdo, int $symbolId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO signals
            (signal_id, run_id, stock_id, signal_date, signal_type, close_price, confidence, reason, rsi, sma20, sma50, volume_ratio, momentum, breakout, pump_risk)
         VALUES
            (:signal_id, :run_id, :stock_id, :signal_date, :signal_type, :close_price, :confidence, :reason, :rsi, :sma20, :sma50, :volume_ratio, :momentum, :breakout, :pump_risk)
         ON DUPLICATE KEY UPDATE
            signal_type = VALUES(signal_type),
            close_price = VALUES(close_price),
            confidence = VALUES(confidence),
            reason = VALUES(reason),
            rsi = VALUES(rsi),
            sma20 = VALUES(sma20),
            sma50 = VALUES(sma50),
            volume_ratio = VALUES(volume_ratio),
            momentum = VALUES(momentum),
            breakout = VALUES(breakout),
            pump_risk = VALUES(pump_risk)'
    );
    $stmt->execute([
        'signal_id' => require_string($row, 'signal_id', 80),
        'run_id' => $runId,
        'stock_id' => $symbolId,
        'signal_date' => require_date_value($row, 'date'),
        'signal_type' => require_enum_value($row, 'signal_type', ['BUY', 'SELL', 'HOLD', 'WATCH', 'AVOID']),
        'close_price' => require_float_value($row, 'close', 0),
        'confidence' => require_float_value($row, 'confidence', 0),
        'reason' => optional_string($row, 'reason', 255, ''),
        'rsi' => optional_float_value($row, 'rsi'),
        'sma20' => optional_float_value($row, 'sma20'),
        'sma50' => optional_float_value($row, 'sma50'),
        'volume_ratio' => optional_float_value($row, 'volume_ratio'),
        'momentum' => optional_float_value($row, 'momentum'),
        'breakout' => bool_to_int($row['breakout'] ?? false),
        'pump_risk' => bool_to_int($row['pump_risk'] ?? false),
    ]);
}

function upsert_paper_trade(PDO $pdo, int $symbolId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO paper_trades
            (trade_id, run_id, stock_id, trade_date, side, quantity, price, gross_value, transaction_cost, net_value, realized_pl, source, entry_trade_id, reason)
         VALUES
            (:trade_id, :run_id, :stock_id, :trade_date, :side, :quantity, :price, :gross_value, :transaction_cost, :net_value, :realized_pl, :source, :entry_trade_id, :reason)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            price = VALUES(price),
            gross_value = VALUES(gross_value),
            transaction_cost = VALUES(transaction_cost),
            net_value = VALUES(net_value),
            realized_pl = VALUES(realized_pl),
            source = VALUES(source),
            entry_trade_id = VALUES(entry_trade_id),
            reason = VALUES(reason)'
    );
    $stmt->execute([
        'trade_id' => require_string($row, 'trade_id', 80),
        'run_id' => $runId,
        'stock_id' => $symbolId,
        'trade_date' => require_date_value($row, 'trade_date'),
        'side' => require_enum_value($row, 'side', ['BUY', 'SELL']),
        'quantity' => (int)require_float_value($row, 'quantity', 0),
        'price' => require_float_value($row, 'price', 0),
        'gross_value' => require_float_value($row, 'gross_value', 0),
        'transaction_cost' => require_float_value($row, 'transaction_cost', 0),
        'net_value' => require_float_value($row, 'net_value', 0),
        'realized_pl' => optional_float_value($row, 'realized_pl') ?? 0.0,
        'source' => optional_string($row, 'source', 20, 'SYSTEM'),
        'entry_trade_id' => optional_string($row, 'entry_trade_id', 64),
        'reason' => optional_string($row, 'reason', 50, ''),
    ]);
}

function upsert_position(PDO $pdo, int $symbolId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO positions
            (run_id, stock_id, quantity, avg_price, current_price, market_value, cost_basis, unrealized_pl, entry_date, status)
         VALUES
            (:run_id, :stock_id, :quantity, :avg_price, :current_price, :market_value, :cost_basis, :unrealized_pl, :entry_date, :status)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            avg_price = VALUES(avg_price),
            current_price = VALUES(current_price),
            market_value = VALUES(market_value),
            cost_basis = VALUES(cost_basis),
            unrealized_pl = VALUES(unrealized_pl),
            status = VALUES(status)'
    );
    $stmt->execute([
        'run_id' => $runId,
        'stock_id' => $symbolId,
        'quantity' => (int)require_float_value($row, 'quantity', 0),
        'avg_price' => require_float_value($row, 'avg_price', 0),
        'current_price' => require_float_value($row, 'current_price', 0),
        'market_value' => require_float_value($row, 'market_value', 0),
        'cost_basis' => require_float_value($row, 'cost_basis', 0),
        'unrealized_pl' => optional_float_value($row, 'unrealized_pl') ?? 0.0,
        'entry_date' => require_date_value($row, 'entry_date'),
        'status' => isset($row['status']) ? require_enum_value($row, 'status', ['OPEN', 'CLOSED']) : 'OPEN',
    ]);
}

function upsert_portfolio_snapshot(PDO $pdo, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO portfolio_snapshots
            (snapshot_id, run_id, snapshot_date, cash_balance, positions_value, portfolio_value, realized_pl, unrealized_pl, open_positions)
         VALUES
            (:snapshot_id, :run_id, :snapshot_date, :cash_balance, :positions_value, :portfolio_value, :realized_pl, :unrealized_pl, :open_positions)
         ON DUPLICATE KEY UPDATE
            cash_balance = VALUES(cash_balance),
            positions_value = VALUES(positions_value),
            portfolio_value = VALUES(portfolio_value),
            realized_pl = VALUES(realized_pl),
            unrealized_pl = VALUES(unrealized_pl),
            open_positions = VALUES(open_positions)'
    );
    $stmt->execute([
        'snapshot_id' => require_string($row, 'snapshot_id', 80),
        'run_id' => $runId,
        'snapshot_date' => require_date_value($row, 'snapshot_date'),
        'cash_balance' => require_float_value($row, 'cash_balance'),
        'positions_value' => require_float_value($row, 'positions_value'),
        'portfolio_value' => require_float_value($row, 'portfolio_value'),
        'realized_pl' => optional_float_value($row, 'realized_pl') ?? 0.0,
        'unrealized_pl' => optional_float_value($row, 'unrealized_pl') ?? 0.0,
        'open_positions' => (int)require_float_value($row, 'open_positions', 0),
    ]);
}

function upsert_accuracy_evaluation(PDO $pdo, int $symbolId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO accuracy_evaluations
            (evaluation_id, signal_id, run_id, stock_id, signal_date, signal_type, entry_price, evaluation_days, days_checked, status, max_gain_pct, max_drawdown_pct, result_note)
         VALUES
            (:evaluation_id, :signal_id, :run_id, :stock_id, :signal_date, :signal_type, :entry_price, :evaluation_days, :days_checked, :status, :max_gain_pct, :max_drawdown_pct, :result_note)
         ON DUPLICATE KEY UPDATE
            days_checked = VALUES(days_checked),
            status = VALUES(status),
            max_gain_pct = VALUES(max_gain_pct),
            max_drawdown_pct = VALUES(max_drawdown_pct),
            result_note = VALUES(result_note)'
    );
    $stmt->execute([
        'evaluation_id' => require_string($row, 'evaluation_id', 80),
        'signal_id' => require_string($row, 'signal_id', 80),
        'run_id' => $runId,
        'stock_id' => $symbolId,
        'signal_date' => require_date_value($row, 'signal_date'),
        'signal_type' => require_enum_value($row, 'signal_type', ['BUY', 'SELL', 'HOLD', 'WATCH', 'AVOID']),
        'entry_price' => require_float_value($row, 'entry_price', 0),
        'evaluation_days' => (int)require_float_value($row, 'evaluation_days', 1),
        'days_checked' => (int)require_float_value($row, 'days_checked', 0),
        'status' => require_enum_value($row, 'status', ['PENDING', 'CORRECT', 'WRONG', 'NOT_APPLICABLE']),
        'max_gain_pct' => optional_float_value($row, 'max_gain_pct'),
        'max_drawdown_pct' => optional_float_value($row, 'max_drawdown_pct'),
        'result_note' => optional_string($row, 'result_note', 255, ''),
    ]);
}

function upsert_strategy_performance(PDO $pdo, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO strategy_performance
            (run_id, strategy_name, initial_balance, ending_value, total_pl, total_return_pct, trade_count, win_rate, signal_accuracy)
         VALUES
            (:run_id, :strategy_name, :initial_balance, :ending_value, :total_pl, :total_return_pct, :trade_count, :win_rate, :signal_accuracy)
         ON DUPLICATE KEY UPDATE
            ending_value = VALUES(ending_value),
            total_pl = VALUES(total_pl),
            total_return_pct = VALUES(total_return_pct),
            trade_count = VALUES(trade_count),
            win_rate = VALUES(win_rate),
            signal_accuracy = VALUES(signal_accuracy)'
    );
    $stmt->execute([
        'run_id' => $runId,
        'strategy_name' => optional_string($row, 'strategy_name', 80, 'dse_mvp_rules_v1'),
        'initial_balance' => require_float_value($row, 'initial_balance', 0),
        'ending_value' => require_float_value($row, 'ending_value', 0),
        'total_pl' => optional_float_value($row, 'total_pl') ?? 0.0,
        'total_return_pct' => optional_float_value($row, 'total_return_pct') ?? 0.0,
        'trade_count' => (int)require_float_value($row, 'trade_count', 0),
        'win_rate' => optional_float_value($row, 'win_rate') ?? 0.0,
        'signal_accuracy' => optional_float_value($row, 'signal_accuracy') ?? 0.0,
    ]);
}

function insert_api_log(PDO $pdo, string $runId, string $status, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO api_logs (run_id, status, message, remote_addr)
         VALUES (:run_id, :status, :message, :remote_addr)'
    );
    $stmt->execute([
        'run_id' => $runId !== '' ? $runId : null,
        'status' => $status,
        'message' => $message,
        'remote_addr' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}
