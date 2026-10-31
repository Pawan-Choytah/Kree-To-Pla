<?php
/**
 * Kree To Pla - Form Action Handler (Semester 1 scope: Weeks 1-10)
 *
 * Receives standard HTML <form method="POST"> submissions for:
 *   - add_to_cart       (Cart stored in $_SESSION)
 *   - toggle_favourite  (Favourite table, PDO prepared statements)
 *
 * Flow (Post/Redirect/Get): validate -> process -> set $_SESSION flash message -> redirect back.
 * No output is ever echoed, so there is no AJAX/JSON response of any kind.
 *
 * Security Controls:
 *  - POST-only (other methods are bounced)
 *  - CSRF token verification (hash_equals) before any action runs
 *  - Action whitelist + server-side validation of every input
 *  - PDO prepared statements (SQL Injection mitigation)
 *  - Redirect target is rebuilt from the Referer's path + query only, never its host
 *    (prevents open redirects / header injection via a forged Referer)
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

/**
 * Store a one-time message. includes/header.php already renders and clears
 * 'flash_success' and 'flash_error', so no changes to the header are needed.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Redirect the user back to the page they submitted the form from.
 * HTTP_REFERER is client-controlled, so it is never trusted blindly:
 * only the same-host PATH and QUERY are reused; anything else falls back.
 */
function redirectBack(string $fallback = 'menu.php'): void
{
    $target  = $fallback;
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($referer !== '') {
        $parts   = parse_url($referer);
        $ownHost = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);

        if (
            is_array($parts)
            && isset($parts['host'], $parts['path'])
            && is_string($ownHost)
            && strcasecmp($parts['host'], $ownHost) === 0
            && preg_match('#^/(?!/)[^\\\\]*$#', $parts['path']) === 1   // single leading "/", no "//" or "\"
        ) {
            $target = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
    }

    // Strip control characters (CR/LF etc.) as a final header-injection guard
    $target = preg_replace('/[\x00-\x1F\x7F]/', '', $target) ?? $fallback;

    header('Location: ' . $target);
    exit;
}

// ---------------------------------------------------------------------------
// 0. Gatekeeping: POST only + CSRF
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Typing the URL in the address bar (GET) is not a valid way to use this script
    header('Location: menu.php');
    exit;
}

requireValidCsrf(); // Stops with "403 Forbidden" if the token is missing or wrong

$action = (string) ($_POST['action'] ?? '');
$dishId = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT);

if (!$dishId || $dishId <= 0) {
    setFlash('error', 'Invalid dish selected.');
    redirectBack();
}

try {
    switch ($action) {

        // -------------------------------------------------------------------
        // 1. ADD STANDARD DISH TO SESSION CART
        // -------------------------------------------------------------------
        case 'add_to_cart':
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?: 1;
            $quantity = max(1, min(20, $quantity));

            // Verify dish exists, is active, and read the active weekly-menu price
            $stmt = $pdo->prepare('
                SELECT d.dish_id, d.dish_name, d.image_url,
                       COALESCE(mi.price, d.base_price) AS unit_price,
                       COALESCE(mi.availability, TRUE) AS is_available
                FROM Dish d
                LEFT JOIN Menu m ON m.is_published = TRUE
                LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id AND mi.menu_id = m.menu_id
                WHERE d.dish_id = :dish_id AND d.is_active = TRUE
                ORDER BY m.week_start DESC
                LIMIT 1
            ');
            $stmt->execute([':dish_id' => $dishId]);
            $dish = $stmt->fetch();

            if (!$dish || !$dish['is_available']) {
                setFlash('error', 'This dish is currently unavailable.');
                break;
            }

            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $cartKey = 'dish_' . $dishId;
            if (isset($_SESSION['cart'][$cartKey])) {
                $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
            } else {
                $_SESSION['cart'][$cartKey] = [
                    'type'           => 'standard',
                    'dish_id'        => (int) $dish['dish_id'],
                    'custom_dish_id' => null,
                    'name'           => $dish['dish_name'],
                    'unit_price'     => (float) $dish['unit_price'],
                    'quantity'       => $quantity,
                    'image_url'      => $dish['image_url'],
                ];
            }

            setFlash('success', $dish['dish_name'] . ' added to your order!');
            break;

        // -------------------------------------------------------------------
        // 2. TOGGLE FAVOURITE DISH (Customer Req #10 & #11)
        // -------------------------------------------------------------------
        case 'toggle_favourite':
            if (!isLoggedIn()) {
                setFlash('error', 'Please log in to save your favourite dishes.');
                header('Location: auth.php');
                exit;
            }

            $customer = (int) $_SESSION['user_id'];

            // Ensure a Customer record exists for this user
            $pdo->prepare('INSERT IGNORE INTO Customer (user_id, address) VALUES (:uid, "Cafeteria Pickup")')
                ->execute([':uid' => $customer]);

            $check = $pdo->prepare('SELECT 1 FROM Favourite WHERE customer_id = :cid AND dish_id = :did');
            $check->execute([':cid' => $customer, ':did' => $dishId]);

            if ($check->fetch()) {
                $pdo->prepare('DELETE FROM Favourite WHERE customer_id = :cid AND dish_id = :did')
                    ->execute([':cid' => $customer, ':did' => $dishId]);
                setFlash('success', 'Removed from your favourites.');
            } else {
                $pdo->prepare('INSERT INTO Favourite (customer_id, dish_id) VALUES (:cid, :did)')
                    ->execute([':cid' => $customer, ':did' => $dishId]);
                setFlash('success', 'Saved to your favourites!');
            }
            break;

        // -------------------------------------------------------------------
        // Anything not in the whitelist is rejected
        // -------------------------------------------------------------------
        default:
            setFlash('error', 'Unknown action.');
    }
} catch (PDOException $ex) {
    // Never show SQL errors to the user (information disclosure) - log them instead
    error_log('process_action.php DB error: ' . $ex->getMessage());
    setFlash('error', 'Something went wrong. Please try again.');
}

redirectBack();
