<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_run_id($pdo);
$trades = $latestRunId ? fetch_all(
    $pdo,
    'SELECT t.trade_date, st.symbol, t.side, t.quantity, t.price, t.gross_value, t.transaction_cost, t.net_value, t.realized_pl, t.reason
     FROM paper_trades t
     JOIN stocks st ON st.id = t.stock_id
     WHERE t.run_id = :run_id
     ORDER BY t.trade_date DESC, t.created_at DESC
     LIMIT 200',
    ['run_id' => $latestRunId]
) : [];

$pageTitle = 'Trades';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Symbol</th>
                <th>Side</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Gross</th>
                <th>Cost</th>
                <th>Net</th>
                <th>Realized P/L</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($trades as $row): ?>
            <tr>
                <td><?= h($row['trade_date']) ?></td>
                <td><?= h($row['symbol']) ?></td>
                <td><span class="badge <?= h($row['side'] === 'BUY' ? 'BUY' : 'SELL') ?>"><?= h($row['side']) ?></span></td>
                <td><?= number_format((int)$row['quantity']) ?></td>
                <td><?= money($row['price']) ?></td>
                <td><?= money($row['gross_value']) ?></td>
                <td><?= money($row['transaction_cost']) ?></td>
                <td><?= money($row['net_value']) ?></td>
                <td><?= money($row['realized_pl']) ?></td>
                <td><?= h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$trades): ?>
            <tr><td colspan="10" class="muted">No paper trades yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
