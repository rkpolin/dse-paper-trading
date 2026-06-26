<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/signal_analysis.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_daily_run_id($pdo);
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
                <th>Entry Action</th>
                <th>Pump Risk</th>
                <th>Summary</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($signals as $row): ?>
            <?php
            $recentPrices = signal_recent_prices($pricesByStock, (int)$row['stock_id'], (string)$row['signal_date']);
            $pumpRisk = calculate_pump_risk_display($row, $recentPrices);
            $entryZone = calculate_entry_zone_display($row, $recentPrices, $pumpRisk);
            $explanation = build_signal_explanation($row, $pumpRisk);
            $explanationBn = build_signal_explanation_bn($row, $pumpRisk, $entryZone);
            $entryActionClass = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$entryZone['action'])), '-');
            $detailId = 'signal-detail-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$row['symbol']) . '-' . preg_replace('/[^0-9]/', '', (string)$row['signal_date']);

            $summaryBits = [$explanation['why']];
            if ($entryZone['available']) {
                $summaryBits[] = 'Preferred zone: ' . format_price_range($entryZone['preferred_low'], $entryZone['preferred_high']) . '.';
            } else {
                $summaryBits[] = $entryZone['reason'];
            }
            if (!empty($explanation['risk_factors'][0])) {
                $summaryBits[] = 'Main risk: ' . $explanation['risk_factors'][0];
            }
            ?>
            <tr>
                <td><?= h((string)$row['signal_date']) ?></td>
                <td><?= h((string)$row['symbol']) ?></td>
                <td><span class="badge <?= h((string)$row['signal_type']) ?>"><?= h((string)$row['signal_type']) ?></span></td>
                <td><?= money((float)$row['close_price']) ?></td>
                <td><?= pct((float)$row['confidence']) ?></td>
                <td>
                    <span class="badge action-<?= h($entryActionClass ?: 'na') ?>"><?= h((string)$entryZone['action']) ?></span>
                    <div class="muted small-text"><?= h((string)$entryZone['reason']) ?></div>
                </td>
                <td>
                    <span class="badge risk-<?= h(strtolower((string)$pumpRisk['level'])) ?>"><?= h((string)$pumpRisk['level']) ?></span>
                    <div><?= number_format((int)$pumpRisk['score']) ?>/100</div>
                </td>
                <td class="summary-cell"><?= h(implode(' ', $summaryBits)) ?></td>
                <td>
                    <button
                        type="button"
                        class="compact-button signal-expand-toggle"
                        data-target="<?= h($detailId) ?>"
                        aria-expanded="false"
                    >
                        See more
                    </button>
                </td>
            </tr>
            <tr id="<?= h($detailId) ?>" class="signal-detail-row" hidden>
                <td colspan="9">
                    <div class="signal-detail-grid">
                        <div class="signal-detail-block">
                            <h3>Entry Zone</h3>
                            <?php if ($entryZone['available']): ?>
                                <div><strong>Current:</strong> <?= money((float)$entryZone['current_price']) ?></div>
                                <div><strong>Preferred:</strong> <?= h(format_price_range((float)$entryZone['preferred_low'], (float)$entryZone['preferred_high'])) ?></div>
                                <div><strong>Aggressive:</strong> <?= h(format_price_range((float)$entryZone['aggressive_low'], (float)$entryZone['aggressive_high'])) ?></div>
                                <div><strong>Avoid above:</strong> <?= money((float)$entryZone['avoid_above']) ?></div>
                                <div><strong>Stop:</strong> <?= money((float)$entryZone['stop_loss']) ?></div>
                                <div><strong>Targets:</strong> <?= money((float)$entryZone['target1']) ?> / <?= money((float)$entryZone['target2']) ?></div>
                            <?php else: ?>
                                <div class="muted"><?= h((string)$entryZone['reason']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="signal-detail-block">
                            <h3>Signal Explanation</h3>
                            <div><strong>Why:</strong> <?= h((string)$explanation['why']) ?></div>
                            <div><strong>বাংলা:</strong> <?= h((string)$explanationBn['why']) ?></div>
                            <div><strong>RSI:</strong> <?= h((string)$explanation['rsi']) ?></div>
                            <div><strong>RSI বাংলা:</strong> <?= h((string)$explanationBn['rsi']) ?></div>
                            <div><strong>SMA20/SMA50:</strong> <?= h((string)$explanation['trend']) ?></div>
                            <div><strong>Trend বাংলা:</strong> <?= h((string)$explanationBn['trend']) ?></div>
                            <div><strong>Volume:</strong> <?= h((string)$explanation['volume']) ?></div>
                            <div><strong>Volume বাংলা:</strong> <?= h((string)$explanationBn['volume']) ?></div>
                            <div><strong>Momentum:</strong> <?= h((string)$explanation['momentum']) ?></div>
                            <div><strong>Momentum বাংলা:</strong> <?= h((string)$explanationBn['momentum']) ?></div>
                            <div><strong>Pump Risk বাংলা:</strong> <?= h((string)$explanationBn['pump_risk']) ?></div>
                            <div><strong>Entry Zone বাংলা:</strong> <?= h((string)$explanationBn['entry']) ?></div>
                            <div><strong>Invalid if:</strong> <?= h(implode(' ', $explanation['invalid'])) ?></div>
                        </div>
                        <div class="signal-detail-block">
                            <h3>Risk Factors</h3>
                            <ul class="compact-list">
                                <?php foreach ($explanation['risk_factors'] as $risk): ?>
                                    <li><?= h((string)$risk) ?></li>
                                <?php endforeach; ?>
                                <?php if (!$explanation['risk_factors']): ?>
                                    <li class="muted">No major extra risk flags.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$signals): ?>
            <tr><td colspan="9" class="muted">No signals found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<script>
document.querySelectorAll('.signal-expand-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target');
        const detailRow = targetId ? document.getElementById(targetId) : null;
        if (!detailRow) {
            return;
        }
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        button.textContent = expanded ? 'See more' : 'See less';
        detailRow.hidden = expanded;
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
