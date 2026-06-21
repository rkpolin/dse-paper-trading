<?php
declare(strict_types=1);

function handle_intraday_endpoint(string $arrayKey, callable $handler): void
{
    $config = require __DIR__ . '/../lib/bootstrap.php';
    require_once __DIR__ . '/../lib/auth.php';
    require_once __DIR__ . '/../lib/database.php';
    require_once __DIR__ . '/../lib/validation.php';

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        api_error(400, 'BODY_EMPTY', 'JSON body is required.');
    }

    verify_signed_request($config, $rawBody);

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $payload = validate_intraday_payload($decoded, $arrayKey);
        $pdo = db_connect($config);
        $result = $handler($pdo, $payload);
        json_response(['ok' => true, 'result' => $result]);
    } catch (JsonException) {
        api_error(400, 'JSON_INVALID', 'Malformed JSON body.');
    } catch (Throwable) {
        api_error(500, 'SERVER_ERROR', 'The API could not process this request.');
    }
}

function validate_intraday_payload(mixed $payload, string $arrayKey): array
{
    if (!is_array($payload)) {
        api_error(400, 'JSON_INVALID', 'Request body must be a JSON object.');
    }
    $run = require_array($payload, 'run');
    $runId = require_string($run, 'run_id', 80);
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $runId)) {
        api_error(400, 'RUN_ID_INVALID', 'run_id contains unsupported characters.');
    }
    if (!isset($payload[$arrayKey]) || !is_array($payload[$arrayKey])) {
        api_error(400, 'PAYLOAD_INVALID', $arrayKey . ' must be an array.');
    }
    return $payload;
}

function upsert_intraday_system_run(PDO $pdo, array $run): void
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
        'status' => isset($run['status']) ? require_enum_value($run, 'status', ['SUCCESS', 'FAILED', 'PARTIAL', 'SKIPPED']) : 'SUCCESS',
        'source' => optional_string($run, 'source', 80, 'python_intraday_engine'),
        'latest_data_date' => optional_string($run, 'latest_data_date', 10),
    ]);
}

function ensure_intraday_stock(PDO $pdo, array $row): int
{
    $symbol = require_symbol($row);
    $name = optional_string($row, 'name', 120, $symbol);
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

function save_intraday_snapshots(PDO $pdo, array $payload): array
{
    $counts = ['intraday_snapshots' => 0, 'daily_intraday_extremes' => 0];
    $pdo->beginTransaction();
    try {
        upsert_intraday_system_run($pdo, $payload['run']);
        $runId = require_string($payload['run'], 'run_id', 80);
        foreach ($payload['intraday_snapshots'] as $row) {
            if (!is_array($row)) {
                api_error(400, 'PAYLOAD_INVALID', 'intraday_snapshots contains an invalid row.');
            }
            $stockId = ensure_intraday_stock($pdo, $row);
            upsert_intraday_snapshot($pdo, $stockId, $runId, $row);
            refresh_daily_intraday_extreme_from_snapshots($pdo, $stockId, $runId, require_date_value($row, 'trade_date'));
            $counts['intraday_snapshots']++;
            $counts['daily_intraday_extremes']++;
        }
        insert_intraday_api_log($pdo, $runId, 'SUCCESS', 'Intraday snapshots saved');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return ['run_id' => $payload['run']['run_id'], 'counts' => $counts];
}

function upsert_intraday_snapshot(PDO $pdo, int $stockId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO intraday_snapshots
            (run_id, stock_id, trade_date, snapshot_time, bucket_time, snapshot_at, last_price, day_high, day_low, volume, source)
         VALUES
            (:run_id, :stock_id, :trade_date, :snapshot_time, :bucket_time, :snapshot_at, :last_price, :day_high, :day_low, :volume, :source)
         ON DUPLICATE KEY UPDATE
            run_id = VALUES(run_id),
            snapshot_time = VALUES(snapshot_time),
            snapshot_at = VALUES(snapshot_at),
            last_price = VALUES(last_price),
            day_high = VALUES(day_high),
            day_low = VALUES(day_low),
            volume = VALUES(volume),
            source = VALUES(source)'
    );
    $stmt->execute([
        'run_id' => $runId,
        'stock_id' => $stockId,
        'trade_date' => require_date_value($row, 'trade_date'),
        'snapshot_time' => require_time_value($row, 'snapshot_time'),
        'bucket_time' => require_time_value($row, 'bucket_time'),
        'snapshot_at' => require_datetime_value($row, 'snapshot_at'),
        'last_price' => require_float_value($row, 'last_price', 0),
        'day_high' => require_float_value($row, 'day_high', 0),
        'day_low' => require_float_value($row, 'day_low', 0),
        'volume' => require_int_value($row, 'volume', 0),
        'source' => optional_string($row, 'source', 80, 'dse_latest_share_price'),
    ]);
}

function refresh_daily_intraday_extreme_from_snapshots(PDO $pdo, int $stockId, string $runId, string $tradeDate): void
{
    $stmt = $pdo->prepare(
        'SELECT trade_date, bucket_time, last_price, day_high, day_low
         FROM intraday_snapshots
         WHERE stock_id = :stock_id AND trade_date = :trade_date
         ORDER BY bucket_time ASC, snapshot_time ASC'
    );
    $stmt->execute(['stock_id' => $stockId, 'trade_date' => $tradeDate]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return;
    }

    $highRow = $rows[0];
    $lowRow = $rows[0];
    foreach ($rows as $candidate) {
        if ((float)$candidate['day_high'] > (float)$highRow['day_high']) {
            $highRow = $candidate;
        }
        if ((float)$candidate['day_low'] < (float)$lowRow['day_low']) {
            $lowRow = $candidate;
        }
    }

    $openPrice = (float)$rows[0]['last_price'];
    $closePrice = (float)$rows[count($rows) - 1]['last_price'];
    $dayHigh = (float)$highRow['day_high'];
    $dayLow = (float)$lowRow['day_low'];
    $bucketTimes = array_map(static fn($row) => substr((string)$row['bucket_time'], 0, 5), $rows);

    upsert_daily_intraday_extreme($pdo, $stockId, $runId, [
        'trade_date' => $tradeDate,
        'day_high' => $dayHigh,
        'day_high_time' => (string)$highRow['bucket_time'],
        'day_low' => $dayLow,
        'day_low_time' => (string)$lowRow['bucket_time'],
        'intraday_range_pct' => $openPrice > 0 ? ($dayHigh - $dayLow) / $openPrice : 0,
        'open_snapshot_price' => $openPrice,
        'close_snapshot_price' => $closePrice,
        'snapshot_count' => count($rows),
        'is_complete' => in_array('14:05', $bucketTimes, true) || count(array_unique($bucketTimes)) >= 17,
    ]);
}

function save_daily_intraday_extremes(PDO $pdo, array $payload): array
{
    $counts = ['daily_intraday_extremes' => 0];
    $pdo->beginTransaction();
    try {
        upsert_intraday_system_run($pdo, $payload['run']);
        $runId = require_string($payload['run'], 'run_id', 80);
        foreach ($payload['daily_intraday_extremes'] as $row) {
            if (!is_array($row)) {
                api_error(400, 'PAYLOAD_INVALID', 'daily_intraday_extremes contains an invalid row.');
            }
            $stockId = ensure_intraday_stock($pdo, $row);
            upsert_daily_intraday_extreme($pdo, $stockId, $runId, $row);
            $counts['daily_intraday_extremes']++;
        }
        insert_intraday_api_log($pdo, $runId, 'SUCCESS', 'Intraday extremes saved');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return ['run_id' => $payload['run']['run_id'], 'counts' => $counts];
}

function upsert_daily_intraday_extreme(PDO $pdo, int $stockId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO daily_intraday_extremes
            (run_id, stock_id, trade_date, day_high, day_high_time, day_low, day_low_time, intraday_range_pct, open_snapshot_price, close_snapshot_price, snapshot_count, is_complete)
         VALUES
            (:run_id, :stock_id, :trade_date, :day_high, :day_high_time, :day_low, :day_low_time, :intraday_range_pct, :open_snapshot_price, :close_snapshot_price, :snapshot_count, :is_complete)
         ON DUPLICATE KEY UPDATE
            run_id = VALUES(run_id),
            day_high = VALUES(day_high),
            day_high_time = VALUES(day_high_time),
            day_low = VALUES(day_low),
            day_low_time = VALUES(day_low_time),
            intraday_range_pct = VALUES(intraday_range_pct),
            open_snapshot_price = VALUES(open_snapshot_price),
            close_snapshot_price = VALUES(close_snapshot_price),
            snapshot_count = VALUES(snapshot_count),
            is_complete = VALUES(is_complete)'
    );
    $stmt->execute([
        'run_id' => $runId,
        'stock_id' => $stockId,
        'trade_date' => require_date_value($row, 'trade_date'),
        'day_high' => require_float_value($row, 'day_high', 0),
        'day_high_time' => require_time_value($row, 'day_high_time'),
        'day_low' => require_float_value($row, 'day_low', 0),
        'day_low_time' => require_time_value($row, 'day_low_time'),
        'intraday_range_pct' => require_float_value($row, 'intraday_range_pct'),
        'open_snapshot_price' => require_float_value($row, 'open_snapshot_price', 0),
        'close_snapshot_price' => require_float_value($row, 'close_snapshot_price', 0),
        'snapshot_count' => require_int_value($row, 'snapshot_count', 1),
        'is_complete' => bool_to_int($row['is_complete'] ?? false),
    ]);
}

function save_intraday_time_window_stats(PDO $pdo, array $payload): array
{
    $counts = ['intraday_time_window_stats' => 0];
    $pdo->beginTransaction();
    try {
        upsert_intraday_system_run($pdo, $payload['run']);
        $runId = require_string($payload['run'], 'run_id', 80);
        foreach ($payload['intraday_time_window_stats'] as $row) {
            if (!is_array($row)) {
                api_error(400, 'PAYLOAD_INVALID', 'intraday_time_window_stats contains an invalid row.');
            }
            $stockId = ensure_intraday_stock($pdo, $row);
            upsert_intraday_time_window_stat($pdo, $stockId, $runId, $row);
            $counts['intraday_time_window_stats']++;
        }
        insert_intraday_api_log($pdo, $runId, 'SUCCESS', 'Intraday time window stats saved');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return ['run_id' => $payload['run']['run_id'], 'counts' => $counts];
}

function upsert_intraday_time_window_stat(PDO $pdo, int $stockId, string $runId, array $row): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO intraday_time_window_stats
            (run_id, stock_id, lookback_days, bucket_time, sample_days, low_count, high_count, low_probability, high_probability, avg_return_to_close_pct, avg_return_next_bucket_pct, buy_window_score, sell_window_score, confidence_level, computed_through_date)
         VALUES
            (:run_id, :stock_id, :lookback_days, :bucket_time, :sample_days, :low_count, :high_count, :low_probability, :high_probability, :avg_return_to_close_pct, :avg_return_next_bucket_pct, :buy_window_score, :sell_window_score, :confidence_level, :computed_through_date)
         ON DUPLICATE KEY UPDATE
            run_id = VALUES(run_id),
            sample_days = VALUES(sample_days),
            low_count = VALUES(low_count),
            high_count = VALUES(high_count),
            low_probability = VALUES(low_probability),
            high_probability = VALUES(high_probability),
            avg_return_to_close_pct = VALUES(avg_return_to_close_pct),
            avg_return_next_bucket_pct = VALUES(avg_return_next_bucket_pct),
            buy_window_score = VALUES(buy_window_score),
            sell_window_score = VALUES(sell_window_score),
            confidence_level = VALUES(confidence_level)'
    );
    $confidence = require_enum_value($row, 'confidence_level', ['NOT_ENOUGH_DATA', 'LOW', 'MEDIUM', 'HIGH']);
    $stmt->execute([
        'run_id' => $runId,
        'stock_id' => $stockId,
        'lookback_days' => require_int_value($row, 'lookback_days', 1),
        'bucket_time' => require_time_value($row, 'bucket_time'),
        'sample_days' => require_int_value($row, 'sample_days', 0),
        'low_count' => require_int_value($row, 'low_count', 0),
        'high_count' => require_int_value($row, 'high_count', 0),
        'low_probability' => require_float_value($row, 'low_probability', 0),
        'high_probability' => require_float_value($row, 'high_probability', 0),
        'avg_return_to_close_pct' => optional_float_value($row, 'avg_return_to_close_pct'),
        'avg_return_next_bucket_pct' => optional_float_value($row, 'avg_return_next_bucket_pct'),
        'buy_window_score' => require_float_value($row, 'buy_window_score', 0),
        'sell_window_score' => require_float_value($row, 'sell_window_score', 0),
        'confidence_level' => $confidence,
        'computed_through_date' => require_date_value($row, 'computed_through_date'),
    ]);
}

function insert_intraday_api_log(PDO $pdo, string $runId, string $status, string $message): void
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
