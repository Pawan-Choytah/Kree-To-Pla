<?php
/**
 * Kree To Pla - Cart Service & JSON AJAX Controller
 *
 * This file has two jobs:
 *  1. Shared cart/order rules (kp_* functions). cart.php and orders.php require this file so the
 *     AJAX endpoint, the no-JavaScript form fallbacks and checkout all price and validate the same way.
 *  2. When requested directly (POST api/actions.php), a JSON endpoint for the jQuery calls in assets/js/app.js.
 *
 * Security Controls:
 *  - CSRF token required on every AJAX request (X-CSRF-Token header or csrf_token field)
 *  - PDO prepared statements only; prices are always re-read from MySQL, never trusted from the browser/session
 *  - Ownership checks on orders and custom dishes (customer_id = logged-in user)
 *  - Conditional UPDATEs make cancel/edit atomic: they only succeed while the order is still pending and inside the cut-off
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';

/** Errors whose message is safe to show the customer. Anything else (e.g. PDOException) gets a generic message. */
final class KpUserException extends Exception
{
}

const KP_MAX_QTY        = 20;
const KP_PICKUP_ADDRESS = 'Cafeteria Pickup';
const KP_PAYMENT_METHODS = ['Cash', 'Card', 'Online Banking', 'Mobile Payment'];

// Timeline position of each Order.status (Figma /order-status-customer). 'cancelled' has no position.
const KP_STATUS_RANK = [
    'pending'          => 0,
    'confirmed'        => 1,
    'preparing'        => 2,
    'ready'            => 3,
    'out_for_delivery' => 3,
    'delivered'        => 4,
    'completed'        => 4,
];
const KP_FINAL_STATUSES = ['delivered', 'completed', 'cancelled'];

/* ---------------------------------------------------------------------------
 * Formatting & order status helpers
 * ------------------------------------------------------------------------- */

function kp_money(float $amount): string
{
    $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;
    return 'Rs ' . number_format($amount, $decimals);
}

function kp_is_pickup(string $address): bool
{
    return strcasecmp(trim($address), KP_PICKUP_ADDRESS) === 0;
}

function kp_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/** Timeline step labels, indexed by KP_STATUS_RANK. */
function kp_order_steps(bool $pickup): array
{
    return [
        'Order Placed',
        'Order Confirmed',
        'Preparing',
        $pickup ? 'Ready For Pickup' : 'Out For Delivery',
        $pickup ? 'Collected' : 'Delivered',
    ];
}

function kp_order_eta(string $status, bool $pickup): string
{
    return match ($status) {
        'pending', 'confirmed' => '25-35 min',
        'preparing'            => '20-30 min',
        'ready'                => 'Ready now',
        'out_for_delivery'     => '10-15 min',
        'delivered'            => 'Delivered',
        'completed'            => $pickup ? 'Collected' : 'Delivered',
        'cancelled'            => 'Cancelled',
        default                => '—',
    };
}

/** Customers may edit/cancel only while the order is pending and inside the cut-off window. */
function kp_order_can_modify(array $order): bool
{
    return $order['status'] === 'pending' && (int) $order['mins_elapsed'] <= ORDER_CUTOFF_MINUTES;
}

/** Status payload shared by orders.php (initial render) and check_order_status (polling). */
function kp_order_progress(array $order): array
{
    $pickup = kp_is_pickup((string) $order['delivery_address']);
    return [
        'order_id'   => (int) $order['order_id'],
        'status'     => $order['status'],
        'label'      => kp_status_label($order['status']),
        'rank'       => KP_STATUS_RANK[$order['status']] ?? -1,
        'eta'        => kp_order_eta($order['status'], $pickup),
        'can_modify' => kp_order_can_modify($order),
        'is_final'   => in_array($order['status'], KP_FINAL_STATUSES, true),
    ];
}

/** One order owned by the customer, with the minutes elapsed measured on the database clock. */
function kp_find_order(PDO $pdo, int $orderId, int $customerId): ?array
{
    $stmt = $pdo->prepare('
        SELECT o.order_id, o.order_date, o.total_amount, o.status, o.delivery_address,
               TIMESTAMPDIFF(MINUTE, o.order_date, NOW()) AS mins_elapsed
        FROM `Order` o
        WHERE o.order_id = :oid AND o.customer_id = :cid
    ');
    $stmt->execute([':oid' => $orderId, ':cid' => $customerId]);
    return $stmt->fetch() ?: null;
}

/* ---------------------------------------------------------------------------
 * Dish lookups (server-side prices)
 * ------------------------------------------------------------------------- */

/** Price and availability of a standard dish, using the same rule as menu.php (latest published menu, else base price). */
function kp_find_dish(PDO $pdo, int $dishId): ?array
{
    $stmt = $pdo->prepare('
        SELECT d.dish_id, d.dish_name, d.image_url,
               COALESCE(mi.price, d.base_price) AS unit_price,
               COALESCE(mi.availability, TRUE) AS is_available
        FROM Dish d
        LEFT JOIN Menu_item mi
               ON mi.dish_id = d.dish_id
              AND mi.menu_id = (SELECT menu_id FROM Menu WHERE is_published = TRUE ORDER BY week_start DESC LIMIT 1)
        WHERE d.dish_id = :dish_id AND d.is_active = TRUE
    ');
    $stmt->execute([':dish_id' => $dishId]);
    return $stmt->fetch() ?: null;
}

function kp_find_custom_dish(PDO $pdo, int $customDishId, int $customerId): ?array
{
    $stmt = $pdo->prepare('
        SELECT custom_dish_id, dish_name, instructions, total_price
        FROM Custom_dish
        WHERE custom_dish_id = :cdid AND customer_id = :cid
    ');
    $stmt->execute([':cdid' => $customDishId, ':cid' => $customerId]);
    return $stmt->fetch() ?: null;
}

/* ---------------------------------------------------------------------------
 * Session cart
 * Item shape (shared with build_dish.php): type, dish_id, custom_dish_id, name,
 * unit_price, quantity, instructions, image_url. Keys: dish_{id} / custom_{id}.
 * ------------------------------------------------------------------------- */

function kp_cart(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    return $_SESSION['cart'];
}

function kp_cart_subtotal(): float
{
    $subtotal = 0.0;
    foreach (kp_cart() as $item) {
        $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
    }
    return $subtotal;
}

function kp_cart_add_dish(PDO $pdo, int $dishId, int $quantity): array
{
    $dish = kp_find_dish($pdo, $dishId);
    if (!$dish || !$dish['is_available']) {
        throw new KpUserException('This dish is currently unavailable.');
    }

    $key      = 'dish_' . $dishId;
    $existing = kp_cart()[$key] ?? null;

    $_SESSION['cart'][$key] = [
        'type'           => 'standard',
        'dish_id'        => $dishId,
        'custom_dish_id' => null,
        'name'           => $dish['dish_name'],
        'unit_price'     => (float) $dish['unit_price'],
        'quantity'       => min(KP_MAX_QTY, (int) ($existing['quantity'] ?? 0) + $quantity),
        'instructions'   => $existing['instructions'] ?? null,
        'image_url'      => $dish['image_url'],
    ];
    return $_SESSION['cart'][$key];
}

/** Sets an item's quantity; 0 or less removes it. Returns the updated item, or null when removed. */
function kp_cart_set_quantity(string $key, int $quantity): ?array
{
    if (!array_key_exists($key, kp_cart())) {
        throw new KpUserException('That item is no longer in your cart.');
    }
    if ($quantity <= 0) {
        unset($_SESSION['cart'][$key]);
        return null;
    }
    $_SESSION['cart'][$key]['quantity'] = min(KP_MAX_QTY, $quantity);
    return $_SESSION['cart'][$key];
}

/* ---------------------------------------------------------------------------
 * Checkout & order changes
 * ------------------------------------------------------------------------- */

/**
 * Turns the session cart into Order + Order_item + Payment rows in one transaction.
 * Every unit price is re-read from MySQL; the session copy is display-only.
 * Returns the new order_id.
 */
function kp_place_order(PDO $pdo, int $customerId, bool $pickup, string $address, string $paymentMethod, string $note): int
{
    $cart = kp_cart();
    if (!$cart) {
        throw new KpUserException('Your cart is empty.');
    }
    if (!in_array($paymentMethod, KP_PAYMENT_METHODS, true)) {
        throw new KpUserException('Choose a valid payment method.');
    }

    $address = $pickup ? KP_PICKUP_ADDRESS : trim($address);
    if (!$pickup && (mb_strlen($address) < 5 || mb_strlen($address) > 500)) {
        throw new KpUserException('Enter a complete delivery address (5 to 500 characters), or choose cafeteria pickup.');
    }
    $note = trim($note);
    if (mb_strlen($note) > 500) {
        throw new KpUserException('Special instructions can be at most 500 characters.');
    }

    $lines    = [];
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => KP_MAX_QTY]]);
        if ($qty === false) {
            throw new KpUserException('Quantities must be between 1 and ' . KP_MAX_QTY . '. Please update your cart.');
        }

        if (($item['type'] ?? '') === 'custom') {
            $custom = kp_find_custom_dish($pdo, (int) $item['custom_dish_id'], $customerId);
            if (!$custom) {
                throw new KpUserException('A custom dish in your cart is no longer available. Please remove it.');
            }
            $dishId       = null;
            $customDishId = (int) $custom['custom_dish_id'];
            $unitPrice    = (float) $custom['total_price'];
        } else {
            $dish = kp_find_dish($pdo, (int) ($item['dish_id'] ?? 0));
            if (!$dish || !$dish['is_available']) {
                throw new KpUserException(($item['name'] ?? 'A dish') . ' is no longer available. Please remove it from your cart.');
            }
            $dishId       = (int) $dish['dish_id'];
            $customDishId = null;
            $unitPrice    = (float) $dish['unit_price'];
        }

        $instructions = implode(' | ', array_filter([trim((string) ($item['instructions'] ?? '')), $note], 'strlen'));
        $lines[]   = [$dishId, $customDishId, $qty, $unitPrice, $instructions !== '' ? $instructions : null];
        $subtotal += $unitPrice * $qty;
    }

    $total = round($subtotal + ($pickup ? 0.0 : (float) DELIVERY_FEE), 2);

    $pdo->beginTransaction();
    try {
        // Order.customer_id references Customer, so make sure this user has a Customer row.
        $hasCustomer = $pdo->prepare('SELECT 1 FROM Customer WHERE user_id = :uid');
        $hasCustomer->execute([':uid' => $customerId]);
        if (!$hasCustomer->fetchColumn()) {
            $pdo->prepare('INSERT INTO Customer (user_id, address) VALUES (:uid, :addr)')
                ->execute([':uid' => $customerId, ':addr' => $pickup ? null : $address]);
        }

        $pdo->prepare("INSERT INTO `Order` (customer_id, total_amount, status, delivery_address) VALUES (:cid, :total, 'pending', :addr)")
            ->execute([':cid' => $customerId, ':total' => $total, ':addr' => $address]);
        $orderId = (int) $pdo->lastInsertId();

        $insItem = $pdo->prepare('
            INSERT INTO Order_item (order_id, dish_id, custom_dish_id, quantity, unit_price, special_instructions)
            VALUES (:oid, :did, :cdid, :qty, :price, :inst)
        ');
        foreach ($lines as [$dishId, $customDishId, $qty, $unitPrice, $instructions]) {
            $insItem->execute([
                ':oid'   => $orderId,
                ':did'   => $dishId,
                ':cdid'  => $customDishId,
                ':qty'   => $qty,
                ':price' => $unitPrice,
                ':inst'  => $instructions,
            ]);
        }

        // No payment is processed online: every method is recorded as pending and
        // the admin panel marks it completed when the order is delivered/collected.
        $pdo->prepare("INSERT INTO Payment (order_id, amount, payment_method, payment_status) VALUES (:oid, :amt, :method, 'pending')")
            ->execute([':oid' => $orderId, ':amt' => $total, ':method' => $paymentMethod]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $_SESSION['cart'] = [];
    return $orderId;
}

function kp_cancel_order(PDO $pdo, int $orderId, int $customerId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE `Order` SET status = 'cancelled'
            WHERE order_id = :oid AND customer_id = :cid AND status = 'pending'
              AND order_date >= NOW() - INTERVAL :mins MINUTE
        ");
        $stmt->execute([':oid' => $orderId, ':cid' => $customerId, ':mins' => ORDER_CUTOFF_MINUTES]);
        if ($stmt->rowCount() !== 1) {
            throw new KpUserException('Order #' . $orderId . ' can no longer be cancelled (the kitchen has started it or the ' . ORDER_CUTOFF_MINUTES . '-minute cut-off has passed).');
        }

        $pdo->prepare("
            UPDATE Payment
            SET payment_status = CASE WHEN payment_status = 'completed' THEN 'refunded' ELSE 'failed' END
            WHERE order_id = :oid
        ")->execute([':oid' => $orderId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kp_update_order_address(PDO $pdo, int $orderId, int $customerId, string $address): void
{
    $address = trim($address);
    if (mb_strlen($address) < 5 || mb_strlen($address) > 500) {
        throw new KpUserException('Enter a complete delivery address (5 to 500 characters).');
    }

    $order = kp_find_order($pdo, $orderId, $customerId);
    if (!$order) {
        throw new KpUserException('Order not found.');
    }
    // Pickup orders were priced without a delivery fee, so they cannot be switched to delivery here.
    if (kp_is_pickup((string) $order['delivery_address'])) {
        throw new KpUserException('Order #' . $orderId . ' is a cafeteria pickup order, so it has no delivery address to change.');
    }
    if (!kp_order_can_modify($order)) {
        throw new KpUserException('The delivery address for order #' . $orderId . ' can no longer be changed.');
    }

    // status = 'pending' is re-checked in the UPDATE in case the kitchen accepted the order meanwhile.
    $pdo->prepare("UPDATE `Order` SET delivery_address = :addr WHERE order_id = :oid AND customer_id = :cid AND status = 'pending'")
        ->execute([':addr' => $address, ':oid' => $orderId, ':cid' => $customerId]);
}

/* ===========================================================================
 * JSON endpoint — only runs when this file is the requested script
 * (cart.php and orders.php include it just for the helpers above).
 * ========================================================================= */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

header('Content-Type: application/json; charset=utf-8');

function kp_json(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kp_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if (!verifyCsrfToken()) {
    kp_json(['success' => false, 'message' => 'Your session token expired. Please refresh the page and try again.'], 403);
}

/** Cart state returned after every cart change so the page can redraw badge and totals. */
function kp_cart_payload(?string $key = null, ?array $item = null): array
{
    return [
        'success'    => true,
        'cart_count' => getCartCount(),
        'subtotal'   => kp_cart_subtotal(),
        'item'       => $item === null ? null : [
            'key'        => $key,
            'quantity'   => (int) $item['quantity'],
            'line_total' => (float) $item['unit_price'] * (int) $item['quantity'],
        ],
    ];
}

$action = (string) ($_POST['action'] ?? '');

try {
    switch ($action) {
        // 1. Add a standard dish (menu, dish details, favourites, find food, home page)
        case 'add_to_cart':
            $dishId   = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => KP_MAX_QTY]]) ?: 1;
            if (!$dishId) {
                throw new KpUserException('Invalid dish selected.');
            }
            $item = kp_cart_add_dish($pdo, $dishId, $quantity);
            kp_json(['success' => true, 'cart_count' => getCartCount(), 'message' => $item['name'] . ' added to your order!']);

        // 2. Cart page − / + buttons (quantity 0 removes the line)
        case 'update_cart_qty':
            $key      = (string) ($_POST['cart_key'] ?? '');
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity === null) {
                throw new KpUserException('Invalid quantity.');
            }
            $item = kp_cart_set_quantity($key, $quantity);
            kp_json(kp_cart_payload($key, $item) + ['message' => $item ? 'Cart updated.' : 'Item removed from your cart.']);

        // 3. Cart page remove link
        case 'remove_cart_item':
            kp_cart_set_quantity((string) ($_POST['cart_key'] ?? ''), 0);
            kp_json(kp_cart_payload() + ['message' => 'Item removed from your cart.']);

        // 4. Favourite heart toggle (menu, dish details, favourites, find food)
        case 'toggle_favourite':
            if (!isLoggedIn()) {
                kp_json(['success' => false, 'require_login' => true, 'message' => 'Please log in first.']);
            }
            $dishId     = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $customerId = (int) $_SESSION['user_id'];
            if (!$dishId) {
                throw new KpUserException('Invalid dish ID.');
            }

            $hasCustomer = $pdo->prepare('SELECT 1 FROM Customer WHERE user_id = :uid');
            $hasCustomer->execute([':uid' => $customerId]);
            if (!$hasCustomer->fetchColumn()) {
                $pdo->prepare('INSERT INTO Customer (user_id) VALUES (:uid)')->execute([':uid' => $customerId]);
            }

            $removed = $pdo->prepare('DELETE FROM Favourite WHERE customer_id = :cid AND dish_id = :did');
            $removed->execute([':cid' => $customerId, ':did' => $dishId]);
            if ($removed->rowCount() > 0) {
                kp_json(['success' => true, 'favourited' => false, 'message' => 'Removed from your favourites.']);
            }
            $pdo->prepare('INSERT INTO Favourite (customer_id, dish_id) VALUES (:cid, :did)')
                ->execute([':cid' => $customerId, ':did' => $dishId]);
            kp_json(['success' => true, 'favourited' => true, 'message' => 'Saved to your favourites!']);

        // 5. Live order tracking poll (orders.php)
        case 'check_order_status':
            if (!isLoggedIn()) {
                kp_json(['success' => false, 'require_login' => true, 'message' => 'Please log in first.']);
            }
            $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $order   = $orderId ? kp_find_order($pdo, $orderId, (int) $_SESSION['user_id']) : null;
            if (!$order) {
                throw new KpUserException('Order not found.');
            }
            kp_json(['success' => true] + kp_order_progress($order));

        default:
            kp_json(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (KpUserException $e) {
    kp_json(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('AJAX ' . $action . ' failed: ' . $e->getMessage());
    kp_json(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
