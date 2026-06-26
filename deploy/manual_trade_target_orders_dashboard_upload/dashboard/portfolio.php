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
$positions = aggregate_open_positions($ledger['open_lots'], $latestPrices);
$portfolioSummary = portfolio_totals_from_ledger($ledger, $positions);
$snapshots = fetch_all(
    $pdo,
    'SELECT snapshot_date, cash_balance, positions_value, portfolio_value, realized_pl, unrealized_pl
     FROM portfolio_snapshots
     WHERE run_id = :run_id
     ORDER BY snapshot_date ASC
     LIMIT 120',
    ['run_id' => $latestRunId]
);

$pageTitle = 'Portfolio';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-4">
    <div class="metric">
        <div class="label">Cash</div>
        <div class="value"><?= money($portfolioSummary['cash_balance']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Positions</div>
        <div class="value"><?= money($portfolioSummary['positions_value']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Realized P/L</div>
        <div class="value"><?= money($portfolioSummary['realized_pl']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Unrealized P/L</div>
        <div class="value"><?= money($portfolioSummary['unrealized_pl']) ?></div>
    </div>
</section>

<section class="panel">
    <h2>Portfolio History</h2>
    <div class="muted small-text">History chart comes from the latest automated daily run. Manual trades are included in the current totals above, but not backfilled into old snapshot history.</div>
    <div class="chart-wrap">
        <canvas id="portfolioChart"></canvas>
    </div>
</section>

<section class="panel">
    <h2>Open Positions</h2>
    <table>
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Qty</th>
                <th>Average</th>
                <th>Current</th>
                <th>Market Value</th>
                <th>Cost Basis</th>
                <th>Unrealized P/L</th>
                <th>Entry Date</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($positions as $row): ?>
            <tr>
                <td><?= h($row['symbol']) ?></td>
                <td><?= number_format((int)$row['quantity']) ?></td>
                <td><?= money($row['avg_price']) ?></td>
                <td><?= money($row['current_price']) ?></td>
                <td><?= money($row['market_value']) ?></td>
                <td><?= money($row['cost_basis']) ?></td>
                <td><?= money($row['unrealized_pl']) ?></td>
                <td><?= h($row['entry_date']) ?></td>
                <td><?= h($row['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$positions): ?>
            <tr><td colspan="9" class="muted">No open positions.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
const portfolioRows = <?= json_encode($snapshots, JSON_UNESCAPED_SLASHES) ?>;
new Chart(document.getElementById('portfolioChart'), {
    type: 'line',
    data: {
        labels: portfolioRows.map(row => row.snapshot_date),
        datasets: [
            { label: 'Portfolio value', data: portfolioRows.map(row => Number(row.portfolio_value)), borderColor: '#0f766e', tension: 0.2 },
            { label: 'Cash', data: portfolioRows.map(row => Number(row.cash_balance)), borderColor: '#657080', tension: 0.2 },
            { label: 'Positions', data: portfolioRows.map(row => Number(row.positions_value)), borderColor: '#a15c00', tension: 0.2 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
