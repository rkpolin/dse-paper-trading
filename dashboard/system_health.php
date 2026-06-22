<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

function health_status_badge(string $status): string
{
    return $status === 'SUCCESS' ? 'CORRECT' : 'WRONG';
}

function health_is_weekend(DateTimeImmutable $date): bool
{
    $weekday = (int)$date->format('N');
    return $weekday === 5 || $weekday === 6;
}

$pdo = dashboard_db();
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));
$todayText = $today->format('Y-m-d');

$lastSuccessfulRun = fetch_one($pdo, "SELECT run_id, started_at, completed_at, status, source, latest_data_date, created_at FROM system_runs WHERE status = 'SUCCESS' ORDER BY created_at DESC LIMIT 1");
$lastFailedRun = fetch_one($pdo, "SELECT run_id, started_at, completed_at, status, source, latest_data_date, created_at FROM system_runs WHERE status <> 'SUCCESS' ORDER BY created_at DESC LIMIT 1");
$latestDaily = fetch_one($pdo, 'SELECT MAX(trade_date) AS latest_market_date FROM daily_prices') ?? [];
$latestMarketDate = (string)($latestDaily['latest_market_date'] ?? '');
$stocksUpdated = 0;
if ($latestMarketDate !== '') {
    $stockRow = fetch_one(
        $pdo,
        'SELECT COUNT(DISTINCT stock_id) AS stock_count FROM daily_prices WHERE trade_date = :trade_date',
        ['trade_date' => $latestMarketDate]
    ) ?? [];
    $stocksUpdated = (int)($stockRow['stock_count'] ?? 0);
}

$latestApiLog = fetch_one($pdo, 'SELECT run_id, status, message, created_at FROM api_logs ORDER BY created_at DESC LIMIT 1');
$apiFailures = fetch_one(
    $pdo,
    "SELECT COUNT(*) AS failed_count
     FROM api_logs
     WHERE status <> 'SUCCESS' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
) ?? [];

$intradayStatus = 'Intraday table not installed.';
$intradayLatest = null;
$intradayTodayCount = 0;
if (dashboard_table_exists($pdo, 'intraday_snapshots')) {
    $intradayLatest = fetch_one(
        $pdo,
        'SELECT snap.trade_date, snap.bucket_time, MAX(snap.snapshot_at) AS snapshot_at, COUNT(*) AS symbol_count
         FROM intraday_snapshots snap
         WHERE snap.trade_date = (SELECT MAX(trade_date) FROM intraday_snapshots)
           AND snap.bucket_time = (
                SELECT MAX(bucket_time)
                FROM intraday_snapshots
                WHERE trade_date = snap.trade_date
           )
         GROUP BY snap.trade_date, snap.bucket_time
         LIMIT 1'
    );
    $intradayToday = fetch_one(
        $pdo,
        'SELECT COUNT(*) AS snapshot_count FROM intraday_snapshots WHERE trade_date = :trade_date',
        ['trade_date' => $todayText]
    ) ?? [];
    $intradayTodayCount = (int)($intradayToday['snapshot_count'] ?? 0);
    if ($intradayLatest) {
        $intradayStatus = 'Latest bucket ' . (string)$intradayLatest['bucket_time'] . ' on ' . (string)$intradayLatest['trade_date'];
    } else {
        $intradayStatus = 'No intraday snapshots yet.';
    }
}

$dailyDuplicates = fetch_one(
    $pdo,
    'SELECT COUNT(*) AS duplicate_count
     FROM (
        SELECT stock_id, trade_date
        FROM daily_prices
        GROUP BY stock_id, trade_date
        HAVING COUNT(*) > 1
     ) duplicates'
) ?? [];
$duplicateCount = (int)($dailyDuplicates['duplicate_count'] ?? 0);

if (dashboard_table_exists($pdo, 'intraday_snapshots')) {
    $intradayDuplicates = fetch_one(
        $pdo,
        'SELECT COUNT(*) AS duplicate_count
         FROM (
            SELECT stock_id, trade_date, bucket_time
            FROM intraday_snapshots
            GROUP BY stock_id, trade_date, bucket_time
            HAVING COUNT(*) > 1
         ) duplicates'
    ) ?? [];
    $duplicateCount += (int)($intradayDuplicates['duplicate_count'] ?? 0);
}

$warnings = [];
if ($latestMarketDate === '') {
    $warnings[] = 'No daily market data found.';
} elseif (health_is_weekend($today)) {
    $warnings[] = 'Market closed today in Bangladesh time.';
} elseif ($latestMarketDate < $todayText) {
    $warnings[] = 'Latest daily data is older than today. This can be normal before the daily workflow finishes.';
} else {
    $warnings[] = 'Latest daily data is current for today.';
}
if ($stocksUpdated === 0) {
    $warnings[] = 'No stocks were updated for the latest daily data date.';
}
if ((int)($apiFailures['failed_count'] ?? 0) > 0) {
    $warnings[] = 'API failures were logged in the last 24 hours.';
}
if ($duplicateCount > 0) {
    $warnings[] = 'Duplicate data was detected.';
} else {
    $warnings[] = 'No duplicate daily or intraday rows detected.';
}
if (dashboard_table_exists($pdo, 'intraday_snapshots') && !health_is_weekend($today) && $intradayTodayCount === 0) {
    $warnings[] = 'No intraday snapshots for today yet.';
}

$pageTitle = 'System Health';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-4">
    <div class="metric">
        <div class="label">Latest Market Data</div>
        <div class="value"><?= h($latestMarketDate !== '' ? $latestMarketDate : 'N/A') ?></div>
    </div>
    <div class="metric">
        <div class="label">Stocks Updated</div>
        <div class="value"><?= number_format($stocksUpdated) ?></div>
    </div>
    <div class="metric">
        <div class="label">API Failures 24h</div>
        <div class="value"><?= number_format((int)($apiFailures['failed_count'] ?? 0)) ?></div>
    </div>
    <div class="metric">
        <div class="label">Intraday Today</div>
        <div class="value"><?= number_format($intradayTodayCount) ?></div>
    </div>
</section>

<section class="grid cols-2">
    <div class="panel">
        <h2>Run Status</h2>
        <table>
            <tbody>
                <tr><th>Last successful run</th><td><?= h($lastSuccessfulRun['created_at'] ?? 'N/A') ?></td></tr>
                <tr><th>Success run ID</th><td><?= h($lastSuccessfulRun['run_id'] ?? 'N/A') ?></td></tr>
                <tr><th>Last failed run</th><td><?= h($lastFailedRun['created_at'] ?? 'N/A') ?></td></tr>
                <tr><th>Failed run ID</th><td><?= h($lastFailedRun['run_id'] ?? 'N/A') ?></td></tr>
                <tr><th>Intraday snapshot status</th><td><?= h($intradayStatus) ?></td></tr>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>API Log Status</h2>
        <table>
            <tbody>
                <tr>
                    <th>Latest API status</th>
                    <td>
                        <?php if ($latestApiLog): ?>
                            <span class="badge <?= h(health_status_badge((string)$latestApiLog['status'])) ?>"><?= h($latestApiLog['status']) ?></span>
                        <?php else: ?>
                            <span class="muted">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Latest API time</th><td><?= h($latestApiLog['created_at'] ?? 'N/A') ?></td></tr>
                <tr><th>Latest API message</th><td><?= h($latestApiLog['message'] ?? 'N/A') ?></td></tr>
                <tr><th>Duplicate warning</th><td><?= $duplicateCount > 0 ? h((string)$duplicateCount . ' duplicate groups found') : 'No duplicates detected' ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Warnings</h2>
    <ul class="check-list">
        <?php foreach ($warnings as $warning): ?>
            <li><?= h($warning) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
