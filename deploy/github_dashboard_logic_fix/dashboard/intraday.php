<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

function intraday_market_status(DateTimeImmutable $now): array
{
    $buckets = ['10:05', '10:20', '10:35', '10:50', '11:05', '11:20', '11:35', '11:50', '12:05', '12:20', '12:35', '12:50', '13:05', '13:20', '13:35', '13:50', '14:05'];
    $today = $now->format('Y-m-d');
    $weekday = (int)$now->format('N');
    $isMarketDay = !in_array($weekday, [5, 6], true);
    $currentHm = $now->format('H:i');
    $marketOpen = $isMarketDay && $currentHm >= '10:05' && $currentHm <= '14:05';

    $nextExpected = null;
    foreach ($buckets as $bucket) {
        if ($bucket > $currentHm) {
            $nextExpected = $today . ' ' . $bucket . ':00';
            break;
        }
    }
    if ($nextExpected === null && $isMarketDay && $currentHm <= '10:05') {
        $nextExpected = $today . ' 10:05:00';
    }

    return [
        'status' => $marketOpen ? 'OPEN' : 'CLOSED',
        'is_market_day' => $isMarketDay,
        'next_expected' => $nextExpected,
    ];
}

$pdo = dashboard_db();
$latestIntradayRunId = latest_intraday_run_id($pdo);
$symbols = fetch_all(
    $pdo,
    'SELECT DISTINCT st.symbol
     FROM stocks st
     JOIN intraday_snapshots snap ON snap.stock_id = st.id
     ORDER BY st.symbol'
);
$symbolList = array_map(static fn($row) => (string)$row['symbol'], $symbols);
$selectedSymbol = strtoupper((string)($_GET['symbol'] ?? ($symbolList[0] ?? '')));
if ($selectedSymbol !== '' && !in_array($selectedSymbol, $symbolList, true)) {
    $selectedSymbol = $symbolList[0] ?? '';
}

$dateRows = $selectedSymbol !== '' ? fetch_all(
    $pdo,
    'SELECT DISTINCT snap.trade_date
     FROM intraday_snapshots snap
     JOIN stocks st ON st.id = snap.stock_id
     WHERE st.symbol = :symbol
     ORDER BY snap.trade_date DESC
     LIMIT 90',
    ['symbol' => $selectedSymbol]
) : [];
$dateList = array_map(static fn($row) => (string)$row['trade_date'], $dateRows);
$selectedDate = (string)($_GET['date'] ?? ($dateList[0] ?? ''));
if ($selectedDate !== '' && !in_array($selectedDate, $dateList, true)) {
    $selectedDate = $dateList[0] ?? '';
}

$allowedLookbacks = [20, 30, 60];
$lookback = (int)($_GET['lookback'] ?? 20);
if (!in_array($lookback, $allowedLookbacks, true)) {
    $lookback = 20;
}

$extreme = ($selectedSymbol !== '' && $selectedDate !== '') ? fetch_one(
    $pdo,
    'SELECT e.*
     FROM daily_intraday_extremes e
     JOIN stocks st ON st.id = e.stock_id
     WHERE st.symbol = :symbol AND e.trade_date = :trade_date
     LIMIT 1',
    ['symbol' => $selectedSymbol, 'trade_date' => $selectedDate]
) : null;

$chartRows = ($selectedSymbol !== '' && $selectedDate !== '') ? fetch_all(
    $pdo,
    'SELECT snap.bucket_time, snap.last_price, snap.day_high, snap.day_low
     FROM intraday_snapshots snap
     JOIN stocks st ON st.id = snap.stock_id
     WHERE st.symbol = :symbol AND snap.trade_date = :trade_date
     ORDER BY snap.bucket_time ASC',
    ['symbol' => $selectedSymbol, 'trade_date' => $selectedDate]
) : [];

$computedDate = $selectedSymbol !== '' ? fetch_one(
    $pdo,
    'SELECT MAX(stats.computed_through_date) AS computed_through_date
     FROM intraday_time_window_stats stats
     JOIN stocks st ON st.id = stats.stock_id
     WHERE st.symbol = :symbol AND stats.lookback_days = :lookback',
    ['symbol' => $selectedSymbol, 'lookback' => $lookback]
) : null;
$computedThrough = (string)($computedDate['computed_through_date'] ?? '');

$statsRows = ($selectedSymbol !== '' && $computedThrough !== '') ? fetch_all(
    $pdo,
    'SELECT stats.*
     FROM intraday_time_window_stats stats
     JOIN stocks st ON st.id = stats.stock_id
     WHERE st.symbol = :symbol
       AND stats.lookback_days = :lookback
       AND stats.computed_through_date = :computed_through_date
     ORDER BY stats.bucket_time ASC',
    ['symbol' => $selectedSymbol, 'lookback' => $lookback, 'computed_through_date' => $computedThrough]
) : [];

$bestBuy = null;
$bestSell = null;
foreach ($statsRows as $row) {
    if ($bestBuy === null || (float)$row['buy_window_score'] > (float)$bestBuy['buy_window_score']) {
        $bestBuy = $row;
    }
    if ($bestSell === null || (float)$row['sell_window_score'] > (float)$bestSell['sell_window_score']) {
        $bestSell = $row;
    }
}

$lowDistribution = fetch_all(
    $pdo,
    'SELECT day_low_time AS bucket_time, COUNT(*) AS low_count
     FROM daily_intraday_extremes
     WHERE is_complete = 1
     GROUP BY day_low_time'
);
$highDistribution = fetch_all(
    $pdo,
    'SELECT day_high_time AS bucket_time, COUNT(*) AS high_count
     FROM daily_intraday_extremes
     WHERE is_complete = 1
     GROUP BY day_high_time'
);
$distribution = [];
foreach ($lowDistribution as $row) {
    $bucket = substr((string)$row['bucket_time'], 0, 5);
    $distribution[$bucket]['bucket_time'] = $bucket;
    $distribution[$bucket]['low_count'] = (int)$row['low_count'];
    $distribution[$bucket]['high_count'] = $distribution[$bucket]['high_count'] ?? 0;
}
foreach ($highDistribution as $row) {
    $bucket = substr((string)$row['bucket_time'], 0, 5);
    $distribution[$bucket]['bucket_time'] = $bucket;
    $distribution[$bucket]['low_count'] = $distribution[$bucket]['low_count'] ?? 0;
    $distribution[$bucket]['high_count'] = (int)$row['high_count'];
}
ksort($distribution);

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));
$marketInfo = intraday_market_status($now);
$lastSnapshot = fetch_one(
    $pdo,
    'SELECT snap.trade_date, snap.snapshot_time, snap.bucket_time, snap.snapshot_at
     FROM intraday_snapshots snap
     ORDER BY snap.trade_date DESC, snap.bucket_time DESC, snap.snapshot_time DESC
     LIMIT 1'
);
$latestIntradayRun = $latestIntradayRunId ? fetch_one(
    $pdo,
    'SELECT run_id, started_at, completed_at, status, latest_data_date, created_at
     FROM system_runs
     WHERE run_id = :run_id
     LIMIT 1',
    ['run_id' => $latestIntradayRunId]
) : null;
$skipMessage = 'No skip detected.';
if ($marketInfo['status'] === 'CLOSED') {
    $skipMessage = 'Market is closed now, so scheduled intraday runs outside market hours are skipped.';
} elseif ($lastSnapshot === null) {
    $skipMessage = 'No intraday snapshot has been saved yet for today.';
} else {
    $lastSnapshotDate = (string)$lastSnapshot['trade_date'];
    $lastBucket = substr((string)$lastSnapshot['bucket_time'], 0, 5);
    $todayText = $now->format('Y-m-d');
    if ($lastSnapshotDate < $todayText) {
        $skipMessage = 'Today has no saved intraday snapshot yet. A scheduled run may have been skipped because the bucket window was missed or DSE returned no data.';
    } elseif ($marketInfo['next_expected'] !== null) {
        $skipMessage = 'Latest saved bucket is ' . $lastBucket . '. Next expected bucket is ' . substr((string)$marketInfo['next_expected'], 11, 5) . '.';
    }
}

$pageTitle = 'Intraday';
require __DIR__ . '/includes/header.php';
?>
<form class="filters filter-form" method="get">
    <label>
        Stock
        <select name="symbol">
            <?php foreach ($symbolList as $symbol): ?>
                <option value="<?= h($symbol) ?>" <?= $selectedSymbol === $symbol ? 'selected' : '' ?>><?= h($symbol) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Date
        <select name="date">
            <?php foreach ($dateList as $date): ?>
                <option value="<?= h($date) ?>" <?= $selectedDate === $date ? 'selected' : '' ?>><?= h($date) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Lookback
        <select name="lookback">
            <?php foreach ($allowedLookbacks as $option): ?>
                <option value="<?= h($option) ?>" <?= $lookback === $option ? 'selected' : '' ?>><?= h($option) ?> days</option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="button compact-button" type="submit">Apply</button>
</form>

<section class="grid cols-4">
    <div class="metric">
        <div class="label">Market Status</div>
        <div class="value"><?= h($marketInfo['status']) ?></div>
        <div class="muted"><?= h($now->format('Y-m-d H:i:s')) ?> BD</div>
    </div>
    <div class="metric">
        <div class="label">Last Update Time</div>
        <div class="value"><?= $lastSnapshot ? h((string)$lastSnapshot['snapshot_at']) : 'N/A' ?></div>
        <div class="muted"><?= $lastSnapshot ? 'Bucket ' . h(substr((string)$lastSnapshot['bucket_time'], 0, 5)) : 'No snapshots yet' ?></div>
    </div>
    <div class="metric">
        <div class="label">Next Expected Update</div>
        <div class="value"><?= $marketInfo['next_expected'] ? h((string)$marketInfo['next_expected']) : 'N/A' ?></div>
        <div class="muted"><?= $marketInfo['status'] === 'OPEN' ? 'Bangladesh time UTC+6' : 'Shown only during market schedule' ?></div>
    </div>
    <div class="metric">
        <div class="label">Latest Run</div>
        <div class="value"><?= $latestIntradayRun ? h((string)$latestIntradayRun['status']) : 'N/A' ?></div>
        <div class="muted"><?= $latestIntradayRun ? h((string)$latestIntradayRun['created_at']) : 'No intraday run logged yet' ?></div>
    </div>
</section>

<section class="notice">
    <?= h($skipMessage) ?>
</section>

<section class="grid cols-4">
    <div class="metric">
        <div class="label">High So Far</div>
        <div class="value"><?= $extreme ? money($extreme['day_high']) : 'N/A' ?></div>
        <div class="muted"><?= $extreme ? h(substr((string)$extreme['day_high_time'], 0, 5)) : '' ?></div>
    </div>
    <div class="metric">
        <div class="label">Low So Far</div>
        <div class="value"><?= $extreme ? money($extreme['day_low']) : 'N/A' ?></div>
        <div class="muted"><?= $extreme ? h(substr((string)$extreme['day_low_time'], 0, 5)) : '' ?></div>
    </div>
    <div class="metric">
        <div class="label">Best Historical Buy Window</div>
        <div class="value"><?= $bestBuy ? h(substr((string)$bestBuy['bucket_time'], 0, 5)) : 'N/A' ?></div>
        <div class="muted"><?= $bestBuy ? h($bestBuy['confidence_level']) . ' / ' . pct($bestBuy['buy_window_score']) : 'Need at least 20 days' ?></div>
    </div>
    <div class="metric">
        <div class="label">Best Historical Sell Window</div>
        <div class="value"><?= $bestSell ? h(substr((string)$bestSell['bucket_time'], 0, 5)) : 'N/A' ?></div>
        <div class="muted"><?= $bestSell ? h($bestSell['confidence_level']) . ' / ' . pct($bestSell['sell_window_score']) : 'Need at least 20 days' ?></div>
    </div>
</section>

<section class="notice">
    Paper trading only. Not financial advice. These windows are historical tendencies, not guaranteed outcomes.
</section>

<section class="panel">
    <h2>Intraday Price</h2>
    <div class="chart-wrap">
        <canvas id="intradayChart"></canvas>
    </div>
</section>

<section class="panel">
    <h2>Time Bucket Stats <?= $computedThrough !== '' ? '(through ' . h($computedThrough) . ')' : '' ?></h2>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Low Prob</th>
                <th>High Prob</th>
                <th>Avg To Close</th>
                <th>Avg Next</th>
                <th>Buy Score</th>
                <th>Sell Score</th>
                <th>Data Confidence</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($statsRows as $row): ?>
            <tr>
                <td><?= h(substr((string)$row['bucket_time'], 0, 5)) ?></td>
                <td><?= pct($row['low_probability']) ?></td>
                <td><?= pct($row['high_probability']) ?></td>
                <td><?= $row['avg_return_to_close_pct'] === null ? 'N/A' : pct($row['avg_return_to_close_pct']) ?></td>
                <td><?= $row['avg_return_next_bucket_pct'] === null ? 'N/A' : pct($row['avg_return_next_bucket_pct']) ?></td>
                <td><?= pct($row['buy_window_score']) ?></td>
                <td><?= pct($row['sell_window_score']) ?></td>
                <td><span class="badge <?= h($row['confidence_level']) ?>"><?= h($row['confidence_level']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$statsRows): ?>
            <tr><td colspan="8" class="muted">Need at least 20 intraday trading days before the stats table becomes useful.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Market-Wide High/Low Time Distribution</h2>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Low Count</th>
                <th>High Count</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($distribution as $row): ?>
            <tr>
                <td><?= h($row['bucket_time']) ?></td>
                <td><?= h($row['low_count']) ?></td>
                <td><?= h($row['high_count']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$distribution): ?>
            <tr><td colspan="3" class="muted">No complete intraday days yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
const intradayRows = <?= json_encode($chartRows, JSON_UNESCAPED_SLASHES) ?>;
new Chart(document.getElementById('intradayChart'), {
    type: 'line',
    data: {
        labels: intradayRows.map(row => String(row.bucket_time).slice(0, 5)),
        datasets: [
            {
                label: 'Last price',
                data: intradayRows.map(row => Number(row.last_price)),
                borderColor: '#0f766e',
                backgroundColor: 'rgba(15, 118, 110, 0.12)',
                tension: 0.25
            },
            {
                label: 'High so far',
                data: intradayRows.map(row => Number(row.day_high)),
                borderColor: '#157347',
                tension: 0.2
            },
            {
                label: 'Low so far',
                data: intradayRows.map(row => Number(row.day_low)),
                borderColor: '#b42318',
                tension: 0.2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { ticks: { callback: value => value.toLocaleString() } } }
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
