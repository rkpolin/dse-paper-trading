<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = dashboard_db();
$allowed = ['ALL', 'BUY', 'SELL', 'HOLD', 'WATCH', 'AVOID'];
$type = strtoupper((string)($_GET['type'] ?? 'ALL'));
if (!in_array($type, $allowed, true)) {
    $type = 'ALL';
}

$params = [];
$where = '';
if ($type !== 'ALL') {
    $where = 'WHERE s.signal_type = :type';
    $params['type'] = $type;
}

$signals = fetch_all(
    $pdo,
    "SELECT s.signal_date, st.symbol, s.signal_type, s.close_price, s.confidence, s.rsi, s.sma20, s.sma50, s.volume_ratio, s.momentum, s.breakout, s.pump_risk, s.reason
     FROM signals s
     JOIN stocks st ON st.id = s.stock_id
     $where
     ORDER BY s.signal_date DESC, s.confidence DESC
     LIMIT 200",
    $params
);

$pageTitle = 'Signals';
require __DIR__ . '/includes/header.php';
?>
<div class="filters">
    <?php foreach ($allowed as $option): ?>
        <a class="<?= $type === $option ? 'active' : '' ?>" href="signals.php?type=<?= h($option) ?>"><?= h($option) ?></a>
    <?php endforeach; ?>
</div>
<section class="panel">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Symbol</th>
                <th>Signal</th>
                <th>Close</th>
                <th>Confidence</th>
                <th>RSI</th>
                <th>SMA20</th>
                <th>SMA50</th>
                <th>Vol Ratio</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($signals as $row): ?>
            <tr>
                <td><?= h($row['signal_date']) ?></td>
                <td><?= h($row['symbol']) ?></td>
                <td><span class="badge <?= h($row['signal_type']) ?>"><?= h($row['signal_type']) ?></span></td>
                <td><?= money($row['close_price']) ?></td>
                <td><?= pct($row['confidence']) ?></td>
                <td><?= number_format((float)$row['rsi'], 2) ?></td>
                <td><?= money($row['sma20']) ?></td>
                <td><?= money($row['sma50']) ?></td>
                <td><?= number_format((float)$row['volume_ratio'], 2) ?></td>
                <td><?= h($row['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$signals): ?>
            <tr><td colspan="10" class="muted">No signals found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
