<?php
/**
 * Kree To Pla - Customer Orders & Live Tracking (Figma: /order-status-customer)
 * Implements Customer Req #7 (edit/cancel before cut-off), #8 (track status), #9 (order history).
 *
 * The tracking panel is rendered here; assets/js/app.js polls api/actions.php (check_order_status)
 * so the status, timeline and estimated time update without reloading.
 *
 * Security Controls:
 *  - Login required; every query is scoped to customer_id = logged-in user
 *  - CSRF verification on cancel/edit; both are conditional UPDATEs (pending + inside cut-off) in kp_* helpers
 */

declare(strict_types=1);
require_once __DIR__ . '/api/actions.php'; // security.php + shared kp_* order helpers
requireLogin('auth.php');

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
    $formAction = (string) ($_POST['form_action'] ?? '');
    $orderId    = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

    try {
        if ($formAction === 'cancel_order') {
            kp_cancel_order($pdo, $orderId, $userId);
            $_SESSION['flash_success'] = 'Order #' . $orderId . ' has been cancelled.';
        } elseif ($formAction === 'edit_address') {
            kp_update_order_address($pdo, $orderId, $userId, (string) ($_POST['delivery_address'] ?? ''));
            $_SESSION['flash_success'] = 'Delivery address updated for order #' . $orderId . '.';
        }
    } catch (KpUserException $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Order update error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Your order could not be updated. Please try again.';
    }

    header('Location: orders.php?order_id=' . $orderId);
    exit;
}

// Order history, newest first
$stmt = $pdo->prepare('
    SELECT o.order_id, o.order_date, o.total_amount, o.status, o.delivery_address,
           TIMESTAMPDIFF(MINUTE, o.order_date, NOW()) AS mins_elapsed,
           p.payment_method, p.payment_status
    FROM `Order` o
    LEFT JOIN Payment p ON p.order_id = o.order_id
    WHERE o.customer_id = :cid
    ORDER BY o.order_date DESC, o.order_id DESC
');
$stmt->execute([':cid' => $userId]);
$orders = $stmt->fetchAll();

// Order shown in the tracking panel: ?order_id=…, else the most recent
$selectedId  = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT) ?: 0;
$activeOrder = $orders[0] ?? null;
foreach ($orders as $order) {
    if ((int) $order['order_id'] === $selectedId) {
        $activeOrder = $order;
        break;
    }
}

$activeItems = [];
if ($activeOrder) {
    $itemStmt = $pdo->prepare('
        SELECT oi.quantity, oi.unit_price, oi.special_instructions,
               COALESCE(d.dish_name, cd.dish_name) AS item_name
        FROM Order_item oi
        LEFT JOIN Dish d ON d.dish_id = oi.dish_id
        LEFT JOIN Custom_dish cd ON cd.custom_dish_id = oi.custom_dish_id
        WHERE oi.order_id = :oid
        ORDER BY oi.order_item_id
    ');
    $itemStmt->execute([':oid' => $activeOrder['order_id']]);
    $activeItems = $itemStmt->fetchAll();
}

$pageTitle = 'My Orders';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .kp-order-card { display: block; background: #d9d9d9; border: 2px solid transparent; border-radius: 8px; color: inherit; text-decoration: none; transition: border-color .15s ease; }
    .kp-order-card:hover { border-color: #a1a1aa; color: inherit; }
    .kp-order-card.is-selected { border-color: var(--kp-black, #18181b); }
    .kp-order-card .kp-order-status { font-weight: 300; font-size: 1.15rem; }
    .kp-timeline-item.upcoming .kp-step-label { color: #9ca3af; font-weight: 400; }
    .kp-step-time { font-size: .78rem; color: #6b7280; }
</style>

<div class="container py-5">
    <h3 class="fw-bold mb-4">Orders</h3>

    <?php if (!$orders): ?>
        <div class="alert alert-light border p-5 text-center">
            <h5>You haven't placed any orders yet.</h5>
            <a href="menu.php" class="btn btn-dark mt-2">Order from This Week's Menu</a>
        </div>
    <?php else: ?>
        <div class="row g-5 align-items-start">
            <!-- Left: order history -->
            <div class="col-lg-7">
                <?php foreach ($orders as $order):
                    $oid = (int) $order['order_id'];
                    $isSelected = (int) $activeOrder['order_id'] === $oid;
                ?>
                    <a href="orders.php?order_id=<?= $oid ?>" class="kp-order-card p-4 mb-3 <?= $isSelected ? 'is-selected' : '' ?>"
                       <?= $isSelected ? 'aria-current="true"' : '' ?>>
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <div class="fs-5 fw-semibold">Order #<?= $oid ?></div>
                                <div class="fs-6 fw-light"><?= e(date('d M Y', strtotime($order['order_date']))) ?></div>
                                <div class="kp-order-status js-order-status" data-order-id="<?= $oid ?>"><?= e(kp_status_label($order['status'])) ?></div>
                            </div>
                            <div class="fs-5 fw-bold text-nowrap">&bull; <?= e(kp_money((float) $order['total_amount'])) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Right: tracking panel for the selected order -->
            <div class="col-lg-5">
                <?php
                $progress = kp_order_progress($activeOrder);
                $pickup   = kp_is_pickup((string) $activeOrder['delivery_address']);
                $oid      = (int) $activeOrder['order_id'];
                ?>
                <div class="kp-summary-panel" id="js-order-tracker"
                     data-order-id="<?= $oid ?>" data-status="<?= e($progress['status']) ?>">
                    <div class="text-center mb-4">
                        <h5 class="fw-bold mb-2">Order #<?= $oid ?></h5>
                        <?php if ($progress['status'] === 'cancelled'): ?>
                            <span class="badge bg-danger fs-6">Order Cancelled</span>
                        <?php else: ?>
                            <div class="fw-semibold"><?= $pickup ? 'Estimated Pickup' : 'Estimated Delivery' ?></div>
                            <div class="fw-bold fs-5" id="js-order-eta"><?= e($progress['eta']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($progress['status'] !== 'cancelled'): ?>
                        <ul class="kp-timeline mx-auto mb-4" style="max-width: 280px;">
                            <?php foreach (kp_order_steps($pickup) as $rank => $label):
                                $state = $rank < $progress['rank'] ? 'completed' : ($rank === $progress['rank'] ? 'completed active' : 'upcoming');
                            ?>
                                <li class="kp-timeline-item js-timeline-step <?= $state ?> <?= $rank === 4 ? 'mb-0' : '' ?>" data-rank="<?= $rank ?>">
                                    <span class="kp-timeline-dot"></span>
                                    <div class="fw-bold small kp-step-label"><?= e($label) ?></div>
                                    <?php if ($rank === 0): ?>
                                        <div class="kp-step-time"><?= e(date('d M Y, h.i A', strtotime($activeOrder['order_date']))) ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($progress['can_modify']): ?>
                        <div id="js-order-actions" class="border-top border-secondary-subtle pt-3 mb-3">
                            <p class="small text-muted mb-2">
                                You can change or cancel this order until the kitchen confirms it
                                (up to <?= (int) ORDER_CUTOFF_MINUTES ?> minutes after ordering).
                            </p>
                            <?php if (!$pickup): ?>
                                <form method="POST" action="orders.php" class="d-flex gap-2 mb-2">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="form_action" value="edit_address">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <label for="kp-edit-address" class="visually-hidden">Delivery address</label>
                                    <input type="text" id="kp-edit-address" name="delivery_address" class="form-control form-control-sm"
                                           minlength="5" maxlength="500" required value="<?= e($activeOrder['delivery_address']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark text-nowrap">Save address</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="orders.php" data-confirm="Cancel order #<?= $oid ?>? This cannot be undone.">
                                <?= csrfInput() ?>
                                <input type="hidden" name="form_action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?= $oid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel Order</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="border-top border-secondary-subtle pt-3">
                        <h6 class="fw-bold small mb-2">Ordered items</h6>
                        <ul class="list-unstyled small mb-3">
                            <?php foreach ($activeItems as $item): ?>
                                <li class="mb-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span><?= (int) $item['quantity'] ?> &times; <?= e($item['item_name']) ?></span>
                                        <span class="fw-semibold text-nowrap"><?= e(kp_money((float) $item['unit_price'] * (int) $item['quantity'])) ?></span>
                                    </div>
                                    <?php if (!empty($item['special_instructions'])): ?>
                                        <div class="text-muted fst-italic">"<?= e($item['special_instructions']) ?>"</div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            <li class="d-flex justify-content-between gap-2 border-top border-secondary-subtle pt-2 mt-2 fw-bold">
                                <span>Total</span><span><?= e(kp_money((float) $activeOrder['total_amount'])) ?></span>
                            </li>
                        </ul>
                        <div class="small text-muted">
                            <strong><?= $pickup ? 'Pickup' : 'Deliver to' ?>:</strong> <?= e($activeOrder['delivery_address']) ?><br>
                            <strong>Payment:</strong> <?= e($activeOrder['payment_method'] ?? '—') ?>
                            (<?= e($activeOrder['payment_status'] ?? 'pending') ?>)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
