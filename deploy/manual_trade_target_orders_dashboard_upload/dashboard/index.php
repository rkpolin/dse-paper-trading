<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trade_ledger.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_daily_run_id($pdo);
process_pending_manual_orders($pdo, $latestRunId);
$tradeRows = fetch_trade_rows_for_dashboard($pdo, $latestRunId);
$ledger = build_trade_ledger($tradeRows);
$latestPrices = fetch_latest_close_prices($pdo);
$openPositions = aggregate_open_positions($ledger['open_lots'], $latestPrices);
$portfolioSummary = portfolio_totals_from_ledger($ledger, $openPositions);
$tradeSummary = summarize_closed_trades($ledger['closed_trades']);
$accuracy = $latestRunId ? (fetch_one(
    $pdo,
    "SELECT SUM(status = 'CORRECT') AS correct_count, SUM(status = 'WRONG') AS wrong_count
     FROM accuracy_evaluations
     WHERE run_id = :run_id",
    ['run_id' => $latestRunId]
) ?? []) : [];
$recentSignals = $latestRunId ? fetch_all(
    $pdo,
    'SELECT s.signal_date, st.symbol, s.signal_type, s.confidence, s.close_price, s.reason
     FROM signals s
     JOIN stocks st ON st.id = s.stock_id
     WHERE s.run_id = :run_id
     ORDER BY s.signal_date DESC, s.confidence DESC
     LIMIT 12',
    ['run_id' => $latestRunId]
) : [];
$equityRows = $latestRunId ? fetch_all(
    $pdo,
    'SELECT snapshot_date, portfolio_value
     FROM portfolio_snapshots
     WHERE run_id = :run_id
     ORDER BY snapshot_date ASC
     LIMIT 90',
    ['run_id' => $latestRunId]
) : [];

$correct = (int)($accuracy['correct_count'] ?? 0);
$wrong = (int)($accuracy['wrong_count'] ?? 0);
$scored = $correct + $wrong;
$signalAccuracy = $scored > 0 ? $correct / $scored : null;

$pageTitle = 'Overview';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-4">
    <div class="metric">
        <div class="label">Portfolio Value</div>
        <div class="value"><?= money($portfolioSummary['portfolio_value']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Total P/L</div>
        <div class="value"><?= money($portfolioSummary['realized_pl'] + $portfolioSummary['unrealized_pl']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Signal Accuracy</div>
        <div class="value"><?= pct_or_na($signalAccuracy, 'Not enough data yet') ?></div>
        <div class="muted small-text">BUY signal is evaluated after 5 trading days.</div>
    </div>
    <div class="metric">
        <div class="label">Win Rate</div>
        <div class="value"><?= pct_or_na($tradeSummary['win_rate']) ?></div>
        <div class="muted small-text">Only closed trades are counted.</div>
    </div>
</section>

<section class="panel">
    <h2>Equity Curve</h2>
    <div class="muted small-text">Equity history is from the latest automated daily run. Current totals above include manual paper trades too.</div>
    <div class="chart-wrap">
        <canvas id="equityChart"></canvas>
    </div>
</section>

<section class="panel">
    <h2>Recent Signals</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Symbol</th>
                <th>Signal</th>
                <th>Signal Score</th>
                <th>Close</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentSignals as $row): ?>
            <tr>
                <td><?= h($row['signal_date']) ?></td>
                <td><?= h($row['symbol']) ?></td>
                <td><span class="badge <?= h($row['signal_type']) ?>"><?= h($row['signal_type']) ?></span></td>
                <td><?= pct($row['confidence']) ?></td>
                <td><?= money($row['close_price']) ?></td>
                <td><?= h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentSignals): ?>
            <tr><td colspan="6" class="muted">No signals yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
const equityRows = <?= json_encode($equityRows, JSON_UNESCAPED_SLASHES) ?>;
new Chart(document.getElementById('equityChart'), {
    type: 'line',
    data: {
        labels: equityRows.map(row => row.snapshot_date),
        datasets: [{
            label: 'Portfolio value',
            data: equityRows.map(row => Number(row.portfolio_value)),
            borderColor: '#0f766e',
            backgroundColor: 'rgba(15, 118, 110, 0.12)',
            fill: true,
            tension: 0.25
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { ticks: { callback: value => value.toLocaleString() } } }
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
