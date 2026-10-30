<?php
/**
 * Kree To Pla - Cart & Checkout (Figma: /cartcheckout)
 * Implements Customer Req #6 (place an order online).
 *
 * Works without JavaScript: every button is a real form post handled below.
 * assets/js/app.js upgrades the − / + / Remove buttons to AJAX calls on api/actions.php
 * so quantities and totals update in place.
 *
 * Security Controls:
 *  - CSRF verification on all state-changing actions
 *  - Prices re-read from MySQL at checkout (kp_place_order); the session copy is display-only
 *  - Atomic PDO transaction across `Order`, `Order_item` and `Payment`
 */

declare(strict_types=1);
require_once __DIR__ . '/api/actions.php'; // security.php + shared kp_* cart helpers

kp_cart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
    $formAction = (string) ($_POST['form_action'] ?? '');
    $cartKey    = (string) ($_POST['cart_key'] ?? '');

    try {
        if ($formAction === 'update_qty') {
            $current = (int) (kp_cart()[$cartKey]['quantity'] ?? 0);
            kp_cart_set_quantity($cartKey, $current + (int) ($_POST['step'] ?? 0));
        } elseif ($formAction === 'remove_item') {
            kp_cart_set_quantity($cartKey, 0);
            $_SESSION['flash_success'] = 'Item removed from your cart.';
        } elseif ($formAction === 'place_order') {
            // Keep what the customer typed in case checkout fails or they must log in first.
            $_SESSION['checkout_draft'] = [
                'pickup'               => isset($_POST['pickup']),
                'delivery_address'     => (string) ($_POST['delivery_address'] ?? ''),
                'special_instructions' => (string) ($_POST['special_instructions'] ?? ''),
                'payment_method'       => (string) ($_POST['payment_method'] ?? 'Cash'),
            ];
            requireLogin('auth.php');

            $draft   = $_SESSION['checkout_draft'];
            $orderId = kp_place_order(
                $pdo,
                (int) $_SESSION['user_id'],
                $draft['pickup'],
                $draft['delivery_address'],
                $draft['payment_method'],
                $draft['special_instructions']
            );
            unset($_SESSION['checkout_draft']);
            $_SESSION['flash_success'] = 'Order #' . $orderId . ' placed successfully! Track its status below.';
            header('Location: orders.php?order_id=' . $orderId);
            exit;
        }
    } catch (KpUserException $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Checkout error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Your order could not be completed. Please try again.';
    }

    header('Location: cart.php');
    exit;
}

// Pre-fill the checkout form: last attempt first, then the customer's saved address.
$draft = $_SESSION['checkout_draft'] ?? [];
$savedAddress = '';
if (isLoggedIn()) {
    $stmt = $pdo->prepare('SELECT address FROM Customer WHERE user_id = :uid');
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $savedAddress = (string) ($stmt->fetchColumn() ?: '');
}
$isPickup       = (bool) ($draft['pickup'] ?? false);
$address        = (string) ($draft['delivery_address'] ?? $savedAddress);
$instructions   = (string) ($draft['special_instructions'] ?? '');
$paymentMethod  = (string) ($draft['payment_method'] ?? 'Cash');
$paymentOptions = [
    'Cash'           => 'Cash on Delivery',
    'Card'           => 'Card Payment',
    'Mobile Payment' => 'Mobile Payment',
];

$cart        = kp_cart();
$subtotal    = kp_cart_subtotal();
$deliveryFee = $isPickup ? 0.0 : (float) DELIVERY_FEE;

$pageTitle = 'Cart & Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .kp-cart-card { border: 1px solid #e4e4e7; border-radius: 12px; background: #fff; }
    .kp-cart-thumb { width: 97px; height: 66px; object-fit: cover; background: #d9d9d9; border-radius: 6px; flex-shrink: 0; }
    .kp-stepper { border: 1px solid #d4d4d8; border-radius: 6px; }
    .kp-stepper .btn { line-height: 1; padding: 6px 10px; }
    .kp-stepper .js-cart-qty { min-width: 22px; text-align: center; }
    .kp-line-total { min-width: 72px; }
    .kp-cart-row.is-busy { opacity: .55; pointer-events: none; }
</style>

<div class="container py-5">
    <?php if (!$cart): ?>
        <div class="kp-cart-card p-5 text-center">
            <i class="bi bi-cart-x display-4 text-secondary mb-3"></i>
            <h4 class="fw-bold">Your cart is empty</h4>
            <p class="text-muted">Add dishes from this week's menu or build your own dish to get started.</p>
            <div class="d-flex justify-content-center flex-wrap gap-3 mt-2">
                <a href="menu.php" class="btn btn-dark px-4">Browse Weekly Menu</a>
                <a href="build_dish.php" class="btn btn-outline-dark px-4">Build a Custom Dish</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4 align-items-start">
            <!-- Left: cart items, instructions, delivery details -->
            <div class="col-lg-7">
                <div class="kp-cart-card p-4 mb-4">
                    <div id="kp-cart-items">
                        <?php foreach ($cart as $key => $item):
                            $qty = (int) $item['quantity'];
                            $lineTotal = (float) $item['unit_price'] * $qty;
                        ?>
                            <div class="kp-cart-row js-cart-row d-flex flex-wrap align-items-center justify-content-between gap-3 py-3 border-bottom"
                                 data-cart-key="<?= e($key) ?>">
                                <div class="d-flex align-items-center gap-3 flex-grow-1" style="min-width: 0;">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= e($item['image_url']) ?>" alt="" class="kp-cart-thumb">
                                    <?php else: ?>
                                        <div class="kp-cart-thumb"></div>
                                    <?php endif; ?>
                                    <div style="min-width: 0;">
                                        <h6 class="fw-semibold mb-1 text-truncate"><?= e($item['name']) ?></h6>
                                        <div class="small text-muted"><?= e(kp_money((float) $item['unit_price'])) ?> each</div>
                                        <?php if (($item['type'] ?? '') === 'custom'): ?>
                                            <span class="badge bg-dark-subtle text-dark fw-normal">Custom dish</span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['instructions'])): ?>
                                            <div class="small text-muted fst-italic text-truncate">"<?= e($item['instructions']) ?>"</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-3 ms-auto">
                                    <form method="POST" action="cart.php" class="kp-stepper d-inline-flex align-items-center">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="form_action" value="update_qty">
                                        <input type="hidden" name="cart_key" value="<?= e($key) ?>">
                                        <button type="submit" name="step" value="-1" class="btn btn-link text-dark text-decoration-none fw-semibold js-cart-step"
                                                data-step="-1" aria-label="Decrease quantity of <?= e($item['name']) ?>">&minus;</button>
                                        <span class="fw-semibold js-cart-qty" aria-live="polite"><?= $qty ?></span>
                                        <button type="submit" name="step" value="1" class="btn btn-link text-dark text-decoration-none fw-semibold js-cart-step"
                                                data-step="1" aria-label="Increase quantity of <?= e($item['name']) ?>"
                                                <?= $qty >= KP_MAX_QTY ? 'disabled' : '' ?>>+</button>
                                    </form>

                                    <div class="fw-semibold text-end kp-line-total js-line-total"><?= e(kp_money($lineTotal)) ?></div>

                                    <form method="POST" action="cart.php">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="form_action" value="remove_item">
                                        <input type="hidden" name="cart_key" value="<?= e($key) ?>">
                                        <button type="submit" class="btn btn-link text-secondary p-0 js-cart-remove" title="Remove <?= e($item['name']) ?>">
                                            <i class="bi bi-trash3"></i><span class="visually-hidden">Remove <?= e($item['name']) ?></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- These fields belong to the checkout form on the right via form="kp-checkout-form" -->
                    <div class="mt-4">
                        <label for="kp-special-inst" class="form-label fw-semibold small">Add special instructions (optional)</label>
                        <input type="text" id="kp-special-inst" name="special_instructions" form="kp-checkout-form"
                               class="form-control form-control-lg" maxlength="500" value="<?= e($instructions) ?>"
                               placeholder="E.g. less spicy, no onions, extra sauce">
                    </div>

                    <div class="mt-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="kp-pickup" name="pickup" value="1"
                                   form="kp-checkout-form" <?= $isPickup ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="kp-pickup">
                                Collect from the cafeteria instead (no delivery fee)
                            </label>
                        </div>
                        <div id="kp-address-group" class="<?= $isPickup ? 'd-none' : '' ?>">
                            <label for="kp-delivery-address" class="form-label fw-semibold small">Delivery address</label>
                            <input type="text" id="kp-delivery-address" name="delivery_address" form="kp-checkout-form"
                                   class="form-control" minlength="5" maxlength="500" autocomplete="street-address"
                                   value="<?= e($address) ?>" placeholder="Building, street and town" <?= $isPickup ? 'disabled' : 'required' ?>>
                        </div>
                    </div>
                </div>

                <div class="kp-cart-card p-3 text-secondary small">
                    <div class="mb-1">Secure checkout &bull; Your order will be confirmed after payment selection.</div>
                    <div>Review your items and delivery details before placing the order.</div>
                </div>
            </div>

            <!-- Right: order summary & payment method -->
            <div class="col-lg-5">
                <form method="POST" action="cart.php" id="kp-checkout-form" class="kp-cart-card p-4"
                      data-delivery-fee="<?= e((string) DELIVERY_FEE) ?>">
                    <?= csrfInput() ?>
                    <input type="hidden" name="form_action" value="place_order">

                    <h4 class="fw-semibold mb-4">Order Summary</h4>

                    <div class="d-flex justify-content-between text-secondary mb-2">
                        <span>Subtotal</span>
                        <span id="kp-subtotal"><?= e(kp_money($subtotal)) ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary pb-3 mb-3 border-bottom">
                        <span>Delivery Fee</span>
                        <span id="kp-delivery-fee"><?= e(kp_money($deliveryFee)) ?></span>
                    </div>
                    <div class="d-flex justify-content-between fw-semibold fs-5 mb-4">
                        <span>Total</span>
                        <span id="kp-total"><?= e(kp_money($subtotal + $deliveryFee)) ?></span>
                    </div>

                    <fieldset>
                        <legend class="fs-6 fw-semibold mb-3">Payment Method</legend>
                        <?php foreach ($paymentOptions as $value => $label): $id = 'kp-pay-' . strtolower(str_replace(' ', '-', $value)); ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="payment_method" id="<?= e($id) ?>"
                                       value="<?= e($value) ?>" <?= $paymentMethod === $value ? 'checked' : '' ?> required>
                                <label class="form-check-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <p class="small text-muted mt-2 mb-4">
                            No card details are collected online. Your payment is recorded as pending and settled when you receive your order.
                        </p>
                    </fieldset>

                    <button type="submit" class="btn btn-dark btn-lg w-100 fw-semibold py-3 js-place-order">Place Order</button>
                    <?php if (!isLoggedIn()): ?>
                        <p class="small text-muted text-center mt-2 mb-0">You'll be asked to log in before your order is placed.</p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
