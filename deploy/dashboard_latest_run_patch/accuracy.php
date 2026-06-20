<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = dashboard_db();
$latestRunId = latest_run_id($pdo);
$summary = $latestRunId ? fetch_all(
    $pdo,
    "SELECT signal_type,
            SUM(status = 'CORRECT') AS correct_count,
            SUM(status = 'WRONG') AS wrong_count,
            SUM(status = 'PENDING') AS pending_count,
            SUM(status = 'NOT_APPLICABLE') AS skipped_count
     FROM accuracy_evaluations
     WHERE run_id = :run_id
     GROUP BY signal_type
     ORDER BY signal_type",
    ['run_id' => $latestRunId]
) : [];
$evaluations = $latestRunId ? fetch_all(
    $pdo,
    'SELECT a.signal_date, st.symbol, a.signal_type, a.entry_price, a.status, a.days_checked, a.max_gain_pct, a.max_drawdown_pct, a.result_note
     FROM accuracy_evaluations a
     JOIN stocks st ON st.id = a.stock_id
     WHERE a.run_id = :run_id
     ORDER BY a.signal_date DESC
     LIMIT 200',
    ['run_id' => $latestRunId]
) : [];

$pageTitle = 'Accuracy';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-2">
    <div class="panel">
        <h2>Signal Accuracy</h2>
        <div class="chart-wrap">
            <canvas id="accuracyChart"></canvas>
        </div>
    </div>
    <div class="panel">
        <h2>Summary</h2>
        <table>
            <thead>
                <tr>
                    <th>Signal</th>
                    <th>Correct</th>
                    <th>Wrong</th>
                    <th>Pending</th>
                    <th>Skipped</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($summary as $row): ?>
                <tr>
                    <td><?= h($row['signal_type']) ?></td>
                    <td><?= number_format((int)$row['correct_count']) ?></td>
                    <td><?= number_format((int)$row['wrong_count']) ?></td>
                    <td><?= number_format((int)$row['pending_count']) ?></td>
                    <td><?= number_format((int)$row['skipped_count']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$summary): ?>
                <tr><td colspan="5" class="muted">No evaluations yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Evaluations</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Symbol</th>
                <th>Signal</th>
                <th>Entry</th>
                <th>Status</th>
                <th>Days</th>
                <th>Max Gain</th>
                <th>Max Drawdown</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($evaluations as $row): ?>
            <tr>
                <td><?= h($row['signal_date']) ?></td>
                <td><?= h($row['symbol']) ?></td>
                <td><span class="badge <?= h($row['signal_type']) ?>"><?= h($row['signal_type']) ?></span></td>
                <td><?= money($row['entry_price']) ?></td>
                <td><span class="badge <?= h($row['status']) ?>"><?= h($row['status']) ?></span></td>
                <td><?= number_format((int)$row['days_checked']) ?></td>
                <td><?= $row['max_gain_pct'] === null ? '-' : pct($row['max_gain_pct']) ?></td>
                <td><?= $row['max_drawdown_pct'] === null ? '-' : pct($row['max_drawdown_pct']) ?></td>
                <td><?= h($row['result_note']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$evaluations): ?>
            <tr><td colspan="9" class="muted">No accuracy rows yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
const accuracyRows = <?= json_encode($summary, JSON_UNESCAPED_SLASHES) ?>;
new Chart(document.getElementById('accuracyChart'), {
    type: 'bar',
    data: {
        labels: accuracyRows.map(row => row.signal_type),
        datasets: [
            { label: 'Correct', data: accuracyRows.map(row => Number(row.correct_count)), backgroundColor: '#157347' },
            { label: 'Wrong', data: accuracyRows.map(row => Number(row.wrong_count)), backgroundColor: '#b42318' },
            { label: 'Pending', data: accuracyRows.map(row => Number(row.pending_count)), backgroundColor: '#a15c00' }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
