<?php
/**
 * Kree To Pla - Core Web Application Security Module
 * Explicitly mitigates:
 *  1. Session Hijacking (HttpOnly, SameSite, Strict Mode, UA Binding, Idle Timeout, ID Regeneration)
 *  2. Cross-Site Request Forgery (CSRF) (Cryptographic token generation + hash_equals verification)
 *  3. Cross-Site Scripting (XSS) (Contextual output encoding helper e() + Security Headers)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// 1. Send Defensive HTTP Security Headers (XSS & Clickjacking protection)
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data: https://images.unsplash.com;");
}

// 2. Secure Session Initialization (Session Hijacking Mitigation)
function initSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,     // Blocks JavaScript document.cookie theft (XSS -> Session Hijack)
            'samesite' => 'Lax',    // Protects session cookie across cross-site requests
        ]);

        session_name('KP_SECURE_SESSID');
        session_start();
    }

    // Session Idle Timeout (30 minutes = 1800 seconds)
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        destroySecureSession();
        session_start();
        $_SESSION['flash_error'] = 'Your session expired due to inactivity. Please log in again.';
    }
    $_SESSION['last_activity'] = time();

    // User-Agent Fingerprint Binding (Detects stolen session IDs used from a different browser)
    $currentUaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_ua');
    if (isset($_SESSION['user_id'])) {
        if (!isset($_SESSION['ua_fingerprint'])) {
            $_SESSION['ua_fingerprint'] = $currentUaHash;
        } elseif (!hash_equals($_SESSION['ua_fingerprint'], $currentUaHash)) {
            destroySecureSession();
            session_start();
            $_SESSION['flash_error'] = 'Security alert: Session anomaly detected. Please log in again.';
        }
    }
}

/**
 * Regenerates Session ID upon Login/Privilege change to prevent Session Fixation
 */
function establishUserSession(array $user, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']        = (int) $user['user_id'];
    $_SESSION['user_name']      = $user['name'];
    $_SESSION['user_email']     = $user['email'];
    $_SESSION['user_role']      = $role; // 'admin' or 'customer'
    $_SESSION['ua_fingerprint'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_ua');
    $_SESSION['last_activity']  = time();
    // Rotate CSRF token on privilege change
    $_SESSION['csrf_token']     = bin2hex(random_bytes(32));
}

/**
 * Completely invalidates session data and expires session cookie
 */
function destroySecureSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// 3. Cross-Site Scripting (XSS) Contextual Output Encoding Helper
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// 4. Cross-Site Request Forgery (CSRF) Token Generation & Verification
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(getCsrfToken()) . '">';
}

function verifyCsrfToken(?string $submittedToken = null): bool
{
    $token = $submittedToken
        ?? $_POST['csrf_token']
        ?? '';

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string) $token);
}

function requireValidCsrf(): void
{
    if (!verifyCsrfToken()) {
        http_response_code(403);
        die('<h3>403 Forbidden - CSRF Verification Failed</h3><p>Invalid or missing security token. Please go back, refresh the page, and try again.</p>');
    }
}

// 5. Role-Based Access Control (RBAC) Helpers
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function isCustomer(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'customer';
}

function requireLogin(string $redirectUrl = 'auth.php'): void
{
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Please log in to your account to access this feature.';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

function requireAdmin(string $redirectUrl = '../auth.php'): void
{
    if (!isAdmin()) {
        $_SESSION['flash_error'] = 'Access denied: Administrator privileges required.';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// 6. Cart Helper (Stores Cart Items in Session for fast UX, synced to DB on Checkout)
function getCartCount(): int
{
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return 0;
    }
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $count += (int) ($item['quantity'] ?? 1);
    }
    return $count;
}

initSecureSession();