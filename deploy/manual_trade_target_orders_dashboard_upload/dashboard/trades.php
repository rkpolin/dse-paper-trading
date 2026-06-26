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
$closedTrades = array_reverse($ledger['closed_trades']);
$tradeSummary = summarize_closed_trades($closedTrades);

$pageTitle = 'Trades';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-4">
    <div class="metric">
        <div class="label">Closed Trades</div>
        <div class="value"><?= number_format($tradeSummary['closed_count']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Winning Trades</div>
        <div class="value"><?= number_format($tradeSummary['winning_count']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Losing Trades</div>
        <div class="value"><?= number_format($tradeSummary['losing_count']) ?></div>
    </div>
    <div class="metric">
        <div class="label">Win Rate</div>
        <div class="value"><?= pct_or_na($tradeSummary['win_rate']) ?></div>
        <div class="muted small-text">Open BUY trades are excluded.</div>
    </div>
</section>
<section class="panel">
    <h2>Closed Trade Lifecycle</h2>
    <table>
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Buy Date</th>
                <th>Buy Price</th>
                <th>Sell Date</th>
                <th>Sell Price</th>
                <th>Qty</th>
                <th>Holding Days</th>
                <th>Realized P/L</th>
                <th>Sell Reason</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($closedTrades as $row): ?>
            <tr>
                <td><?= h($row['symbol']) ?></td>
                <td><?= h($row['buy_date']) ?></td>
                <td><?= money($row['buy_price']) ?></td>
                <td><?= h($row['sell_date']) ?></td>
                <td><?= money($row['sell_price']) ?></td>
                <td><?= number_format((int)$row['quantity']) ?></td>
                <td><?= $row['holding_days'] === null ? 'N/A' : number_format((int)$row['holding_days']) ?></td>
                <td><?= money($row['realized_pl']) ?></td>
                <td><span class="badge <?= h($row['sell_reason'] === 'TAKE_PROFIT' ? 'CORRECT' : ($row['sell_reason'] === 'STOP_LOSS' ? 'WRONG' : 'WATCH')) ?>"><?= h($row['sell_reason']) ?></span></td>
                <td><?= h($row['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$closedTrades): ?>
            <tr><td colspan="10" class="muted">No closed trades yet. Open BUY trades are not counted as wins or losses.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
