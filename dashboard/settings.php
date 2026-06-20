<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = dashboard_db();
$runs = fetch_all(
    $pdo,
    'SELECT run_id, started_at, completed_at, status, source, latest_data_date, created_at
     FROM system_runs
     ORDER BY created_at DESC
     LIMIT 20'
);
$logs = fetch_all(
    $pdo,
    'SELECT run_id, status, message, remote_addr, created_at
     FROM api_logs
     ORDER BY created_at DESC
     LIMIT 20'
);

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
?>
<section class="grid cols-2">
    <div class="panel">
        <h2>Paper Trading Rules</h2>
        <table>
            <tbody>
                <tr><th>Initial balance</th><td>100,000 BDT</td></tr>
                <tr><th>Max position size</th><td>10% per stock</td></tr>
                <tr><th>Max open positions</th><td>5</td></tr>
                <tr><th>Transaction cost</th><td>0.5% per buy/sell side</td></tr>
                <tr><th>Stop loss</th><td>-5%</td></tr>
                <tr><th>Take profit</th><td>+8%</td></tr>
                <tr><th>Evaluation window</th><td>5 trading days</td></tr>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>Recent API Logs</h2>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Run</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $row): ?>
                <tr>
                    <td><?= h($row['created_at']) ?></td>
                    <td><span class="badge <?= h($row['status'] === 'SUCCESS' ? 'CORRECT' : 'WRONG') ?>"><?= h($row['status']) ?></span></td>
                    <td><?= h($row['run_id']) ?></td>
                    <td><?= h($row['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr><td colspan="4" class="muted">No API logs yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>System Runs</h2>
    <table>
        <thead>
            <tr>
                <th>Run ID</th>
                <th>Status</th>
                <th>Started</th>
                <th>Completed</th>
                <th>Latest Data</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $row): ?>
            <tr>
                <td><?= h($row['run_id']) ?></td>
                <td><?= h($row['status']) ?></td>
                <td><?= h($row['started_at']) ?></td>
                <td><?= h($row['completed_at']) ?></td>
                <td><?= h($row['latest_data_date']) ?></td>
                <td><?= h($row['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$runs): ?>
            <tr><td colspan="6" class="muted">No runs yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
