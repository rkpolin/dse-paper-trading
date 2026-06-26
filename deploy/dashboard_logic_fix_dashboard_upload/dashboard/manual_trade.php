<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trade_ledger.php';
require_login();

const DASHBOARD_MANUAL_RUN_ID = 'dashboard-manual-trades';

function dashboard_manual_ensure_stock(PDO $pdo, string $symbol): int
{
    $row = fetch_one($pdo, 'SELECT id FROM stocks WHERE symbol = :symbol LIMIT 1', ['symbol' => $symbol]);
    if ($row !== null) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO stocks (symbol, name) VALUES (:symbol, :name)');
    $stmt->execute([
        'symbol' => $symbol,
        'name' => $symbol,
    ]);
    return (int)$pdo->lastInsertId();
}

function dashboard_manual_upsert_run(PDO $pdo, string $latestDataDate): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO system_runs (run_id, started_at, completed_at, status, source, latest_data_date)
         VALUES (:run_id, :started_at, :completed_at, 'SUCCESS', 'dashboard_manual', :latest_data_date)
         ON DUPLICATE KEY UPDATE
            completed_at = VALUES(completed_at),
            latest_data_date = VALUES(latest_data_date),
            updated_at = CURRENT_TIMESTAMP"
    );
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $stmt->execute([
        'run_id' => DASHBOARD_MANUAL_RUN_ID,
        'started_at' => $now,
        'completed_at' => $now,
        'latest_data_date' => $latestDataDate,
    ]);
}

function dashboard_insert_manual_trade(PDO $pdo, array $trade): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO paper_trades
            (trade_id, run_id, stock_id, trade_date, side, quantity, price, gross_value, transaction_cost, net_value, realized_pl, source, entry_trade_id, reason)
         VALUES
            (:trade_id, :run_id, :stock_id, :trade_date, :side, :quantity, :price, :gross_value, :transaction_cost, :net_value, :realized_pl, :source, :entry_trade_id, :reason)'
    );
    $stmt->execute($trade);
}

$pdo = dashboard_db();
$latestRunId = latest_daily_run_id($pdo);
$errors = [];
$successMessage = null;

$tradeRows = fetch_trade_rows_for_dashboard($pdo, $latestRunId);
$ledger = build_trade_ledger($tradeRows);
$openLots = $ledger['open_lots'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!dashboard_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session token mismatch. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $symbol = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['symbol'] ?? '')));
        $tradeDate = trim((string)($_POST['trade_date'] ?? ''));
        $price = is_numeric($_POST['price'] ?? null) ? (float)$_POST['price'] : 0.0;
        $quantity = is_numeric($_POST['quantity'] ?? null) ? (int)$_POST['quantity'] : 0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tradeDate)) {
            $errors[] = 'Trade date must be YYYY-MM-DD.';
        }
        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($quantity <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }

        if (!$errors && $action === 'manual_buy') {
            if ($symbol === '') {
                $errors[] = 'Symbol is required for manual BUY.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stockId = dashboard_manual_ensure_stock($pdo, $symbol);
                    $grossValue = $price * $quantity;
                    $transactionCost = round($grossValue * DASHBOARD_TRANSACTION_COST_PCT, 4);
                    $netValue = round($grossValue + $transactionCost, 4);
                    $tradeId = hash('sha256', 'manual-buy|' . $symbol . '|' . $tradeDate . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
                    dashboard_manual_upsert_run($pdo, $tradeDate);
                    dashboard_insert_manual_trade($pdo, [
                        'trade_id' => $tradeId,
                        'run_id' => DASHBOARD_MANUAL_RUN_ID,
                        'stock_id' => $stockId,
                        'trade_date' => $tradeDate,
                        'side' => 'BUY',
                        'quantity' => $quantity,
                        'price' => round($price, 4),
                        'gross_value' => round($grossValue, 4),
                        'transaction_cost' => $transactionCost,
                        'net_value' => $netValue,
                        'realized_pl' => 0.0,
                        'source' => 'MANUAL',
                        'entry_trade_id' => null,
                        'reason' => substr(trim((string)($_POST['reason'] ?? 'MANUAL_BUY')) ?: 'MANUAL_BUY', 0, 50),
                    ]);
                    $pdo->commit();
                    $successMessage = 'Manual paper BUY saved.';
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Manual BUY could not be saved right now.';
                }
            }
        } elseif (!$errors && $action === 'manual_sell') {
            $entryTradeId = trim((string)($_POST['entry_trade_id'] ?? ''));
            $sellReason = trim((string)($_POST['reason'] ?? 'MANUAL_SELL'));
            $allowedSellReasons = ['TAKE_PROFIT', 'STOP_LOSS', 'SELL_SIGNAL', 'MANUAL_SELL'];
            if (!in_array($sellReason, $allowedSellReasons, true)) {
                $errors[] = 'Sell reason is invalid.';
            }

            $selectedLot = null;
            foreach ($openLots as $lot) {
                if ((string)$lot['trade_id'] === $entryTradeId) {
                    $selectedLot = $lot;
                    break;
                }
            }

            if ($selectedLot === null) {
                $errors[] = 'Selected open position is no longer available.';
            } elseif ($quantity > (int)$selectedLot['remaining_quantity']) {
                $errors[] = 'Sell quantity is larger than the remaining open quantity.';
            }

            if ($selectedLot !== null && $tradeDate < (string)$selectedLot['buy_date']) {
                $errors[] = 'Sell date cannot be earlier than the selected buy date.';
            }

            if (!$errors && $selectedLot !== null) {
                try {
                    $pdo->beginTransaction();
                    $stockId = (int)$selectedLot['stock_id'];
                    $grossValue = $price * $quantity;
                    $transactionCost = round($grossValue * DASHBOARD_TRANSACTION_COST_PCT, 4);
                    $netValue = round($grossValue - $transactionCost, 4);
                    $costBasisShare = ((float)$selectedLot['remaining_cost_basis'] / max(1, (int)$selectedLot['remaining_quantity'])) * $quantity;
                    $tradeId = hash('sha256', 'manual-sell|' . $selectedLot['symbol'] . '|' . $tradeDate . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
                    dashboard_manual_upsert_run($pdo, $tradeDate);
                    dashboard_insert_manual_trade($pdo, [
                        'trade_id' => $tradeId,
                        'run_id' => DASHBOARD_MANUAL_RUN_ID,
                        'stock_id' => $stockId,
                        'trade_date' => $tradeDate,
                        'side' => 'SELL',
                        'quantity' => $quantity,
                        'price' => round($price, 4),
                        'gross_value' => round($grossValue, 4),
                        'transaction_cost' => $transactionCost,
                        'net_value' => $netValue,
                        'realized_pl' => round($netValue - $costBasisShare, 4),
                        'source' => 'MANUAL',
                        'entry_trade_id' => $entryTradeId,
                        'reason' => $sellReason,
                    ]);
                    $pdo->commit();
                    $successMessage = 'Manual paper SELL saved.';
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Manual SELL could not be saved right now.';
                }
            }
        }
    }

    if (!$errors) {
        header('Location: manual_trade.php?success=' . urlencode($successMessage ?? 'Saved'));
        exit;
    }
}

if (isset($_GET['success']) && is_scalar($_GET['success'])) {
    $successMessage = (string)$_GET['success'];
}

$pageTitle = 'Manual Trade';
require __DIR__ . '/includes/header.php';
?>
<section class="notice">Paper trading only. Not financial advice. Manual entries affect dashboard reporting only and do not place real orders.</section>

<?php if ($successMessage): ?>
    <section class="notice"><?= h($successMessage) ?></section>
<?php endif; ?>

<?php if ($errors): ?>
    <section class="error"><?= h(implode(' ', $errors)) ?></section>
<?php endif; ?>

<section class="grid cols-2">
    <div class="panel">
        <h2>Manual BUY</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(dashboard_csrf_token()) ?>">
            <input type="hidden" name="action" value="manual_buy">
            <div class="field">
                <label for="buy-symbol">Symbol</label>
                <input id="buy-symbol" name="symbol" type="text" maxlength="30" required>
            </div>
            <div class="field">
                <label for="buy-date">Date</label>
                <input id="buy-date" name="trade_date" type="date" value="<?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="buy-price">Price</label>
                <input id="buy-price" name="price" type="number" min="0.01" step="0.01" required>
            </div>
            <div class="field">
                <label for="buy-quantity">Quantity</label>
                <input id="buy-quantity" name="quantity" type="number" min="1" step="1" required>
            </div>
            <div class="field">
                <label for="buy-reason">Reason</label>
                <input id="buy-reason" name="reason" type="text" maxlength="50" value="MANUAL_BUY">
            </div>
            <button type="submit">Save Manual BUY</button>
        </form>
    </div>

    <div class="panel">
        <h2>Manual SELL</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(dashboard_csrf_token()) ?>">
            <input type="hidden" name="action" value="manual_sell">
            <div class="field">
                <label for="sell-position">Open Position</label>
                <select id="sell-position" name="entry_trade_id" required>
                    <option value="">Select open lot</option>
                    <?php foreach ($openLots as $lot): ?>
                        <option value="<?= h((string)$lot['trade_id']) ?>">
                            <?= h((string)$lot['symbol'] . ' | Buy ' . (string)$lot['buy_date'] . ' | Qty ' . (string)$lot['remaining_quantity'] . ' | Source ' . (string)$lot['source']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="sell-date">Date</label>
                <input id="sell-date" name="trade_date" type="date" value="<?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="sell-price">Price</label>
                <input id="sell-price" name="price" type="number" min="0.01" step="0.01" required>
            </div>
            <div class="field">
                <label for="sell-quantity">Quantity</label>
                <input id="sell-quantity" name="quantity" type="number" min="1" step="1" required>
            </div>
            <div class="field">
                <label for="sell-reason">Reason</label>
                <select id="sell-reason" name="reason">
                    <option value="MANUAL_SELL">MANUAL_SELL</option>
                    <option value="TAKE_PROFIT">TAKE_PROFIT</option>
                    <option value="STOP_LOSS">STOP_LOSS</option>
                    <option value="SELL_SIGNAL">SELL_SIGNAL</option>
                </select>
            </div>
            <button type="submit">Save Manual SELL</button>
        </form>
    </div>
</section>

<section class="panel">
    <h2>Currently Open Lots</h2>
    <table>
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Buy Date</th>
                <th>Buy Price</th>
                <th>Remaining Qty</th>
                <th>Remaining Cost Basis</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($openLots as $lot): ?>
            <tr>
                <td><?= h((string)$lot['symbol']) ?></td>
                <td><?= h((string)$lot['buy_date']) ?></td>
                <td><?= money((float)$lot['buy_price']) ?></td>
                <td><?= number_format((int)$lot['remaining_quantity']) ?></td>
                <td><?= money((float)$lot['remaining_cost_basis']) ?></td>
                <td><?= h((string)$lot['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$openLots): ?>
            <tr><td colspan="6" class="muted">No open lots available for manual SELL.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
