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

$focusAllowed = ['ALL', 'BUY_READY', 'TOP5'];
$focus = strtoupper((string)($_GET['focus'] ?? 'ALL'));
if (!in_array($focus, $focusAllowed, true)) {
    $focus = 'ALL';
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

$priceUniverse = [];
$preparedSignals = [];
foreach ($signals as $row) {
    $recentPrices = signal_recent_prices($pricesByStock, (int)$row['stock_id'], (string)$row['signal_date']);
    $pumpRisk = calculate_pump_risk_display($row, $recentPrices);
    $entryZone = calculate_entry_zone_display($row, $recentPrices, $pumpRisk);
    $explanation = build_signal_explanation($row, $pumpRisk);
    $explanationBn = build_signal_explanation_bn($row, $pumpRisk, $entryZone);
    $entryActionClass = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$entryZone['action'])), '-');
    $detailId = 'signal-detail-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$row['symbol']) . '-' . preg_replace('/[^0-9]/', '', (string)$row['signal_date']);

    if ((string)$row['signal_type'] === 'BUY' && ($entryZone['available'] ?? false) && (float)$row['close_price'] > 0.0) {
        $priceUniverse[] = (float)$row['close_price'];
    }

    $summaryBits = [$explanation['why']];
    if ($entryZone['available']) {
        $summaryBits[] = 'Preferred zone: ' . format_price_range((float)$entryZone['preferred_low'], (float)$entryZone['preferred_high']) . '.';
    } else {
        $summaryBits[] = (string)$entryZone['reason'];
    }
    if (!empty($explanation['risk_factors'][0])) {
        $summaryBits[] = 'Main risk: ' . (string)$explanation['risk_factors'][0];
    }

    $preparedSignals[] = [
        'row' => $row,
        'recent_prices' => $recentPrices,
        'pump_risk' => $pumpRisk,
        'entry_zone' => $entryZone,
        'explanation' => $explanation,
        'explanation_bn' => $explanationBn,
        'entry_action_class' => $entryActionClass,
        'detail_id' => $detailId,
        'summary_text' => implode(' ', $summaryBits),
    ];
}

$minPrice = $priceUniverse ? min($priceUniverse) : 0.0;
$maxPrice = $priceUniverse ? max($priceUniverse) : 0.0;
$rankableSignals = [];

foreach ($preparedSignals as &$signalView) {
    $price = (float)($signalView['row']['close_price'] ?? 0.0);
    if ($maxPrice > $minPrice && $price > 0.0) {
        $affordabilityScore = (($maxPrice - $price) / ($maxPrice - $minPrice)) * 100.0;
    } elseif ($price > 0.0) {
        $affordabilityScore = 50.0;
    } else {
        $affordabilityScore = 0.0;
    }

    $buyRank = calculate_buy_rank_display(
        $signalView['row'],
        $signalView['entry_zone'],
        $signalView['pump_risk'],
        $affordabilityScore
    );
    $signalView['buy_rank'] = $buyRank;
    if ($buyRank['eligible']) {
        $rankableSignals[] = &$signalView;
    }
}
unset($signalView);

usort(
    $rankableSignals,
    static function (array $left, array $right): int {
        $scoreCompare = (float)$right['buy_rank']['score'] <=> (float)$left['buy_rank']['score'];
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        return (float)$left['row']['close_price'] <=> (float)$right['row']['close_price'];
    }
);

$rankMap = [];
foreach ($rankableSignals as $index => $rankedSignal) {
    $rankKey = (string)$rankedSignal['detail_id'];
    $rankMap[$rankKey] = $index + 1;
}

$displaySignals = [];
foreach ($preparedSignals as $signalView) {
    $signalView['buy_rank_position'] = $rankMap[(string)$signalView['detail_id']] ?? null;
    if (in_array($focus, ['BUY_READY', 'TOP5'], true) && !($signalView['buy_rank']['eligible_for_focus'] ?? false)) {
        continue;
    }
    $displaySignals[] = $signalView;
}

if (in_array($focus, ['BUY_READY', 'TOP5'], true) || $type === 'BUY') {
    usort(
        $displaySignals,
        static function (array $left, array $right): int {
            $leftRank = (int)($left['buy_rank_position'] ?? 999999);
            $rightRank = (int)($right['buy_rank_position'] ?? 999999);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }
            return strcmp((string)$right['row']['signal_date'], (string)$left['row']['signal_date']);
        }
    );
}

if ($focus === 'TOP5') {
    $displaySignals = array_slice($displaySignals, 0, 5);
}

$showQuickBuy = $focus === 'TOP5';

$pageTitle = 'Signals';
require __DIR__ . '/includes/header.php';
?>
<div class="filters">
    <?php foreach ($allowed as $option): ?>
        <a class="<?= $type === $option ? 'active' : '' ?>" href="signals.php?type=<?= h($option) ?>&focus=<?= h($focus) ?>"><?= h($option) ?></a>
    <?php endforeach; ?>
    <a class="<?= $focus === 'ALL' ? 'active' : '' ?>" href="signals.php?type=<?= h($type) ?>&focus=ALL">All Rows</a>
    <a class="<?= $focus === 'BUY_READY' ? 'active' : '' ?>" href="signals.php?type=<?= h($type) ?>&focus=BUY_READY">Buy Rank</a>
    <a class="<?= $focus === 'TOP5' ? 'active' : '' ?>" href="signals.php?type=<?= h($type) ?>&focus=TOP5">Top 5 Buy</a>
</div>
<div class="notice">Paper trading only. Buy Rank is a decision aid based on Signal Score, entry action, pump risk, upside, and affordability. It is not financial advice or a guaranteed profit signal.</div>

<section class="panel">
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Date</th>
                <th>Symbol</th>
                <th>Signal</th>
                <th>Current Price</th>
                <th>Signal Score</th>
                <th>Entry Action</th>
                <th>Pump Risk</th>
                <th>Buy Rank</th>
                <th>Summary</th>
                <?php if ($showQuickBuy): ?>
                    <th>Quick Buy</th>
                <?php endif; ?>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($displaySignals as $signalView): ?>
            <?php
            $row = $signalView['row'];
            $entryZone = $signalView['entry_zone'];
            $pumpRisk = $signalView['pump_risk'];
            $explanation = $signalView['explanation'];
            $explanationBn = $signalView['explanation_bn'];
            $buyRank = $signalView['buy_rank'];
            ?>
            <tr>
                <td>
                    <?php if ($signalView['buy_rank_position'] !== null): ?>
                        <span class="rank-pill">#<?= h((string)$signalView['buy_rank_position']) ?></span>
                    <?php else: ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
                <td><?= h((string)$row['signal_date']) ?></td>
                <td><?= h((string)$row['symbol']) ?></td>
                <td><span class="badge <?= h((string)$row['signal_type']) ?>"><?= h((string)$row['signal_type']) ?></span></td>
                <td><?= money((float)$row['close_price']) ?></td>
                <td><?= pct((float)$row['confidence']) ?></td>
                <td>
                    <span class="badge action-<?= h($signalView['entry_action_class'] ?: 'na') ?>"><?= h((string)$entryZone['action']) ?></span>
                    <div class="muted small-text"><?= h((string)$entryZone['reason']) ?></div>
                </td>
                <td>
                    <span class="badge risk-<?= h(strtolower((string)$pumpRisk['level'])) ?>"><?= h((string)$pumpRisk['level']) ?></span>
                    <div><?= number_format((int)$pumpRisk['score']) ?>/100</div>
                </td>
                <td class="rank-cell">
                    <?php if ($buyRank['eligible']): ?>
                        <div><strong><?= number_format((float)$buyRank['score'], 2) ?>/100</strong></div>
                        <div class="muted small-text"><?= h((string)$buyRank['label']) ?></div>
                        <div class="muted small-text"><?= h((string)$buyRank['reason']) ?></div>
                    <?php else: ?>
                        <span class="muted"><?= h((string)$buyRank['reason']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="summary-cell"><?= h((string)$signalView['summary_text']) ?></td>
                <?php if ($showQuickBuy): ?>
                    <td>
                        <?php if (($buyRank['eligible_for_focus'] ?? false) === true): ?>
                            <?php
                            $quickBuyQuery = http_build_query([
                                'symbol' => (string)$row['symbol'],
                                'target_price' => number_format((float)$row['close_price'], 2, '.', ''),
                                'quantity' => 1,
                                'reason' => 'QUICK_BUY',
                            ]);
                            $quickBuyUrl = 'manual_trade.php?' . $quickBuyQuery . '#buy-form';
                            ?>
                            <a class="button compact-link" href="<?= h($quickBuyUrl) ?>">Quick Buy</a>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td>
                    <button
                        type="button"
                        class="compact-button signal-expand-toggle"
                        data-target="<?= h((string)$signalView['detail_id']) ?>"
                        aria-expanded="false"
                    >
                        See more
                    </button>
                </td>
            </tr>
            <tr id="<?= h((string)$signalView['detail_id']) ?>" class="signal-detail-row" hidden>
                <td colspan="<?= $showQuickBuy ? '12' : '11' ?>">
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
                            <div><strong>Bangla:</strong> <?= h((string)$explanationBn['why']) ?></div>
                            <div><strong>RSI:</strong> <?= h((string)$explanation['rsi']) ?></div>
                            <div><strong>RSI Bangla:</strong> <?= h((string)$explanationBn['rsi']) ?></div>
                            <div><strong>SMA20/SMA50:</strong> <?= h((string)$explanation['trend']) ?></div>
                            <div><strong>Trend Bangla:</strong> <?= h((string)$explanationBn['trend']) ?></div>
                            <div><strong>Volume:</strong> <?= h((string)$explanation['volume']) ?></div>
                            <div><strong>Volume Bangla:</strong> <?= h((string)$explanationBn['volume']) ?></div>
                            <div><strong>Momentum:</strong> <?= h((string)$explanation['momentum']) ?></div>
                            <div><strong>Momentum Bangla:</strong> <?= h((string)$explanationBn['momentum']) ?></div>
                            <div><strong>Pump Risk Bangla:</strong> <?= h((string)$explanationBn['pump_risk']) ?></div>
                            <div><strong>Entry Zone Bangla:</strong> <?= h((string)$explanationBn['entry']) ?></div>
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
        <?php if (!$displaySignals): ?>
            <tr><td colspan="<?= $showQuickBuy ? '12' : '11' ?>" class="muted">No signals found for this filter.</td></tr>
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
