<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/signal_analysis.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_run_id($pdo);
$allowed = ['ALL', 'BUY', 'SELL', 'HOLD', 'WATCH', 'AVOID'];
$type = strtoupper((string)($_GET['type'] ?? 'ALL'));
if (!in_array($type, $allowed, true)) {
    $type = 'ALL';
}

$params = ['run_id' => $latestRunId];
$where = 'WHERE s.run_id = :run_id';
if ($type !== 'ALL') {
    $where .= ' AND s.signal_type = :type';
    $params['type'] = $type;
}

$signals = $latestRunId ? fetch_all(
    $pdo,
    "SELECT s.stock_id, s.signal_date, st.symbol, s.signal_type, s.close_price, s.confidence, s.rsi, s.sma20, s.sma50, s.volume_ratio, s.momentum, s.breakout, s.pump_risk, s.reason
     FROM signals s
     JOIN stocks st ON st.id = s.stock_id
     $where
     ORDER BY s.signal_date DESC, s.confidence DESC
     LIMIT 200",
    $params
) : [];

$pricesByStock = [];
if ($signals) {
    $stockIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['stock_id'], $signals)));
    $signalDates = array_map(static fn(array $row): string => (string)$row['signal_date'], $signals);
    $minDate = min($signalDates);
    $maxDate = max($signalDates);
    $placeholders = [];
    $priceParams = [
        'min_date' => $minDate,
        'max_date' => $maxDate,
    ];
    foreach ($stockIds as $index => $stockId) {
        $key = 'stock_' . $index;
        $placeholders[] = ':' . $key;
        $priceParams[$key] = $stockId;
    }
    $recentPriceRows = fetch_all(
        $pdo,
        'SELECT stock_id, trade_date, open_price, high_price, low_price, close_price, volume
         FROM daily_prices
         WHERE stock_id IN (' . implode(', ', $placeholders) . ')
           AND trade_date BETWEEN DATE_SUB(:min_date, INTERVAL 90 DAY) AND :max_date
         ORDER BY stock_id ASC, trade_date ASC',
        $priceParams
    );
    foreach ($recentPriceRows as $priceRow) {
        $pricesByStock[(int)$priceRow['stock_id']][] = $priceRow;
    }
}

$pageTitle = 'Signals';
require __DIR__ . '/includes/header.php';
?>
<div class="filters">
    <?php foreach ($allowed as $option): ?>
        <a class="<?= $type === $option ? 'active' : '' ?>" href="signals.php?type=<?= h($option) ?>"><?= h($option) ?></a>
    <?php endforeach; ?>
</div>
<div class="notice">Paper trading only. Signal Score, entry zones, and pump risk are learning tools, not financial advice.</div>
<section class="panel">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Symbol</th>
                <th>Signal</th>
                <th>Current Price</th>
                <th>Signal Score</th>
                <th>Entry Zone</th>
                <th>Entry Action</th>
                <th>Pump Risk</th>
                <th>Signal Explanation</th>
                <th>Risk Factors</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($signals as $row): ?>
            <?php
            $recentPrices = signal_recent_prices($pricesByStock, (int)$row['stock_id'], (string)$row['signal_date']);
            $pumpRisk = calculate_pump_risk_display($row, $recentPrices);
            $entryZone = calculate_entry_zone_display($row, $recentPrices, $pumpRisk);
            $explanation = build_signal_explanation($row, $pumpRisk);
            $entryActionClass = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$entryZone['action'])), '-');
            ?>
            <tr>
                <td><?= h($row['signal_date']) ?></td>
                <td><?= h($row['symbol']) ?></td>
                <td><span class="badge <?= h($row['signal_type']) ?>"><?= h($row['signal_type']) ?></span></td>
                <td><?= money($row['close_price']) ?></td>
                <td><?= pct($row['confidence']) ?></td>
                <td class="detail-cell">
                    <?php if ($entryZone['available']): ?>
                        <div><strong>Current:</strong> <?= money($entryZone['current_price']) ?></div>
                        <div><strong>Preferred:</strong> <?= h(format_price_range($entryZone['preferred_low'], $entryZone['preferred_high'])) ?></div>
                        <div><strong>Aggressive:</strong> <?= h(format_price_range($entryZone['aggressive_low'], $entryZone['aggressive_high'])) ?></div>
                        <div><strong>Avoid above:</strong> <?= money($entryZone['avoid_above']) ?></div>
                        <div><strong>Stop:</strong> <?= money($entryZone['stop_loss']) ?></div>
                        <div><strong>Targets:</strong> <?= money($entryZone['target1']) ?> / <?= money($entryZone['target2']) ?></div>
                    <?php else: ?>
                        <span class="muted"><?= h($entryZone['reason']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge action-<?= h($entryActionClass ?: 'na') ?>"><?= h($entryZone['action']) ?></span>
                    <div class="muted small-text"><?= h($entryZone['reason']) ?></div>
                </td>
                <td>
                    <span class="badge risk-<?= h(strtolower($pumpRisk['level'])) ?>"><?= h($pumpRisk['level']) ?></span>
                    <div><?= number_format((int)$pumpRisk['score']) ?>/100</div>
                </td>
                <td class="detail-cell wide-cell">
                    <div><?= h($explanation['why']) ?></div>
                    <div><strong>RSI:</strong> <?= h($explanation['rsi']) ?></div>
                    <div><strong>SMA20/SMA50:</strong> <?= h($explanation['trend']) ?></div>
                    <div><strong>Volume:</strong> <?= h($explanation['volume']) ?></div>
                    <div><strong>Momentum:</strong> <?= h($explanation['momentum']) ?></div>
                    <div><strong>Invalid if:</strong> <?= h(implode(' ', $explanation['invalid'])) ?></div>
                </td>
                <td class="detail-cell">
                    <ul class="compact-list">
                        <?php foreach ($explanation['risk_factors'] as $risk): ?>
                            <li><?= h($risk) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$signals): ?>
            <tr><td colspan="10" class="muted">No signals found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
