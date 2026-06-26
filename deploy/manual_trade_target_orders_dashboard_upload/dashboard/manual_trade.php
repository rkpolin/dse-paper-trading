<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trade_ledger.php';
require_login();

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

$pdo = dashboard_db();
$latestRunId = latest_daily_run_id($pdo);
$errors = [];
$successMessage = null;

$orderProcess = process_pending_manual_orders($pdo, $latestRunId);
$tradeRows = fetch_trade_rows_for_dashboard($pdo, $latestRunId);
$ledger = build_trade_ledger($tradeRows);
$openLots = $ledger['open_lots'];
$stockRows = fetch_stocks_with_latest_prices($pdo);
$manualOrders = fetch_manual_orders($pdo);
$stockPriceMap = [];
foreach ($stockRows as $stockRow) {
    $stockPriceMap[(string)$stockRow['symbol']] = [
        'close_price' => $stockRow['close_price'] !== null ? (float)$stockRow['close_price'] : null,
        'latest_trade_date' => (string)($stockRow['latest_trade_date'] ?? ''),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!dashboard_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session token mismatch. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $symbol = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['symbol'] ?? '')));
        $tradeDate = trim((string)($_POST['requested_date'] ?? ''));
        $price = is_numeric($_POST['target_price'] ?? null) ? (float)$_POST['target_price'] : 0.0;
        $quantity = is_numeric($_POST['quantity'] ?? null) ? (int)$_POST['quantity'] : 0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tradeDate)) {
            $errors[] = 'Requested date must be YYYY-MM-DD.';
        }
        if ($price <= 0) {
            $errors[] = 'Target price must be greater than zero.';
        }
        if ($quantity <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }

        if (!$errors && $action === 'manual_buy_order') {
            if ($symbol === '') {
                $errors[] = 'Symbol is required for manual BUY.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stockId = dashboard_manual_ensure_stock($pdo, $symbol);
                    dashboard_manual_upsert_run($pdo, $tradeDate);
                    $currentRow = fetch_one(
                        $pdo,
                        'SELECT close_price FROM daily_prices WHERE stock_id = :stock_id ORDER BY trade_date DESC LIMIT 1',
                        ['stock_id' => $stockId]
                    );
                    $stmt = $pdo->prepare(
                        'INSERT INTO manual_trade_orders
                            (order_id, run_id, stock_id, side, requested_date, quantity, target_price, current_reference_price, entry_trade_id, reason, status, note)
                         VALUES
                            (:order_id, :run_id, :stock_id, :side, :requested_date, :quantity, :target_price, :current_reference_price, :entry_trade_id, :reason, :status, :note)'
                    );
                    $orderId = hash('sha256', 'manual-buy-order|' . $symbol . '|' . $tradeDate . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
                    $stmt->execute([
                        'order_id' => $orderId,
                        'run_id' => DASHBOARD_MANUAL_RUN_ID,
                        'stock_id' => $stockId,
                        'side' => 'BUY',
                        'requested_date' => $tradeDate,
                        'quantity' => $quantity,
                        'target_price' => round($price, 4),
                        'current_reference_price' => $currentRow ? round((float)$currentRow['close_price'], 4) : null,
                        'entry_trade_id' => null,
                        'reason' => substr(trim((string)($_POST['reason'] ?? 'MANUAL_BUY')) ?: 'MANUAL_BUY', 0, 50),
                        'status' => 'PENDING',
                        'note' => 'Waiting for market price to touch the target buy price.',
                    ]);
                    $pdo->commit();
                    $successMessage = 'Manual BUY order saved. It will execute when market data hits your target price.';
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Manual BUY order could not be saved right now.';
                }
            }
        } elseif (!$errors && $action === 'manual_sell_order') {
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
                $errors[] = 'Sell order date cannot be earlier than the selected buy date.';
            }

            if (!$errors && $selectedLot !== null) {
                try {
                    $pdo->beginTransaction();
                    dashboard_manual_upsert_run($pdo, $tradeDate);
                    $currentRow = fetch_one(
                        $pdo,
                        'SELECT close_price FROM daily_prices WHERE stock_id = :stock_id ORDER BY trade_date DESC LIMIT 1',
                        ['stock_id' => (int)$selectedLot['stock_id']]
                    );
                    $stmt = $pdo->prepare(
                        'INSERT INTO manual_trade_orders
                            (order_id, run_id, stock_id, side, requested_date, quantity, target_price, current_reference_price, entry_trade_id, reason, status, note)
                         VALUES
                            (:order_id, :run_id, :stock_id, :side, :requested_date, :quantity, :target_price, :current_reference_price, :entry_trade_id, :reason, :status, :note)'
                    );
                    $orderId = hash('sha256', 'manual-sell-order|' . $selectedLot['symbol'] . '|' . $tradeDate . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
                    $stmt->execute([
                        'order_id' => $orderId,
                        'run_id' => DASHBOARD_MANUAL_RUN_ID,
                        'stock_id' => (int)$selectedLot['stock_id'],
                        'side' => 'SELL',
                        'requested_date' => $tradeDate,
                        'quantity' => $quantity,
                        'target_price' => round($price, 4),
                        'current_reference_price' => $currentRow ? round((float)$currentRow['close_price'], 4) : null,
                        'entry_trade_id' => $entryTradeId,
                        'reason' => $sellReason,
                        'status' => 'PENDING',
                        'note' => 'Waiting for market price to touch the target sell price.',
                    ]);
                    $pdo->commit();
                    $successMessage = 'Manual SELL order saved. It will execute when market data hits your target price.';
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Manual SELL order could not be saved right now.';
                }
            }
        } elseif (!$errors && $action === 'cancel_order') {
            $orderId = trim((string)($_POST['order_id'] ?? ''));
            if ($orderId === '') {
                $errors[] = 'Order id is missing.';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE manual_trade_orders
                     SET status = 'CANCELLED',
                         note = 'Order cancelled from dashboard.',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE order_id = :order_id AND status = 'PENDING'"
                );
                $stmt->execute(['order_id' => $orderId]);
                $successMessage = 'Pending order cancelled.';
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

$selectedBuySymbol = (string)($stockRows[0]['symbol'] ?? '');
$selectedBuyPrice = isset($stockRows[0]['close_price']) && $stockRows[0]['close_price'] !== null ? (float)$stockRows[0]['close_price'] : null;

$pageTitle = 'Manual Trade';
require __DIR__ . '/includes/header.php';
?>
<section class="notice">Paper trading only. Not financial advice. These are pending paper orders. They execute only after stored market data reaches your target price.</section>

<?php if ($successMessage): ?>
    <section class="notice"><?= h($successMessage) ?></section>
<?php endif; ?>

<?php if ($errors): ?>
    <section class="error"><?= h(implode(' ', $errors)) ?></section>
<?php endif; ?>

<?php if (($orderProcess['executed'] ?? 0) > 0 || ($orderProcess['cancelled'] ?? 0) > 0): ?>
    <section class="notice">
        <?= h((string)($orderProcess['executed'] ?? 0)) ?> pending order executed, <?= h((string)($orderProcess['cancelled'] ?? 0)) ?> cancelled during the latest order check.
    </section>
<?php endif; ?>

<section class="grid cols-2">
    <div class="panel">
        <h2>Manual BUY Order</h2>
        <p class="muted small-text">Use a buy price at or below the current market price when you want to wait for a pullback.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(dashboard_csrf_token()) ?>">
            <input type="hidden" name="action" value="manual_buy_order">
            <div class="field">
                <label for="buy-symbol">Symbol</label>
                <select id="buy-symbol" name="symbol" required>
                    <?php foreach ($stockRows as $row): ?>
                        <option
                            value="<?= h((string)$row['symbol']) ?>"
                            data-price="<?= h($row['close_price'] === null ? '' : number_format((float)$row['close_price'], 4, '.', '')) ?>"
                            data-date="<?= h((string)($row['latest_trade_date'] ?? '')) ?>"
                        >
                            <?= h((string)$row['symbol'] . (!empty($row['name']) && (string)$row['name'] !== (string)$row['symbol'] ? ' - ' . (string)$row['name'] : '') . (($row['close_price'] !== null) ? ' | Current ' . number_format((float)$row['close_price'], 2) . ' BDT' : ' | No price yet')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Current Price</label>
                <div id="buy-current-price" class="readonly-box"><?= $selectedBuyPrice === null ? 'N/A' : h(number_format($selectedBuyPrice, 2) . ' BDT') ?></div>
            </div>
            <div class="field">
                <label>Latest Price Date</label>
                <div id="buy-current-date" class="readonly-box"><?= h((string)($stockRows[0]['latest_trade_date'] ?? 'N/A')) ?></div>
            </div>
            <div class="field">
                <label for="buy-date">Order Start Date</label>
                <input id="buy-date" name="requested_date" type="date" value="<?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="buy-price">My Buy Price</label>
                <input id="buy-price" name="target_price" type="number" min="0.01" step="0.01" required>
            </div>
            <div class="field">
                <label for="buy-quantity">Quantity</label>
                <input id="buy-quantity" name="quantity" type="number" min="1" step="1" required>
            </div>
            <div class="field">
                <label for="buy-reason">Reason</label>
                <input id="buy-reason" name="reason" type="text" maxlength="50" value="MANUAL_BUY">
            </div>
            <button type="submit">Place BUY Order</button>
        </form>
    </div>

    <div class="panel">
        <h2>Manual SELL Order</h2>
        <p class="muted small-text">Use a sell price at or above the current market price when you want to wait for profit-taking.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(dashboard_csrf_token()) ?>">
            <input type="hidden" name="action" value="manual_sell_order">
            <div class="field">
                <label for="sell-position">Open Position</label>
                <select id="sell-position" name="entry_trade_id" required>
                    <option value="">Select open lot</option>
                    <?php foreach ($openLots as $lot): ?>
                        <?php $sellCurrentPrice = $stockPriceMap[(string)$lot['symbol']]['close_price'] ?? null; ?>
                        <option
                            value="<?= h((string)$lot['trade_id']) ?>"
                            data-buy-price="<?= h(number_format((float)$lot['buy_price'], 4, '.', '')) ?>"
                            data-current-price="<?= h($sellCurrentPrice === null ? '' : number_format((float)$sellCurrentPrice, 4, '.', '')) ?>"
                            data-buy-date="<?= h((string)$lot['buy_date']) ?>"
                        >
                            <?= h((string)$lot['symbol'] . ' | Buy ' . (string)$lot['buy_date'] . ' @ ' . number_format((float)$lot['buy_price'], 2) . ' | Qty ' . (string)$lot['remaining_quantity']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>My Buy Price</label>
                <div id="sell-buy-price" class="readonly-box"><?= $openLots ? h(number_format((float)$openLots[0]['buy_price'], 2) . ' BDT') : 'N/A' ?></div>
            </div>
            <div class="field">
                <label>Current Price</label>
                <div id="sell-current-price" class="readonly-box">N/A</div>
            </div>
            <div class="field">
                <label for="sell-date">Order Start Date</label>
                <input id="sell-date" name="requested_date" type="date" value="<?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="sell-price">My Sell Price</label>
                <input id="sell-price" name="target_price" type="number" min="0.01" step="0.01" required>
            </div>
            <div class="field">
                <label for="sell-quantity">Quantity To Sell</label>
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
            <button type="submit">Place SELL Order</button>
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
                <th>Current Price</th>
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
                <td><?= money_or_na($stockPriceMap[(string)$lot['symbol']]['close_price'] ?? null) ?></td>
                <td><?= number_format((int)$lot['remaining_quantity']) ?></td>
                <td><?= money((float)$lot['remaining_cost_basis']) ?></td>
                <td><?= h((string)$lot['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$openLots): ?>
            <tr><td colspan="7" class="muted">No open lots available for manual SELL.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Manual Orders</h2>
    <table>
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Side</th>
                <th>Requested</th>
                <th>Current Price</th>
                <th>Current Price Date</th>
                <th>My Price</th>
                <th>Qty</th>
                <th>Status</th>
                <th>Executed</th>
                <th>Note</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($manualOrders as $order): ?>
            <tr>
                <td><?= h((string)$order['symbol']) ?></td>
                <td><span class="badge <?= h((string)$order['side'] === 'BUY' ? 'BUY' : 'SELL') ?>"><?= h((string)$order['side']) ?></span></td>
                <td><?= h((string)$order['requested_date']) ?></td>
                <td><?= money_or_na($order['latest_close_price']) ?></td>
                <td><?= h((string)($order['latest_trade_date'] ?? 'N/A')) ?></td>
                <td><?= money((float)$order['target_price']) ?></td>
                <td><?= number_format((int)$order['quantity']) ?></td>
                <td><span class="badge <?= h((string)$order['status'] === 'EXECUTED' ? 'CORRECT' : ((string)$order['status'] === 'CANCELLED' ? 'WRONG' : 'WATCH')) ?>"><?= h((string)$order['status']) ?></span></td>
                <td><?= h((string)($order['executed_date'] ?? 'N/A')) ?></td>
                <td>
                    <?= h((string)($order['note'] ?? '')) ?>
                    <?php if ($order['current_reference_price'] !== null): ?>
                        <div class="muted small-text">Order placed when price was <?= h(number_format((float)$order['current_reference_price'], 2)) ?> BDT.</div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((string)$order['status'] === 'PENDING'): ?>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h(dashboard_csrf_token()) ?>">
                            <input type="hidden" name="action" value="cancel_order">
                            <input type="hidden" name="order_id" value="<?= h((string)$order['order_id']) ?>">
                            <button class="compact-button" type="submit">Cancel</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$manualOrders): ?>
            <tr><td colspan="11" class="muted">No manual orders yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
function updateBuyPriceDisplay() {
    const select = document.getElementById('buy-symbol');
    const option = select.options[select.selectedIndex];
    document.getElementById('buy-current-price').textContent = option.dataset.price ? `${Number(option.dataset.price).toFixed(2)} BDT` : 'N/A';
    document.getElementById('buy-current-date').textContent = option.dataset.date || 'N/A';
}

function updateSellPriceDisplay() {
    const select = document.getElementById('sell-position');
    const option = select.options[select.selectedIndex];
    document.getElementById('sell-buy-price').textContent = option && option.dataset.buyPrice ? `${Number(option.dataset.buyPrice).toFixed(2)} BDT` : 'N/A';
    document.getElementById('sell-current-price').textContent = option && option.dataset.currentPrice ? `${Number(option.dataset.currentPrice).toFixed(2)} BDT` : 'N/A';
}

const buySymbolSelect = document.getElementById('buy-symbol');
if (buySymbolSelect) {
    buySymbolSelect.addEventListener('change', updateBuyPriceDisplay);
    updateBuyPriceDisplay();
}

const sellPositionSelect = document.getElementById('sell-position');
if (sellPositionSelect) {
    sellPositionSelect.addEventListener('change', updateSellPriceDisplay);
    updateSellPriceDisplay();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
