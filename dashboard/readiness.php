<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

function readiness_max_drawdown(array $rows): ?float
{
    $peak = null;
    $maxDrawdown = 0.0;
    foreach ($rows as $row) {
        $value = (float)($row['portfolio_value'] ?? 0);
        if ($value <= 0) {
            continue;
        }
        if ($peak === null || $value > $peak) {
            $peak = $value;
        }
        if ($peak > 0) {
            $drawdown = ($value - $peak) / $peak;
            $maxDrawdown = min($maxDrawdown, $drawdown);
        }
    }
    return $peak === null ? null : $maxDrawdown;
}

$pdo = dashboard_db();

$accuracy = fetch_one(
    $pdo,
    "SELECT
        COUNT(*) AS total_signals,
        COALESCE(SUM(CASE WHEN status = 'CORRECT' THEN 1 ELSE 0 END), 0) AS correct_signals,
        COALESCE(SUM(CASE WHEN status = 'WRONG' THEN 1 ELSE 0 END), 0) AS wrong_signals,
        COALESCE(SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END), 0) AS pending_signals
     FROM (
        SELECT
            stock_id,
            signal_date,
            signal_type,
            CASE
                WHEN SUM(CASE WHEN status = 'CORRECT' THEN 1 ELSE 0 END) > 0 THEN 'CORRECT'
                WHEN SUM(CASE WHEN status = 'WRONG' THEN 1 ELSE 0 END) > 0 THEN 'WRONG'
                WHEN SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) > 0 THEN 'PENDING'
                ELSE 'NOT_APPLICABLE'
            END AS status
        FROM accuracy_evaluations
        GROUP BY stock_id, signal_date, signal_type
     ) unique_evaluations"
) ?? [];

$paperDaysRow = fetch_one($pdo, 'SELECT COUNT(DISTINCT snapshot_date) AS paper_days FROM portfolio_snapshots') ?? [];
$trades = fetch_one(
    $pdo,
    "SELECT
        COUNT(DISTINCT CONCAT(stock_id, '|', trade_date, '|', side, '|', reason)) AS total_trades,
        COUNT(DISTINCT CASE WHEN side = 'SELL' AND realized_pl > 0 THEN CONCAT(stock_id, '|', trade_date, '|', side, '|', reason) END) AS winning_trades,
        COUNT(DISTINCT CASE WHEN side = 'SELL' AND realized_pl < 0 THEN CONCAT(stock_id, '|', trade_date, '|', side, '|', reason) END) AS losing_trades
     FROM paper_trades"
) ?? [];
$latestSnapshot = fetch_one($pdo, 'SELECT * FROM portfolio_snapshots ORDER BY snapshot_date DESC, created_at DESC LIMIT 1');
$latestPerformance = fetch_one($pdo, 'SELECT * FROM strategy_performance ORDER BY updated_at DESC LIMIT 1');
$portfolioRows = fetch_all($pdo, 'SELECT snapshot_date, portfolio_value FROM portfolio_snapshots ORDER BY snapshot_date ASC, created_at ASC');
$lastSuccessfulRun = fetch_one($pdo, "SELECT * FROM system_runs WHERE status = 'SUCCESS' ORDER BY created_at DESC LIMIT 1");
$latestData = fetch_one($pdo, 'SELECT MAX(latest_data_date) AS latest_data_date FROM system_runs');
$intradayDays = 0;
if (dashboard_table_exists($pdo, 'intraday_snapshots')) {
    $intradayRow = fetch_one($pdo, 'SELECT COUNT(DISTINCT trade_date) AS intraday_days FROM intraday_snapshots') ?? [];
    $intradayDays = (int)($intradayRow['intraday_days'] ?? 0);
}

$correctSignals = (int)($accuracy['correct_signals'] ?? 0);
$wrongSignals = (int)($accuracy['wrong_signals'] ?? 0);
$pendingSignals = (int)($accuracy['pending_signals'] ?? 0);
$completedSignals = $correctSignals + $wrongSignals;
$paperDays = (int)($paperDaysRow['paper_days'] ?? 0);
$totalTrades = (int)($trades['total_trades'] ?? 0);
$winningTrades = (int)($trades['winning_trades'] ?? 0);
$losingTrades = (int)($trades['losing_trades'] ?? 0);
$closedTrades = $winningTrades + $losingTrades;
$accuracyRate = $completedSignals > 0 ? $correctSignals / $completedSignals : null;
$winRate = $closedTrades > 0 ? $winningTrades / $closedTrades : null;
if ($winRate === null && $latestPerformance && (int)$latestPerformance['trade_count'] > 0) {
    $winRate = (float)$latestPerformance['win_rate'];
}
$portfolioValue = (float)($latestSnapshot['portfolio_value'] ?? 0);
$realizedPl = (float)($latestSnapshot['realized_pl'] ?? 0);
$unrealizedPl = (float)($latestSnapshot['unrealized_pl'] ?? 0);
$netPl = $latestPerformance ? (float)$latestPerformance['total_pl'] : ($latestSnapshot ? $portfolioValue - 100000 : 0.0);
$maxDrawdown = readiness_max_drawdown($portfolioRows);

$reasons = [];
if ($completedSignals < 100) {
    $reasons[] = 'Need more completed signals: ' . number_format($completedSignals) . '/100.';
}
if ($paperDays < 60) {
    $reasons[] = 'Need more paper trading days: ' . number_format($paperDays) . '/60.';
}
if ($pendingSignals > $completedSignals && $pendingSignals > 0) {
    $reasons[] = 'Most signals are pending.';
}
if ($accuracyRate === null || $accuracyRate <= 0) {
    $reasons[] = 'Accuracy not proven yet.';
} elseif ($accuracyRate < 0.55) {
    $reasons[] = 'Signal accuracy is below 55%.';
}
if ($winRate === null || $winRate <= 0) {
    $reasons[] = 'Win rate is not proven yet.';
} elseif ($winRate < 0.55) {
    $reasons[] = 'Win rate is below 55%.';
}
if ($netPl <= 0) {
    $reasons[] = 'Net P/L is not positive yet.';
}
if ($maxDrawdown !== null && $maxDrawdown <= -0.10) {
    $reasons[] = 'Max drawdown is worse than -10%.';
}
if ($intradayDays < 20) {
    $reasons[] = 'Intraday needs at least 20 days.';
}

$hasSomeData = $paperDays > 0 || $completedSignals > 0 || $pendingSignals > 0 || $totalTrades > 0 || $portfolioValue > 0;
$ready = $completedSignals >= 100
    && $paperDays >= 60
    && $accuracyRate !== null
    && $accuracyRate >= 0.55
    && $winRate !== null
    && $winRate >= 0.55
    && $netPl > 0
    && ($maxDrawdown === null || $maxDrawdown > -0.10);

if ($ready) {
    $finalStatus = 'READY FOR VERY SMALL TEST';
    $statusClass = 'status-ready';
} elseif (!$hasSomeData || $completedSignals < 100 || $paperDays < 60 || $accuracyRate === null || $accuracyRate <= 0 || $winRate === null || $winRate <= 0) {
    $finalStatus = 'NOT READY';
    $statusClass = 'status-not-ready';
} else {
    $finalStatus = 'CONTINUE PAPER TESTING';
    $statusClass = 'status-testing';
}

$pageTitle = 'Readiness';
require __DIR__ . '/includes/header.php';
?>
<section class="status-panel <?= h($statusClass) ?>">
    <div>
        <div class="label">Final Status</div>
        <div class="status-title"><?= h($finalStatus) ?></div>
        <div class="muted">Paper trading only. Not financial advice.</div>
    </div>
</section>

<section class="grid cols-4">
    <div class="metric">
        <div class="label">Paper Test Days</div>
        <div class="value"><?= number_format($paperDays) ?></div>
    </div>
    <div class="metric">
        <div class="label">Completed Signals</div>
        <div class="value"><?= number_format($completedSignals) ?></div>
    </div>
    <div class="metric">
        <div class="label">Signal Accuracy</div>
        <div class="value"><?= $accuracyRate === null ? 'N/A' : pct($accuracyRate) ?></div>
    </div>
    <div class="metric">
        <div class="label">Win Rate</div>
        <div class="value"><?= $winRate === null ? 'N/A' : pct($winRate) ?></div>
    </div>
</section>

<section class="grid cols-4">
    <div class="metric">
        <div class="label">Net P/L</div>
        <div class="value"><?= money($netPl) ?></div>
    </div>
    <div class="metric">
        <div class="label">Realized P/L</div>
        <div class="value"><?= money($realizedPl) ?></div>
    </div>
    <div class="metric">
        <div class="label">Unrealized P/L</div>
        <div class="value"><?= money($unrealizedPl) ?></div>
    </div>
    <div class="metric">
        <div class="label">Portfolio Value</div>
        <div class="value"><?= money($portfolioValue) ?></div>
    </div>
</section>

<section class="grid cols-2">
    <div class="panel">
        <h2>Evidence Summary</h2>
        <table>
            <tbody>
                <tr><th>Pending signals</th><td><?= number_format($pendingSignals) ?></td></tr>
                <tr><th>Correct signals</th><td><?= number_format($correctSignals) ?></td></tr>
                <tr><th>Wrong signals</th><td><?= number_format($wrongSignals) ?></td></tr>
                <tr><th>Total trades</th><td><?= number_format($totalTrades) ?></td></tr>
                <tr><th>Winning trades</th><td><?= number_format($winningTrades) ?></td></tr>
                <tr><th>Losing trades</th><td><?= number_format($losingTrades) ?></td></tr>
                <tr><th>Max drawdown</th><td><?= $maxDrawdown === null ? 'N/A' : pct($maxDrawdown) ?></td></tr>
                <tr><th>Intraday data days</th><td><?= number_format($intradayDays) ?></td></tr>
                <tr><th>Last successful run</th><td><?= h($lastSuccessfulRun['created_at'] ?? 'N/A') ?></td></tr>
                <tr><th>Latest data date</th><td><?= h($latestData['latest_data_date'] ?? 'N/A') ?></td></tr>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>Reasons</h2>
        <?php if ($reasons): ?>
            <ul class="check-list">
                <?php foreach ($reasons as $reason): ?>
                    <li><?= h($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Readiness thresholds are currently satisfied. Keep position sizes very small and continue paper tracking.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
