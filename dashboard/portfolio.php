<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_run_id($pdo);
$positions = $latestRunId ? fetch_all(
    $pdo,
    "SELECT st.symbol, p.quantity, p.avg_price, p.current_price, p.market_value, p.cost_basis, p.unrealized_pl, p.entry_date
     FROM positions p
     JOIN stocks st ON st.id = p.stock_id
     WHERE p.run_id = :run_id AND p.status = 'OPEN'
     ORDER BY p.market_value DESC",
    ['run_id' => $latestRunId]
) : [];
$snapshots = fetch_all(
    $pdo,
    'SELECT snapshot_date, cash_balance, positions_value, portfolio_value, realized_pl, unrealized_pl
     FROM portfolio_snapshots
     ORDER BY snapshot_date ASC
     LIMIT 120'
);

$latestSnapshot = $latestRunId ? fetch_one($pdo, 'SELECT * FROM portfolio_snapshots WHERE run_id = :run_id ORDER BY snapshot_date DESC LIMIT 1', ['run_id' => $latestRunId]) : null;

$pageTitle = 'Portfolio';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-4">
    <div class="metric">
        <div class="label">Cash</div>
        <div class="value"><?= money($latestSnapshot['cash_balance'] ?? 0) ?></div>
    </div>
    <div class="metric">
        <div class="label">Positions</div>
        <div class="value"><?= money($latestSnapshot['positions_value'] ?? 0) ?></div>
    </div>
    <div class="metric">
        <div class="label">Realized P/L</div>
        <div class="value"><?= money($latestSnapshot['realized_pl'] ?? 0) ?></div>
    </div>
    <div class="metric">
        <div class="label">Unrealized P/L</div>
        <div class="value"><?= money($latestSnapshot['unrealized_pl'] ?? 0) ?></div>
    </div>
</section>

<section class="panel">
    <h2>Portfolio History</h2>
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
            </tr>
        <?php endforeach; ?>
        <?php if (!$positions): ?>
            <tr><td colspan="8" class="muted">No open positions.</td></tr>
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
